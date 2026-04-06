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
 * AJAX handler for uploading course-level student CSV mappings.
 *
 * @package    mod_assignsubmission
 * @copyright  2026 Custom
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../config.php');

$cmid = required_param('cmid', PARAM_INT);

$cm = get_coursemodule_from_id('assignsubmission', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_sesskey();
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/assignsubmission:grade', $context); // Only graders can upload mappings

header('Content-Type: application/json');

if (!isset($_FILES['csvfile'])) {
    echo json_encode(array('status' => 'error', 'message' => 'No file uploaded.'));
    die;
}

$file = $_FILES['csvfile'];
$tmp_name = $file['tmp_name'];

if (!is_uploaded_file($tmp_name)) {
    echo json_encode(array('status' => 'error', 'message' => 'Invalid file upload.'));
    die;
}

// Read the CSV
$content = file_get_contents($tmp_name);
// Normalize line endings
$content = str_replace(array("\r\n", "\r"), "\n", $content);
$lines = explode("\n", $content);

$inserted_count = 0;
$mappings_to_insert = array();

foreach ($lines as $line) {
    if (trim($line) === '') continue;
    
    // Parse using semicolon as requested
    $cols = explode(';', $line);
    
    // Format expected: NO.;NIM;NAMA
    // Allow variable columns but try to find the ID and Name.
    if (count($cols) >= 3) {
        $id = trim($cols[1]);     // The NIM column
        $name = trim($cols[2]);   // The NAMA column
    } else if (count($cols) == 2) {
        $id = trim($cols[0]);
        $name = trim($cols[1]);
    } else {
        continue; // Skip lines with too few columns
    }
    
    // Clean data
    // Remove invisible characters
    $id = preg_replace('/[\x00-\x1F\x7F]/u', '', $id);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    
    // Skip empty IDs or names
    if (empty($id) || empty($name)) {
        continue;
    }
    
    // Skip header row if NIM matches exactly "NIM" (case insensitive)
    if (strcasecmp($id, 'NIM') === 0) {
        continue;
    }

    $map = new stdClass();
    $map->course = $course->id;
    $map->studentid = $id;
    $map->studentname = $name;
    
    $mappings_to_insert[] = $map;
}

if (!empty($mappings_to_insert)) {
    // Delete existing mappings for this course to replace them
    $DB->delete_records('assignsubmission_mapping', array('course' => $course->id));
    
    // Insert new mappings
    foreach ($mappings_to_insert as $map) {
        $DB->insert_record('assignsubmission_mapping', $map);
        $inserted_count++;
    }
    
    echo json_encode(array(
        'status' => 'success', 
        'message' => get_string('mappinguploaded', 'assignsubmission', $inserted_count),
        'count' => $inserted_count
    ));
} else {
    echo json_encode(array(
        'status' => 'error', 
        'message' => 'No valid student mappings found in the CSV. Make sure it uses semicolon (;) delimiter.'
    ));
}
die;
