<?php
/**
 * Orchestrator API endpoint for AMT-CS1.
 * 
 * Routes Moodle student requests to the right AMT-CS1 agents (Analyzer, Lesson Generator, Critic, Retriever).
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$evidence_json = required_param('evidence', PARAM_RAW);

// Load the Orchestrator Skill definition
$skill_path = $CFG->dirroot . '/.gemini/skills/amtcs1-orchestrator/SKILL.md';
if (!file_exists($skill_path)) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Orchestrator skill definition not found.'
    ]);
    die();
}

$system_instruction = file_get_contents($skill_path);

// We want the AI to return a JSON object as defined in the skill.
$full_prompt = $system_instruction . "\n\n=== INCOMING EVIDENCE ===\n" . $evidence_json . "\n\nProvide the orchestrator response in strict JSON format as specified in the instructions.";

$context = context_system::instance();

try {
    // initialize the action
    $action = new \core_ai\aiactions\generate_text(
        contextid: $context->id,
        userid: $USER->id,
        prompttext: $full_prompt
    );

    // Process via AI manager
    global $DB;
    $manager = new \core_ai\manager($DB);
    $response = $manager->process_action($action);

    if ($response->get_success()) {
        $data = $response->get_response_data();
        $generated = $data['generatedcontent'];

        // Ensure we extract JSON payload
        // Sometimes LLMs wrap JSON in markdown block ```json ... ```
        $clean_json = preg_replace('/^```(?:json)?\s*/i', '', $generated);
        $clean_json = preg_replace('/\s*```$/i', '', $clean_json);
        $clean_json = trim($clean_json);

        // Further safety cleanup if text was appended before or after JSON braces
        if (($start = strpos($clean_json, '{')) !== false && ($end = strrpos($clean_json, '}')) !== false) {
            $clean_json = substr($clean_json, $start, $end - $start + 1);
        }

        // Attempt to decode to verify it's valid JSON
        $decoded = json_decode($clean_json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            header('Content-Type: application/json');
            echo json_encode($decoded);
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'AI did not return valid JSON.',
                'raw_response' => $generated,
                'json_error' => json_last_error_msg()
            ]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => $response->get_errormessage()
        ]);
    }

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
