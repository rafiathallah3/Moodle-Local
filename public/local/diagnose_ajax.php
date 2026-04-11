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
// Convert HTML line breaks and block-level tags to newlines BEFORE stripping,
// so pseudo-code structure (program/dictionary/algorithm) is preserved.
$text_with_newlines = preg_replace('/<br\s*\/?>/i', "\n", $responsetext);
$text_with_newlines = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $text_with_newlines);
$text_with_newlines = preg_replace('/&nbsp;/i', ' ', $text_with_newlines);
$clean_text = trim(strip_tags($text_with_newlines));
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

    // Get the student's language preference
    $language_code = 'en'; // fallback
    $student_userid = 0;
    $student_attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $usageid], 'userid');
    if ($student_attempt && !empty($student_attempt->userid)) {
        $student_userid = $student_attempt->userid;
        $student_user = $DB->get_record('user', ['id' => $student_userid], 'lang');
        if ($student_user && !empty($student_user->lang)) {
            $language_code = $student_user->lang;
        }
    }

    // Run the Python Orchestrator Graph via Bridge
    require_once(__DIR__ . '/../../local/orchestrator/classes/python_runner.php');

    // Fallback course_id if not strictly available
    $course_id = isset($context->instanceid) ? $context->instanceid : 'CS101';

    $evidence = [
        'user_id' => (string) $student_userid,
        'course_id' => (string) $course_id,
        'content' => $clean_text,
        'trigger' => 'diagnose',
        'metadata' => [
            'role' => 'student',
            'assessment_id' => $usageid,
            'slot' => $slot,
            'language' => $language_code
        ]
    ];

    if ($mediafilepath) {
        $evidence['metadata']['media_path'] = $mediafilepath;
    }

    $result = \local_orchestrator\python_runner::run_agentic_flow($evidence);

    if ($result && isset($result['status']) && $result['status'] === 'success') {

        $mark = null;
        $diagnosis_html = '';

        if (isset($result['scoring'])) {
            $scoring = $result['scoring'];
            $mark = isset($scoring['score']) ? (float) $scoring['score'] : null;

            $diagnosis_html .= "<strong>Pemeriksaan Logika:</strong><br/>";
            $diagnosis_html .= nl2br(htmlspecialchars($scoring['summary'])) . "<br/><br/>";

            if (!empty($scoring['misconceptions'])) {
                $diagnosis_html .= "<em>Identifikasi Masalah:</em><br/>";
                foreach ($scoring['misconceptions'] as $misc) {
                    $diagnosis_html .= "- " . htmlspecialchars($misc) . "<br/>";
                }
                $diagnosis_html .= "<br/>";
            }
        }

        // Append learning tips if present (from Learning Style Agent)
        if (isset($result['learning_tips']) && !empty($result['learning_tips'])) {
            $diagnosis_html .= "<strong>&#128270; Tips (Sesuai Gaya Belajarmu):</strong><br/>";

            // The tips array is nested under recommendations.tips
            $tips_array = [];
            if (isset($result['learning_tips']['recommendations']['tips'])) {
                $tips_array = $result['learning_tips']['recommendations']['tips'];
            } elseif (isset($result['learning_tips']['general_tips'])) {
                $tips_array = $result['learning_tips']['general_tips'];
            }
            foreach ($tips_array as $tip) {
                $diagnosis_html .= "- " . htmlspecialchars($tip) . "<br/>";
            }

            // Include adaptive message from the Learning Style Agent
            if (isset($result['learning_tips']['adaptive_message'])) {
                $diagnosis_html .= "<br/><em>" . htmlspecialchars($result['learning_tips']['adaptive_message']) . "</em><br/>";
            }
            $diagnosis_html .= "<br/>";
        }

        // Append motivational message
        if (isset($result['motivational_message'])) {
            $diagnosis_html .= "<em>" . htmlspecialchars($result['motivational_message']) . "</em>";
        }

        if (empty($diagnosis_html) && isset($result['content']['explanation'])) {
            $diagnosis_html .= nl2br(htmlspecialchars($result['content']['explanation']));
        }

        echo json_encode(['status' => 'success', 'diagnosis' => $diagnosis_html, 'mark' => $mark]);

    } else if ($result && isset($result['message'])) {
        echo json_encode(['status' => 'error', 'message' => htmlspecialchars($result['message'])]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No output or invalid JSON from diagnosis engine.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No text or media file found for diagnosis.']);
}
