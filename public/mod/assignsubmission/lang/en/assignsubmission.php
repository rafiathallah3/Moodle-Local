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
 * Strings for component 'assignsubmission', language 'en'.
 *
 * @package   mod_assignsubmission
 * @copyright 2026 Custom
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['modulename'] = 'Assignment Submission';
$string['modulename_help'] = 'The Assignment Submission activity allows lecturers and lecturer assistants to bulk-upload student assignment images, automatically extract student names via OCR, and auto-grade submissions using AI.

Use this activity to:
* Upload 30-40 student assignment images at once
* Automatically identify student names from images
* Auto-grade submissions with AI-powered feedback';
$string['modulenameplural'] = 'Assignment Submissions';
$string['pluginname'] = 'Assignment Submission';
$string['pluginadministration'] = 'Assignment Submission administration';

// Form fields.
$string['assignmentname'] = 'Assignment name';
$string['questiontext'] = 'Assignment/Question description';
$string['questiontext_help'] = 'Describe the assignment or question. This text is used as context by the AI grader to evaluate student submissions.';
$string['maxmark'] = 'Maximum mark';
$string['maxmark_help'] = 'The maximum mark a student can receive for this assignment.';

// View page.
$string['uploadsubmissions'] = 'Upload Submissions';
$string['uploadzone_label'] = 'Drag & drop student assignment images here, or click to browse';
$string['uploadzone_hint'] = 'Accepted formats: JPG, JPEG, PNG, GIF, WEBP';
$string['submissionstable'] = 'Student Submissions';
$string['studentname'] = 'Student Name';
$string['mark'] = 'Mark';
$string['feedback'] = 'Feedback';
$string['status'] = 'Status';
$string['actions'] = 'Actions';
$string['imagepreview'] = 'Image';
$string['ocrtext'] = 'Extracted Text';
$string['diagnose'] = 'Diagnose';
$string['diagnosing'] = 'Diagnosing...';
$string['autograde_all'] = 'Auto-grade All';
$string['autograde_warning'] = 'Warning: Auto-grading all submissions will make multiple AI API calls. This may exhaust your free-tier Gemini API quota. Are you sure you want to continue?';
$string['autograde_confirm'] = 'Yes, auto-grade all';
$string['autograde_cancel'] = 'Cancel';
$string['nosubmissions'] = 'No submissions uploaded yet.';
$string['uploadingfiles'] = 'Uploading and processing files...';
$string['deleteconfirm'] = 'Are you sure you want to delete this submission?';
$string['editsubmission'] = 'Edit Submission';
$string['edit_title'] = 'Edit Title';

// Status labels.
$string['status_pending'] = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_graded'] = 'Graded';
$string['status_error'] = 'Error';

// Capabilities.
$string['assignsubmission:view'] = 'View assignment submissions';
$string['assignsubmission:addinstance'] = 'Add a new Assignment Submission activity';
$string['assignsubmission:upload'] = 'Upload student submissions';
$string['assignsubmission:grade'] = 'Grade student submissions';

// Events.
$string['eventcoursemoduleviewed'] = 'Assignment Submission viewed';

// Misc.
$string['privacy:metadata'] = 'The Assignment Submission plugin stores uploaded student images and AI-generated grades and feedback.';
$string['search:activity'] = 'Assignment Submission';
$string['unnamed_student'] = 'Unnamed';
$string['deletesubmission'] = 'Delete';
$string['editsubmission'] = 'Edit';
$string['edit_title'] = 'Edit Submission';
$string['edit_save'] = 'Save Changes';
$string['of'] = 'of';

// Edit description.
$string['editdescription'] = 'Edit Description';
$string['editdescription_title'] = 'Edit Assignment Description';
$string['editdescription_help'] = 'This description is used as context by the AI grader when evaluating student submissions. For example, specify the expected input/output format, rubric criteria, or specific requirements.';
$string['descriptionsaved'] = 'Description saved successfully.';

// Student Mapping CSV
$string['viewmapping'] = 'View Student Mapping';
$string['viewmapping_title'] = 'Course Student Mapping';
$string['nomappings'] = 'No student mappings found for this course. Please upload a mapping CSV.';
$string['studentid'] = 'Student ID';
$string['close'] = 'Close';
$string['uploadmapping'] = 'Upload New CSV';
$string['mapping_title'] = 'Upload Course Student Mapping (CSV)';
$string['mapping_help'] = 'Upload a CSV file (semicolon separated) containing student IDs and names. This mapping will apply to ALL Assignment Submission activities in this course. It is used to quickly identify students from uploaded image filenames (e.g., if the filename contains "1030...").';
$string['csv_file'] = 'CSV File (.csv)';
$string['upload'] = 'Upload';
$string['mappinguploaded'] = 'Successfully uploaded {$a} student mappings.';
$string['uploading'] = 'Uploading...';
