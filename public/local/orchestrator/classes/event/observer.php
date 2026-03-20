<?php
namespace local_orchestrator\event;

defined('MOODLE_INTERNAL') || die();

class observer
{

    /**
     * Observer for mod_assign assessable_submitted event.
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event)
    {
        global $DB;

        // Get assignment submission data
        $submissionid = $event->objectid;
        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission) {
            return;
        }

        $assignid = $event->other['assignid'] ?? 0;

        $submission_text = '';
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($onlinetext) {
            $submission_text = strip_tags($onlinetext->onlinetext);
        }

        // Check for file submissions
        $files = [];
        $fs = get_file_storage();
        $context = \context_module::instance($event->contextinstanceid);
        $submission_files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submissionid, 'filename', false);

        foreach ($submission_files as $file) {
            $files[] = [
                'filename' => $file->get_filename(),
                'mimetype' => $file->get_mimetype()
            ];
        }

        $submission_data = ['text' => $submission_text];
        if (!empty($files)) {
            $submission_data['files'] = $files;
        }

        self::process_orchestrator(
            $event->userid,
            $event->courseid,
            'assign',
            $assignid,
            $submission_data
        );

        // Execute adhoc task for AI Diagnosis grading synchronously
        try {
            $task = new \local_orchestrator\task\diagnose_assign_submission_task();
            $task->set_custom_data(['submissionid' => $submissionid, 'assignid' => $assignid]);
            $task->execute();
        } catch (\Exception $e) {
            // Log it but don't break the submission
            debugging('Error executing AI Diagnosis: ' . $e->getMessage());
        }
    }

    /**
     * Observer for mod_quiz attempt_submitted event.
     */
    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event)
    {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attemptid = $event->objectid;
        $quizid = $event->other['quizid'] ?? 0;

        // Load attempt data
        $qubaid = $DB->get_field('quiz_attempts', 'uniqueid', ['id' => $attemptid]);
        if (!$qubaid) {
            return;
        }

        try {
            $quba = \question_engine::load_questions_usage_by_activity($qubaid);
            $slots = $quba->get_slots();

            $text_responses = [];
            foreach ($slots as $slot) {
                $qa = $quba->get_question_attempt($slot);
                $ans = $qa->get_last_qt_var('answer', '');
                if ($ans !== '') {
                    $text_responses[] = strip_tags($ans);
                }
            }
            $submission_text = implode("\n", $text_responses);

            self::process_orchestrator(
                $event->userid,
                $event->courseid,
                'quiz',
                $quizid,
                ['text' => $submission_text]
            );

            // Execute adhoc task for AI Diagnosis grading synchronously
            try {
                $task = new \local_orchestrator\task\diagnose_quiz_attempt_task();
                $task->set_custom_data(['attemptid' => $attemptid]);
                $task->execute();
            } catch (\Exception $e) {
                // Log it but don't break the submission
                debugging('Error executing AI Diagnosis: ' . $e->getMessage());
            }

        } catch (\Exception $e) {
            // Exception in quiz extraction
        }
    }

    /**
     * Assemble evidence, call the AI subsystem, and save output to database.
     */
    private static function process_orchestrator($userid, $courseid, $module, $instanceid, $student_submission)
    {
        global $DB, $CFG, $USER;

        // Fetch Student Profile
        $user_record = $DB->get_record('user', ['id' => $userid], 'id, username, email, idnumber, department, institution');
        $student_profile = $user_record ? (array) $user_record : ['id' => $userid];

        // Fetch Recent Grade History internally for this user
        $history = [
            'recent_grades' => []
        ];
        $grades = $DB->get_records_sql(
            "SELECT g.id, i.itemname, i.itemmodule, g.finalgrade, g.rawgrade
             FROM {grade_grades} g
             JOIN {grade_items} i ON g.itemid = i.id
             WHERE g.userid = ? AND i.courseid = ? AND g.finalgrade IS NOT NULL
             ORDER BY g.timemodified DESC LIMIT 5",
            [$userid, $courseid]
        );
        if ($grades) {
            foreach ($grades as $g) {
                $history['recent_grades'][] = ['activity' => $g->itemname, 'module' => $g->itemmodule, 'grade' => $g->finalgrade];
            }
        }

        // Summarize available course resources slightly
        $resources = [];
        $course_mods = $DB->get_records_sql(
            "SELECT cm.id, m.name as modname, cm.instance
             FROM {course_modules} cm
             JOIN {modules} m ON cm.module = m.id
             WHERE cm.course = ? AND (m.name = 'resource' OR m.name = 'page' OR m.name = 'folder')",
            [$courseid]
        );
        if ($course_mods) {
            $resources['available_materials'] = count($course_mods) . ' reading materials available in course.';
        }

        $evidence = [
            'mode' => 'submit_answer',
            'student_profile' => $student_profile,
            'task_context' => [
                'courseid' => $courseid,
                'module' => $module,
                'instanceid' => $instanceid
            ],
            'student_submission' => $student_submission,
            'history' => $history,
            'resources' => $resources,
            'policies' => []
        ];

        $evidence_json = json_encode($evidence);

        // Load the Orchestrator Skill definition
        $base_dir = $CFG->dirroot;
        if (basename($base_dir) === 'public') {
            $base_dir = dirname($base_dir);
        }
        $skill_path = $base_dir . '/.gemini/skills/amtcs1-orchestrator/SKILL.md';
        if (!file_exists($skill_path)) {
            $skill_path = dirname(__DIR__, 4) . '/.gemini/skills/amtcs1-orchestrator/SKILL.md';
        }

        if (!file_exists($skill_path)) {
            // Cannot run without definitions
            self::log_error($userid, $courseid, $module, $instanceid, "Skill definition not found at $skill_path");
            return;
        }

        $system_instruction = file_get_contents($skill_path);
        // We want the AI to return a JSON object as defined in the skill.
        $full_prompt = $system_instruction . "\n\n=== INCOMING EVIDENCE ===\n" . $evidence_json . "\n\nProvide the orchestrator response in strict JSON format as specified in the instructions.";

        $context = \context_system::instance();

        // Fallback user if current user is not set (e.g. cron)
        $executor_id = $USER->id ?? null;
        if (empty($executor_id)) {
            $executor_id = $userid;
        }

        try {
            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: $executor_id,
                prompttext: $full_prompt
            );

            $manager = new \core_ai\manager($DB);
            $response = $manager->process_action($action);

            if ($response->get_success()) {
                $data = $response->get_response_data();
                $generated = $data['generatedcontent'];

                // Cleanup JSON
                $clean_json = preg_replace('/^```(?:json)?\s*/i', '', $generated);
                $clean_json = preg_replace('/\s*```$/i', '', $clean_json);
                $clean_json = trim($clean_json);

                if (($start = strpos($clean_json, '{')) !== false && ($end = strrpos($clean_json, '}')) !== false) {
                    $clean_json = substr($clean_json, $start, $end - $start + 1);
                }

                $decoded = json_decode($clean_json, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Save successful routing decision to DB
                    $record = new \stdClass();
                    $record->run_id = $decoded['run_id'] ?? ('RUN-' . date('Ymd-His') . '-' . rand(1000, 9999));
                    $record->userid = $userid;
                    $record->courseid = $courseid;
                    $record->module = $module;
                    $record->instanceid = $instanceid;
                    $record->mode = $decoded['mode'] ?? null;
                    $record->input_evidence = $evidence_json; // Save raw evidence string
                    $record->input_modalities = isset($decoded['input_modalities']) ? json_encode($decoded['input_modalities']) : null;
                    $record->input_quality = isset($decoded['input_quality']) ? (is_string($decoded['input_quality']) ? $decoded['input_quality'] : json_encode($decoded['input_quality'])) : null;
                    $record->request_summary = $decoded['request_summary'] ?? null;
                    $record->policy = isset($decoded['policy']) ? json_encode($decoded['policy']) : null;
                    $record->routing = isset($decoded['routing']) ? json_encode($decoded['routing']) : null;
                    $record->agents_called = isset($decoded['agents_called']) ? json_encode($decoded['agents_called']) : null;
                    $record->final_payload = isset($decoded['final_payload']) ? json_encode($decoded['final_payload']) : null;
                    $record->timecreated = time();

                    $DB->insert_record('local_orchestrator_log', $record);
                } else {
                    self::log_error($userid, $courseid, $module, $instanceid, "AI returned invalid JSON: " . json_last_error_msg());
                }
            } else {
                self::log_error($userid, $courseid, $module, $instanceid, "AI API action failed: " . $response->get_errormessage());
            }
        } catch (\Throwable $e) {
            self::log_error($userid, $courseid, $module, $instanceid, "Exception during pipeline: " . $e->getMessage());
        }
    }

    private static function log_error($userid, $courseid, $module, $instanceid, $error_msg)
    {
        global $DB;
        try {
            $record = new \stdClass();
            $record->run_id = 'ERR-' . date('Ymd-His') . '-' . rand(100, 999);
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->module = $module;
            $record->instanceid = $instanceid;
            $record->mode = 'system_error';
            $record->final_payload = json_encode(['error' => $error_msg]);
            $record->timecreated = time();
            $DB->insert_record('local_orchestrator_log', $record);
        } catch (\Throwable $ex) {
            // Failsafe
        }
    }
}
