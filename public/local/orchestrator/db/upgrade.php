<?php
/**
 * Upgrade script for local_orchestrator
 *
 * @package    local_orchestrator
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_orchestrator_upgrade($oldversion)
{
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026030702) {

        // Define field input_evidence to be added to local_orchestrator_log.
        $table = new xmldb_table('local_orchestrator_log');
        $field = new xmldb_field('input_evidence', XMLDB_TYPE_TEXT, null, null, null, null, null, 'mode');

        // Conditionally launch add field input_evidence.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Orchestrator savepoint reached.
        upgrade_plugin_savepoint(true, 2026030702, 'local', 'orchestrator');
    }

    return true;
}
