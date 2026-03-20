<?php
/**
 * AJAX endpoint for on-demand OCR of image file submissions.
 *
 * Accepts Moodle file storage parameters, validates the file is an image,
 * checks the local_ocr_results cache, and if not cached runs the Python
 * OCR script (admin/cli/ocr.py) via proc_open (hard 45-second timeout)
 * before storing and returning the result.
 *
 * Using proc_open instead of shell_exec prevents the request from hanging
 * forever when the Gemini API stalls or Python's SSL stack blocks on Windows.
 *
 * @package    local_ocr
 */

define("AJAX_SCRIPT", true);
require_once __DIR__ . "/../config.php";
require_once $CFG->dirroot . "/lib/filelib.php";

require_login();
require_sesskey();

global $USER, $CFG, $DB;

header("Content-Type: application/json");

// ── Supported image types ────────────────────────────────────────────────────
const OCR_IMAGE_MIMETYPES = [
    "image/jpeg",
    "image/png",
    "image/gif",
    "image/webp",
    "image/bmp",
    "image/tiff",
    "image/svg+xml",
];

const OCR_IMAGE_EXTENSIONS = [
    "jpg",
    "jpeg",
    "png",
    "gif",
    "webp",
    "bmp",
    "tiff",
    "tif",
];

// ── Read and validate input parameters ──────────────────────────────────────
try {
    $contextid = required_param("contextid", PARAM_INT);
    $component = required_param("component", PARAM_COMPONENT);
    $filearea = required_param("filearea", PARAM_AREA);
    $itemid = required_param("itemid", PARAM_INT);
    $filepath = required_param("filepath", PARAM_PATH);
    $filename = required_param("filename", PARAM_FILE);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Missing required parameter: " . $e->getMessage(),
    ]);
    exit();
}

// ── Helper: resolve the OCR Python script path ───────────────────────────────
function get_ocr_script_path(string $dirroot): string
{
    $base = basename($dirroot) === "public" ? dirname($dirroot) : $dirroot;
    return $base . "/admin/cli/ocr.py";
}

// ── Helper: determine the preferred model from plugin settings ───────────────
// Falls back to 'gemini' when the plugin is not installed or config not set.
function get_ocr_model(): string
{
    try {
        $model = get_config("local_ocr", "model");
    } catch (Throwable $e) {
        $model = false;
    }
    return in_array($model, ["gemini", "openai"], true) ? $model : "gemini";
}

// ── Helper: find which Python executable is available in Apache's PATH ────────
// Apache PHP runs in a stripped environment; 'python' might not be resolvable
// even though it works in the CLI. We probe the three common names.
function find_python_exec(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    foreach (["python", "python3", "py"] as $candidate) {
        $test = @shell_exec($candidate . " --version 2>&1");
        if ($test && stripos($test, "python") !== false) {
            $cached = $candidate;
            return $cached;
        }
    }
    // Last resort: return 'python' and let the error surface via proc_open.
    $cached = "python";
    return $cached;
}

// ── Helper: run an external command with a hard timeout via proc_open ─────────
// Returns the combined stdout+stderr output, or throws on timeout / failure.
// Unlike shell_exec, proc_open lets us poll for completion and kill the process
// if it exceeds $timeout_sec seconds — critical when the Gemini API stalls.
function run_command_with_timeout(
    string $command,
    int $timeout_sec = 45,
): string {
    $descriptorspec = [
        0 => ["pipe", "r"], // stdin  (we close it immediately)
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"], // stderr
    ];

    $process = proc_open($command, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        throw new Exception(
            "proc_open() failed — could not launch: " . $command,
        );
    }

    fclose($pipes[0]); // no stdin needed

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = "";
    $deadline = time() + $timeout_sec;

    while (true) {
        $chunk_out = fread($pipes[1], 8192);
        $chunk_err = fread($pipes[2], 8192);
        if ($chunk_out !== false && $chunk_out !== "") {
            $output .= $chunk_out;
        }
        if ($chunk_err !== false && $chunk_err !== "") {
            $output .= $chunk_err;
        }

        $status = proc_get_status($process);
        if (!$status["running"]) {
            // Drain any remaining bytes after process exits.
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            break;
        }

        if (time() >= $deadline) {
            // Kill the hung process tree before bailing out.
            if (function_exists("proc_terminate")) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new Exception(
                "OCR process timed out after {$timeout_sec} seconds. " .
                    "Partial output: " .
                    substr($output, 0, 300),
            );
        }

        usleep(120000); // poll every 120 ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}

// ── Main logic ───────────────────────────────────────────────────────────────
try {
    // 1. Retrieve the stored file ------------------------------------------
    $fs = get_file_storage();
    $file = $fs->get_file(
        $contextid,
        $component,
        $filearea,
        $itemid,
        $filepath,
        $filename,
    );

    if (!$file) {
        throw new Exception("File not found in Moodle file storage.");
    }

    // 2. Confirm it is an image -------------------------------------------
    $mimetype = $file->get_mimetype();
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (
        !in_array($mimetype, OCR_IMAGE_MIMETYPES, true) &&
        !in_array($ext, OCR_IMAGE_EXTENSIONS, true)
    ) {
        throw new Exception(
            "The requested file is not a supported image type (mime: " .
                $mimetype .
                ").",
        );
    }

    // 3. Check the DB cache -----------------------------------------------
    // Wrapped in try-catch: if the local_ocr plugin has not been installed
    // yet the table will not exist, which must not prevent OCR from running.
    $cached = null;
    try {
        $cached = $DB->get_record("local_ocr_results", [
            "contextid" => $contextid,
            "component" => $component,
            "filearea" => $filearea,
            "itemid" => $itemid,
            "filepath" => $filepath,
            "filename" => $filename,
        ]);
    } catch (Throwable $dbe) {
        // Table does not exist yet (plugin pending install) — skip cache lookup.
        $cached = null;
    }

    if ($cached) {
        echo json_encode([
            "success" => true,
            "text" => $cached->ocr_text,
            "cached" => true,
        ]);
        exit();
    }

    // 4. Write image to a temporary file ----------------------------------
    $tempdir = make_request_directory();
    $tempfilepath =
        $tempdir . DIRECTORY_SEPARATOR . clean_filename($file->get_filename());
    $file->copy_content_to($tempfilepath);

    // 5. Locate and run the OCR script ------------------------------------
    $script_path = get_ocr_script_path($CFG->dirroot);

    if (!file_exists($script_path)) {
        throw new Exception("OCR script not found at: " . $script_path);
    }

    $python_exec = find_python_exec();
    $model = get_ocr_model();

    // Build command — escapeshellarg uses double-quotes on Windows, which
    // handles spaces in paths (e.g. inside the Moodle temp directory).
    $command =
        $python_exec .
        " " .
        escapeshellarg($script_path) .
        " --image " .
        escapeshellarg($tempfilepath) .
        " --model " .
        escapeshellarg($model);

    // Run with a 45-second hard timeout via proc_open.
    // If the Gemini API hangs, the process is killed and we get a clean error.
    $raw_output = null;
    try {
        $raw_output = run_command_with_timeout($command, 45);
    } catch (Exception $run_ex) {
        @unlink($tempfilepath);
        throw $run_ex; // re-throw: will be caught by the outer try-catch
    }

    // Clean up temp file immediately after use
    @unlink($tempfilepath);

    $raw_output = trim($raw_output ?? "");

    if ($raw_output === "") {
        throw new Exception(
            "No output received from the OCR engine. " .
                "Python executable used: '{$python_exec}'. " .
                "Verify that Python is in the system PATH for the Apache service " .
                "and that the GEMINI_API key is set in .env",
        );
    }

    // 6. Parse Python script output ---------------------------------------
    $result = json_decode($raw_output, true);

    if (!is_array($result) || !isset($result["status"])) {
        throw new Exception(
            "Could not parse OCR script output: " . substr($raw_output, 0, 300),
        );
    }

    if ($result["status"] !== "success") {
        $error_msg =
            $result["message"] ?? "OCR script returned a non-success status.";
        throw new Exception($error_msg);
    }

    $ocr_text = trim($result["text"] ?? "");
    if ($ocr_text === "") {
        $ocr_text = "Text not found inside the image.";
    }

    // 7. Cache result in the database -------------------------------------
    // Wrapped in try-catch: if the local_ocr plugin has not been installed
    // yet we skip caching silently — the OCR text is still returned to the
    // caller so the page can display it without a DB table being present.
    try {
        $record = new stdClass();
        $record->contextid = $contextid;
        $record->component = $component;
        $record->filearea = $filearea;
        $record->itemid = $itemid;
        $record->filepath = $filepath;
        $record->filename = $filename;
        $record->ocr_text = $ocr_text;
        $record->timecreated = time();

        $DB->insert_record("local_ocr_results", $record);
    } catch (Throwable $dbe) {
        // Table does not exist yet (plugin pending install) — skip caching.
    }

    // 8. Return success response ------------------------------------------
    echo json_encode([
        "success" => true,
        "text" => $ocr_text,
        "cached" => false,
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ]);
}
