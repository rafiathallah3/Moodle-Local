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
 * Assignment Submission module form.
 *
 * @package    mod_assignsubmission
 * @copyright  2026 Custom
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_assignsubmission_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // General section.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('assignmentname', 'assignsubmission'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 1333), 'maxlength', 1333, 'client');

        $this->standard_intro_elements();

        // Assignment description for AI grading context.
        $mform->addElement('header', 'assignmentsection', get_string('questiontext', 'assignsubmission'));

        $mform->addElement('textarea', 'questiontext',
            get_string('questiontext', 'assignsubmission'),
            array('rows' => 6, 'cols' => 80));
        $mform->setType('questiontext', PARAM_TEXT);
        $mform->addHelpButton('questiontext', 'questiontext', 'assignsubmission');

        // Maximum mark.
        $mform->addElement('text', 'maxmark', get_string('maxmark', 'assignsubmission'), array('size' => '10'));
        $mform->setType('maxmark', PARAM_FLOAT);
        $mform->setDefault('maxmark', 100);
        $mform->addHelpButton('maxmark', 'maxmark', 'assignsubmission');
        $mform->addRule('maxmark', null, 'required', null, 'client');
        $mform->addRule('maxmark', null, 'numeric', null, 'client');

        // Standard course module elements.
        $this->standard_coursemodule_elements();

        // Action buttons.
        $this->add_action_buttons();
    }

    /**
     * Pre-process form data for editing.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        if (empty($defaultvalues['maxmark'])) {
            $defaultvalues['maxmark'] = 100;
        }
    }
}
