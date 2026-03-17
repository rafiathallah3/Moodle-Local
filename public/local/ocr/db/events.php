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
 * Event observer registration for local_ocr.
 *
 * Hooks into assignment file submissions and quiz attempt submissions
 * so that OCR is automatically triggered for any image file that a
 * student submits. Results are stored in the local_ocr_results table
 * and served back to the grading UI via ocr_ajax.php.
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // ── Assignment: fires when a student finalises (submits) an assignment ──
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => '\local_ocr\event\observer::assessable_submitted',
        'internal'  => false,
        'priority'  => 0,
    ],

    // ── Quiz: fires when a student submits a quiz attempt ───────────────────
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_ocr\event\observer::attempt_submitted',
        'internal'  => false,
        'priority'  => 0,
    ],

];
