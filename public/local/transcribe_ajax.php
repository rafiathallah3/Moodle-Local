<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/lib/filelib.php');

require_login();
require_sesskey();

$contextid = required_param('contextid', PARAM_INT);
$component = required_param('component', PARAM_COMPONENT);
$filearea = required_param('filearea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);
$filepath = required_param('filepath', PARAM_PATH);
$filename = required_param('filename', PARAM_FILE);

global $USER, $CFG;

header('Content-Type: application/json');

try {
    $fs = get_file_storage();
    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

    if (!$file) {
        throw new Exception('File not found in storage.');
    }

    $mimetype = $file->get_mimetype();
    if (strpos($mimetype, 'audio/') !== 0 && strpos($mimetype, 'video/webm') !== 0 && !preg_match('/\.(mp3|wav|ogg|webm)$/i', $filename)) {
        throw new Exception('File is not an audio file.');
    }

    $tempdir = make_request_directory();
    $tempfilepath = $tempdir . '/' . $file->get_filename();
    $file->copy_content_to($tempfilepath);

    $base_dir = $CFG->dirroot;
    if (basename($base_dir) === 'public') {
        $base_dir = dirname($base_dir);
    }
    $script_path = $base_dir . '/admin/cli/transcribe.py';

    $escaped_script = escapeshellarg($script_path);
    $escaped_audio = escapeshellarg($tempfilepath);

    $command = "python $escaped_script --audio $escaped_audio --model gemini 2>&1";
    $output_json = shell_exec($command);

    if ($output_json) {
        $result = json_decode($output_json, true);
        if ($result && isset($result['status']) && $result['status'] === 'success') {
            echo json_encode(['success' => true, 'transcript' => $result['transcript']]);
        } else if ($result && isset($result['message'])) {
            echo json_encode(['success' => false, 'message' => $result['message'], 'debug' => $output_json]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not parse output', 'debug' => $output_json]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No output from transcription script']);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
