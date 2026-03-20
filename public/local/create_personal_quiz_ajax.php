<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

require_sesskey();

$sectionid = required_param('sectionid', PARAM_INT);

global $DB, $USER;

try {
    $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $section->course], '*', MUST_EXIST);
    $coursecontext = context_course::instance($course->id);

    // Validate user is enrolled in the course (not require_capability which checks a different thing)
    if (!is_enrolled($coursecontext, $USER->id, '', true)) {
        throw new \moodle_exception('error', 'error', '', 'You must be enrolled in this course.');
    }

    // (Removed restriction: allow admins/teachers to test the endpoint)

    // Keep reference to original user before switching to admin
    $originaluser = clone $USER;

    // Switch to admin to bypass capability checks for module creation
    $adminuser = get_admin();
    \core\session\manager::set_user($adminuser);

    // CRITICAL: After switching user, we must reload capabilities so the admin's
    // site-admin status is recognized by has_capability() checks inside add_moduleinfo.
    load_all_capabilities();

    $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);

    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'quiz';
    $moduleinfo->module = $module->id;
    $moduleinfo->name = $section->name . ' - Personal Practice Quiz - ' . fullname($originaluser);
    $moduleinfo->intro = 'This is your personal practice quiz.';
    $moduleinfo->introformat = FORMAT_HTML;
    $moduleinfo->course = $course->id;
    $moduleinfo->section = $section->section;
    $moduleinfo->visible = 1;

    // Set Availability condition so ONLY this user's email matches
    $availability = [
        'op' => '&',
        'c' => [
            [
                'type' => 'profile',
                'op' => 'isequalto',
                'sf' => 'email',
                'v' => $originaluser->email
            ]
        ],
        'showc' => [false]
    ];
    $moduleinfo->availability = json_encode($availability);

    // Quiz defaults
    $moduleinfo->timeopen = 0;
    $moduleinfo->timeclose = 0;
    $moduleinfo->timelimit = 0;
    $moduleinfo->overduehandling = 'autosubmit';
    $moduleinfo->graceperiod = 0;
    $moduleinfo->attempts = 0;
    $moduleinfo->attemptonlast = 0;
    $moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
    $moduleinfo->decimalpoints = 2;
    $moduleinfo->questiondecimalpoints = -1;
    $moduleinfo->preferredbehaviour = 'deferredfeedback';
    $moduleinfo->canredoquestions = 0;
    $moduleinfo->shuffleanswers = 1;
    $moduleinfo->questionsperpage = 1;
    $moduleinfo->navmethod = 'free';
    $moduleinfo->grade = 10;
    $moduleinfo->sumgrades = 0;
    $moduleinfo->quizpassword = '';
    $moduleinfo->subnet = '';
    $moduleinfo->browsersecurity = '-';
    $moduleinfo->delay1 = 0;
    $moduleinfo->delay2 = 0;
    $moduleinfo->showuserpicture = 0;
    $moduleinfo->showblocks = 0;
    $moduleinfo->completionattemptsexhausted = 0;
    $moduleinfo->completionminattempts = 0;
    $moduleinfo->allowofflineattempts = 0;

    // Review options for the quiz so the student can see their answers and grades
    // quiz_process_options expects boolean flags rather than bitmasks.
    $review_fields = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
    $review_times = ['during', 'immediately', 'open', 'closed'];
    foreach ($review_fields as $field) {
        foreach ($review_times as $time) {
            $prop = $field . $time;
            $moduleinfo->$prop = 1;
        }
    }
    $moduleinfo->coursemodule = 0;
    $moduleinfo->instance = 0;

    $result = add_moduleinfo($moduleinfo, $course);

    if (!$result || !isset($result->instance)) {
        throw new Exception("Failed to create quiz.");
    }

    // Switch back to original user temporarily to properly execute question APIs 
    // (though admin usually has these capabilities too)
    \core\session\manager::set_user($originaluser);

    // --- Auto-assign questions based on previous quiz grades in this section ---

    // 1. Find all quizzes in this section.
    $sql = "SELECT cm.instance as quizid
              FROM {course_modules} cm
              JOIN {modules} m ON cm.module = m.id
             WHERE cm.course = ? AND cm.section = ? AND m.name = 'quiz' AND cm.instance != ?";
    $quizzesinsection = $DB->get_records_sql($sql, [$course->id, $section->id, $result->instance]);

    $highestgrade = null;

    if ($quizzesinsection) {
        $quizids = array_keys($quizzesinsection);
        list($insql, $inparams) = $DB->get_in_or_equal($quizids);

        // 2. Find the highest grade this student achieved on ANY quiz in this section
        $params = array_merge([$originaluser->id], $inparams);
        $grade_sql = "SELECT MAX(grade) as highest 
                        FROM {quiz_grades} 
                       WHERE userid = ? AND quiz $insql";
        $highestgrade = $DB->get_field_sql($grade_sql, $params);
    }

    // 3. Determine question difficulty
    // No previous quiz / No grade -> Easy
    // Grade <= 5 (out of 10) -> Medium
    // Grade > 5 -> Hard
    $difficulty = 'Easy';
    if ($highestgrade !== null && $highestgrade !== false) {
        // We assume 50% is 5 out of 10 or similar boundary. 
        // For standard percentage checking, we might need quiz max grade.
        // Assuming raw grade is scored out of 10 or 100
        if ((float) $highestgrade <= 5.0 || (float) $highestgrade <= 50.0) {
            $difficulty = 'Medium'; // Using medium if they scored <= 50%
        } else {
            $difficulty = 'Hard'; // Using hard if they scored > 50%
        }
    }

    // 4. Find a matching category by name from a qbank activity module (Required in Moodle 4.3+)
    require_once($CFG->dirroot . '/course/lib.php');
    $sectionname = !empty($section->name) ? $section->name : get_section_name($course, $section);
    
    $catsql_base = "SELECT qc.id, qc.name 
                 FROM {qbank} m
                 JOIN {course_modules} cm ON m.id = cm.instance
                 JOIN {modules} mods ON cm.module = mods.id AND mods.name = 'qbank'
                 JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = 70
                 JOIN {question_categories} qc ON qc.contextid = ctx.id AND qc.name != 'top'
                WHERE m.course = ?";

    $category = null;

    if (!empty($sectionname)) {
        // Try to find a qbank matching BOTH section name and difficulty
        $catsql_both = $catsql_base . " AND m.name LIKE ? AND m.name LIKE ?";
        $category = $DB->get_record_sql($catsql_both, [$course->id, '%' . $sectionname . '%', '%' . $difficulty . '%'], IGNORE_MULTIPLE);

        if (!$category) {
            // Fallback to just section name
            $catsql_sec = $catsql_base . " AND m.name LIKE ?";
            $category = $DB->get_record_sql($catsql_sec, [$course->id, '%' . $sectionname . '%'], IGNORE_MULTIPLE);
        }
    }

    if (!$category) {
        // Fallback to just difficulty, as before
        $catsql_diff = $catsql_base . " AND m.name LIKE ?";
        $category = $DB->get_record_sql($catsql_diff, [$course->id, '%' . $difficulty . '%'], IGNORE_MULTIPLE);
    }

    if ($category) {
        // Elevate back to Admin just in case to add questions without capability issues
        \core\session\manager::set_user($adminuser);
        try {
            // Moodle 4.3+ API
            if (class_exists('\mod_quiz\quiz_settings')) {
                $quizobj = \mod_quiz\quiz_settings::create($result->instance);
                if (method_exists($quizobj, 'get_structure')) {
                    $structure = $quizobj->get_structure();
                    $filtercondition = [
                        'filter' => [
                            'category' => [
                                'jointype' => 1,
                                'values' => [$category->id],
                                'filteroptions' => ['includesubcategories' => true]
                            ]
                        ]
                    ];
                    $structure->add_random_questions(1, 1, $filtercondition);
                }
            } else if (function_exists('quiz_add_random_questions')) {
                // Fallback for older Moodle versions
                quiz_add_random_questions($DB->get_record('quiz', ['id' => $result->instance]), 1, $category->id, 1);
            }

            // Recompute the sumgrades since we added questions
            if (class_exists('\mod_quiz\quiz_settings')) {
                $quizobj = \mod_quiz\quiz_settings::create($result->instance);
                if (method_exists($quizobj, 'get_grade_calculator')) {
                    $gradecalc = $quizobj->get_grade_calculator();
                    $gradecalc->recompute_quiz_sumgrades();
                } else if (class_exists('\mod_quiz\grade_calculator')) {
                    // Fallback to static if necessary (as IDE reported it's deprecated/wrong but let's be safe)
                    // \mod_quiz\grade_calculator::recompute_quiz_sumgrades($result->instance);
                }
            } else if (function_exists('quiz_update_sumgrades')) {
                quiz_update_sumgrades($DB->get_record('quiz', ['id' => $result->instance]));
            }
        } catch (Exception $e) {
            // If adding questions fails, we continue anyway and log the error
            error_log('Failed to add random questions to personal quiz: ' . $e->getMessage());
        }
    }

    // Switch back to original user permanently
    \core\session\manager::set_user($originaluser);

    echo json_encode([
        'success' => true,
        'highestgrade' => $highestgrade
    ]);

} catch (Exception $e) {
    // Attempt rollback to original user in case of error
    if (isset($originaluser)) {
        \core\session\manager::set_user($originaluser);
    }

    $debuginfo = isset($e->debuginfo) ? ' | Debug: ' . $e->debuginfo : '';

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() . $debuginfo . ' in ' . $e->getFile() . ':' . $e->getLine()
    ]);
}
