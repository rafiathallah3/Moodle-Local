<?php
namespace local_orchestrator\task;

defined('MOODLE_INTERNAL') || die();

class diagnose_assign_submission_task extends \core\task\adhoc_task {
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $submissionid = $this->get_custom_data()->submissionid;
        $assignid = $this->get_custom_data()->assignid;

        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission) return;

        $cm = get_coursemodule_from_instance('assign', $assignid);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, null);

        $assignment_record = $DB->get_record('assign', ['id' => $assignid]);
        $maxmark = (float)$assignment_record->grade;
        if ($maxmark <= 0) return; // Scale or no grade

        $tempdir = make_request_directory();

        // Extract student text
        $submission_text = '';
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($onlinetext) {
            $text_with_newlines = preg_replace('/<br\s*\/?>/i', "\n", $onlinetext->onlinetext);
            $text_with_newlines = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $text_with_newlines);
            $text_with_newlines = preg_replace('/&nbsp;/i', ' ', $text_with_newlines);
            $submission_text = trim(strip_tags($text_with_newlines));
        }

        // Extract media
        $fs = get_file_storage();
        $submission_files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submissionid, 'filename', false);
        
        $mediafile = null;
        foreach ($submission_files as $file) {
            $mimetype = $file->get_mimetype();
            $filename = $file->get_filename();
            if (strpos($mimetype, 'audio/') === 0 || strpos($mimetype, 'image/') === 0 || strpos($mimetype, 'video/') === 0 || preg_match('/\.(mp3|wav|ogg|webm|jpg|jpeg|png|gif|mp4)$/i', $filename)) {
                $mediafile = $file;
                break;
            }
        }

        if ($submission_text === '' && !$mediafile) return;

        $textfilepath = '';
        if ($submission_text !== '') {
            $textfilepath = $tempdir . '/student_text.txt';
            file_put_contents($textfilepath, $submission_text);
        }

        $mediafilepath = '';
        if ($mediafile) {
            $mediafilepath = $tempdir . '/' . $mediafile->get_filename();
            $mediafile->copy_content_to($mediafilepath);
        }

        $questiontextpath = $tempdir . '/question_text.txt';
        file_put_contents($questiontextpath, strip_tags($assignment_record->intro));

        // Get the student's language preference for diagnosis output.
        $student_lang = 'en'; // fallback
        $student_user = $DB->get_record('user', ['id' => $submission->userid], 'lang');
        if ($student_user && !empty($student_user->lang)) {
            $student_lang = $student_user->lang;
        }

        $script_path = dirname($CFG->dirroot) . '/admin/cli/diagnose.py';
        if (!file_exists($script_path)) {
            $script_path = $CFG->dirroot . '/admin/cli/diagnose.py';
        }

        $command = "python " . escapeshellarg($script_path);
        if ($textfilepath) {
            $command .= " --textfile " . escapeshellarg($textfilepath);
        }
        if ($mediafilepath) {
            $command .= " --file " . escapeshellarg($mediafilepath);
        }
        $command .= " --questiontextfile " . escapeshellarg($questiontextpath);
        $command .= " --maxmark " . escapeshellarg($maxmark);
        $command .= " --language " . escapeshellarg($student_lang);
        $command .= " 2>&1";

        $output_json = shell_exec($command);
        if ($output_json) {
            $result = json_decode($output_json, true);
            if ($result && isset($result['status']) && $result['status'] === 'success') {
                $diagnosis = htmlspecialchars($result['diagnosis']);
                $diagnosis = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $diagnosis);
                $diagnosis = nl2br($diagnosis);
                
                $mark = $result['mark'] ?? null;
                if (is_numeric($mark)) {
                    $mark = (float)$mark;
                    if ($mark > $maxmark) $mark = $maxmark;
                    if ($mark < 0) $mark = 0;

                    // Save to assignment grades
                    $grade = $assign->get_user_grade($submission->userid, true);
                    $grade->grade = $mark;
                    $DB->update_record('assign_grades', $grade);

                    // Save feedback comment
                    $feedback_plugin = $assign->get_plugin_by_type('assignfeedback', 'comments');
                    if ($feedback_plugin && $feedback_plugin->is_enabled()) {
                        $feedback = new \stdClass();
                        $feedback->grade = $grade->id;
                        $feedback->commenttext = $diagnosis;
                        $feedback->commentformat = FORMAT_HTML;
                        
                        $existing = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]);
                        if ($existing) {
                            $feedback->id = $existing->id;
                            $DB->update_record('assignfeedback_comments', $feedback);
                        } else {
                            $DB->insert_record('assignfeedback_comments', $feedback);
                        }
                    }
                    
                    // Update main grades table
                    $assign->update_grade($grade);
                }
            }
        }
    }
}
