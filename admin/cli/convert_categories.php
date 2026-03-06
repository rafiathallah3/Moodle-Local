<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

$adminuser = get_admin();
\core\session\manager::set_user($adminuser);

$courseid = 2; // Algorithm Programming 1
$coursecontext = context_course::instance($courseid);

// Find orphaned categories in the course context (excluding 'top' category itself)
$sql = "SELECT id, name, contextid, parent FROM {question_categories} 
        WHERE contextid = :ctx AND name != 'top'";
$categories = $DB->get_records_sql($sql, ['ctx' => $coursecontext->id]);

if (empty($categories)) {
    cli_writeln("No orphaned categories found in course context.");
    exit(0);
}

$course = $DB->get_record('course', ['id' => $courseid]);

foreach ($categories as $cat) {
    cli_writeln("Processing category: " . $cat->name);

    // Check if qbank with exactly this name already exists
    $modinfo = get_fast_modinfo($course);
    $existing = false;
    foreach ($modinfo->get_instances_of('qbank') as $qbank) {
        if ($qbank->name === $cat->name) {
            cli_writeln(" -> qbank activity '{$cat->name}' already exists. Skipping.");
            $existing = true;
            break;
        }
    }

    if ($existing)
        continue;

    // Create the qbank module
    $cm = \core_question\local\bank\question_bank_helper::create_default_open_instance(
        $course,
        $cat->name
    );

    // Provide the proper name when updating the module
    $DB->set_field('qbank', 'name', $cat->name, ['id' => $cm->instance]);
    $DB->set_field('course_modules', 'idnumber', $cat->name, ['id' => $cm->id]);
    $DB->set_field('qbank', 'intro', 'Created to rescue orphaned category', ['id' => $cm->instance]);
    $DB->set_field('qbank', 'introformat', FORMAT_HTML, ['id' => $cm->instance]);

    // Get the new module context and its default top category
    $modcontext = \context_module::instance($cm->id);
    $topcategory = question_get_default_category($modcontext->id, true);

    // Re-parent the orphaned category into the new qbank's context
    $cat->contextid = $modcontext->id;
    $cat->parent = $topcategory->id;
    $DB->update_record('question_categories', $cat);

    cli_writeln(" -> Successfully converted into qbank activity (CM ID: {$cm->id}). Context moved from {$coursecontext->id} to {$modcontext->id}.");
}

rebuild_course_cache($courseid, true);
cli_writeln("Done!");
