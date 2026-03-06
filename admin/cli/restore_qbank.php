<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$cmid = 467; // Week 2 - Loop Hard Course Module ID
$courseid = 2; // Algorithm Programming 1

// Reset the deletion flag
$DB->set_field('course_modules', 'deletioninprogress', 0, ['id' => $cmid]);

echo "Restored CM: {$cmid}\n";

// Update the course cache so the module appears immediately
require_once($CFG->dirroot . '/course/lib.php');
rebuild_course_cache($courseid, true);

echo "Course cache rebuilt.\n";
