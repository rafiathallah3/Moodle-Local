<?php
namespace local_orchestrator;

defined('MOODLE_INTERNAL') || die();

class python_runner
{
    /**
     * Run the agentic flow via Python script
     *
     * @param array $state_payload The evidence payload from Moodle
     * @return array|null The decoded JSON response from Python, or null on failure.
     */
    public static function run_agentic_flow(array $state_payload, string $input_mode = 'moodle_evidence', string $action = 'run'): ?array
    {
        global $CFG;

        $python_path = isset($CFG->pathtopython) ? $CFG->pathtopython : 'python';
        $script_path = escapeshellarg($CFG->dirroot . '/main.py');

        // Prepare the payload for amtcs1_entrypoint.py
        $input_data = [
            'action' => $action,
            'input_mode' => $input_mode
        ];
        
        if ($input_mode === 'moodle_evidence') {
            $input_data['evidence'] = $state_payload;
        } else {
            $input_data['payload'] = $state_payload;
        }

        $input_json = json_encode($input_data);

        $command = "{$python_path} {$script_path} --stdin";

        $descriptorspec = [
            0 => ["pipe", "r"],  // STDIN
            1 => ["pipe", "w"],  // STDOUT
            2 => ["pipe", "w"]   // STDERR
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            // Write to STDIN
            fwrite($pipes[0], $input_json);
            fclose($pipes[0]);

            // Read STDOUT and STDERR
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($process);

            // Clean output to ensure we just parse JSON
            $clean_json = trim($stdout);
            if (($start = strpos($clean_json, '{')) !== false && ($end = strrpos($clean_json, '}')) !== false) {
                // If it starts cleanly with a brace, use the bounds
                $clean_json = substr($clean_json, $start, $end - $start + 1);
            }

            $decoded = json_decode($clean_json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            } else {
                // Log stderr or invalid JSON if needed
                if (!empty($stderr)) {
                    error_log("AMT-CS1 Python Error: " . $stderr);
                }
                error_log("AMT-CS1 Python JSON Parse Error: " . json_last_error_msg() . " ON string: " . $stdout);
                return null;
            }
        }
        return null;
    }
}
