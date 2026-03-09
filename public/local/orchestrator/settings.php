<?php
/**
 * Settings configuration for local_orchestrator
 *
 * @package    local_orchestrator
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) { // Needs site configuration access.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_orchestrator_report',
        get_string('pluginname', 'local_orchestrator') . ' Logs',
        new moodle_url('/local/orchestrator/report.php'),
        'moodle/site:config'
    ));
}
