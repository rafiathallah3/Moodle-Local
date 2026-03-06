<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

$catids = [
    'Easy Questions' => 50,
    'Medium Questions' => 52, // Checking DB for exact IDs, actually "Week 2 - Loop Medium" was 52. Let's just use 50, 52, 54
    'Hard Questions' => 54
];

foreach ($catids as $name => $catid) {
    echo "Creating 5 questions for ($name - Cat ID: $catid)...\n";
    for ($i = 1; $i <= 5; $i++) {
        $q = new stdClass();
        $q->category = $catid;
        $q->parent = 0;
        $q->name = "Test $name - $i";
        $q->questiontext = 'Test text...';
        $q->questiontextformat = 1;
        $q->generalfeedback = '';
        $q->generalfeedbackformat = 1;
        $q->defaultmark = 1.0;
        $q->penalty = 0.3333333;
        $q->qtype = 'truefalse';
        $q->length = 1;
        $q->stamp = make_unique_id_code();
        $q->version = make_unique_id_code();
        $q->hidden = 0;
        $q->timecreated = time();
        $q->timemodified = time();
        $q->createdby = 2; // admin
        $q->modifiedby = 2;

        $qid = $DB->insert_record('question', $q);

        // Also need a question bank entry for Moodle 4.x
        $qbe = new stdClass();
        $qbe->questioncategoryid = $catid;
        $qbe->idnumber = null;
        $qbe->ownerid = 2;
        $qbeid = $DB->insert_record('question_bank_entries', $qbe);

        $qv = new stdClass();
        $qv->questionbankentryid = $qbeid;
        $qv->version = 1;
        $qv->questionid = $qid;
        $qv->status = 'ready';
        $DB->insert_record('question_versions', $qv);
    }
}
echo "Done.\n";
