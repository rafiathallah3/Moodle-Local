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
 * AJAX grading handler for Assignment Submission.
 * Handles: single diagnose, auto-grade all, and delete actions.
 *
 * @package    mod_assignsubmission
 * @copyright  2026 Custom
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

$cmid = required_param('cmid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$cm = get_coursemodule_from_id('assignsubmission', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$instance = $DB->get_record('assignsubmission', array('id' => $cm->instance), '*', MUST_EXIST);

require_sesskey();
require_login($course, false, $cm);
$context = context_module::instance($cm->id);

header('Content-Type: application/json');

// ---- DELETE action ----
if ($action === 'delete') {
    require_capability('mod/assignsubmission:upload', $context);

    $subid = required_param('subid', PARAM_INT);
    $submission = $DB->get_record('assignsubmission_files', array('id' => $subid, 'assignsubmission' => $instance->id));

    if (!$submission) {
        echo json_encode(array('status' => 'error', 'message' => 'Submission not found.'));
        die;
    }

    // Delete the stored file.
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_assignsubmission', 'submissions', $subid);
    foreach ($files as $file) {
        $file->delete();
    }

    // Delete record.
    $DB->delete_records('assignsubmission_files', array('id' => $subid));

    echo json_encode(array('status' => 'success'));
    die;
}

// ---- SINGLE diagnose action ----
if ($action === 'single') {
    require_capability('mod/assignsubmission:grade', $context);

    $subid = required_param('subid', PARAM_INT);
    $submission = $DB->get_record('assignsubmission_files', array('id' => $subid, 'assignsubmission' => $instance->id));

    if (!$submission) {
        echo json_encode(array('status' => 'error', 'message' => 'Submission not found.'));
        die;
    }

    $result = diagnose_submission($submission, $instance, $context);
    echo json_encode($result);
    die;
}

// ---- EDIT action ----
if ($action === 'edit') {
    require_capability('mod/assignsubmission:grade', $context);

    $subid = required_param('subid', PARAM_INT);
    $submission = $DB->get_record('assignsubmission_files', array('id' => $subid, 'assignsubmission' => $instance->id));

    if (!$submission) {
        echo json_encode(array('status' => 'error', 'message' => 'Submission not found.'));
        die;
    }

    $studentname = optional_param('studentname', $submission->studentname, PARAM_TEXT);
    $mark = optional_param('mark', null, PARAM_FLOAT);
    $feedback = optional_param('feedback', $submission->feedback, PARAM_RAW);

    $update = new stdClass();
    $update->id = $submission->id;
    $update->studentname = $studentname;

    if ($mark !== null) {
        if ($mark > $instance->maxmark) {
            $mark = $instance->maxmark;
        }
        if ($mark < 0) {
            $mark = 0;
        }
        $update->mark = $mark;
        $update->status = 'graded';
        $update->timegraded = time();
    }

    if ($feedback !== null) {
        $update->feedback = $feedback;
    }

    $DB->update_record('assignsubmission_files', $update);

    echo json_encode(array(
        'status' => 'success',
        'data' => array(
            'id' => $submission->id,
            'studentname' => $studentname,
            'mark' => $mark,
            'feedback' => $feedback,
        ),
    ));
    die;
}

// ---- EDIT DESCRIPTION action ----
if ($action === 'editdescription') {
    require_capability('mod/assignsubmission:grade', $context);

    $questiontext = required_param('questiontext', PARAM_RAW);

    $update = new stdClass();
    $update->id = $instance->id;
    $update->questiontext = $questiontext;
    $update->timemodified = time();

    $DB->update_record('assignsubmission', $update);

    echo json_encode(array(
        'status' => 'success',
        'data' => array(
            'questiontext' => $questiontext,
        ),
    ));
    die;
}

echo json_encode(array('status' => 'error', 'message' => 'Invalid action.'));
die;


/**
 * Diagnose a single submission using diagnose.py.
 *
 * @param stdClass $submission The submission record.
 * @param stdClass $instance The activity instance.
 * @param context_module $context
 * @return array Result with status, data (mark, feedback).
 */
function diagnose_submission($submission, $instance, $context) {
    global $DB, $CFG;

    // Mark as processing.
    $DB->set_field('assignsubmission_files', 'status', 'processing', array('id' => $submission->id));

    // Get the stored file.
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_assignsubmission', 'submissions', $submission->id, 'id', false);
    $storedfile = null;
    foreach ($files as $file) {
        if (!$file->is_directory()) {
            $storedfile = $file;
            break;
        }
    }

    if (!$storedfile) {
        $DB->set_field('assignsubmission_files', 'status', 'error', array('id' => $submission->id));
        return array('status' => 'error', 'message' => 'File not found in storage.');
    }

    // Copy to temp.
    $tempdir = make_request_directory();
    $temppath = $tempdir . '/' . $storedfile->get_filename();
    $storedfile->copy_content_to($temppath);

    // Prepare question text file.
    $questiontext = !empty($instance->questiontext) ? strip_tags($instance->questiontext) : '';
    $questiontextpath = $tempdir . '/question.txt';
    file_put_contents($questiontextpath, $questiontext);

    // Find the diagnose.py script.
    $script_path = dirname($CFG->dirroot) . '/admin/cli/diagnose.py';
    if (!file_exists($script_path)) {
        $script_path = $CFG->dirroot . '/admin/cli/diagnose.py';
    }

    if (!file_exists($script_path)) {
        $DB->set_field('assignsubmission_files', 'status', 'error', array('id' => $submission->id));
        return array('status' => 'error', 'message' => 'diagnose.py script not found.');
    }

    // Build the command.
    $command = "python " . escapeshellarg($script_path)
        . " --file " . escapeshellarg($temppath)
        . " --questiontextfile " . escapeshellarg($questiontextpath)
        . " --maxmark " . escapeshellarg($instance->maxmark)
        . " 2>&1";

    $output_json = shell_exec($command);

    if (!$output_json) {
        $DB->set_field('assignsubmission_files', 'status', 'error', array('id' => $submission->id));
        return array('status' => 'error', 'message' => 'diagnose.py produced no output.');
    }

    $result = json_decode($output_json, true);

    if (!$result || !isset($result['status']) || $result['status'] !== 'success') {
        $DB->set_field('assignsubmission_files', 'status', 'error', array('id' => $submission->id));
        $errmsg = $result['message'] ?? 'Unknown error from diagnose.py.';
        return array('status' => 'error', 'message' => $errmsg);
    }

    // Extract mark and feedback.
    $mark = isset($result['mark']) ? (float) $result['mark'] : 0;
    if ($mark > $instance->maxmark) {
        $mark = $instance->maxmark;
    }
    if ($mark < 0) {
        $mark = 0;
    }

    $diagnosis = $result['diagnosis'] ?? '';
    $diagnosis = htmlspecialchars($diagnosis);
    // Convert markdown bold.
    $diagnosis = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $diagnosis);
    $diagnosis = nl2br($diagnosis);

    // Update the submission record.
    $DB->update_record('assignsubmission_files', (object) array(
        'id' => $submission->id,
        'mark' => $mark,
        'feedback' => $diagnosis,
        'status' => 'graded',
        'timegraded' => time(),
    ));

    return array(
        'status' => 'success',
        'data' => array(
            'id' => $submission->id,
            'mark' => $mark,
            'feedback' => $diagnosis,
        ),
    );
}
