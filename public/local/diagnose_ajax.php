<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

require_sesskey();
require_login();

$usageid = required_param('usageid', PARAM_INT);
$slot = required_param('slot', PARAM_INT);
$contextid = required_param('contextid', PARAM_INT);

try {
    $context = context::instance_by_id($contextid);
    if (!has_capability('mod/quiz:grade', $context) && !has_capability('moodle/grade:edit', $context) && !has_capability('moodle/course:manageactivities', $context)) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        die();
    }

    $quba = question_engine::load_questions_usage_by_activity($usageid);
    $qa = $quba->get_question_attempt($slot);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid attempt']);
    die();
}

$stepdata = $qa->get_last_qt_data();
$responsetext = $qa->get_last_qt_var('answer', '');

$mediafile = null;
if ($stepdata) {
    foreach (array_keys($stepdata) as $qtvar) {
        $files = $qa->get_last_qt_files($qtvar, $contextid);
        if (!empty($files)) {
            foreach ($files as $file) {
                $mimetype = $file->get_mimetype();
                $filename = $file->get_filename();
                if (strpos($mimetype, 'audio/') === 0 || strpos($mimetype, 'image/') === 0 || strpos($mimetype, 'video/') === 0 || preg_match('/\.(mp3|wav|ogg|webm|jpg|jpeg|png|gif|mp4)$/i', $filename)) {
                    $mediafile = $file;
                    break 2;
                }
            }
        }
    }
}

$diag_html = '';
$clean_text = trim(strip_tags($responsetext));
if ($clean_text !== '' || $mediafile) {
    $tempdir = make_request_directory();

    $textfilepath = '';
    if ($clean_text !== '') {
        $textfilepath = $tempdir . '/student_text.txt';
        file_put_contents($textfilepath, $clean_text);
    }

    $mediafilepath = '';
    if ($mediafile) {
        $mediafilepath = $tempdir . '/' . $mediafile->get_filename();
        $mediafile->copy_content_to($mediafilepath);
    }

    // Run the Python script to fetch the diagnosis
    $script_path = $CFG->dirroot . '/admin/cli/diagnose.py';
    if (!file_exists($script_path)) {
        $script_path = dirname($CFG->dirroot) . '/admin/cli/diagnose.py';
    }

    $command = "python " . escapeshellarg($script_path);
    if ($textfilepath) {
        $command .= " --textfile " . escapeshellarg($textfilepath);
    }
    if ($mediafilepath) {
        $command .= " --file " . escapeshellarg($mediafilepath);
    }
    $command .= " 2>&1";

    $output_json = shell_exec($command);

    if ($output_json) {
        $result = json_decode($output_json, true);
        if ($result && isset($result['status']) && $result['status'] === 'success') {

            // Critic / Quality Gate validation
            require_once(__DIR__ . '/../../local/orchestrator/classes/critic.php');
            $run_id = 'DIAGNOSE-' . $usageid . '-' . $slot . '-' . time();
            $policy = [
                'constraints' => [
                    'no_full_solution' => true,
                    'hint_only' => true,
                    'max_hint_tier' => 2,
                    'max_revision_iters' => 1,
                    'grounding_required' => false
                ]
            ];
            $evidence = [
                'mode' => 'diagnose',
                'task_context' => [
                    'usageid' => $usageid,
                    'slot' => $slot,
                    'contextid' => $contextid
                ],
                'student_submission' => $clean_text
            ];
            $candidate = [
                'type' => 'feedback_only',
                'feedback' => [$result['diagnosis']]
            ];

            $critic_result = \local_orchestrator\critic::evaluate_candidate($run_id, 'diagnose', $policy, $evidence, $candidate);

            if ($critic_result && isset($critic_result['final_verdict']) && $critic_result['final_verdict'] === 'BLOCK') {
                $block_reasons = [];
                $findings = $critic_result['findings'] ?? [];
                foreach ($findings as $finding) {
                    if (isset($finding['issue'])) {
                        $block_reasons[] = $finding['issue'];
                    }
                }
                $reason_str = empty($block_reasons) ? 'Quality gate check failed.' : implode('; ', $block_reasons);
                echo json_encode(['status' => 'error', 'message' => 'AI Response blocked by Quality Gate: ' . $reason_str]);
                die();
            }

            $diagnosis = htmlspecialchars($result['diagnosis']);
            // Convert **text** to <h2>text</h2>
            $diagnosis = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $diagnosis);
            // Convert newlines to breaks
            $diagnosis = nl2br($diagnosis);

            echo json_encode(['status' => 'success', 'diagnosis' => $diagnosis]);
        } else if ($result && isset($result['message'])) {
            echo json_encode(['status' => 'error', 'message' => htmlspecialchars($result['message'])]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Could not parse output: ' . htmlspecialchars($output_json)]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No output from diagnosis engine.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No text or media file found for diagnosis.']);
}
