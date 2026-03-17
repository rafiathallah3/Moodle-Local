<?php
namespace qtype_audiorecord;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External API for qtype_audiorecord.
 */
class external extends external_api {

    /**
     * Parameters for upload function.
     */
    public static function upload_parameters() {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'context id'),
            'component' => new external_value(PARAM_COMPONENT, 'component'),
            'filearea' => new external_value(PARAM_AREA, 'file area'),
            'itemid' => new external_value(PARAM_INT, 'associated id'),
            'filepath' => new external_value(PARAM_PATH, 'file path'),
            'filename' => new external_value(PARAM_FILE, 'file name'),
            'filecontent' => new external_value(PARAM_RAW, 'file content (base64 encoded)'),
            'contextlevel' => new external_value(PARAM_ALPHA, 'context level', VALUE_DEFAULT, null),
            'instanceid' => new external_value(PARAM_INT, 'instance id', VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Upload a file to the user's draft area.
     */
    public static function upload($contextid, $component, $filearea, $itemid, $filepath, $filename, $filecontent, $contextlevel = null, $instanceid = null) {
        global $USER;

        $params = self::validate_parameters(self::upload_parameters(), [
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'filecontent' => $filecontent,
            'contextlevel' => $contextlevel,
            'instanceid' => $instanceid,
        ]);

        $usercontext = \core\context\user::instance($USER->id);
        if (!$usercontext) {
            throw new \moodle_exception('invalidcontext');
        }
        self::validate_context($usercontext);

        if ($params['component'] !== 'user' || $params['filearea'] !== 'draft') {
            throw new \coding_exception('Only user draft area is supported for recording uploads.');
        }

        $itemid = $params['itemid'];
        if ($itemid <= 0) {
            $itemid = file_get_unused_draft_itemid();
        }

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => $params['filepath'],
            'filename' => $params['filename'],
        ];

        // Delete existing file if any.
        if ($fs->get_file($filerecord['contextid'], $filerecord['component'], $filerecord['filearea'], $filerecord['itemid'], $filerecord['filepath'], $filerecord['filename'])) {
            $fs->delete_area_files($filerecord['contextid'], $filerecord['component'], $filerecord['filearea'], $filerecord['itemid']);
        }

        $filecontent = base64_decode($params['filecontent']);
        $file = $fs->create_file_from_string($filerecord, $filecontent);

        return [
            'contextid' => (int)$file->get_contextid(),
            'component' => $file->get_component(),
            'filearea' => $file->get_filearea(),
            'itemid' => (int)$file->get_itemid(),
            'filepath' => $file->get_filepath(),
            'filename' => $file->get_filename(),
            'url' => \moodle_url::make_draftfile_url($file->get_itemid(), $file->get_filepath(), $file->get_filename())->out(false),
        ];
    }

    /**
     * Returns description of upload result.
     */
    public static function upload_returns() {
        return new external_single_structure([
            'contextid' => new external_value(PARAM_INT, 'context id'),
            'component' => new external_value(PARAM_COMPONENT, 'component'),
            'filearea' => new external_value(PARAM_AREA, 'file area'),
            'itemid' => new external_value(PARAM_INT, 'associated id'),
            'filepath' => new external_value(PARAM_PATH, 'file path'),
            'filename' => new external_value(PARAM_FILE, 'file name'),
            'url' => new external_value(PARAM_URL, 'file url'),
        ]);
    }
}
