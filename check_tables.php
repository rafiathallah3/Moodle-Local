<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
global $DB;
$manager = $DB->get_manager();
echo "profile exists: " . ($manager->table_exists('local_orch_stud_profile') ? 'yes' : 'no') . "\n";
echo "summ exists: " . ($manager->table_exists('local_orch_int_summ') ? 'yes' : 'no') . "\n";
