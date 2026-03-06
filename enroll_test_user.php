<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

$userid = 3;
$courseid = 2;

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$enrol = enrol_get_plugin('manual');
$instances = enrol_get_instances($course->id, true);
$manualinstance = null;
foreach ($instances as $instance) {
    if ($instance->enrol === 'manual') {
        $manualinstance = $instance;
        break;
    }
}

if ($manualinstance) {
    $enrol->enrol_user($manualinstance, $user->id, 5); // 5 is student role natively
    echo "Enrolled user $userid in course $courseid\n";
} else {
    echo "Manual enrolment instance not found for course $courseid\n";
}
