<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

// Initialize the Moodle environment
global $DB, $USER;

$courseid = 2; // Algorithm Programming 1
$course = $DB->get_record('course', ['id' => $courseid]);

if (!$course) {
    cli_error("Course ID $courseid not found.");
}

// User context (required for owner ID)
$admin = get_admin();
$USER = $admin;

cli_heading("Question Bank Creation with Difficulty: Loops in Pseudocode");

// Define categories (already existing based on previous research)
$categories = [
    'easy'   => 136,
    'medium' => 138,
    'hard'   => 140
];

// Define questions
$questions_data = [
    'easy' => [
        [
            'name' => 'Print numbers 1 to N',
            'text' => 'Create a pseudocode program that takes an integer **n** as input and outputs each number from 1 to **n** on its own line.',
        ],
        [
            'name' => 'Even Numbers up to N',
            'text' => 'Create a pseudocode program that takes an integer **n** as input and outputs all even integers between 1 and **n**.',
        ],
        [
            'name' => 'Sum of N Integers',
            'text' => 'Create a pseudocode program that takes an integer **n** as input and outputs the sum of all integers from 1 up to **n**.',
        ],
        [
            'name' => 'Multiples of 3',
            'text' => 'Create a pseudocode program that takes an integer **n** as input and prints all multiples of 3 up to **n**.',
        ],
        [
            'name' => 'Countdown to 1',
            'text' => 'Create a pseudocode program that takes an integer **n** as input and prints a countdown from **n** down to 1.',
        ],
    ],
    'medium' => [
        [
            'name' => 'Average of N Inputs',
            'text' => 'Create a pseudocode program that takes an integer **n** and then prompts the user **n** times for numbers. The program should output the average of those numbers.',
        ],
        [
            'name' => 'Factorial calculation',
            'text' => 'Create a pseudocode program that takes an input **n** and outputs the factorial of **n** (expressed as n!).',
        ],
        [
            'name' => 'Find Maximum in Loop',
            'text' => 'Create a pseudocode program that prompts for 10 numbers and uses a loop to find and output the maximum value.',
        ],
        [
            'name' => 'Star Row printing',
            'text' => 'Create a pseudocode program that takes an integer **n** and prints a row of **n** characters of "*".',
        ],
        [
            'name' => 'Geometric Progression',
            'text' => 'Create a pseudocode program that takes a starting value **a**, a ratio **r**, and a count **n**, and outputs the first **n** terms of the geometric progression.',
        ],
    ],
    'hard' => [
        [
            'name' => 'Prime Number Tester',
            'text' => 'Create a pseudocode program that takes an integer **n** and determines if it is a prime number, outputting "Prime" or "Not Prime".',
        ],
        [
            'name' => 'Fibonacci terms',
            'text' => 'Create a pseudocode program that takes an integer **n** as input and outputs the first **n** terms of the Fibonacci sequence.',
        ],
        [
            'name' => 'Nested Triangle Pattern',
            'text' => 'Create a pseudocode program that takes an integer **n** and prints a right-angled triangle of numbers with **n** rows (row 1: "1", row 2: "1 2", row 3: "1 2 3" ...).',
        ],
        [
            'name' => 'Digit Reverse Loop',
            'text' => 'Create a pseudocode program that takes an integer as input and outputs that number with its digits reversed using a loop.',
        ],
        [
            'name' => 'Multiplication Table Grid',
            'text' => 'Create a pseudocode program that prints a multiplication table from 1x1 to 10x10 using nested loops.',
        ],
    ]
];

foreach ($categories as $level => $categoryid) {
    cli_heading("Populating Category: " . strtoupper($level));
    
    foreach ($questions_data[$level] as $data) {
        $name = $data['name'];
        $text = $data['text'];
        
        // 1. Create the question bank entry
        $entry = new stdClass();
        $entry->questioncategoryid = $categoryid;
        $entry->idnumber = null;
        $entry->ownerid = $USER->id;
        $entry->nextversion = 2; // version numbers start at 1
        $entry->id = $DB->insert_record('question_bank_entries', $entry);
        
        // 2. Create the question record
        $question = new stdClass();
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
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        $questionid = $DB->insert_record('question', $question);
        
        // 3. Create the question version
        $version = new stdClass();
        $version->questionbankentryid = $entry->id;
        $version->version = 1;
        $version->questionid = $questionid;
        $version->status = 'ready'; // using string 'ready' (const QUESTION_STATUS_READY if we wanted)
        $DB->insert_record('question_versions', $version);
        
        // 4. Create the essay options (Required for Essay questions)
        $options = new stdClass();
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
        
        cli_writeln("Created question: $name (ID: $questionid)");
    }
}

cli_heading("Done!");
