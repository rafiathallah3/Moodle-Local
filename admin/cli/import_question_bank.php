<?php

use core_question\local\bank\question_edit_contexts;
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/question/editlib.php');

list($options, $unrecognized) = cli_get_params([
    'courseid' => false,
    'file' => false,
    'help' => false,
], [
    'h' => 'help'
]);

if ($options['help'] || !$options['courseid'] || !$options['file']) {
    echo "Import Moodle XML questions into a course question bank.

Options:
--courseid=INT    The ID of the course to import questions into.
--file=PATH       The path to the Moodle XML file.
-h, --help        Print out this help.

Example:
php admin/cli/import_question_bank.php --courseid=2 --file=questions.xml
";
    exit;
}

$courseid = $options['courseid'];
$filename = $options['file'];

if (!file_exists($filename)) {
    cli_error("File not found: $filename");
}

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

// Get the default category for the course.
$qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
$qbankcontext = \context_module::instance($qbank->id);
$contexts = new question_edit_contexts($qbankcontext);
$defaultcategory = question_get_default_category($qbankcontext->id, true);

// Set up the import format.
$qformat = new qformat_xml();
$qformat->setCategory($defaultcategory);
$qformat->setContexts($contexts->all());
$qformat->setCourse($course);
$qformat->setFilename($filename);

echo "Importing questions into course: " . format_string($course->fullname) . " (ID: $courseid)\n";
echo "Default category: " . $defaultcategory->name . "\n";

if (!$qformat->importpreprocess()) {
    cli_error("Error during import preprocess.");
}

if (!$qformat->importprocess()) {
    cli_error("Error during import process.");
}

if (!$qformat->importpostprocess()) {
    cli_error("Error during import postprocess.");
}

echo "Successfully imported questions!\n";
