<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../config.php');

require_login();
require_sesskey();

$prompt = required_param('prompt', PARAM_TEXT);

// Custom System Instruction for Chat
$system_instruction = "You are a helpful and witty Moodle assistant. Answer concisely.";
$full_prompt = $system_instruction . "\n\n" . $prompt;

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
        echo json_encode([
            'success' => true,
            'message' => $data['generatedcontent']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $response->get_errormessage()
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
