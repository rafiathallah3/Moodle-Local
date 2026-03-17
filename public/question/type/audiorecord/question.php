<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questionbase.php');

class qtype_audiorecord_question extends question_with_responses {

    public $timelimit;

    public function make_behaviour(question_attempt $qa, $preferredbehaviour) {
        return question_engine::make_behaviour('manualgraded', $qa, $preferredbehaviour);
    }

    public function get_correct_response() {
        return null;
    }

    public function get_expected_data() {
        return ['audioresponse' => question_attempt::PARAM_FILES];
    }

    public function summarise_response(array $response) {
        if (isset($response['audioresponse'])) {
            return get_string('recordingcompleted', 'qtype_audiorecord');
        } else {
            return null;
        }
    }

    public function is_complete_response(array $response) {
        return array_key_exists('audioresponse', $response) && $response['audioresponse'] !== '';
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('recordingfailed', 'qtype_audiorecord');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_have_same_keys_and_values($prevresponse, $newresponse, ['audioresponse']);
    }

    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    public function check_file_access($qa, $options, $component, $filearea, $args, $forcedownload) {
        if ($component == 'question' && $filearea == 'response_audioresponse') {
            return true;
        } else {
            return parent::check_file_access($qa, $options, $component, $filearea, $args, $forcedownload);
        }
    }
}
