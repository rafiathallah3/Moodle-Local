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
 * Language strings for the local_ocr plugin (English).
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ── Core plugin strings ──────────────────────────────────────────────────────
$string['pluginname']            = 'OCR Image Text Extractor';
$string['plugindescription']     = 'Automatically extracts text from image files submitted in assignments and quizzes using AI vision models (Gemini or OpenAI).';

// ── Admin settings ───────────────────────────────────────────────────────────
$string['settings']              = 'OCR Settings';
$string['settingspage']          = 'OCR Image Text Extractor Settings';

$string['model']                 = 'AI Model';
$string['model_desc']            = 'Select the AI vision model to use for Optical Character Recognition. Gemini requires a GEMINI_API key in the .env file; OpenAI requires an OPENAI_API key.';
$string['model_gemini']          = 'Google Gemini 2.5 Flash (recommended)';
$string['model_openai']          = 'OpenAI GPT-4o';

$string['autoocrsubmissions']    = 'Auto-OCR on submission';
$string['autoocrsubmissions_desc'] = 'When enabled, OCR is automatically triggered in the background whenever a student submits an assignment or quiz that contains image file attachments. Results are cached in the database and shown instantly when a teacher views the submission.';

$string['enabled']               = 'Enable OCR plugin';
$string['enabled_desc']          = 'Globally enable or disable the OCR Image Text Extractor feature. When disabled, no OCR processing will occur and the extracted-text panel will not appear on submission pages.';

// ── Report / log page ────────────────────────────────────────────────────────
$string['ocrreport']             = 'OCR Results Log';
$string['ocrreport_desc']        = 'View all OCR results that have been stored in the database.';
$string['noresults']             = 'No OCR results have been stored yet.';
$string['column_id']             = 'ID';
$string['column_filename']       = 'Filename';
$string['column_component']      = 'Component';
$string['column_filearea']       = 'File Area';
$string['column_itemid']         = 'Item ID';
$string['column_model']          = 'Model Used';
$string['column_timecreated']    = 'Processed At';
$string['column_ocrtext']        = 'Extracted Text';
$string['column_actions']        = 'Actions';

// ── UI strings shown on submission/grading pages ──────────────────────────────
$string['ocr_section_title']     = 'OCR Extracted Text';
$string['ocr_loading']           = 'Extracting text from image\u2026';
$string['ocr_not_found']         = 'Text not found inside the image.';
$string['ocr_error']             = 'OCR could not be completed for this image.';
$string['ocr_cached_badge']      = 'cached';
$string['ocr_fresh_badge']       = 'just extracted';

// ── Privacy / GDPR ───────────────────────────────────────────────────────────
$string['privacy:metadata:local_ocr_results']               = 'The local_ocr plugin stores OCR-extracted text that was obtained from image files submitted by students.';
$string['privacy:metadata:local_ocr_results:contextid']     = 'The Moodle context ID associated with the submission that contained the image file.';
$string['privacy:metadata:local_ocr_results:component']     = 'The component that owns the file (e.g. assignsubmission_file).';
$string['privacy:metadata:local_ocr_results:filearea']      = 'The file area within the component (e.g. submission_files).';
$string['privacy:metadata:local_ocr_results:itemid']        = 'The item ID within the file area (e.g. the submission record ID).';
$string['privacy:metadata:local_ocr_results:filename']      = 'The name of the image file that was processed.';
$string['privacy:metadata:local_ocr_results:ocr_text']      = 'The text extracted from the image by the AI OCR engine.';
$string['privacy:metadata:local_ocr_results:model']         = 'The AI model that was used to perform OCR on the image.';
$string['privacy:metadata:local_ocr_results:timecreated']   = 'The Unix timestamp at which the OCR result was stored.';
