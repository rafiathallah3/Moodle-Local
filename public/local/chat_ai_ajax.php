<?php
/**
 * AI Chatbot AJAX Gateway for Moodle.
 *
 * This endpoint powers the LangChain AI chatbot. It:
 * 1. Gathers Moodle context (course info, sections, assignments) for the current user.
 * 2. Passes the context + conversation history to admin/cli/chat.py via shell_exec.
 * 3. Handles tool actions returned by the AI (e.g., creating a personal quiz).
 *
 * @package    local
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../config.php');

require_login();
require_sesskey();

global $DB, $USER, $CFG;

header('Content-Type: application/json; charset=utf-8');

$prompt = required_param('prompt', PARAM_RAW);
$courseid = optional_param('courseid', 0, PARAM_INT);

// ─── 1. Gather Moodle Context ────────────────────────────────────────────────

$context_data = [
    'user' => [
        'id' => $USER->id,
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname,
    ],
    'course' => [],
    'sections' => [],
    'assignments' => [],
];

if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if ($course) {
        $context_data['course'] = [
            'id' => (int) $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => strip_tags($course->summary),
        ];

        // Sections
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        foreach ($sections as $sec) {
            $sec_name = !empty($sec->name) ? $sec->name : 'Section ' . $sec->section;
            $context_data['sections'][] = [
                'id' => (int) $sec->id,
                'num' => (int) $sec->section,
                'name' => $sec_name,
                'summary' => strip_tags($sec->summary ?? ''),
            ];
        }

        // Assignments & Quizzes — activities with due dates
        $activities = [];

        // Assignments (mod_assign)
        $assigns = $DB->get_records_sql(
            "SELECT a.id, a.name, a.duedate, cm.section as sectionid
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} m ON cm.module = m.id AND m.name = 'assign'
              WHERE a.course = ? AND cm.visible = 1
              ORDER BY a.duedate ASC",
            [$courseid]
        );
        foreach ($assigns as $a) {
            $status = '';
            $sub = $DB->get_record('assign_submission', [
                'assignment' => $a->id,
                'userid' => $USER->id,
                'latest' => 1,
            ]);
            if ($sub && $sub->status === 'submitted') {
                $status = 'Submitted';
            } elseif ($a->duedate > 0 && $a->duedate < time()) {
                $status = 'Overdue';
            } else {
                $status = 'Pending';
            }

            // Get section name
            $sec_name = '';
            $sec_rec = $DB->get_record('course_sections', ['id' => $a->sectionid]);
            if ($sec_rec) {
                $sec_name = !empty($sec_rec->name) ? $sec_rec->name : 'Section ' . $sec_rec->section;
            }

            $activities[] = [
                'name' => $a->name,
                'type' => 'Assignment',
                'duedate' => $a->duedate > 0 ? userdate($a->duedate) : 'No due date',
                'status' => $status,
                'section_name' => $sec_name,
                'sectionid' => (int) $a->sectionid,
            ];
        }

        // Quizzes (mod_quiz)
        $quizzes = $DB->get_records_sql(
            "SELECT q.id, q.name, q.timeclose, cm.section as sectionid
               FROM {quiz} q
               JOIN {course_modules} cm ON cm.instance = q.id
               JOIN {modules} m ON cm.module = m.id AND m.name = 'quiz'
              WHERE q.course = ? AND cm.visible = 1
              ORDER BY q.timeclose ASC",
            [$courseid]
        );
        foreach ($quizzes as $q) {
            $status = '';
            $attempt = $DB->get_record_sql(
                "SELECT id, state FROM {quiz_attempts}
                  WHERE quiz = ? AND userid = ?
                  ORDER BY attempt DESC LIMIT 1",
                [$q->id, $USER->id]
            );
            if ($attempt && $attempt->state === 'finished') {
                $status = 'Attempted';
            } elseif ($q->timeclose > 0 && $q->timeclose < time()) {
                $status = 'Closed';
            } else {
                $status = 'Available';
            }

            $sec_name = '';
            $sec_rec = $DB->get_record('course_sections', ['id' => $q->sectionid]);
            if ($sec_rec) {
                $sec_name = !empty($sec_rec->name) ? $sec_rec->name : 'Section ' . $sec_rec->section;
            }

            $activities[] = [
                'name' => $q->name,
                'type' => 'Quiz',
                'duedate' => $q->timeclose > 0 ? userdate($q->timeclose) : 'No deadline',
                'status' => $status,
                'section_name' => $sec_name,
                'sectionid' => (int) $q->sectionid,
            ];
        }

        $context_data['assignments'] = $activities;
    }
}

// ─── 2. Manage Conversation History ─────────────────────────────────────────

if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

$session_key = 'chat_' . $USER->id . '_' . $courseid;
if (!isset($_SESSION['chat_history'][$session_key])) {
    $_SESSION['chat_history'][$session_key] = [];
}

$history = $_SESSION['chat_history'][$session_key];

// Limit history to last 20 messages to avoid token overflow
if (count($history) > 20) {
    $history = array_slice($history, -20);
}

// ─── 3. Write temp files and call Python ────────────────────────────────────

$tempdir = make_request_directory();

$context_file = $tempdir . '/context.json';
file_put_contents($context_file, json_encode($context_data, JSON_UNESCAPED_UNICODE));

$history_file = $tempdir . '/history.json';
file_put_contents($history_file, json_encode($history, JSON_UNESCAPED_UNICODE));

// Locate the Python script
$script_path = dirname($CFG->dirroot) . '/admin/cli/chat.py';
if (!file_exists($script_path)) {
    $script_path = $CFG->dirroot . '/admin/cli/chat.py';
}

if (!file_exists($script_path)) {
    echo json_encode(['success' => false, 'message' => 'Chat engine not found.']);
    die();
}

$command = "python " . escapeshellarg($script_path)
    . " --prompt " . escapeshellarg($prompt)
    . " --contextfile " . escapeshellarg($context_file)
    . " --historyfile " . escapeshellarg($history_file)
    . " 2>&1";

$output_json = shell_exec($command);

if (!$output_json) {
    echo json_encode(['success' => false, 'message' => 'Failed to execute chat engine.']);
    die();
}

$result = json_decode($output_json, true);

if (!$result || !isset($result['status'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid response from chat engine.',
        'raw' => $output_json,
    ]);
    die();
}

if ($result['status'] !== 'success') {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Unknown error.']);
    die();
}

// ─── 4. Handle Tool Actions ─────────────────────────────────────────────────

$tool_result = null;
$tool_action = $result['tool_action'] ?? null;
$generated_content = $result['generated_content'] ?? null;

if ($tool_action && is_array($tool_action) && isset($tool_action['action'])) {
    if ($tool_action['action'] === 'create_quiz' && !empty($tool_action['sectionid'])) {
        $theme = $tool_action['theme'] ?? null;
        $language = $tool_action['language'] ?? 'English';
        $tool_result = handle_create_quiz((int) $tool_action['sectionid'], $theme, $language, $generated_content);
    }
}

// ─── 5. Save history and respond ────────────────────────────────────────────

// Add user message
$_SESSION['chat_history'][$session_key][] = [
    'role' => 'user',
    'content' => $prompt,
];

// Add AI response
$_SESSION['chat_history'][$session_key][] = [
    'role' => 'ai',
    'content' => $result['message'],
];

$response = [
    'success' => true,
    'message' => $result['message'],
];

if ($tool_result !== null) {
    $response['tool_result'] = $tool_result;
} elseif ($tool_action) {
    if (is_string($tool_action)) {
        // AI returned tool action as a JSON string instead of object
        $parsed = json_decode($tool_action, true);
        if (is_array($parsed) && isset($parsed['action']) && $parsed['action'] === 'create_quiz') {
            $sectionid = $parsed['sectionid'] ?? null;
            $theme = $parsed['theme'] ?? null;
            $language = $parsed['language'] ?? 'English';
            if (!empty($sectionid)) {
                $tool_result = handle_create_quiz((int) $sectionid, $theme, $language, $generated_content);
                $response['tool_result'] = $tool_result;
            } else {
                $response['message'] .= "\n\n*(System Debug): Tool action string decoded, but sectionid is missing: " . $tool_action . "*";
            }
        } else {
            $response['message'] .= "\n\n*(System Debug): Tool action payload was an unparseable string: " . $tool_action . "*";
        }
    } else {
        // It's an array, but skipped
        $debuginfo = json_encode($tool_action);
        $response['message'] .= "\n\n*(System Debug): Tool action was skipped dynamically in PHP. tool_action payload received: " . $debuginfo . "*";
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);


// ─── Tool Handlers ──────────────────────────────────────────────────────────

/**
 * Create a personal practice quiz for the current user in the given section.
 * Supports themed quiz creation: if a theme is specified and no matching
 * questions exist, new questions are generated via AI.
 *
 * @param int $sectionid  The course section ID.
 * @param string|null $theme  Optional topic/theme for the quiz (e.g., "sorting algorithms").
 */
function handle_create_quiz(int $sectionid, ?string $theme = null, string $language = 'English', ?array $generated_content = null): array
{
    global $DB, $USER, $CFG;

    try {
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $section->course], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);

        if (!is_enrolled($coursecontext, $USER->id, '', true)) {
            return ['success' => false, 'message' => 'You must be enrolled in this course.'];
        }

        $originaluser = clone $USER;

        // Switch to admin for module creation
        $adminuser = get_admin();
        \core\session\manager::set_user($adminuser);
        load_all_capabilities();

        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);

        // Build quiz name
        $sectionname = !empty($section->name) ? $section->name : 'Section ' . $section->section;
        $quizname = $sectionname;
        if ($theme) {
            $quizname .= ' - ' . ucwords($theme);
        }
        $quizname .= ' - AI Chat Quiz - ' . fullname($originaluser);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->module = $module->id;
        $moduleinfo->name = $quizname;
        $moduleinfo->intro = $theme
            ? 'Practice quiz on <strong>' . htmlspecialchars($theme) . '</strong>, created by the AI assistant.'
            : 'Practice quiz created by the AI assistant.';
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $section->section;
        $moduleinfo->visible = 1;

        // Restrict to this user only
        $availability = [
            'op' => '&',
            'c' => [
                [
                    'type' => 'profile',
                    'op' => 'isequalto',
                    'sf' => 'email',
                    'v' => $originaluser->email,
                ],
            ],
            'showc' => [false],
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

        // Review options
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
            \core\session\manager::set_user($originaluser);
            return ['success' => false, 'message' => 'Failed to create quiz.'];
        }

        // ─── Question Assignment Strategy ───────────────────────────────────

        require_once($CFG->dirroot . '/course/lib.php');

        $questions_added = false;

        if ($generated_content || $theme || strcasecmp($language, 'English') !== 0) {
            // THEMED, LOCALIZED, OR PRE-GENERATED QUIZ: use agent content or generate new questions.
            $search_theme = $theme ?: (!empty($sectionname) ? $sectionname : 'General Concepts');
            $questions_added = handle_themed_questions($result->instance, $course, $section, $search_theme, $adminuser, $language, $generated_content);
        }

        if (!$questions_added) {
            // FALLBACK: Try section-based random question matching (original behavior).
            $catsql_base = "SELECT qc.id, qc.name
                             FROM {question_categories} qc
                             JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = 50
                            WHERE ctx.instanceid = ?";

            $category = null;
            if (!empty($sectionname)) {
                $catsql = $catsql_base . " AND qc.name LIKE ?";
                $category = $DB->get_record_sql($catsql, [$course->id, '%' . $sectionname . '%'], IGNORE_MULTIPLE);
            }

            if ($category && class_exists('\mod_quiz\quiz_settings')) {
                $quizobj = \mod_quiz\quiz_settings::create($result->instance);
                if (method_exists($quizobj, 'get_structure')) {
                    $structure = $quizobj->get_structure();
                    $filtercondition = [
                        'filter' => [
                            'category' => [
                                'jointype' => 1,
                                'values' => [$category->id],
                                'filteroptions' => ['includesubcategories' => true],
                            ],
                        ],
                    ];
                    $structure->add_random_questions(1, 1, $filtercondition);
                    $questions_added = true;
                }
            }
        }

        // Recompute sum grades
        if ($questions_added && class_exists('\mod_quiz\quiz_settings')) {
            $quizobj = \mod_quiz\quiz_settings::create($result->instance);
            if (method_exists($quizobj, 'get_grade_calculator')) {
                $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
            }
        }

        \core\session\manager::set_user($originaluser);

        $msg = 'Quiz created!';
        if ($theme) {
            $msg = 'Quiz on "' . $theme . '" created!';
        }
        $msg .= ' Refresh the page to see it.';

        return ['success' => true, 'message' => $msg, 'language' => $language];

    } catch (\Exception $e) {
        if (isset($originaluser)) {
            \core\session\manager::set_user($originaluser);
        }
        return ['success' => false, 'message' => 'Quiz creation failed: ' . $e->getMessage()];
    }
}


/**
 * Handle themed question assignment: search existing questions or generate new ones via AI.
 *
 * @param int $quizinstance  The quiz instance ID.
 * @param object $course  The course record.
 * @param object $section  The section record.
 * @param string $theme  The topic/theme for the questions.
 * @param object $adminuser  The admin user for capability checks.
 * @return bool  True if questions were successfully added.
 */
function handle_themed_questions(int $quizinstance, object $course, object $section, string $theme, object $adminuser, string $language = 'English', ?array $generated_content = null): bool
{
    global $DB, $CFG;

    // 0. If pre-generated content from LessonGeneratorAgent is available, use it directly.
    //    This skips the second Python call to generate_questions.py entirely.
    if (!empty($generated_content) && !empty($generated_content['questions'])) {
        $categoryid = get_or_create_theme_category($course, $theme);
        if ($categoryid) {
            $question_ids = [];
            foreach ($generated_content['questions'] as $qdata) {
                if (!empty($qdata['name']) && !empty($qdata['text'])) {
                    // Append skeleton code to question text if available
                    $qtext = $qdata['text'];
                    if (!empty($generated_content['skeleton_code'])) {
                        $qtext .= '<br><br><strong>Skeleton Code:</strong><pre>' 
                            . htmlspecialchars($generated_content['skeleton_code']) . '</pre>';
                    }
                    $qid = insert_essay_question($categoryid, $qdata['name'], $qtext);
                    if ($qid) {
                        $question_ids[] = $qid;
                    }
                }
            }
            if (!empty($question_ids)) {
                return add_questions_to_quiz($quizinstance, $question_ids);
            }
        }
        // Fall through to existing logic if pre-generated insertion failed
        error_log('[handle_themed_questions] Pre-generated content insertion failed, falling back to existing logic.');
    }

    // 1. Search for existing questions matching the theme in this course's question banks.
    // We skip this if language is not English, to force generation of localized questions.
    if (strcasecmp($language, 'English') === 0) {
        $matching_questions = search_questions_by_theme($course->id, $theme);

        if (!empty($matching_questions)) {
            // Use existing questions — add them directly to the quiz.
            return add_questions_to_quiz($quizinstance, $matching_questions);
        }
    }

    // 2. No matching questions found — generate new ones via AI.
    $sectionname = !empty($section->name) ? $section->name : 'Section ' . $section->section;

    $script_path = dirname($CFG->dirroot) . '/admin/cli/generate_questions.py';
    if (!file_exists($script_path)) {
        $script_path = $CFG->dirroot . '/admin/cli/generate_questions.py';
    }

    if (!file_exists($script_path)) {
        error_log('generate_questions.py not found');
        return false;
    }

    $command = "python " . escapeshellarg($script_path)
        . " --theme " . escapeshellarg($theme)
        . " --section " . escapeshellarg($sectionname)
        . " --course " . escapeshellarg($course->fullname)
        . " --count 1"
        . " --lang " . escapeshellarg($language)
        . " 2>&1";

    $output = shell_exec($command);

    if (!$output) {
        error_log('generate_questions.py returned no output');
        return false;
    }

    $gen_result = json_decode($output, true);
    if (!$gen_result || $gen_result['status'] !== 'success' || empty($gen_result['questions'])) {
        error_log('generate_questions.py failed: ' . ($output ?? 'no output'));
        return false;
    }

    // 3. Find or create a question category for this theme in the course context.
    $categoryid = get_or_create_theme_category($course, $theme);
    if (!$categoryid) {
        error_log('Failed to create question category for theme: ' . $theme);
        return false;
    }

    // 4. Insert the generated questions into the database.
    $question_ids = [];
    foreach ($gen_result['questions'] as $qdata) {
        $qid = insert_essay_question($categoryid, $qdata['name'], $qdata['text']);
        if ($qid) {
            $question_ids[] = $qid;
        }
    }

    if (empty($question_ids)) {
        return false;
    }

    // 5. Add the new questions directly to the quiz.
    return add_questions_to_quiz($quizinstance, $question_ids);
}


/**
 * Search for questions matching a theme keyword in the course's question banks.
 *
 * @param int $courseid  The course ID.
 * @param string $theme  The theme to search for.
 * @return array  Array of question IDs.
 */
function search_questions_by_theme(int $courseid, string $theme): array
{
    global $DB;

    // Search question names and text matching the theme within this course's qbank categories.
    $sql = "SELECT DISTINCT q.id
              FROM {question} q
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = 50
             WHERE ctx.instanceid = ?
               AND qv.status = 'ready'
               AND (q.name LIKE ? OR q.questiontext LIKE ?)
             LIMIT 1";

    $like = '%' . $theme . '%';
    $records = $DB->get_records_sql($sql, [$courseid, $like, $like]);

    return array_keys($records);
}


/**
 * Get or create a question category for a theme within the course's default qbank context.
 *
 * @param object $course  The course record.
 * @param string $theme  The theme name.
 * @return int|null  The category ID, or null on failure.
 */
function get_or_create_theme_category(object $course, string $theme): ?int
{
    global $DB;

    $cat_name = 'AI Generated - ' . ucwords($theme);

    // Look for an existing category with this name in any course context for this course.
    $existing = $DB->get_record_sql(
        "SELECT qc.id
           FROM {question_categories} qc
           JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = 50
          WHERE ctx.instanceid = ? AND qc.name = ?",
        [$course->id, $cat_name],
        IGNORE_MULTIPLE
    );

    if ($existing) {
        return (int) $existing->id;
    }

    // Use the course context to create the category in.
    $qbank_ctx = $DB->get_record('context', ['instanceid' => $course->id, 'contextlevel' => 50]);

    if (!$qbank_ctx) {
        return null;
    }

    // Create new category
    $cat = new \stdClass();
    $cat->name = $cat_name;
    $cat->contextid = $qbank_ctx->id;
    $cat->info = 'Questions generated by AI for the theme: ' . $theme;
    $cat->infoformat = FORMAT_HTML;
    $cat->stamp = make_unique_id_code();
    $cat->parent = 0;
    $cat->sortorder = 999;
    $cat->idnumber = null;

    // Find the 'top' category parent
    $top = $DB->get_record('question_categories', [
        'contextid' => $qbank_ctx->id,
        'name' => 'top',
    ]);
    if ($top) {
        $cat->parent = $top->id;
    }

    $cat->id = $DB->insert_record('question_categories', $cat);
    return (int) $cat->id;
}


/**
 * Insert a single essay question into the Moodle question bank.
 *
 * @param int $categoryid  The question category ID.
 * @param string $name  The question name/title.
 * @param string $text  The question text (HTML).
 * @return int|null  The question ID, or null on failure.
 */
function insert_essay_question(int $categoryid, string $name, string $text): ?int
{
    global $DB;

    try {
        $admin = get_admin();

        // 1. Question bank entry
        $entry = new \stdClass();
        $entry->questioncategoryid = $categoryid;
        $entry->idnumber = null;
        $entry->ownerid = $admin->id;
        $entry->nextversion = 2;
        $entry->id = $DB->insert_record('question_bank_entries', $entry);

        // 2. Question record
        $question = new \stdClass();
        $question->parent = 0;
        $question->name = $name;
        $question->questiontext = $text;
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1.0;
        $question->penalty = 0.3333333;
        $question->qtype = 'essay';
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $admin->id;
        $question->modifiedby = $admin->id;
        $questionid = $DB->insert_record('question', $question);

        // 3. Question version
        $version = new \stdClass();
        $version->questionbankentryid = $entry->id;
        $version->version = 1;
        $version->questionid = $questionid;
        $version->status = 'ready';
        $DB->insert_record('question_versions', $version);

        // 4. Essay options (text box response)
        $options = new \stdClass();
        $options->questionid = $questionid;
        $options->responseformat = 'editor';
        $options->responserequired = 1;
        $options->responsefieldlines = 15;
        $options->attachments = 0;
        $options->attachmentsrequired = 0;
        $options->graderinfo = '';
        $options->graderinfoformat = FORMAT_HTML;
        $options->responsetemplate = '';
        $options->responsetemplateformat = FORMAT_HTML;
        $options->maxbytes = 0;
        $options->filetypeslist = '';
        $DB->insert_record('qtype_essay_options', $options);

        return $questionid;
    } catch (\Exception $e) {
        error_log('Failed to insert essay question: ' . $e->getMessage());
        return null;
    }
}


/**
 * Add specific questions directly to a quiz (not random — direct question slots).
 * Uses direct DB insertion into quiz_slots + question_references following
 * the Moodle 4.3+ schema (same pattern as slot_random::insert).
 *
 * @param int $quizinstance  The quiz instance ID.
 * @param array $questionids  Array of question IDs to add.
 * @return bool  True if at least one question was added.
 */
function add_questions_to_quiz(int $quizinstance, array $questionids): bool
{
    global $DB;

    if (empty($questionids)) {
        return false;
    }

    try {
        // Get the quiz record and context
        $quiz = $DB->get_record('quiz', ['id' => $quizinstance], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quizinstance, $quiz->course, false, MUST_EXIST);
        $quizcontext = context_module::instance($cm->id);

        // Find the current max slot number
        $maxslot = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(slot), 0) FROM {quiz_slots} WHERE quizid = ?',
            [$quizinstance]
        );

        // Ensure the quiz has a section (required for structure)
        $hassection = $DB->record_exists('quiz_sections', ['quizid' => $quizinstance]);
        if (!$hassection) {
            $section = new \stdClass();
            $section->quizid = $quizinstance;
            $section->firstslot = 1;
            $section->heading = '';
            $section->shufflequestions = 0;
            $DB->insert_record('quiz_sections', $section);
        }

        $added = 0;

        foreach ($questionids as $qid) {
            // Get the question bank entry for this question
            $version = $DB->get_record('question_versions', ['questionid' => $qid, 'status' => 'ready']);
            if (!$version) {
                continue;
            }

            $maxslot++;

            // 1. Insert into quiz_slots
            $slot = new \stdClass();
            $slot->quizid = $quizinstance;
            $slot->slot = $maxslot;
            $slot->page = $maxslot; // One question per page
            $slot->displaynumber = null;
            $slot->requireprevious = 0;
            $slot->maxmark = 1.0;
            $slot->quizgradeitemid = null;
            $slotid = $DB->insert_record('quiz_slots', $slot);

            // 2. Insert into question_references (links slot to question bank entry)
            $ref = new \stdClass();
            $ref->usingcontextid = $quizcontext->id;
            $ref->component = 'mod_quiz';
            $ref->questionarea = 'slot';
            $ref->itemid = $slotid;
            $ref->questionbankentryid = $version->questionbankentryid;
            $ref->version = null; // null = always latest version
            $DB->insert_record('question_references', $ref);

            $added++;
        }

        return $added > 0;
    } catch (\Exception $e) {
        error_log('Failed to add questions to quiz: ' . $e->getMessage());
        return false;
    }
}
