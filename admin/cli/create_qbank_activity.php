<?php

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');
require_once($CFG->libdir . '/questionlib.php');

$usage = "
Create a Question Bank activity module in a course.

Options:
-c, --courseid=ID      Course ID
-n, --name=NAME        Name of the Question Bank activity
-i, --info=INFO        Optional description
-h, --help             Print out this help

Example:
\$ sudo -u www-data php admin/cli/create_qbank_activity.php --courseid=2 --name='C# Questions'
";

list($options, $unrecognized) = cli_get_params([
    'courseid' => false, // false is correct for required params in older Moodle? Actually, Moodle docs usually suggest either false or '' for values that need an argument. 
    'name' => '', // MUST be a string to tell cli_get_params this expects a value, not a boolean flag.
    'info' => 'Created via CLI',
    'help' => false,
], [
    'c' => 'courseid',
    'n' => 'name',
    'i' => 'info',
    'h' => 'help',
]);

if ($options['help'] || empty($options['courseid']) || empty($options['name'])) {
    cli_error($usage);
}

$courseid = $options['courseid'];
$categoryname = $options['name'];
$info = $options['info'];

if ($categoryname === true || $categoryname === '1') {
    cli_error("Error: The 'name' parameter must be a string. Did you use -n? Try using --name=\"Your Name\"");
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Need admin capability to create modules
$adminuser = get_admin();
if (!$adminuser) {
    cli_error("Error: Could not find admin user.");
}
\core\session\manager::set_user($adminuser);

try {
    // Check if a qbank module with this name already exists in this course
    $modinfo = get_fast_modinfo($course);
    $existing_qbanks = $modinfo->get_instances_of('qbank');
    foreach ($existing_qbanks as $qbank) {
        if ($qbank->name === $categoryname) {
            cli_writeln("Question bank activity '{$categoryname}' already exists in course {$courseid}.");
            exit(0);
        }
    }

    $cm = \core_question\local\bank\question_bank_helper::create_default_open_instance(
        $course,
        $categoryname
    );

    // Provide the proper name when updating the module
    $DB->set_field('qbank', 'name', $categoryname, ['id' => $cm->instance]);
    $DB->set_field('course_modules', 'idnumber', $categoryname, ['id' => $cm->id]);
    $DB->set_field('qbank', 'intro', $info, ['id' => $cm->instance]);
    $DB->set_field('qbank', 'introformat', FORMAT_HTML, ['id' => $cm->instance]);

    // Update the course cache so the new module appears immediately
    rebuild_course_cache($courseid, true);

    $context = \context_module::instance($cm->id);
    $cat = question_get_default_category($context->id, true);

    cli_writeln("Success! Question bank activity '{$categoryname}' created.");
    cli_writeln("QBank Instance ID: {$cm->instance}");
    cli_writeln("Course Module ID: {$cm->id}");
    cli_writeln("Default Category ID: {$cat->id}");

} catch (Exception $e) {
    cli_error("Failed to create Question Bank activity: " . $e->getMessage());
}
