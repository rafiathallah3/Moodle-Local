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
 * Library of functions for mod_assignsubmission.
 *
 * @package    mod_assignsubmission
 * @copyright  2026 Custom
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * List of features supported by this module.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function assignsubmission_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_OTHER,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_MOD_INTRO => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_BACKUP_MOODLE2 => false,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ASSESSMENT,
        default => null,
    };
}

/**
 * Add a new assignsubmission instance.
 *
 * @param stdClass $data Form data
 * @param mod_assignsubmission_mod_form $mform The form
 * @return int New instance ID
 */
function assignsubmission_add_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();

    if (!isset($data->maxmark) || $data->maxmark <= 0) {
        $data->maxmark = 100;
    }

    $data->id = $DB->insert_record('assignsubmission', $data);

    $cmid = $data->coursemodule;
    $DB->set_field('course_modules', 'instance', $data->id, array('id' => $cmid));

    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event($cmid, 'assignsubmission', $data->id, $completiontimeexpected);

    return $data->id;
}

/**
 * Update an existing assignsubmission instance.
 *
 * @param stdClass $data Form data
 * @param mod_assignsubmission_mod_form $mform The form
 * @return bool True on success
 */
function assignsubmission_update_instance($data, $mform) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    if (!isset($data->maxmark) || $data->maxmark <= 0) {
        $data->maxmark = 100;
    }

    $DB->update_record('assignsubmission', $data);

    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event($data->coursemodule, 'assignsubmission', $data->id, $completiontimeexpected);

    return true;
}

/**
 * Delete an assignsubmission instance.
 *
 * @param int $id Instance ID
 * @return bool True on success
 */
function assignsubmission_delete_instance($id) {
    global $DB;

    if (!$instance = $DB->get_record('assignsubmission', array('id' => $id))) {
        return false;
    }

    // Delete all associated submission files records.
    $DB->delete_records('assignsubmission_files', array('assignsubmission' => $id));

    // Delete the file storage entries.
    $cm = get_coursemodule_from_instance('assignsubmission', $id);
    if ($cm) {
        $context = context_module::instance($cm->id);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_assignsubmission', 'submissions');

        \core_completion\api::update_completion_date_event($cm->id, 'assignsubmission', $id, null);
    }

    // Delete the instance.
    $DB->delete_records('assignsubmission', array('id' => $id));

    return true;
}

/**
 * Given a course_module object, returns extra information for course listing.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|null
 */
function assignsubmission_get_coursemodule_info($coursemodule) {
    global $DB;

    if (!$instance = $DB->get_record('assignsubmission',
        array('id' => $coursemodule->instance),
        'id, name, intro, introformat')) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('assignsubmission', $instance, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Serve the plugin files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function assignsubmission_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    if (!has_capability('mod/assignsubmission:view', $context)) {
        return false;
    }

    if ($filearea !== 'submissions') {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_assignsubmission', 'submissions', $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Lists all browsable file areas.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array
 */
function assignsubmission_get_file_areas($course, $cm, $context) {
    return array(
        'submissions' => get_string('uploadsubmissions', 'assignsubmission'),
    );
}

/**
 * Return a list of page types.
 *
 * @param string $pagetype
 * @param stdClass $parentcontext
 * @param stdClass $currentcontext
 * @return array
 */
function assignsubmission_page_type_list($pagetype, $parentcontext, $currentcontext) {
    return array('mod-assignsubmission-*' => 'Any Assignment Submission module page');
}

/**
 * Mark the activity completed and trigger the course_module_viewed event.
 *
 * @param stdClass $instance
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 */
function assignsubmission_view($instance, $course, $cm, $context) {
    $params = array(
        'context' => $context,
        'objectid' => $instance->id,
    );

    $event = \mod_assignsubmission\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('assignsubmission', $instance);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 *
 * @param stdClass $data
 * @return array
 */
function assignsubmission_reset_userdata($data) {
    return array();
}

/**
 * List the actions that correspond to a view of this module.
 *
 * @return array
 */
function assignsubmission_get_view_actions() {
    return array('view', 'view all');
}

/**
 * List the actions that correspond to a post of this module.
 *
 * @return array
 */
function assignsubmission_get_post_actions() {
    return array('update', 'add', 'upload', 'grade');
}
