<?php
namespace local_orchestrator;

defined('MOODLE_INTERNAL') || die();

class critic
{
    /**
     * Evaluate a candidate response using the AMT-CS1 Critic / Quality Gate.
     *
     * @param string $run_id The RUN ID for the current interaction.
     * @param string $mode The mode of the interaction.
     * @param array $policy Policy constraints.
     * @param array $evidence Evidence context.
     * @param array $candidate Candidate response to evaluate.
     * @param int|null $userid Optional user ID. Defaults to current user.
     * @return array|null The JSON decoded response from the AI, or null on failure.
     */
    public static function evaluate_candidate(string $run_id, string $mode, array $policy, array $evidence, array $candidate, ?int $userid = null): ?array
    {
        global $DB, $CFG, $USER;

        $input_data = [
            'run_id' => $run_id,
            'mode' => $mode,
            'policy' => $policy,
            'evidence' => $evidence,
            'candidate' => $candidate
        ];

        $input_json = json_encode($input_data, JSON_PRETTY_PRINT);

        // Load the Critic Skill definition
        $base_dir = $CFG->dirroot;
        if (basename($base_dir) === 'public') {
            $base_dir = dirname($base_dir);
        }
        $skill_path = $base_dir . '/.gemini/skills/amtcs1-critic-quality-gate/SKILL.md';
        if (!file_exists($skill_path)) {
            $skill_path = dirname(__DIR__, 4) . '/.gemini/skills/amtcs1-critic-quality-gate/SKILL.md';
        }

        if (!file_exists($skill_path)) {
            // Cannot run without definitions
            self::log_error($userid ?? 0, 0, 'critic', 0, "Skill definition not found at $skill_path");
            return null;
        }

        $system_instruction = file_get_contents($skill_path);
        // We want the AI to return a JSON object as defined in the skill.
        $full_prompt = $system_instruction . "\n\n=== INCOMING EVIDENCE ===\n" . $input_json . "\n\nProvide the critic response in strict JSON format as specified in the instructions.";

        $context = \context_system::instance();

        // Fallback user if current user is not set (e.g. cron)
        $executor_id = $USER->id ?? null;
        if (empty($executor_id)) {
            $executor_id = $userid;
        }

        if (empty($executor_id)) {
            // System user fallback if possible
            $admins = get_admins();
            if (!empty($admins)) {
                $admin = reset($admins);
                $executor_id = $admin->id;
            }
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
                    // Log successful evaluation
                    try {
                        $record = new \stdClass();
                        $record->run_id = $run_id;
                        $record->userid = $userid ?? ($USER->id ?? 0);
                        $record->courseid = $evidence['task_context']['courseid'] ?? 0;
                        $record->module = 'critic';
                        $record->instanceid = $evidence['task_context']['instanceid'] ?? ($evidence['task_context']['usageid'] ?? 0);
                        $record->mode = $mode;
                        $record->input_evidence = $input_json;
                        $record->agents_called = json_encode(['Critic' => 'OK']);
                        $record->final_payload = json_encode($decoded);
                        $record->timecreated = time();
                        $DB->insert_record('local_orchestrator_log', $record);
                    } catch (\Throwable $e) {
                        // Failsafe if logging fails
                    }
                    return $decoded;
                } else {
                    self::log_error($userid ?? 0, 0, 'critic', 0, "AI returned invalid JSON: " . json_last_error_msg() . "\n" . $clean_json);
                }
            } else {
                self::log_error($userid ?? 0, 0, 'critic', 0, "AI API action failed: " . $response->get_errormessage());
            }
        } catch (\Throwable $e) {
            self::log_error($userid ?? 0, 0, 'critic', 0, "Exception during critic pipeline: " . $e->getMessage());
        }

        return null;
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
