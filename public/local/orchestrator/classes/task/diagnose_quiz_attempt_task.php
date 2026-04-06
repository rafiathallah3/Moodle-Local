<?php
namespace local_orchestrator\task;

defined('MOODLE_INTERNAL') || die();

class diagnose_quiz_attempt_task extends \core\task\adhoc_task {
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attemptid = $this->get_custom_data()->attemptid;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt) {
            return;
        }
        // When executed synchronously from the attempt_submitted event handler,
        // the attempt state may still be 'inprogress' because the DB transaction
        // hasn't committed yet. We allow both states here.
        if (!in_array($attempt->state, ['finished', 'inprogress'])) {
            return;
        }

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();

        $modified = false;
        $tempdir = make_request_directory();

        // Get the student's language preference for diagnosis output.
        $student_lang = 'en'; // fallback
        $student_user = $DB->get_record('user', ['id' => $attempt->userid], 'lang');
        if ($student_user && !empty($student_user->lang)) {
            $student_lang = $student_user->lang;
        }

        foreach ($slots as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question(false);
            $maxmark = $qa->get_max_mark();

            // Skip if no text or media is present
            $stepdata = $qa->get_last_qt_data();
            $responsetext = $qa->get_last_qt_var('answer', '');

            $mediafile = null;
            if ($stepdata) {
                foreach (array_keys($stepdata) as $qtvar) {
                    $files = $qa->get_last_qt_files($qtvar, $quba->get_owning_context()->id);
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

            // Convert HTML line breaks and block-level tags to newlines
            // BEFORE stripping tags, to preserve pseudo-code structure.
            $text_with_newlines = preg_replace('/<br\\s*\\/?>/i', "\n", $responsetext);
            $text_with_newlines = preg_replace('/<\\/(p|div|li|tr|h[1-6])>/i', "\n", $text_with_newlines);
            $text_with_newlines = preg_replace('/&nbsp;/i', ' ', $text_with_newlines);
            $clean_text = trim(strip_tags($text_with_newlines));
            if ($clean_text === '' && !$mediafile) {
                continue;
            }

            // Prepare files for python script
            $textfilepath = '';
            if ($clean_text !== '') {
                $textfilepath = $tempdir . '/student_text_' . $slot . '.txt';
                file_put_contents($textfilepath, $clean_text);
            }

            $mediafilepath = '';
            if ($mediafile) {
                $mediafilepath = $tempdir . '/' . $slot . '_' . $mediafile->get_filename();
                $mediafile->copy_content_to($mediafilepath);
            }

            $questiontextpath = $tempdir . '/question_text_' . $slot . '.txt';
            file_put_contents($questiontextpath, strip_tags($question->questiontext));

            // Run the Python script
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
                    if (!is_numeric($mark)) {
                        $mark = null;
                    } else {
                        $mark = (float)$mark;
                        if ($mark > $maxmark) $mark = $maxmark;
                        if ($mark < 0) $mark = 0;
                    }

                    // Apply manual grade
                    $quba->manual_grade($slot, $diagnosis, $mark, FORMAT_HTML);
                    $modified = true;
                }
            }
        }

        if ($modified) {
            \question_engine::save_questions_usage_by_activity($quba);
            
            // Recompute overall quiz grade
            $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            
            $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
            $gradecalculator = $quizobj->get_grade_calculator();
            
            // 1. Recalculate this specific attempt's sumgrade
            $gradecalculator->recompute_all_attempt_sumgrades();
            
            // 2. Recalculate the user's final quiz grade based on their attempts
            $gradecalculator->recompute_final_grade($attempt->userid);
            
            // 3. Push to Moodle Course Gradebook
            quiz_update_grades($quiz, $attempt->userid);
        }
    }
}
