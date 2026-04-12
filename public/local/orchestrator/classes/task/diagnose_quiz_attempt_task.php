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

            // Run the Agentic AFS v2 Pipeline via main.py
            $script_path = dirname($CFG->dirroot) . '/main.py';
            if (!file_exists($script_path)) {
                $script_path = $CFG->dirroot . '/main.py';
            }

            // Construct evidence block
            $evidence = [
                'content' => $clean_text,
                'trigger' => 'diagnose',
                'metadata' => [
                    'role' => 'student',
                    'language' => $student_lang,
                    'question_text' => strip_tags($question->questiontext),
                    'max_mark' => $maxmark
                ]
            ];
            
            if ($mediafilepath) {
                $evidence['media_path'] = $mediafilepath;
            }

            $payload = [
                'action' => 'run',
                'input_mode' => 'moodle_evidence',
                'evidence' => $evidence,
                'user_id' => (string) $attempt->userid,
                'course_id' => (string) $attempt->quiz
            ];
            
            $json_payload = json_encode($payload);
            
            // Execute python via stdin
            $command = "python " . escapeshellarg($script_path) . " --stdin 2>&1";
            $descriptorspec = [
                0 => ["pipe", "r"],  // stdin
                1 => ["pipe", "w"],  // stdout
                2 => ["pipe", "w"]   // stderr
            ];
            $process = proc_open($command, $descriptorspec, $pipes);
            $output_json = '';
            if (is_resource($process)) {
                fwrite($pipes[0], $json_payload);
                fclose($pipes[0]);
                $output_json = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }

            if ($output_json) {
                // Ignore any text preceding the JSON output
                $clean_json = $output_json;
                if (($start = strpos($clean_json, '{')) !== false && ($end = strrpos($clean_json, '}')) !== false) {
                    $clean_json = substr($clean_json, $start, $end - $start + 1);
                }
                $result = json_decode($clean_json, true);
                if ($result && isset($result['status']) && $result['status'] === 'success') {
                    // Collect fully constructed diagnosis from AFS V2 parts
                    $diagnosis_parts = [];
                    if (isset($result['scoring']) && isset($result['scoring']['summary'])) {
                        $diagnosis_parts[] = $result['scoring']['summary'];
                    }
                    if (isset($result['learning_tips'])) {
                        $diagnosis_parts[] = "Tips (Sesuai Gaya Belajarmu):\n" . $result['learning_tips'];
                    }
                    if (isset($result['motivational_message'])) {
                        $diagnosis_parts[] = "Pesan Tutor:\n" . $result['motivational_message'];
                    }
                    if (empty($diagnosis_parts)) {
                        // fallback
                        $diagnosis_parts[] = "Tutor review complete.";
                    }
                    
                    $full_diagnosis = implode("\n\n", $diagnosis_parts);
                    $full_diagnosis = htmlspecialchars($full_diagnosis);
                    $full_diagnosis = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $full_diagnosis);
                    $full_diagnosis = nl2br($full_diagnosis);
                    
                    // Mark calculation
                    $mark = null;
                    if (isset($result['scoring']) && isset($result['scoring']['score'])) {
                        // score_0_100 is returned, map to max_mark
                        $score_0_100 = (float) $result['scoring']['score'];
                        $mark = ($score_0_100 / 100.0) * $maxmark;
                    }

                    if (!is_numeric($mark)) {
                        $mark = null;
                    } else {
                        if ($mark > $maxmark) $mark = $maxmark;
                        if ($mark < 0) $mark = 0;
                    }

                    // Apply manual grade
                    $quba->manual_grade($slot, $full_diagnosis, $mark, FORMAT_HTML);
                    $modified = true;
                    
                    // DB Logging for Orchestrator
                    $record = new \stdClass();
                    $record->run_id = 'DIAG-' . date('Ymd-His') . '-' . rand(1000, 9999);
                    $record->userid = $attempt->userid;
                    $record->courseid = $attempt->quiz; 
                    $record->module = 'quiz';
                    $record->instanceid = $attempt->quiz;
                    $record->mode = 'diagnose_quiz';
                    $record->input_evidence = $json_payload;
                    $record->timecreated = time();
                    
                    if (isset($result['intent'])) $record->request_summary = 'Intent: ' . $result['intent'];
                    if (isset($result['cff_applied'])) $record->policy = 'CFF: ' . ($result['cff_applied'] ? 'Applied' : 'None');
                    if (isset($result['nodes_executed'])) $record->agents_called = json_encode($result['nodes_executed']);
                    $record->final_payload = $clean_json;
                    
                    try {
                        $DB->insert_record('local_orchestrator_log', $record);
                    } catch (\Exception $e) {
                        // Failsafe
                    }
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
