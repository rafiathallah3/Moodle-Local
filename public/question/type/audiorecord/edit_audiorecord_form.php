<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/edit_question_form.php');

class qtype_audiorecord_edit_form extends question_edit_form {

    protected function definition_inner($mform) {
        $mform->addElement('text', 'timelimit', get_string('timelimit', 'qtype_audiorecord'),
                ['size' => 5, 'maxlength' => 5]);
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', 300);
        $mform->addHelpButton('timelimit', 'timelimit', 'qtype_audiorecord');
    }

    public function qtype() {
        return 'audiorecord';
    }

    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);

        if (empty($question->options)) {
            return $question;
        }

        $question->timelimit = $question->options->timelimit;

        return $question;
    }
}
