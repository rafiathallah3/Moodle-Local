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
 * Event observer for local_ocr.
 *
 * Automatically runs OCR on any image file a student submits through
 * an assignment (assignsubmission_file) or a quiz (essay with attachments).
 * Results are persisted to the local_ocr_results table so the grading UI
 * can serve them without re-calling the AI API on every page load.
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ocr\event;

defined("MOODLE_INTERNAL") || die();

class observer
{
    // ── Supported image MIME types and file extensions ────────────────────────

    /** @var string[] MIME types recognised as images for OCR. */
    private const IMAGE_MIMETYPES = [
        "image/jpeg",
        "image/png",
        "image/gif",
        "image/webp",
        "image/bmp",
        "image/tiff",
    ];

    /** @var string[] File extensions recognised as images for OCR. */
    private const IMAGE_EXTENSIONS = [
        "jpg",
        "jpeg",
        "png",
        "gif",
        "webp",
        "bmp",
        "tiff",
        "tif",
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Public event callbacks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fired when a student finalises an assignment submission.
     *
     * Iterates all files in the assignsubmission_file area for the submitted
     * attempt and runs OCR on every image it finds.
     *
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function assessable_submitted(
        \mod_assign\event\assessable_submitted $event,
    ): void {
        // Respect the admin "Auto-OCR on submission" toggle.
        if (!(bool) get_config("local_ocr", "enabled")) {
            return;
        }
        if (!(bool) get_config("local_ocr", "autoocrsubmissions")) {
            return;
        }

        $submissionid = $event->objectid;

        // Resolve the module context so we can query the right file area.
        $context = \context_module::instance($event->contextinstanceid);

        $fs = get_file_storage();
        $submission_files = $fs->get_area_files(
            $context->id,
            "assignsubmission_file",
            "submission_files",
            $submissionid,
            "filename",
            false, // exclude directories
        );

        foreach ($submission_files as $file) {
            if (!self::is_image_file($file)) {
                continue;
            }

            self::run_ocr_for_stored_file(
                $file,
                $context->id,
                "assignsubmission_file",
                "submission_files",
                $submissionid,
            );
        }
    }

    /**
     * Fired when a student submits a quiz attempt.
     *
     * Loads the question usage, iterates every slot, and checks all
     * question-type file variables (attachments, answers with file uploads)
     * for image files to OCR.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function attempt_submitted(
        \mod_quiz\event\attempt_submitted $event,
    ): void {
        // Respect the admin "Auto-OCR on submission" toggle.
        if (!(bool) get_config("local_ocr", "enabled")) {
            return;
        }
        if (!(bool) get_config("local_ocr", "autoocrsubmissions")) {
            return;
        }

        global $DB, $CFG;

        require_once $CFG->dirroot . "/question/engine/lib.php";

        $attemptid = $event->objectid;
        $contextid = $event->contextid;

        // Retrieve the question-usage ID that stores the student's responses.
        $qubaid = $DB->get_field("quiz_attempts", "uniqueid", [
            "id" => $attemptid,
        ]);
        if (!$qubaid) {
            return;
        }

        try {
            $quba = \question_engine::load_questions_usage_by_activity($qubaid);
            $slots = $quba->get_slots();

            foreach ($slots as $slot) {
                $qa = $quba->get_question_attempt($slot);
                $stepdata = $qa->get_last_qt_data();

                if (empty($stepdata)) {
                    continue;
                }

                // Each question type may store files in different qt-vars
                // (e.g. "attachments" for essay, or the answer var itself).
                foreach (array_keys($stepdata) as $qtvar) {
                    $files = $qa->get_last_qt_files($qtvar, $contextid);

                    foreach ($files as $file) {
                        if (!self::is_image_file($file)) {
                            continue;
                        }

                        self::run_ocr_for_stored_file(
                            $file,
                            $contextid,
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let an OCR failure break the quiz submission flow.
            self::log_error(
                $event->userid,
                $event->courseid,
                "quiz",
                $event->other["quizid"] ?? 0,
                "OCR observer exception in attempt_submitted: " .
                    $e->getMessage(),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determines whether a stored file is an image that we can attempt to OCR.
     *
     * @param \stored_file $file
     * @return bool
     */
    private static function is_image_file(\stored_file $file): bool
    {
        $mimetype = $file->get_mimetype();
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

        return in_array($mimetype, self::IMAGE_MIMETYPES, true) ||
            in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Runs the OCR Python script for a single stored image file and caches
     * the result in the local_ocr_results table.
     *
     * If a result already exists for this exact file it is skipped (idempotent).
     *
     * @param \stored_file $file       The stored image file.
     * @param int          $contextid  Moodle context ID.
     * @param string       $component  Component that owns the file.
     * @param string       $filearea   File area within the component.
     * @param int          $itemid     Item ID (submission ID, attempt ID, etc.).
     */
    private static function run_ocr_for_stored_file(
        \stored_file $file,
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
    ): void {
        global $DB, $CFG;

        $filename = $file->get_filename();
        $filepath = $file->get_filepath();

        // ── 1. Check whether we already have a cached OCR result ─────────────
        $already_done = $DB->record_exists("local_ocr_results", [
            "contextid" => $contextid,
            "component" => $component,
            "filearea" => $filearea,
            "itemid" => $itemid,
            "filename" => $filename,
        ]);

        if ($already_done) {
            return;
        }

        // ── 2. Copy the file to a temporary location ──────────────────────────
        $tempdir = sys_get_temp_dir();
        $safe_name = preg_replace("/[^a-zA-Z0-9._-]/", "_", $filename);
        $tempfilepath =
            $tempdir .
            DIRECTORY_SEPARATOR .
            "ocr_" .
            uniqid("", true) .
            "_" .
            $safe_name;

        try {
            $file->copy_content_to($tempfilepath);
        } catch (\Throwable $e) {
            // Cannot write temp file – skip silently.
            return;
        }

        // ── 3. Locate the OCR Python script ───────────────────────────────────
        $base_dir = $CFG->dirroot;
        if (basename($base_dir) === "public") {
            $base_dir = dirname($base_dir);
        }
        $script_path = $base_dir . "/admin/cli/ocr.py";

        if (!file_exists($script_path)) {
            @unlink($tempfilepath);
            return;
        }

        // ── 4. Determine which AI model to use ────────────────────────────────
        $model = get_config("local_ocr", "model");
        if (!in_array($model, ["gemini", "openai"], true)) {
            $model = "gemini";
        }

        // ── 5. Execute the OCR script ─────────────────────────────────────────
        $command =
            "python " .
            escapeshellarg($script_path) .
            " --image " .
            escapeshellarg($tempfilepath) .
            " --model " .
            escapeshellarg($model) .
            " 2>&1";

        $raw_output = shell_exec($command);

        // Always remove the temp file once done.
        @unlink($tempfilepath);

        if (empty($raw_output)) {
            return;
        }

        // ── 6. Parse the JSON output ──────────────────────────────────────────
        $result = json_decode($raw_output, true);

        if (!is_array($result) || ($result["status"] ?? "") !== "success") {
            // The script itself reported an error; nothing to cache.
            return;
        }

        $ocr_text = trim($result["text"] ?? "");
        if ($ocr_text === "") {
            $ocr_text = "Text not found inside the image.";
        }

        // ── 7. Persist the OCR result ─────────────────────────────────────────
        $record = new \stdClass();
        $record->contextid = $contextid;
        $record->component = $component;
        $record->filearea = $filearea;
        $record->itemid = $itemid;
        $record->filepath = $filepath;
        $record->filename = $filename;
        $record->ocr_text = $ocr_text;
        $record->model = $model;
        $record->timecreated = time();

        try {
            $DB->insert_record("local_ocr_results", $record);
        } catch (\Throwable $e) {
            // DB insert failure should not bubble up and break the submission.
        }
    }

    /**
     * Writes a minimal error entry to the local_ocr_results table so that
     * failures are visible in the admin report without crashing the page.
     *
     * @param int    $userid
     * @param int    $courseid
     * @param string $module
     * @param int    $instanceid
     * @param string $error_msg
     */
    private static function log_error(
        int $userid,
        int $courseid,
        string $module,
        int $instanceid,
        string $error_msg,
    ): void {
        // Errors are written to the PHP error log so they appear in server
        // logs without requiring a separate table or admin UI.
        error_log(
            sprintf(
                "[local_ocr] Error — userid:%d courseid:%d module:%s instanceid:%d — %s",
                $userid,
                $courseid,
                $module,
                $instanceid,
                $error_msg,
            ),
        );
    }
}
