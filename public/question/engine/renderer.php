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
 * Renderers for outputting parts of the question engine.
 *
 * @package    moodlecore
 * @subpackage questionengine
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_question\output\question_version_info;

defined("MOODLE_INTERNAL") || die();

/**
 * This renderer controls the overall output of questions. It works with a
 * {@link qbehaviour_renderer} and a {@link qtype_renderer} to output the
 * type-specific bits. The main entry point is the {@link question()} method.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_question_renderer extends plugin_renderer_base
{
    /**
     * Generate the display of a question in a particular state, and with certain
     * display options. Normally you do not call this method directly. Intsead
     * you call {@link question_usage_by_activity::render_question()} which will
     * call this method with appropriate arguments.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the renderer to output the behaviour
     *      specific parts.
     * @param qtype_renderer $qtoutput the renderer to output the question type
     *      specific parts.
     * @param question_display_options $options controls what should and should not be displayed.
     * @param string|null $number The question number to display. 'i' is a special
     *      value that gets displayed as Information. Null means no number is displayed.
     * @return string HTML representation of the question.
     */
    public function question(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        qtype_renderer $qtoutput,
        question_display_options $options,
        $number,
    ) {
        // If not already set, record the questionidentifier.
        $options = clone $options;
        if (!$options->has_question_identifier()) {
            $options->questionidentifier = $this->question_number_text($number);
        }

        $output = "";
        $output .= html_writer::start_tag("div", [
            "id" => $qa->get_outer_question_div_unique_id(),
            "class" => implode(" ", [
                "que",
                $qa->get_question(false)->get_type_name(),
                $qa->get_behaviour_name(),
                $qa->get_state_class($options->correctness && $qa->has_marks()),
            ]),
        ]);

        $output .= html_writer::tag(
            "div",
            $this->info($qa, $behaviouroutput, $qtoutput, $options, $number),
            ["class" => "info"],
        );

        $output .= html_writer::start_tag("div", ["class" => "content"]);

        $output .= html_writer::tag(
            "div",
            $this->add_part_heading(
                $qtoutput->formulation_heading(),
                $this->formulation($qa, $behaviouroutput, $qtoutput, $options),
            ),
            ["class" => "formulation clearfix"],
        );
        $output .= html_writer::nonempty_tag(
            "div",
            $this->add_part_heading(
                get_string("feedback", "question"),
                $this->outcome($qa, $behaviouroutput, $qtoutput, $options),
            ),
            ["class" => "outcome clearfix"],
        );

        // Inject custom section conditionally here
        $hasaudio = false;

        // 1. Check for audio tags in the text response (e.g. from RecordRTC)
        $responsetext = $qa->get_last_qt_var("answer", "");
        if (
            stripos($responsetext, "<audio") !== false ||
            stripos($responsetext, ".mp3") !== false ||
            stripos($responsetext, ".ogg") !== false ||
            stripos($responsetext, ".wav") !== false ||
            stripos($responsetext, ".webm") !== false
        ) {
            $hasaudio = true;
        }

        // 2. Check for uploaded file attachments with audio mime types or embedded RecordRTC audio
        $audiofile = null;
        $imagefile = null;
        // Check ANY file area available in the latest step data
        $stepdata = $qa->get_last_qt_data();
        if ($stepdata) {
            foreach (array_keys($stepdata) as $qtvar) {
                $files = $qa->get_last_qt_files($qtvar, $options->context->id);
                if (!empty($files)) {
                    foreach ($files as $file) {
                        $mimetype = $file->get_mimetype();
                        $filename = $file->get_filename();
                        $ext = strtolower(
                            pathinfo($filename, PATHINFO_EXTENSION),
                        );

                        // Audio check (existing logic)
                        if (
                            $audiofile === null &&
                            (strpos($mimetype, "audio/") === 0 ||
                                strpos($mimetype, "video/webm") === 0 ||
                                preg_match(
                                    '/\.(mp3|wav|ogg|webm)$/i',
                                    $filename,
                                ))
                        ) {
                            $hasaudio = true;
                            $audiofile = $file;
                        }

                        // Image check — pick the first image file found
                        if (
                            $imagefile === null &&
                            (strpos($mimetype, "image/") === 0 ||
                                in_array($ext, [
                                    "jpg",
                                    "jpeg",
                                    "png",
                                    "gif",
                                    "webp",
                                    "bmp",
                                    "tiff",
                                    "tif",
                                ]))
                        ) {
                            $imagefile = $file;
                        }

                        // Stop scanning once we have both
                        if ($audiofile !== null && $imagefile !== null) {
                            break 2;
                        }
                    }
                }
            }
        }

        if ($hasaudio) {
            $containerid = "transcribe-container-" . uniqid();

            if ($audiofile) {
                global $CFG;
                $output .=
                    '
                <div class="custom-transcription-section mt-3 mb-3">
                    <div id="' .
                    $containerid .
                    '" class="alert alert-info">
                        <div class="transcription-loading">
                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="sr-only">Loading transcript...</span>
                            </div>
                            <span class="ml-2">Transcribing audio...</span>
                        </div>
                    </div>
                </div>
                <script>
                (function() {
                    function fetchTranscription() {
                        const container = document.getElementById("' .
                    $containerid .
                    '");
                        if (!container) return;

                        const formData = new FormData();
                        formData.append("sesskey", M.cfg.sesskey);
                        formData.append("contextid", ' .
                    $audiofile->get_contextid() .
                    ');
                        formData.append("component", "' .
                    addslashes($audiofile->get_component()) .
                    '");
                        formData.append("filearea", "' .
                    addslashes($audiofile->get_filearea()) .
                    '");
                        formData.append("itemid", ' .
                    $audiofile->get_itemid() .
                    ');
                        formData.append("filepath", "' .
                    addslashes($audiofile->get_filepath()) .
                    '");
                        formData.append("filename", "' .
                    addslashes($audiofile->get_filename()) .
                    '");

                        fetch(M.cfg.wwwroot + "/local/transcribe_ajax.php", {
                            method: "POST",
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                container.innerHTML = "<strong>Transcript:</strong><br/>" + (data.transcript || "").replace(/\\n/g, "<br/>");
                                container.classList.remove("alert-info");
                                container.classList.add("alert-light");
                            } else {
                                container.innerHTML = "<strong>Transcription Error:</strong> " + (data.message || "Unknown error") +
                                    "<br/><button type=\"button\" class=\"btn btn-link btn-sm p-0\" onclick=\"location.reload()\">Retry</button>";
                                container.classList.remove("alert-info");
                                container.classList.add("alert-warning");
                            }
                        })
                        .catch(error => {
                            container.innerHTML = "<strong>Transcription Request Failed:</strong> " + error.message;
                            container.classList.remove("alert-info");
                            container.classList.add("alert-danger");
                        });
                    }

                    if (document.readyState === "complete" || document.readyState === "interactive") {
                        fetchTranscription();
                    } else {
                        document.addEventListener("DOMContentLoaded", fetchTranscription);
                    }
                })();
                </script>';
            } else {
                $output .= html_writer::tag(
                    "div",
                    html_writer::tag(
                        "div",
                        "<em>Audio embedded via HTML tag. Transcription requires an uploaded file.</em>",
                        ["class" => "alert alert-light mt-3 mb-3"],
                    ),
                    ["class" => "custom-injected-section"],
                );
            }
        }

        // ── Image OCR Section ────────────────────────────────────────────────────
        // Only show OCR when the student submitted an IMAGE without also typing
        // text.  If there is already a text response the extracted-text panel is
        // redundant and confusing.
        $has_text_response = trim(strip_tags($responsetext)) !== '';
        if ($imagefile !== null && !$has_text_response) {
            $ocr_container_id =
                "ocr-container-" . $qa->get_usage_id() . "-" . $qa->get_slot();

            // All values are baked in server-side so the JS closure needs no
            // extra round-trip to discover which file to request OCR for.
            $ocr_contextid = (int) $imagefile->get_contextid();
            $ocr_component = addslashes($imagefile->get_component());
            $ocr_filearea = addslashes($imagefile->get_filearea());
            $ocr_itemid = (int) $imagefile->get_itemid();
            $ocr_filepath = addslashes($imagefile->get_filepath());
            $ocr_filename = addslashes($imagefile->get_filename());

            $output .=
                '
            <div class="custom-ocr-section mt-3 mb-3">
                <div id="' .
                $ocr_container_id .
                '" class="alert alert-info" role="status">
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm text-primary mr-2" aria-hidden="true"></div>
                        <span class="ml-2">Extracting text from image&hellip;</span>
                    </div>
                </div>
            </div>
            <script>
            (function() {
                function runOcr() {
                    var container = document.getElementById("' .
                $ocr_container_id .
                '");
                    if (!container) { return; }
                    if (typeof M === "undefined" || typeof M.cfg === "undefined") {
                        container.className = "alert alert-warning";
                        container.innerHTML = "<em>OCR unavailable: Moodle page config not loaded. Please reload the page.</em>";
                        return;
                    }

                    var fd = new FormData();
                    fd.append("sesskey",   M.cfg.sesskey);
                    fd.append("contextid", "' .
                $ocr_contextid .
                '");
                    fd.append("component", "' .
                $ocr_component .
                '");
                    fd.append("filearea",  "' .
                $ocr_filearea .
                '");
                    fd.append("itemid",    "' .
                $ocr_itemid .
                '");
                    fd.append("filepath",  "' .
                $ocr_filepath .
                '");
                    fd.append("filename",  "' .
                $ocr_filename .
                '");

                    fetch(M.cfg.wwwroot + "/local/ocr_ajax.php", { method: "POST", body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success && d.text) {
                            var safe = d.text
                                .replace(/&/g, "&amp;")
                                .replace(/</g, "&lt;")
                                .replace(/>/g, "&gt;");
                            container.className = "alert alert-light";
                            container.innerHTML =
                                "<strong>Extracted Text:<\/strong>" +
                                "<pre style=\"white-space:pre-wrap;margin:8px 0 0;font-family:inherit;\">" +
                                safe + "<\/pre>";
                        } else {
                            container.className = "alert alert-secondary";
                            container.innerHTML =
                                "<strong>\uD83D\uDD0D OCR:<\/strong> " +
                                "<em>Text not found inside the image.<\/em>";
                        }
                    })
                    .catch(function() {
                        container.className = "alert alert-secondary";
                        container.innerHTML =
                            "<strong>\uD83D\uDD0D OCR:<\/strong> " +
                            "<em>Text not found inside the image.<\/em>";
                    });
                }

                if (document.readyState === "complete" || document.readyState === "interactive") {
                    runOcr();
                } else {
                    document.addEventListener("DOMContentLoaded", runOcr);
                }
            })();
            </script>';
        }



        $output .= html_writer::nonempty_tag(
            "div",
            $this->add_part_heading(
                get_string("comments", "question"),
                $this->manual_comment(
                    $qa,
                    $behaviouroutput,
                    $qtoutput,
                    $options,
                ),
            ),
            ["class" => "comment clearfix"],
        );

        $output .= html_writer::nonempty_tag(
            "div",
            $this->response_history($qa, $behaviouroutput, $qtoutput, $options),
            ["class" => "history clearfix border p-2"],
        );

        $output .= html_writer::end_tag("div");
        $output .= html_writer::end_tag("div");
        return $output;
    }

    /**
     * Generate the information bit of the question display that contains the
     * metadata like the question number, current state, and mark.
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the renderer to output the behaviour
     *      specific parts.
     * @param qtype_renderer $qtoutput the renderer to output the question type
     *      specific parts.
     * @param question_display_options $options controls what should and should not be displayed.
     * @param string|null $number The question number to display. 'i' is a special
     *      value that gets displayed as Information. Null means no number is displayed.
     * @return HTML fragment.
     */
    protected function info(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        qtype_renderer $qtoutput,
        question_display_options $options,
        $number,
    ) {
        $output = "";
        $output .= $this->number($number);
        $output .= $this->status($qa, $behaviouroutput, $options);
        $output .= $this->mark_summary($qa, $behaviouroutput, $options);
        $output .= $this->question_flag($qa, $options->flags);
        $output .= $this->edit_question_link($qa, $options);
        if ($options->versioninfo) {
            $output .= $this->render(
                new question_version_info($qa->get_question(), true),
            );
        }
        return $output;
    }

    /**
     * Generate the display of the question number.
     * @param string|null $number The question number to display. 'i' is a special
     *      value that gets displayed as Information. Null means no number is displayed.
     * @return HTML fragment.
     */
    protected function number($number)
    {
        if (trim($number ?? "") === "") {
            return "";
        }
        if (trim($number) === "i") {
            $numbertext = get_string("information", "question");
        } else {
            $numbertext = get_string(
                "questionx",
                "question",
                html_writer::tag("span", s($number), ["class" => "qno"]),
            );
        }
        return html_writer::tag("h3", $numbertext, ["class" => "no"]);
    }

    /**
     * Get the question number as a string.
     *
     * @param string|null $number e.g. '123' or 'i'. null or '' means do not display anything number-related.
     * @return string e.g. 'Question 123' or 'Information' or ''.
     */
    protected function question_number_text(?string $number): string
    {
        $number = $number ?? "";
        // Trim the question number of whitespace, including &nbsp;.
        $trimmed = trim(html_entity_decode($number), " \n\r\t\v\x00\xC2\xA0");
        if ($trimmed === "") {
            return "";
        }
        if (trim($number) === "i") {
            return get_string("information", "question");
        } else {
            return get_string("questionx", "question", s($number));
        }
    }

    /**
     * Add an invisible heading like 'question text', 'feebdack' at the top of
     * a section's contents, but only if the section has some content.
     * @param string $heading the heading to add.
     * @param string $content the content of the section.
     * @return string HTML fragment with the heading added.
     */
    protected function add_part_heading($heading, $content)
    {
        if ($content) {
            $content =
                html_writer::tag("h4", $heading, ["class" => "accesshide"]) .
                $content;
        }
        return $content;
    }

    /**
     * Generate the display of the status line that gives the current state of
     * the question.
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the renderer to output the behaviour
     *      specific parts.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    protected function status(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        question_display_options $options,
    ) {
        return html_writer::tag(
            "div",
            $qa->get_state_string($options->correctness),
            ["class" => "state"],
        );
    }

    /**
     * Generate the display of the marks for this question.
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the behaviour renderer, which can generate a custom display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    protected function mark_summary(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        question_display_options $options,
    ) {
        return html_writer::nonempty_tag(
            "div",
            $behaviouroutput->mark_summary($qa, $this, $options),
            ["class" => "grade"],
        );
    }

    /**
     * Generate the display of the marks for this question.
     * @param question_attempt $qa the question attempt to display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    public function standard_mark_summary(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        question_display_options $options,
    ) {
        if (!$options->marks) {
            return "";
        } elseif ($qa->get_max_mark() == 0) {
            return get_string("notgraded", "question");
        } elseif (
            $options->marks == question_display_options::MAX_ONLY ||
            is_null($qa->get_fraction())
        ) {
            return $behaviouroutput->marked_out_of_max($qa, $this, $options);
        } else {
            return $behaviouroutput->mark_out_of_max($qa, $this, $options);
        }
    }

    /**
     * Generate the display of the available marks for this question.
     * @param question_attempt $qa the question attempt to display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    public function standard_marked_out_of_max(
        question_attempt $qa,
        question_display_options $options,
    ) {
        return get_string(
            "markedoutofmax",
            "question",
            $qa->format_max_mark($options->markdp),
        );
    }

    /**
     * Generate the display of the marks for this question out of the available marks.
     * @param question_attempt $qa the question attempt to display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    public function standard_mark_out_of_max(
        question_attempt $qa,
        question_display_options $options,
    ) {
        $a = new stdClass();
        $a->mark = $qa->format_mark($options->markdp);
        $a->max = $qa->format_max_mark($options->markdp);
        return get_string("markoutofmax", "question", $a);
    }

    /**
     * Render the question flag, assuming $flagsoption allows it.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param int $flagsoption the option that says whether flags should be displayed.
     */
    protected function question_flag(question_attempt $qa, $flagsoption)
    {
        $divattributes = ["class" => "questionflag"];

        switch ($flagsoption) {
            case question_display_options::VISIBLE:
                $flagcontent = $this->get_flag_html($qa->is_flagged());
                break;

            case question_display_options::EDITABLE:
                $id = $qa->get_flag_field_name();
                // The checkbox id must be different from any element name, because
                // of a stupid IE bug:
                // http://www.456bereastreet.com/archive/200802/beware_of_id_and_name_attribute_mixups_when_using_getelementbyid_in_internet_explorer/
                $checkboxattributes = [
                    "type" => "checkbox",
                    "id" => $id . "checkbox",
                    "name" => $id,
                    "value" => 1,
                ];
                if ($qa->is_flagged()) {
                    $checkboxattributes["checked"] = "checked";
                }
                $postdata = question_flags::get_postdata($qa);

                $flagcontent =
                    html_writer::empty_tag("input", [
                        "type" => "hidden",
                        "name" => $id,
                        "value" => 0,
                    ]) .
                    html_writer::empty_tag("input", [
                        "type" => "hidden",
                        "value" => $postdata,
                        "class" => "questionflagpostdata",
                    ]) .
                    html_writer::empty_tag("input", $checkboxattributes) .
                    html_writer::tag(
                        "label",
                        $this->get_flag_html($qa->is_flagged(), $id . "img"),
                        ["id" => $id . "label", "for" => $id . "checkbox"],
                    ) .
                    "\n";

                $divattributes = [
                    "class" => "questionflag editable",
                ];

                break;

            default:
                $flagcontent = "";
        }

        return html_writer::nonempty_tag("div", $flagcontent, $divattributes);
    }

    /**
     * Work out the actual img tag needed for the flag
     *
     * @param bool $flagged whether the question is currently flagged.
     * @param string $id an id to be added as an attribute to the img (optional).
     * @return string the img tag.
     */
    protected function get_flag_html($flagged, $id = "")
    {
        if ($flagged) {
            $icon = "i/flagged";
            $label = get_string("clickunflag", "question");
        } else {
            $icon = "i/unflagged";
            $label = get_string("clickflag", "question");
        }
        $attributes = [
            "src" => $this->image_url($icon),
            "alt" => "",
            "class" => "questionflagimage",
        ];
        if ($id) {
            $attributes["id"] = $id;
        }
        $img = html_writer::empty_tag("img", $attributes);
        $img .= html_writer::span($label);

        return $img;
    }

    /**
     * Generate the display of the edit question link.
     *
     * @param question_attempt $qa The question attempt to display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string
     */
    protected function edit_question_link(
        question_attempt $qa,
        question_display_options $options,
    ) {
        if (empty($options->editquestionparams)) {
            return "";
        }

        $params = $options->editquestionparams;
        if ($params["returnurl"] instanceof moodle_url) {
            $params["returnurl"] = $params["returnurl"]->out_as_local_url(
                false,
            );
        }
        $params["id"] = $qa->get_question_id();
        $editurl = new moodle_url(
            "/question/bank/editquestion/question.php",
            $params,
        );

        return html_writer::tag(
            "div",
            html_writer::link(
                $editurl,
                $this->pix_icon("t/edit", get_string("edit"), "", [
                    "class" => "iconsmall",
                ]) . get_string("editquestion", "question"),
            ),
            ["class" => "editquestion"],
        );
    }

    /**
     * Generate the display of the formulation part of the question. This is the
     * area that contains the quetsion text, and the controls for students to
     * input their answers. Some question types also embed feedback, for
     * example ticks and crosses, in this area.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the renderer to output the behaviour
     *      specific parts.
     * @param qtype_renderer $qtoutput the renderer to output the question type
     *      specific parts.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    protected function formulation(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        qtype_renderer $qtoutput,
        question_display_options $options,
    ) {
        $output = "";
        $output .= html_writer::empty_tag("input", [
            "type" => "hidden",
            "name" => $qa->get_control_field_name("sequencecheck"),
            "value" => $qa->get_sequence_check_count(),
        ]);
        $output .= $qtoutput->formulation_and_controls($qa, $options);
        if ($options->clearwrong) {
            $output .= $qtoutput->clear_wrong($qa);
        }
        $output .= html_writer::nonempty_tag(
            "div",
            $behaviouroutput->controls($qa, $options),
            ["class" => "im-controls"],
        );
        return $output;
    }

    /**
     * Generate the display of the outcome part of the question. This is the
     * area that contains the various forms of feedback.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the renderer to output the behaviour
     *      specific parts.
     * @param qtype_renderer $qtoutput the renderer to output the question type
     *      specific parts.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    protected function outcome(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        qtype_renderer $qtoutput,
        question_display_options $options,
    ) {
        $output = "";
        $output .= html_writer::nonempty_tag(
            "div",
            $qtoutput->feedback($qa, $options),
            ["class" => "feedback"],
        );
        $output .= html_writer::nonempty_tag(
            "div",
            $behaviouroutput->feedback($qa, $options),
            ["class" => "im-feedback"],
        );
        $output .= html_writer::nonempty_tag(
            "div",
            $options->extrainfocontent,
            ["class" => "extra-feedback"],
        );
        return $output;
    }

    protected function manual_comment(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        qtype_renderer $qtoutput,
        question_display_options $options,
    ) {
        return $qtoutput->manual_comment($qa, $options) .
            $behaviouroutput->manual_comment($qa, $options);
    }

    /**
     * Generate the display of the response history part of the question. This
     * is the table showing all the steps the question has been through.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param qbehaviour_renderer $behaviouroutput the renderer to output the behaviour
     *      specific parts.
     * @param qtype_renderer $qtoutput the renderer to output the question type
     *      specific parts.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return HTML fragment.
     */
    protected function response_history(
        question_attempt $qa,
        qbehaviour_renderer $behaviouroutput,
        qtype_renderer $qtoutput,
        question_display_options $options,
    ) {
        if (!$options->history) {
            return "";
        }

        $table = new html_table();
        $table->head = [
            get_string("step", "question"),
            get_string("time"),
            get_string("action", "question"),
            get_string("state", "question"),
        ];
        if ($options->marks >= question_display_options::MARK_AND_MAX) {
            $table->head[] = get_string("marks", "question");
        }

        foreach ($qa->get_full_step_iterator() as $i => $step) {
            $stepno = $i + 1;

            $rowclass = "";
            if ($stepno == $qa->get_num_steps()) {
                $rowclass = "current";
            } elseif (!empty($options->questionreviewlink)) {
                $url = new moodle_url($options->questionreviewlink, [
                    "slot" => $qa->get_slot(),
                    "step" => $i,
                ]);
                $stepno = $this->output->action_link(
                    $url,
                    $stepno,
                    new popup_action("click", $url, "reviewquestion", [
                        "width" => 450,
                        "height" => 650,
                    ]),
                    ["title" => get_string("reviewresponse", "question")],
                );
            }

            $restrictedqa = new question_attempt_with_restricted_history(
                $qa,
                $i,
                null,
            );

            $row = [
                $stepno,
                userdate(
                    $step->get_timecreated(),
                    get_string(
                        "strftimedatetimeshortaccurate",
                        "core_langconfig",
                    ),
                ),
                s($qa->summarise_action($step)) .
                $this->action_author($step, $options),
                $restrictedqa->get_state_string($options->correctness),
            ];

            if ($options->marks >= question_display_options::MARK_AND_MAX) {
                $row[] = $qa->format_fraction_as_mark(
                    $step->get_fraction(),
                    $options->markdp,
                );
            }

            $table->rowclasses[] = $rowclass;
            $table->data[] = $row;
        }

        return html_writer::tag(
            "h4",
            get_string("responsehistory", "question"),
            ["class" => "responsehistoryheader"],
        ) .
            $options->extrahistorycontent .
            html_writer::tag("div", html_writer::table($table, true), [
                "class" => "responsehistoryheader",
            ]);
    }

    /**
     * Action author's profile link.
     *
     * @param question_attempt_step $step The step.
     * @param question_display_options $options The display options.
     * @return string The link to user's profile.
     */
    protected function action_author(
        question_attempt_step $step,
        question_display_options $options,
    ): string {
        if (
            $options->userinfoinhistory &&
            $step->get_user_id() != $options->userinfoinhistory
        ) {
            return html_writer::link(
                new moodle_url("/user/view.php", [
                    "id" => $step->get_user_id(),
                    "course" => $this->page->course->id,
                ]),
                $step->get_user_fullname(),
                ["class" => "d-table-cell"],
            );
        } else {
            return "";
        }
    }
}
