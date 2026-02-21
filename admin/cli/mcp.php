<?php

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Ensure we don't pollute STDOUT with debug messages
$CFG->debugdisplay = 0;
// But keep errors logged to stderr if possible, or just suppress display
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);

class MoodleMCPServer
{
    private $tools = [];

    public function __construct()
    {
        $this->register_tools();
    }

    private function register_tools()
    {
        $this->tools['sql_query'] = [
            'description' => 'Execute a read-only SQL query against the Moodle database.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The SQL query to execute. MUST be a SELECT statement.'
                    ]
                ],
                'required' => ['query']
            ],
            'handler' => function ($args) {
                global $DB;
                $sql = $args['query'];
                // Basic safety check for read-only
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE)/i', $sql)) {
                    throw new Exception("Only read-only queries are allowed.");
                }
                return array_values($DB->get_records_sql($sql));
            }
        ];

        $this->tools['list_courses'] = [
            'description' => 'List courses in the Moodle site.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of courses to return',
                        'default' => 10
                    ]
                ]
            ],
            'handler' => function ($args) {
                global $DB;
                $limit = $args['limit'] ?? 10;
                // Get courses, excluding site course (id=1 usually, but let's just get all for now to be safe)
                // sort by id DESC to get newest
                return array_values($DB->get_records('course', null, 'id DESC', '*', 0, $limit));
            }
        ];

        $this->tools['run_adhoc_task'] = [
            'description' => 'Execute a specific adhoc task class immediately.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'classname' => [
                        'type' => 'string',
                        'description' => 'The fully qualified class name of the adhoc task (e.g. \core\task\adhoc_task)'
                    ],
                    'customdata' => [
                        'type' => 'string',
                        'description' => 'JSON encoded string of custom data to pass to the task'
                    ]
                ],
                'required' => ['classname']
            ],
            'handler' => function ($args) {
                $classname = $args['classname'];
                // Normalize class name
                if ($classname[0] !== '\\') {
                    $classname = '\\' . $classname;
                }

                if (!class_exists($classname)) {
                    throw new Exception("Class '$classname' not found.");
                }

                $task = new $classname();
                if (!($task instanceof \core\task\adhoc_task)) {
                    throw new Exception("Class '$classname' is not an adhoc task.");
                }

                if (isset($args['customdata'])) {
                    $val = json_decode($args['customdata']);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception("Invalid JSON for customdata");
                    }
                    $task->set_custom_data($val);
                }

                // Keep output buffer clean
                ob_start();
                try {
                    $task->execute();
                    $output = ob_get_clean();
                    return "Task executed successfully. Output: " . $output;
                } catch (Exception $e) {
                    ob_end_clean();
                    throw $e;
                }
            }
        ];

        $this->tools['get_assignment_info'] = [
            'description' => 'Look up an assignment in a course to see its content, dates, and how many students have submitted it.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'courseid' => [
                        'type' => 'integer',
                        'description' => 'The ID of the course.'
                    ],
                    'assignment_name' => [
                        'type' => 'string',
                        'description' => 'The name of the assignment to look up.'
                    ]
                ],
                'required' => ['courseid', 'assignment_name']
            ],
            'handler' => function ($args) {
                global $DB;
                $courseid = $args['courseid'];
                $name = $args['assignment_name'];

                $assignment = $DB->get_record('assign', ['course' => $courseid, 'name' => $name]);
                if (!$assignment) {
                    throw new Exception("Assignment '{$name}' not found in course {$courseid}.");
                }

                $sql = "SELECT COUNT(DISTINCT userid) FROM {assign_submission} WHERE assignment = ? AND status = ?";
                $submitted_count = $DB->count_records_sql($sql, [$assignment->id, 'submitted']);

                return [
                    'assignment_id' => $assignment->id,
                    'name' => $assignment->name,
                    'intro' => strip_tags($assignment->intro), // The content inside of the assignment
                    'allow_submissions_from' => $assignment->allowsubmissionsfromdate ? userdate($assignment->allowsubmissionsfromdate) : 'No date',
                    'due_date' => $assignment->duedate ? userdate($assignment->duedate) : 'No due date',
                    'submitted_count' => $submitted_count
                ];
            }
        ];

        $this->tools['grade_assignment'] = [
            'description' => 'Give a student a grade for an assignment.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'courseid' => [
                        'type' => 'integer',
                        'description' => 'The ID of the course.'
                    ],
                    'assignment_name' => [
                        'type' => 'string',
                        'description' => 'The name of the assignment.'
                    ],
                    'username' => [
                        'type' => 'string',
                        'description' => 'The username of the student to grade.'
                    ],
                    'grade' => [
                        'type' => 'number',
                        'description' => 'The grade value (float or integer).'
                    ]
                ],
                'required' => ['courseid', 'assignment_name', 'username', 'grade']
            ],
            'handler' => function ($args) {
                global $DB, $CFG;
                require_once($CFG->dirroot . '/mod/assign/externallib.php');
                require_once($CFG->dirroot . '/mod/assign/locallib.php');

                $courseid = $args['courseid'];
                $assign_name = $args['assignment_name'];
                $username = $args['username'];
                $gradeval = (float) $args['grade'];

                $assign = $DB->get_record('assign', ['course' => $courseid, 'name' => $assign_name]);
                if (!$assign) {
                    throw new Exception("Assignment '{$assign_name}' not found in course {$courseid}.");
                }

                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
                if (!$user) {
                    throw new Exception("User '{$username}' not found.");
                }

                $adminuser = get_admin();
                \core\session\manager::set_user($adminuser);

                $course = $DB->get_record('course', ['id' => $courseid]);
                $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
                $context = \context_module::instance($cm->id);
                $assign_obj = new \assign($context, $cm, $course);
                $grade_obj = $assign_obj->get_user_grade($user->id, false);

                // Get existing feedback so we don't overwrite it with NULL
                $feedbacktext = '';
                $feedbackformat = FORMAT_HTML;
                if ($grade_obj) {
                    $feedback_plugin = $assign_obj->get_plugin_by_type('assignfeedback', 'comments');
                    if ($feedback_plugin && $feedback_plugin->is_enabled()) {
                        $feedback = $feedback_plugin->get_feedback_comments($grade_obj->id);
                        if ($feedback) {
                            $feedbacktext = $feedback->commenttext;
                            $feedbackformat = $feedback->commentformat;
                        }
                    }
                }

                $plugindata = [
                    'assignfeedbackcomments_editor' => [
                        'text' => $feedbacktext,
                        'format' => $feedbackformat
                    ]
                ];

                \mod_assign_external::save_grade(
                    $assign->id,
                    $user->id,
                    $gradeval,
                    -1,
                    false,
                    'graded',
                    false,
                    $plugindata
                );

                return [
                    'status' => 'success',
                    'message' => "Grade {$gradeval} saved for user {$username} on assignment '{$assign_name}'."
                ];
            }
        ];

        $this->tools['add_assignment_feedback'] = [
            'description' => 'Add feedback comments to a student\'s assignment.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'courseid' => [
                        'type' => 'integer',
                        'description' => 'The ID of the course.'
                    ],
                    'assignment_name' => [
                        'type' => 'string',
                        'description' => 'The name of the assignment.'
                    ],
                    'username' => [
                        'type' => 'string',
                        'description' => 'The username of the student.'
                    ],
                    'feedback' => [
                        'type' => 'string',
                        'description' => 'The feedback text (HTML supported).'
                    ]
                ],
                'required' => ['courseid', 'assignment_name', 'username', 'feedback']
            ],
            'handler' => function ($args) {
                global $DB, $CFG;
                require_once($CFG->dirroot . '/mod/assign/externallib.php');
                require_once($CFG->dirroot . '/mod/assign/locallib.php');

                $courseid = $args['courseid'];
                $assign_name = $args['assignment_name'];
                $username = $args['username'];
                $feedback = $args['feedback'];

                $assign = $DB->get_record('assign', ['course' => $courseid, 'name' => $assign_name]);
                if (!$assign) {
                    throw new Exception("Assignment '{$assign_name}' not found in course {$courseid}.");
                }

                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
                if (!$user) {
                    throw new Exception("User '{$username}' not found.");
                }

                $adminuser = get_admin();
                \core\session\manager::set_user($adminuser);

                $course = $DB->get_record('course', ['id' => $courseid]);
                $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
                $context = \context_module::instance($cm->id);
                $assign_obj = new \assign($context, $cm, $course);
                $grade_obj = $assign_obj->get_user_grade($user->id, false);
                $currentgrade = $grade_obj ? (float) $grade_obj->grade : -1.0;

                $plugindata = [
                    'assignfeedbackcomments_editor' => [
                        'text' => $feedback,
                        'format' => FORMAT_HTML
                    ]
                ];

                \mod_assign_external::save_grade(
                    $assign->id,
                    $user->id,
                    $currentgrade,
                    -1,
                    false,
                    'graded',
                    false,
                    $plugindata
                );

                return [
                    'status' => 'success',
                    'message' => "Feedback saved for user {$username} on assignment '{$assign_name}'."
                ];
            }
        ];

        $this->tools['create_question_bank'] = [
            'description' => 'Create a new question bank category for a course. Returns the category ID which can be used with create_question to add questions later.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'courseid' => [
                        'type' => 'integer',
                        'description' => 'The ID of the course to create the question category in.'
                    ],
                    'category_name' => [
                        'type' => 'string',
                        'description' => 'The name of the new question category.'
                    ],
                    'info' => [
                        'type' => 'string',
                        'description' => 'Optional description for the category.'
                    ]
                ],
                'required' => ['courseid', 'category_name']
            ],
            'handler' => function ($args) {
                global $DB, $CFG, $USER;

                $courseid = $args['courseid'];
                $categoryname = $args['category_name'];
                $info = $args['info'] ?? 'Created via MCP';

                require_once($CFG->dirroot . '/course/lib.php');
                require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');
                require_once($CFG->libdir . '/questionlib.php');

                $course = get_course($courseid);
                if (!$course) {
                    throw new Exception("Course with id $courseid not found");
                }

                // Check if a qbank module with this name already exists in this course
                $modinfo = get_fast_modinfo($course);
                $existing_qbanks = $modinfo->get_instances_of('qbank');
                foreach ($existing_qbanks as $qbank) {
                    if ($qbank->name === $categoryname) {
                        $context = \context_module::instance($qbank->id);
                        $cat = question_get_default_category($context->id, true);
                        return [
                            'status' => 'exists',
                            'category_id' => (int) $cat->id,
                            'message' => "Question bank activity '{$categoryname}' already exists."
                        ];
                    }
                }

                // In Moodle 4.x, we should create a Question Bank activity module.
                // It requires the active user to be set properly in the session
                $adminuser = get_admin();
                if (!$adminuser) {
                    throw new Exception("Could not find admin user for capability check.");
                }
                \core\session\manager::set_user($adminuser);

                // Create the question bank instance using the helper class
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

                // The context of the new module has the default category we actually want to return
                $context = \context_module::instance($cm->id);
                $cat = question_get_default_category($context->id, true);

                return [
                    'status' => 'created',
                    'category_id' => (int) $cat->id,
                    'message' => "Question bank activity '{$categoryname}' created successfully."
                ];
            }
        ];

        $this->tools['create_question'] = [
            'description' => 'Create a single question inside an existing question bank category.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'category_id' => [
                        'type' => 'integer',
                        'description' => 'The ID of the question category (from create_question_bank).'
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Question type: truefalse, shortanswer, essay, or multichoice.'
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Short name/title for the question.'
                    ],
                    'questiontext' => [
                        'type' => 'string',
                        'description' => 'The full question text (HTML supported).'
                    ],
                    'answer' => [
                        'type' => 'string',
                        'description' => 'Correct answer. For truefalse: "true" or "false". For shortanswer: the expected text.'
                    ],
                    'options' => [
                        'type' => 'array',
                        'description' => 'For multichoice only. List of choices. The FIRST option is treated as the correct answer.',
                        'items' => ['type' => 'string']
                    ]
                ],
                'required' => ['category_id', 'type', 'name', 'questiontext']
            ],
            'handler' => function ($args) {
                global $DB, $CFG;
                require_once($CFG->libdir . '/questionlib.php');

                $catid = $args['category_id'];
                $qtype = $args['type'];

                // Verify category exists
                $cat = $DB->get_record('question_categories', ['id' => $catid]);
                if (!$cat) {
                    throw new Exception("Question category with id $catid not found.");
                }

                $question = new stdClass();
                $question->category = $cat->id;
                $question->name = $args['name'];
                $question->questiontext = ['text' => $args['questiontext'], 'format' => FORMAT_HTML];
                $question->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
                $question->defaultmark = 1;
                $question->penalty = 0.3333333;
                $question->qtype = $qtype;
                $question->length = 1;
                $question->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
                $question->version = 1;
                $question->versionid = 0;
                $question->questionbankentryid = 0;
                $question->id = 0;
                $question->timecreated = time();
                $question->timemodified = time();
                $question->createdby = 2;
                $question->modifiedby = 2;

                // Type-specific fields
                if ($qtype === 'truefalse') {
                    $answer = $args['answer'] ?? 'true';
                    $question->correctanswer = ($answer === 'true' || $answer === '1') ? 1 : 0;
                    $question->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
                    $question->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];

                } elseif ($qtype === 'shortanswer') {
                    $question->usecase = 0;
                    $question->answer = [$args['answer'] ?? ''];
                    $question->fraction = [1.0];
                    $question->feedback = [['text' => 'Correct!', 'format' => FORMAT_HTML]];

                } elseif ($qtype === 'essay') {
                    $question->responseformat = 'editor';
                    $question->responsefieldlines = 15;
                    $question->attachments = 0;
                    $question->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
                    $question->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];

                } elseif ($qtype === 'multichoice') {
                    $question->single = 1;
                    $question->shuffleanswers = 1;
                    $question->answernumbering = 'abc';
                    $question->showstandardinstruction = 0;
                    $question->answer = [];
                    $question->fraction = [];
                    $question->feedback = [];

                    $options = $args['options'] ?? [];
                    if (empty($options)) {
                        throw new Exception("multichoice requires 'options' array.");
                    }
                    foreach ($options as $i => $opt) {
                        $question->answer[] = ['text' => $opt, 'format' => FORMAT_HTML];
                        $question->fraction[] = ($i === 0) ? 1.0 : 0.0;
                        $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                    }
                } else {
                    throw new Exception("Unsupported question type '$qtype'. Use: truefalse, shortanswer, essay, multichoice.");
                }

                $qtypeobj = question_bank::get_qtype($qtype);
                $qtypeobj->save_question($question, null);

                return [
                    'status' => 'created',
                    'question_id' => (int) $question->id,
                    'message' => "Question '{$args['name']}' ({$qtype}) created in category {$catid}."
                ];
            }
        ];
    }

    public function run()
    {
        // Log startup to stderr so it doesn't break JSON-RPC
        fwrite(STDERR, "Moodle MCP Server Started.\n");

        $stdin = fopen('php://stdin', 'r');

        while (!feof($stdin)) {
            $line = fgets($stdin);
            if ($line === false)
                break;

            $line = trim($line);
            if (empty($line))
                continue;

            $request = json_decode($line, true);

            // Should be a JSON-RPC request
            if (!$request || !isset($request['jsonrpc'])) {
                continue;
            }

            // If it's a notification (no id), just handle it and don't reply
            if (!isset($request['id'])) {
                try {
                    $this->handle_request($request);
                } catch (Exception $e) {
                    // Notifications don't return errors
                    fwrite(STDERR, "Notification Error: " . $e->getMessage() . "\n");
                }
                continue;
            }

            $response = [
                'jsonrpc' => '2.0',
                'id' => $request['id']
            ];

            try {
                $result = $this->handle_request($request);
                $response['result'] = $result;
            } catch (Exception $e) {
                $response['error'] = [
                    'code' => -32603,
                    'message' => $e->getMessage(),
                    'data' => $e->getTraceAsString()
                ];
            }

            echo json_encode($response) . "\n";
            flush();
        }
    }

    private function handle_request($request)
    {
        $method = $request['method'];
        $params = $request['params'] ?? [];

        switch ($method) {
            case 'initialize':
                return [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [
                        'tools' => [
                            'listChanged' => false
                        ]
                    ],
                    'serverInfo' => [
                        'name' => 'moodle-mcp-server',
                        'version' => '1.0.0'
                    ]
                ];

            case 'notifications/initialized':
                // Client acknowledging initialization. Nothing to return.
                return null;

            case 'tools/list':
                return [
                    'tools' => array_values(array_map(function ($name, $tool) {
                        return [
                            'name' => $name,
                            'description' => $tool['description'],
                            'inputSchema' => $tool['inputSchema']
                        ];
                    }, array_keys($this->tools), $this->tools))
                ];

            case 'tools/call':
                $name = $params['name'];
                $args = $params['arguments'] ?? [];

                if (!isset($this->tools[$name])) {
                    throw new Exception("Tool '$name' not found.");
                }

                $result = call_user_func($this->tools[$name]['handler'], $args);

                // Convert complex objects to array/string if needed, but JSON encode handles it mostly.
                // MCP expects: 
                // { content: [{ type: "text", text: "..." }] }

                $textResult = is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT);

                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $textResult
                        ]
                    ]
                ];

            default:
                throw new Exception("Method '$method' not supported.");
        }
    }
}

$server = new MoodleMCPServer();
$server->run();
