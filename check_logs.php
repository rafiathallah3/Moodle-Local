<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
global $DB;

$log = $DB->get_records('local_orchestrator_log', null, 'id DESC', '*', 0, 1);
if (!empty($log)) {
    $latest = reset($log);
    echo "LATEST LOG ENTRY ERROR OR SUCCESS:\n";
    echo "ID: " . $latest->id . "\n";
    echo "ERROR/PAYLOAD: " . $latest->final_payload . "\n";
} else {
    echo "No logs found.\n";
}
