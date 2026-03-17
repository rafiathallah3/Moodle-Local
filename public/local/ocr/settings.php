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
 * Admin settings for the local_ocr plugin.
 *
 * Exposes three controls in Site Administration > Plugins > Local plugins:
 *   1. Enable / disable the plugin entirely.
 *   2. Choose the AI model (Gemini 2.5 Flash or OpenAI GPT-4o).
 *   3. Toggle automatic background OCR on every image submission.
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // ── Settings page ─────────────────────────────────────────────────────────
    $settings = new admin_settingpage(
        'local_ocr_settings',
        get_string('settingspage', 'local_ocr')
    );

    // Register the settings page under the "Local plugins" category.
    $ADMIN->add('localplugins', $settings);

    // Also add a direct link to the OCR results log report.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ocr_report',
        get_string('ocrreport', 'local_ocr'),
        new moodle_url('/local/ocr/report.php'),
        'moodle/site:config'
    ));

    // ── 1. Global enable / disable toggle ─────────────────────────────────────
    $settings->add(new admin_setting_configcheckbox(
        'local_ocr/enabled',
        get_string('enabled', 'local_ocr'),
        get_string('enabled_desc', 'local_ocr'),
        1   // default: enabled
    ));

    // ── 2. AI model selection ─────────────────────────────────────────────────
    $model_choices = [
        'gemini' => get_string('model_gemini', 'local_ocr'),
        'openai' => get_string('model_openai', 'local_ocr'),
    ];

    $settings->add(new admin_setting_configselect(
        'local_ocr/model',
        get_string('model', 'local_ocr'),
        get_string('model_desc', 'local_ocr'),
        'gemini',       // default: Gemini
        $model_choices
    ));

    // ── 3. Auto-OCR on submission ─────────────────────────────────────────────
    $settings->add(new admin_setting_configcheckbox(
        'local_ocr/autoocrsubmissions',
        get_string('autoocrsubmissions', 'local_ocr'),
        get_string('autoocrsubmissions_desc', 'local_ocr'),
        1   // default: enabled
    ));
}
