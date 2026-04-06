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
 * AJAX upload handler for Assignment Submission.
 * Accepts image files, stores them, runs OCR to extract student name.
 *
 * @package    mod_assignsubmission
 * @copyright  2026 Custom
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

$cmid = required_param('cmid', PARAM_INT);
$instanceid = required_param('instanceid', PARAM_INT);

$cm = get_coursemodule_from_id('assignsubmission', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$instance = $DB->get_record('assignsubmission', array('id' => $cm->instance), '*', MUST_EXIST);

require_sesskey();
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/assignsubmission:upload', $context);

header('Content-Type: application/json');

if (!isset($_FILES['file'])) {
    echo json_encode(array('status' => 'error', 'message' => 'No file uploaded.'));
    die;
}

$file = $_FILES['file'];

// Validate it's an image.
$allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp');
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed_types)) {
    echo json_encode(array('status' => 'error', 'message' => 'Invalid file type. Only images are accepted.'));
    die;
}

// Create assignsubmission_files record first to get an ID for itemid.
$record = new stdClass();
$record->assignsubmission = $instance->id;
$record->studentname = 'Unnamed';
$record->filename = clean_filename($file['name']);
$record->filepath = '';
$record->status = 'pending';
$record->timecreated = time();

$recordid = $DB->insert_record('assignsubmission_files', $record);

// Store the file in Moodle's file storage.
$fs = get_file_storage();
$filerecord = array(
    'contextid' => $context->id,
    'component' => 'mod_assignsubmission',
    'filearea'  => 'submissions',
    'itemid'    => $recordid,
    'filepath'  => '/',
    'filename'  => $record->filename,
);

// Check if file already exists, delete if so.
$existing = $fs->get_file(
    $filerecord['contextid'],
    $filerecord['component'],
    $filerecord['filearea'],
    $filerecord['itemid'],
    $filerecord['filepath'],
    $filerecord['filename']
);
if ($existing) {
    $existing->delete();
}

$storedfile = $fs->create_file_from_pathname($filerecord, $file['tmp_name']);

// Get a temporary file path for OCR.
$tempdir = make_request_directory();
$temppath = $tempdir . '/' . $record->filename;
$storedfile->copy_content_to($temppath);

// Attempt to extract studentname from filename using mapped CSV data
$studentname = 'Unnamed';
$mapped_name_found = false;

$mappings = $DB->get_records('assignsubmission_mapping', array('course' => $course->id));
foreach ($mappings as $mapping) {
    // Check if the filename contains the student ID (e.g., 1030...)
    if (strpos($record->filename, $mapping->studentid) !== false) {
        $studentname = $mapping->studentname;
        $mapped_name_found = true;
        break; // Match found!
    }
}

// Run OCR to extract text from image.
$ocrtext = '';
$ocr_debug = array(); // Diagnostic info.
if ($mapped_name_found) {
    $ocr_debug[] = 'Student name resolved from CSV mapping: ' . $studentname;
}

$script_path = dirname($CFG->dirroot) . '/admin/cli/ocr.py';
$ocr_debug[] = 'Trying script path: ' . $script_path;
if (!file_exists($script_path)) {
    $ocr_debug[] = 'Not found. Trying fallback...';
    $script_path = $CFG->dirroot . '/admin/cli/ocr.py';
    $ocr_debug[] = 'Fallback path: ' . $script_path;
}

if (file_exists($script_path)) {
    $ocr_debug[] = 'Script found at: ' . $script_path;
    $ocr_debug[] = 'Temp image path: ' . $temppath;
    $ocr_debug[] = 'Temp image exists: ' . (file_exists($temppath) ? 'yes (' . filesize($temppath) . ' bytes)' : 'NO');

    $command = "python " . escapeshellarg($script_path)
        . " --image " . escapeshellarg($temppath)
        . " --model gemini"
        . " 2>&1";

    $ocr_debug[] = 'Command: ' . $command;

    $ocr_output = shell_exec($command);

    if ($ocr_output === null) {
        $ocr_debug[] = 'shell_exec returned NULL (shell_exec may be disabled or command failed to execute)';
    } else if ($ocr_output === '') {
        $ocr_debug[] = 'shell_exec returned empty string';
    } else {
        $ocr_debug[] = 'Raw output (' . strlen($ocr_output) . ' chars): ' . substr($ocr_output, 0, 500);
        $ocr_result = json_decode($ocr_output, true);

        if ($ocr_result === null) {
            $ocr_debug[] = 'JSON decode failed: ' . json_last_error_msg();
        } else if (!isset($ocr_result['status'])) {
            $ocr_debug[] = 'Response has no status field. Keys: ' . implode(', ', array_keys($ocr_result));
        } else if ($ocr_result['status'] !== 'success') {
            $ocr_debug[] = 'OCR status: ' . $ocr_result['status'] . ' — ' . ($ocr_result['message'] ?? 'no message');
        } else {
            $extracted_text = $ocr_result['text'] ?? '';
            $ocrtext = $extracted_text;
            $ocr_debug[] = 'OCR success! Extracted ' . strlen($extracted_text) . ' chars';

            if (!$mapped_name_found) {
                // Now use Gemini to extract the student name from the OCR text.
                if (!empty($extracted_text) && $extracted_text !== 'Text not found inside the image.') {
                    $studentname = extract_student_name_from_text($extracted_text);
                    $ocr_debug[] = 'Extracted student name from text: ' . $studentname;
                } else {
                    $ocr_debug[] = 'No usable text found in image for student name extraction';
                }
            } else {
                $ocr_debug[] = 'Skipped student name extraction (already mapped via filename)';
            }
        }
    }
} else {
    $ocr_debug[] = 'ERROR: ocr.py script not found at either path!';
    $ocr_debug[] = 'CFG->dirroot = ' . $CFG->dirroot;
    $ocr_debug[] = 'dirname(CFG->dirroot) = ' . dirname($CFG->dirroot);
}

// Update the record with extracted name and OCR text.
$DB->set_field('assignsubmission_files', 'studentname', $studentname, array('id' => $recordid));
$DB->set_field('assignsubmission_files', 'ocrtext', $ocrtext, array('id' => $recordid));

// Build file URL for response.
$fileurl = moodle_url::make_pluginfile_url(
    $context->id,
    'mod_assignsubmission',
    'submissions',
    $recordid,
    '/',
    $record->filename
)->out();

echo json_encode(array(
    'status' => 'success',
    'data' => array(
        'id' => $recordid,
        'studentname' => $studentname,
        'ocrtext' => $ocrtext,
        'filename' => $record->filename,
        'fileurl' => $fileurl,
        'status' => 'pending',
        'ocr_debug' => $ocr_debug,
    ),
));
die;

/**
 * Extract a student name from OCR text using a simple heuristic,
 * then fall back to asking Gemini if needed.
 *
 * @param string $text The OCR-extracted text.
 * @return string The student name, or 'Unnamed'.
 */
function extract_student_name_from_text($text) {
    global $CFG;

    // First try a simple regex for common patterns like "Name: John Doe" or "Nama: John Doe".
    $patterns = array(
        '/(?:name|nama|student|siswa|mahasiswa)\s*[:=]\s*(.+)/i',
        '/(?:name|nama)\s*[:=]\s*(.+)/i',
    );

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $name = trim($matches[1]);
            // Take only the first line.
            $name = strtok($name, "\n");
            $name = trim($name);
            if (!empty($name) && strlen($name) < 100) {
                return $name;
            }
        }
    }

    // Fall back to Gemini to extract the student name.
    $env_path = dirname(dirname($CFG->dirroot)) . '/.env';
    if (!file_exists($env_path)) {
        $env_path = dirname($CFG->dirroot) . '/.env';
    }

    $gemini_api = '';
    if (file_exists($env_path)) {
        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'GEMINI_API=') === 0) {
                $gemini_api = trim(substr($line, strlen('GEMINI_API=')));
                $gemini_api = trim($gemini_api, "'\"");
                break;
            }
        }
    }

    if (empty($gemini_api)) {
        return 'Unnamed';
    }

    // Use Gemini API directly via cURL to extract the student name.
    $prompt = "The following is text extracted from a student's assignment submission image via OCR. "
        . "Please identify and return ONLY the student's full name. "
        . "If you cannot find a student name, respond with exactly: Unnamed\n\n"
        . "OCR Text:\n" . $text;

    $payload = json_encode(array(
        'contents' => array(
            array(
                'parts' => array(
                    array('text' => $prompt)
                )
            )
        ),
        'generationConfig' => array(
            'temperature' => 0,
            'maxOutputTokens' => 100,
        )
    ));

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $gemini_api;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        $name = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $name = trim($name);
        // Clean up markdown or extra whitespace.
        $name = preg_replace('/[*_`]/', '', $name);
        $name = trim($name);
        if (!empty($name) && strlen($name) < 100 && strtolower($name) !== 'unnamed') {
            return $name;
        }
    }

    return 'Unnamed';
}
