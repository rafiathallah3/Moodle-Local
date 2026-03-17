<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

class qtype_audiorecord_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        $response = $qa->get_last_qt_data();

        $html = '';
        $html .= html_writer::tag('div', $question->format_questiontext($qa), ['class' => 'qtext']);

        if ($options->readonly) {
            $html .= $this->readonly_display($qa, $options);
        } else {
            $html .= $this->interactive_display($qa, $question, $response);
        }

        return $html;
    }

    protected function interactive_display(question_attempt $qa, $question, $response) {
        global $PAGE, $USER;

        $timelimit = $question->timelimit;
        $inputname = $qa->get_qt_field_name('audioresponse');
        
        $itemid = !empty($response['audioresponse']) ? $response['audioresponse'] : file_get_unused_draft_itemid();

        $wrapperid = 'audiorecord-'.$qa->get_database_id();

        $html = html_writer::start_tag('div', ['class' => 'audiorecord-wrapper', 'id' => $wrapperid]);
        
        $html .= html_writer::tag('div', get_string('browsernotsupported', 'qtype_audiorecord'), ['class' => 'alert alert-warning audiorecord-warning d-none']);

        $controls = html_writer::tag('button', get_string('startrecording', 'qtype_audiorecord'), [
            'type' => 'button',
            'class' => 'btn btn-primary start-btn'
        ]);
        $controls .= html_writer::tag('button', get_string('stoprecording', 'qtype_audiorecord'), [
            'type' => 'button',
            'class' => 'btn btn-danger stop-btn d-none ml-2'
        ]);
        $controls .= html_writer::tag('span', '00:00', ['class' => 'timer badge badge-secondary ml-2 d-inline-block p-2']);

        $html .= html_writer::tag('div', $controls, ['class' => 'audiorecord-controls mt-2']);

        // The recording preview will be injected here.
        $html .= html_writer::tag('div', '', ['class' => 'audiorecord-preview mt-3']);

        // Check if there is already a drafted recording
        if (!empty($response['audioresponse'])) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $itemid, 'itemid', false);
            if ($files) {
                foreach ($files as $file) {
                    $url = moodle_url::make_draftfile_url($itemid, $file->get_filepath(), $file->get_filename());
                    $preview = html_writer::tag('audio', '', ['controls' => true, 'src' => $url, 'class' => 'mt-2']);
                    $html .= html_writer::tag('div', $preview . html_writer::tag('br') . html_writer::tag('span', get_string('recordingcompleted', 'qtype_audiorecord'), ['class' => 'text-success font-weight-bold']), ['class' => 'alert alert-success mt-2']);
                    break;
                }
            }
        }

        // Hidden input for the drafted itemid
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $inputname, 'value' => $itemid]);

        $html .= html_writer::end_tag('div');

        $PAGE->requires->js_call_amd('qtype_audiorecord/recorder', 'init', [[
            'id' => $wrapperid,
            'timelimit' => $timelimit,
            'itemid' => $itemid,
            'filename' => 'audio_response_' . time() . '.webm',
        ]]);

        return $html;
    }

    protected function readonly_display(question_attempt $qa, question_display_options $options) {
        $html = html_writer::start_tag('div', ['class' => 'audiorecord-wrapper readonly mt-2']);
        $step = $qa->get_last_step_with_qt_var('audioresponse');
        
        if ($step && $step->has_qt_var('audioresponse')) {
            $fs = get_file_storage();
            $files = $fs->get_area_files($options->context->id, 'question', 'response_audioresponse', $step->get_id(), 'itemid', false);
            if ($files) {
                foreach ($files as $file) {
                    $url = $qa->get_response_file_url($file);
                    $html .= html_writer::tag('audio', '', ['controls' => true, 'src' => $url]);
                    break;
                }
            } else {
                $html .= html_writer::tag('div', get_string('recordingfailed', 'qtype_audiorecord'), ['class' => 'alert alert-danger']);
            }
        } else {
            $html .= html_writer::tag('div', get_string('recordingfailed', 'qtype_audiorecord'), ['class' => 'alert alert-danger']);
        }
        $html .= html_writer::end_tag('div');
        return $html;
    }
}
