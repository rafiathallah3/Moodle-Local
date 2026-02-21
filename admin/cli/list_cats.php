<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
$cats = $DB->get_records('question_categories', null, 'contextid ASC');
foreach($cats as $cat) {
    echo "ID: {$cat->id}, Name: {$cat->name}, Context: {$cat->contextid}, Parent: {$cat->parent}
";
}
