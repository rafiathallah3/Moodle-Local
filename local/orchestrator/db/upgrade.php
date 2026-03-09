<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_orchestrator_upgrade($oldversion)
{
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026030800) {

        // Define table local_orch_stud_profile to be created.
        $table = new xmldb_table('local_orch_stud_profile');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('level', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('mastery_by_kc', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('misconceptions', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('preferences', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('integrity', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('usercourse', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_orch_int_summ to be created.
        $table = new xmldb_table('local_orch_int_summ');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('run_id', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('tags', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('last_targets', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('last_next_steps', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('student_vis_out', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('usercourse', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026030800, 'local', 'orchestrator');
    }

    return true;
}
