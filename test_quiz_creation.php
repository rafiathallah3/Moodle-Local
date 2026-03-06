<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

$userid = 3; // Student Rafi Ata
$courseid = 2;
$sectionid = 7; // Week 2 section ID assuming from DB

// Mocking session context
$student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
\core\session\manager::set_user($student);

// Mocking $_POST
$_POST['courseid'] = $courseid;
$_POST['sectionid'] = $sectionid;
$_POST['sesskey'] = sesskey();

echo "Running create_personal_quiz_ajax.php...\n";
// Include the script which will execute the logic
require(__DIR__ . '/public/local/create_personal_quiz_ajax.php');
echo "\nDone!\n";
