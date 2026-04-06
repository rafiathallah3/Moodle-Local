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
 * Handle user language changes permanently in the database.
 *
 * @package    core
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../config.php');

$lang = required_param('lang', PARAM_SAFEDIR); // Safe check for language code.
$returnurl = optional_param('returnurl', $CFG->wwwroot, PARAM_LOCALURL);

// Standard Moodle session and login check.
// We only require a session and a sesskey to prevent CSRF, but not a full login if it's just a session change.
require_sesskey();

if (get_string_manager()->translation_exists($lang, false)) {
    // Set for current session.
    $SESSION->lang = $lang;
    
    // Set permanently for the user record if they are a real user.
    if (isloggedin() && !isguestuser() && isset($USER->id)) {
        $DB->set_field('user', 'lang', $lang, ['id' => $USER->id]);
        $USER->lang = $lang;
    }
    
    // Reset any localized caches.
    \core_courseformat\base::session_cache_reset_all();
}

// Redirect back to the originating page.
redirect($returnurl);
