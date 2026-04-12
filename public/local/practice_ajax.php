<?php
/**
 * Practice Pipeline AJAX endpoint for AFS v2.
 *
 * Handles 3 stages of the Practice Pipeline:
 *   1. generate     — Call Python to produce a practice problem + Stage 1 question.
 *   2. verify_stage1 — Evaluate student's conceptual answer; get Stage 2 question.
 *   3. verify_stage2 — Final evaluation and recommendation.
 *
 * Session keys (per user+course):
 *   practice_problem       — AI-generated problem explanation text
 *   practice_skeleton      — Skeleton code snippet
 *   practice_concepts      — Array of target KCs
 *   practice_topic         — Topic string
 *   practice_q1            — Stage 1 question text
 *   practice_q2            — Stage 2 question text
 *   practice_current_stage — 1 or 2
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../config.php');

require_login();
require_sesskey();

global $USER, $CFG, $DB;

header('Content-Type: application/json; charset=utf-8');

$action    = required_param('action', PARAM_ALPHA);   // generate | verify_stage1 | verify_stage2
$courseid  = optional_param('courseid', 0, PARAM_INT);
$topic     = optional_param('topic', 'general', PARAM_TEXT);
$answer    = optional_param('answer', '', PARAM_RAW);

$user_id  = (string) $USER->id;
$course_id = $courseid > 0 ? (string) $courseid : 'CS101';

// ─── Session Key ─────────────────────────────────────────────────────────────
$session_key = 'practice_' . $USER->id . '_' . $courseid;

if (!isset($_SESSION[$session_key])) {
    $_SESSION[$session_key] = [];
}
$sess = &$_SESSION[$session_key];

// ─── Load Python Runner ───────────────────────────────────────────────────────
require_once($CFG->dirroot . '/local/orchestrator/classes/python_runner.php');

/**
 * Build a payload and call Python main.py --stdin with action=practice.
 * Returns decoded associative array or null on failure.
 */
function call_practice_python(string $stage, string $user_id, string $course_id, array $practice_data): ?array {
    global $CFG;

    $python_path = isset($CFG->pathtopython) ? $CFG->pathtopython : 'python';
    $script_path = escapeshellarg($CFG->dirroot . '/main.py');

    $payload = [
        'action'    => 'practice',
        'user_id'   => $user_id,
        'course_id' => $course_id,
        'practice'  => array_merge(['stage' => $stage], $practice_data)
    ];

    $input_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $command    = "{$python_path} {$script_path} --stdin";

    $descriptorspec = [
        0 => ['pipe', 'r'],   // STDIN
        1 => ['pipe', 'w'],   // STDOUT
        2 => ['pipe', 'w'],   // STDERR
    ];

    $process = proc_open($command, $descriptorspec, $pipes);

    if (!is_resource($process)) {
        error_log('[PracticeAJAX] proc_open failed');
        return null;
    }

    fwrite($pipes[0], $input_json);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($stderr) {
        error_log('[PracticeAJAX] Python stderr: ' . $stderr);
    }

    $clean = trim($stdout);
    // Strip surrounding non-JSON text if any
    if (($s = strpos($clean, '{')) !== false && ($e = strrpos($clean, '}')) !== false) {
        $clean = substr($clean, $s, $e - $s + 1);
    }

    $decoded = json_decode($clean, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('[PracticeAJAX] JSON parse error: ' . json_last_error_msg() . ' | raw: ' . $stdout);
        return null;
    }

    return $decoded;
}

// ─── Route Stages ────────────────────────────────────────────────────────────

try {

    if ($action === 'generate') {
        // Clear previous session state for a fresh practice round
        $_SESSION[$session_key] = [];
        $sess = &$_SESSION[$session_key];

        $result = call_practice_python('generate', $user_id, $course_id, [
            'topic' => $topic
        ]);

        if (!$result || ($result['status'] ?? '') !== 'success') {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Python pipeline returned no result for generate stage.'
            ]);
            exit;
        }

        // Persist problem state to session
        $sess['topic']           = $topic;
        $sess['problem']         = $result['problem'] ?? '';
        $sess['skeleton_code']   = $result['skeleton_code'] ?? '';
        $sess['concepts']        = $result['concepts'] ?? [];
        $sess['stage1_question'] = $result['stage1_question'] ?? '';
        $sess['current_stage']   = 1;

        echo json_encode([
            'success'         => true,
            'action'          => 'generated',
            'topic'           => $topic,
            'problem'         => $sess['problem'],
            'skeleton_code'   => $sess['skeleton_code'],
            'concepts'        => $sess['concepts'],
            'stage1_question' => $sess['stage1_question'],
        ]);

    } elseif ($action === 'verify_stage1') {

        if (empty($sess['problem'])) {
            echo json_encode(['success' => false, 'message' => 'No active practice session. Please generate a problem first.']);
            exit;
        }

        if (trim($answer) === '') {
            echo json_encode(['success' => false, 'message' => 'Jawaban tidak boleh kosong.']);
            exit;
        }

        $result = call_practice_python('verify_stage1', $user_id, $course_id, [
            'topic'               => $sess['topic'] ?? 'general',
            'student_answer'      => $answer,
            'question'            => $sess['stage1_question'] ?? '',
            'problem_explanation' => $sess['problem'] ?? ''
        ]);

        if (!$result || ($result['status'] ?? '') !== 'success') {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Python pipeline returned no result for verify_stage1.'
            ]);
            exit;
        }

        $sess['stage1_result'] = $result;
        $passed = (bool) ($result['passed'] ?? false);

        $response = [
            'success'          => true,
            'action'           => 'stage1_evaluated',
            'passed'           => $passed,
            'score'            => $result['score'] ?? 0,
            'feedback'         => $result['feedback'] ?? '',
            'missing_concepts' => $result['missing_concepts'] ?? [],
        ];

        if ($passed && !empty($result['stage2_question'])) {
            $sess['stage2_question'] = $result['stage2_question'];
            $sess['current_stage']   = 2;
            $response['stage2_question'] = $result['stage2_question'];
        }

        echo json_encode($response);

    } elseif ($action === 'verify_stage2') {

        if (empty($sess['problem'])) {
            echo json_encode(['success' => false, 'message' => 'No active practice session. Please generate a problem first.']);
            exit;
        }

        if (empty($sess['stage2_question'])) {
            echo json_encode(['success' => false, 'message' => 'Stage 2 question not generated yet. Complete Stage 1 first.']);
            exit;
        }

        if (trim($answer) === '') {
            echo json_encode(['success' => false, 'message' => 'Jawaban tidak boleh kosong.']);
            exit;
        }

        $result = call_practice_python('verify_stage2', $user_id, $course_id, [
            'topic'               => $sess['topic'] ?? 'general',
            'student_answer'      => $answer,
            'question'            => $sess['stage2_question'],
            'problem_explanation' => $sess['problem'] ?? ''
        ]);

        if (!$result || ($result['status'] ?? '') !== 'success') {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Python pipeline returned no result for verify_stage2.'
            ]);
            exit;
        }

        // Clear session after final stage
        $_SESSION[$session_key] = [];

        echo json_encode([
            'success'        => true,
            'action'         => 'stage2_evaluated',
            'passed'         => (bool) ($result['passed'] ?? false),
            'score'          => $result['score'] ?? 0,
            'feedback'       => $result['feedback'] ?? '',
            'final_status'   => $result['final_status'] ?? 'needs_practice',
            'recommendation' => $result['recommendation'] ?? '',
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
    }

} catch (Exception $e) {
    error_log('[PracticeAJAX] Exception: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
