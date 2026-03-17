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
 * Library / hook callbacks for the local_ocr plugin.
 *
 * Moodle calls local_ocr_before_footer() just before the page footer is
 * rendered.  We use that moment to inject the AMD JavaScript module that
 * scans the current page for Moodle image-file links and appends the
 * OCR-extracted text panel directly beneath each one.
 *
 * The module is only injected on pages that are relevant to graders and
 * students viewing submissions (mod_assign and mod_quiz page families).
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook: called by Moodle's output layer immediately before the page footer
 * is emitted.
 *
 * Decides whether the current page is an assignment or quiz page and, if so,
 * queues the local_ocr/ocr_display AMD module with the parameters it needs
 * (sesskey and wwwroot) so it can make authenticated AJAX calls to
 * local/ocr_ajax.php.
 *
 * @return void
 */
function local_ocr_before_footer(): void {
    global $PAGE, $CFG;

    // ── Guard: plugin must be enabled ────────────────────────────────────────
    $enabled = get_config('local_ocr', 'enabled');
    // Treat an unset value (first install) as enabled.
    if ($enabled !== false && !(bool)$enabled) {
        return;
    }

    // ── Guard: only inject for authenticated, non-guest users ────────────────
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // ── Guard: only inject on assignment and quiz page families ──────────────
    // $PAGE->pagetype is a dash-separated string such as:
    //   mod-assign-view, mod-assign-grading, mod-quiz-review, mod-quiz-attempt
    $pagetype = $PAGE->pagetype ?? '';

    $is_assign_page = strpos($pagetype, 'mod-assign') !== false;
    $is_quiz_page   = strpos($pagetype, 'mod-quiz')   !== false;

    if (!$is_assign_page && !$is_quiz_page) {
        return;
    }

    // ── Build the parameters object passed to AMD init() ─────────────────────
    // sesskey   – required by require_sesskey() in ocr_ajax.php.
    // wwwroot   – used to build the absolute AJAX URL client-side so that the
    //             script works regardless of subdirectory installations.
    // ajaxurl   – the full URL to the AJAX endpoint (convenience for the JS).
    // autostart – whether to begin OCR immediately on page load (vs. on click).
    $autostart = (bool) get_config('local_ocr', 'autoocrsubmissions');
    // Treat unset (first install) as enabled.
    if ($autostart === false) {
        $autostart = true;
    }

    $params = [
        'sesskey'   => sesskey(),
        'wwwroot'   => $CFG->wwwroot,
        'ajaxurl'   => $CFG->wwwroot . '/local/ocr_ajax.php',
        'autostart' => $autostart,
    ];

    // Queue the AMD module.  Moodle will emit the RequireJS call at the
    // bottom of the page, after all page content has been rendered, which
    // is exactly when we need the DOM to be ready.
    $PAGE->requires->js_call_amd('local_ocr/ocr_display', 'init', [$params]);
}
