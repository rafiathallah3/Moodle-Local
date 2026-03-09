<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once(__DIR__ . '/local/orchestrator/classes/memory.php');

$student_id = 9999;
$course_id = 1;

$run_result = json_decode('{
  "memory_updates": {
    "student_model_update": {
      "mastery_by_kc_new": { "KC_loop": 0.43 },
      "misconceptions_new": [
        {
          "tag": "mis_loop_termination",
          "activation": 0.41,
          "confidence_last": 0.82,
          "last_seen": "1234",
          "count": 1
        }
      ]
    },
    "session_log_update": {
      "summary": "Likely loop boundary confusion detected",
      "tags": ["KC_loop", "mis_loop_termination"],
      "student_visible_outcome": "feedback_delivered"
    }
  }
}', true);

echo "Inserting mock profile...\n";
\local_orchestrator\memory::update_profile((object) ['id' => $student_id], (object) ['id' => $course_id], $run_result);

echo "Loading back...\n";
$loaded = \local_orchestrator\memory::load_profile($student_id, $course_id);
print_r($loaded);

echo "Cleaning up mock profile...\n";
global $DB;
$DB->delete_records('local_orch_stud_profile', ['userid' => $student_id]);
$DB->delete_records('local_orch_int_summ', ['userid' => $student_id]);
echo "Done.\n";
