<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

class qtype_audiorecord extends question_type {

    public function is_manual_graded() {
        return true;
    }

    public function response_file_areas() {
        return ['audioresponse'];
    }

    public function get_question_options($question) {
        global $DB;
        $question->options = $DB->get_record('qtype_audiorecord_options', ['questionid' => $question->id]);
        return true;
    }

    public function save_question_options($question) {
        global $DB;

        $options = $DB->get_record('qtype_audiorecord_options', ['questionid' => $question->id]);
        $update = true;
        if (!$options) {
            $options = new stdClass();
            $options->questionid = $question->id;
            $update = false;
        }

        $options->timelimit = (int)($question->timelimit ?? 300);

        if ($update) {
            $DB->update_record('qtype_audiorecord_options', $options);
        } else {
            $DB->insert_record('qtype_audiorecord_options', $options);
        }

        return true;
    }

    public function save_defaults_for_new_questions(stdClass $fromform): void {
        parent::save_defaults_for_new_questions($fromform);
        $this->set_default_value('timelimit', $fromform->timelimit);
    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_audiorecord_options', ['questionid' => $questionid]);
        parent::delete_question($questionid, $contextid);
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $question->timelimit = $questiondata->options->timelimit ?? 300;
    }
}
