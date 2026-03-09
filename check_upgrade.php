<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once(__DIR__ . '/local/orchestrator/db/upgrade.php');

try {
    xmldb_local_orchestrator_upgrade(2026030701);
    echo "Upgrade success!\n";
} catch (\Throwable $e) {
    echo "Upgrade error: " . $e->getMessage() . "\n";
}
