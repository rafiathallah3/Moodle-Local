<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade script for local_ocr plugin.
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_ocr_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Future upgrade steps go here.
    // Example:
    // if ($oldversion < 2026031002) {
    //     $table = new xmldb_table('local_ocr_results');
    //     $field = new xmldb_field('newfield', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ocr_text');
    //     if (!$dbman->field_exists($table, $field)) {
    //         $dbman->add_field($table, $field);
    //     }
    //     upgrade_plugin_savepoint(true, 2026031002, 'local', 'ocr');
    // }

    return true;
}
