<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
$courses = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE fullname LIKE '%Algorithm%'");
foreach($courses as $course) {
    echo "ID: {$course->id}, Name: {$course->fullname}
";
}
