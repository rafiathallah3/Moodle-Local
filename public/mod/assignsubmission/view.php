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
 * View page for Assignment Submission activity.
 *
 * @package    mod_assignsubmission
 * @copyright  2026 Custom
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/assignsubmission/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID.
$a  = optional_param('a', 0, PARAM_INT);  // Instance ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('assignsubmission', $id)) {
        throw new \moodle_exception('invalidcoursemodule');
    }
    $instance = $DB->get_record('assignsubmission', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($a) {
    $instance = $DB->get_record('assignsubmission', array('id' => $a), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('assignsubmission', $instance->id, $instance->course, false, MUST_EXIST);
} else {
    throw new \moodle_exception('invalidaccessparameter');
}

$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/assignsubmission:view', $context);

// Trigger view event.
assignsubmission_view($instance, $course, $cm, $context);

$PAGE->set_url('/mod/assignsubmission/view.php', array('id' => $cm->id));
$PAGE->set_title($course->shortname . ': ' . $instance->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($instance);

// Fetch existing submissions.
$submissions = $DB->get_records('assignsubmission_files',
    array('assignsubmission' => $instance->id),
    'timecreated ASC');

// Build file URLs for each submission.
$fs = get_file_storage();
$submission_data = array();
foreach ($submissions as $sub) {
    // Only fetch exactly one file per submission record from this area.
    $files = $fs->get_area_files($context->id, 'mod_assignsubmission', 'submissions', $sub->id, 'id', false);
    $fileurl = '';
    foreach ($files as $file) {
        $fileurl = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_assignsubmission',
            'submissions',
            $sub->id,
            $file->get_filepath(),
            $file->get_filename()
        )->out();
        break;
    }
    $sub->fileurl = $fileurl;
    $submission_data[] = $sub;
}

$can_upload = has_capability('mod/assignsubmission:upload', $context);
$can_grade = has_capability('mod/assignsubmission:grade', $context);

echo $OUTPUT->header();
?>

<div class="container-fluid mt-4" id="assignsubmission-main">
    <!-- Header Card -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h4"><?php echo format_string($instance->name); ?></h2>
            <div class="mb-2" id="description-display">
                <?php if (!empty($instance->questiontext)): ?>
                    <p class="text-muted mb-1" id="description-text"><?php echo format_text($instance->questiontext, FORMAT_PLAIN); ?></p>
                <?php else: ?>
                    <p class="text-muted mb-1 font-italic" id="description-text"><em><?php echo get_string('editdescription_help', 'assignsubmission'); ?></em></p>
                <?php endif; ?>
                <?php if ($can_grade): ?>
                    <button class="btn btn-outline-primary btn-sm" id="btn-edit-description" title="<?php echo get_string('editdescription', 'assignsubmission'); ?>">
                        <i class="fa fa-pencil"></i> <?php echo get_string('editdescription', 'assignsubmission'); ?>
                    </button>
                <?php endif; ?>
            </div>
            <div>
                <span class="badge badge-info bg-info text-white me-2 mr-2">
                    Max Mark: <?php echo $instance->maxmark; ?>
                </span>
                <span class="badge badge-secondary bg-secondary text-white">
                    Submissions: <span id="submission-count"><?php echo count($submission_data); ?></span>
                </span>
            </div>
        </div>
    </div>

    <?php if ($can_upload): ?>
    <!-- Upload Section -->
    <div class="card mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <h3 class="h5 mb-0 text-white"><?php echo get_string('uploadsubmissions', 'assignsubmission'); ?></h3>
        </div>
        <div class="card-body">
            <div id="upload-zone" class="p-5 text-center bg-light rounded" style="cursor: pointer; border: 2px dashed #007bff;">
                <input type="file" id="file-input" multiple accept="image/*" style="display:none;">
                <div id="upload-content">
                    <p class="lead mb-1"><?php echo get_string('uploadzone_label', 'assignsubmission'); ?></p>
                    <p class="text-muted small mb-0"><?php echo get_string('uploadzone_hint', 'assignsubmission'); ?></p>
                </div>
                <div id="upload-progress" style="display:none;">
                    <div class="progress mb-2" style="height: 20px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="progress-fill" role="progressbar" style="width: 0%"></div>
                    </div>
                    <p class="text-muted mb-0" id="progress-text"><?php echo get_string('uploadingfiles', 'assignsubmission'); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Submissions Table Section -->
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <h3 class="h5 mb-0"><?php echo get_string('submissionstable', 'assignsubmission'); ?></h3>
            <?php if ($can_grade && !empty($submission_data)): ?>
            <div>
                <button class="btn btn-warning btn-sm" id="btn-autograde-all" title="Auto-grade all ungraded submissions">
                    <?php echo get_string('autograde_all', 'assignsubmission'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive" id="submissions-table-wrapper">
                <?php if (empty($submission_data)): ?>
                    <div class="p-5 text-center text-muted" id="empty-state">
                        <p class="mb-0"><?php echo get_string('nosubmissions', 'assignsubmission'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="table table-striped table-hover table-bordered mb-0" id="submissions-table">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 100px;"><?php echo get_string('imagepreview', 'assignsubmission'); ?></th>
                                <th><?php echo get_string('studentname', 'assignsubmission'); ?></th>
                                <th style="width: 250px;"><?php echo get_string('ocrtext', 'assignsubmission'); ?></th>
                                <th style="width: 100px;"><?php echo get_string('mark', 'assignsubmission'); ?></th>
                                <th style="width: 300px;"><?php echo get_string('feedback', 'assignsubmission'); ?></th>
                                <th style="width: 120px;"><?php echo get_string('status', 'assignsubmission'); ?></th>
                                <th style="width: 180px;"><?php echo get_string('actions', 'assignsubmission'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="submissions-tbody">
                            <?php $i = 1; foreach ($submission_data as $sub): ?>
                            <tr id="row-<?php echo $sub->id; ?>" data-id="<?php echo $sub->id; ?>">
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <?php if ($sub->fileurl): ?>
                                        <a href="<?php echo $sub->fileurl; ?>" target="_blank">
                                            <img src="<?php echo $sub->fileurl; ?>" alt="Submission" class="img-thumbnail" style="max-width: 80px; max-height: 80px;">
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo s($sub->studentname); ?></strong>
                                </td>
                                <td>
                                    <div style="max-height: 100px; overflow-y: auto; font-size: 0.85em;">
                                        <?php if (!empty($sub->ocrtext)): ?>
                                            <?php echo nl2br(s($sub->ocrtext)); ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span id="mark-<?php echo $sub->id; ?>">
                                        <?php if ($sub->mark !== null): ?>
                                            <strong><?php echo $sub->mark; ?></strong> / <?php echo $instance->maxmark; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <div id="feedback-<?php echo $sub->id; ?>" style="max-height: 100px; overflow-y: auto;">
                                        <?php if (!empty($sub->feedback)): ?>
                                            <?php echo $sub->feedback; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo ($sub->status === 'graded' ? 'success bg-success text-white' : ($sub->status === 'error' ? 'danger bg-danger text-white' : ($sub->status === 'processing' ? 'info bg-info text-white' : 'warning bg-warning text-dark'))); ?>" id="status-<?php echo $sub->id; ?>">
                                        <?php echo get_string('status_' . $sub->status, 'assignsubmission'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($can_grade && ($sub->status === 'pending' || $sub->status === 'error')): ?>
                                        <button class="btn btn-primary btn-sm btn-diagnose mb-1" data-id="<?php echo $sub->id; ?>">
                                            <?php echo get_string('diagnose', 'assignsubmission'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($can_grade): ?>
                                        <button class="btn btn-outline-secondary btn-sm btn-edit mb-1" data-id="<?php echo $sub->id; ?>" data-name="<?php echo s($sub->studentname); ?>" data-mark="<?php echo $sub->mark ?? ''; ?>" data-feedback="<?php echo s($sub->feedback ?? ''); ?>">
                                            <?php echo get_string('editsubmission', 'assignsubmission'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger btn-sm btn-delete mb-1" data-id="<?php echo $sub->id; ?>" title="<?php echo get_string('deletesubmission', 'assignsubmission'); ?>">
                                        <?php echo get_string('deletesubmission', 'assignsubmission'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Auto-grade confirmation modal -->
<div class="modal" tabindex="-1" role="dialog" id="autograde-modal" style="display:none; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo get_string('autograde_all', 'assignsubmission'); ?></h5>
            </div>
            <div class="modal-body text-center">
                <p class="text-danger"><strong><?php echo get_string('autograde_warning', 'assignsubmission'); ?></strong></p>
                <div id="autograde-progress" style="display:none;" class="mt-3">
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning text-dark" id="autograde-progress-fill" style="width: 0%"></div>
                    </div>
                    <p class="text-muted mt-2 mb-0" id="autograde-progress-text">Grading 0 of 0...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="autograde-cancel"><?php echo get_string('autograde_cancel', 'assignsubmission'); ?></button>
                <button type="button" class="btn btn-warning" id="autograde-confirm"><?php echo get_string('autograde_confirm', 'assignsubmission'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Edit submission modal -->
<div class="modal" tabindex="-1" role="dialog" id="edit-modal" style="display:none; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo get_string('edit_title', 'assignsubmission'); ?></h5>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-subid">
                <div class="form-group mb-3">
                    <label for="edit-studentname" class="font-weight-bold"><?php echo get_string('studentname', 'assignsubmission'); ?></label>
                    <input type="text" class="form-control" id="edit-studentname">
                </div>
                <div class="form-group mb-3">
                    <label for="edit-mark" class="font-weight-bold"><?php echo get_string('mark', 'assignsubmission'); ?> (/ <?php echo $instance->maxmark; ?>)</label>
                    <input type="number" class="form-control" id="edit-mark" min="0" max="<?php echo $instance->maxmark; ?>" step="0.01">
                </div>
                <div class="form-group mb-3">
                    <label for="edit-feedback" class="font-weight-bold"><?php echo get_string('feedback', 'assignsubmission'); ?></label>
                    <textarea class="form-control" id="edit-feedback" rows="5"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="edit-cancel"><?php echo get_string('autograde_cancel', 'assignsubmission'); ?></button>
                <button type="button" class="btn btn-primary" id="edit-save"><?php echo get_string('edit_save', 'assignsubmission'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Edit description modal -->
<div class="modal" tabindex="-1" role="dialog" id="editdesc-modal" style="display:none; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo get_string('editdescription_title', 'assignsubmission'); ?></h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <i class="fa fa-info-circle"></i>
                    <?php echo get_string('editdescription_help', 'assignsubmission'); ?>
                </div>
                <div class="form-group">
                    <label for="editdesc-questiontext" class="font-weight-bold"><?php echo get_string('questiontext', 'assignsubmission'); ?></label>
                    <textarea class="form-control" id="editdesc-questiontext" rows="8" placeholder="<?php echo s(get_string('editdescription_help', 'assignsubmission')); ?>"><?php echo s($instance->questiontext ?? ''); ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="editdesc-cancel"><?php echo get_string('autograde_cancel', 'assignsubmission'); ?></button>
                <button type="button" class="btn btn-primary" id="editdesc-save"><?php echo get_string('edit_save', 'assignsubmission'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const cmid = <?php echo $cm->id; ?>;
    const instanceId = <?php echo $instance->id; ?>;
    const maxMark = <?php echo $instance->maxmark; ?>;
    const sesskey = '<?php echo sesskey(); ?>';
    const wwwroot = '<?php echo $CFG->wwwroot; ?>';
    const canUpload = <?php echo $can_upload ? 'true' : 'false'; ?>;
    const canGrade = <?php echo $can_grade ? 'true' : 'false'; ?>;

    const strings = {
        diagnose: <?php echo json_encode(get_string('diagnose', 'assignsubmission')); ?>,
        diagnosing: <?php echo json_encode(get_string('diagnosing', 'assignsubmission')); ?>,
        pending: <?php echo json_encode(get_string('status_pending', 'assignsubmission')); ?>,
        processing: <?php echo json_encode(get_string('status_processing', 'assignsubmission')); ?>,
        graded: <?php echo json_encode(get_string('status_graded', 'assignsubmission')); ?>,
        error: <?php echo json_encode(get_string('status_error', 'assignsubmission')); ?>,
        deleteconfirm: <?php echo json_encode(get_string('deleteconfirm', 'assignsubmission')); ?>,
        nosubmissions: <?php echo json_encode(get_string('nosubmissions', 'assignsubmission')); ?>,
        image: <?php echo json_encode(get_string('imagepreview', 'assignsubmission')); ?>,
        studentname: <?php echo json_encode(get_string('studentname', 'assignsubmission')); ?>,
        mark: <?php echo json_encode(get_string('mark', 'assignsubmission')); ?>,
        feedback: <?php echo json_encode(get_string('feedback', 'assignsubmission')); ?>,
        status: <?php echo json_encode(get_string('status', 'assignsubmission')); ?>,
        actions: <?php echo json_encode(get_string('actions', 'assignsubmission')); ?>,
        deletesubmission: <?php echo json_encode(get_string('deletesubmission', 'assignsubmission')); ?>,
        ocrtext: <?php echo json_encode(get_string('ocrtext', 'assignsubmission')); ?>,
        editsubmission: <?php echo json_encode(get_string('editsubmission', 'assignsubmission')); ?>,
        descriptionsaved: <?php echo json_encode(get_string('descriptionsaved', 'assignsubmission')); ?>,
    };

    // ---- Upload handling ----
    if (canUpload) {
        const uploadZone = document.getElementById('upload-zone');
        const fileInput = document.getElementById('file-input');
        const uploadContent = document.getElementById('upload-content');
        const uploadProgress = document.getElementById('upload-progress');
        const progressFill = document.getElementById('progress-fill');
        const progressText = document.getElementById('progress-text');

        uploadZone.addEventListener('click', function(e) {
            if (e.target.closest('#upload-progress')) return;
            fileInput.click();
        });

        uploadZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            uploadZone.style.backgroundColor = '#e9ecef';
        });

        uploadZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            uploadZone.style.backgroundColor = '#f8f9fa';
        });

        uploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadZone.style.backgroundColor = '#f8f9fa';
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFiles(files);
            }
        });

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                handleFiles(this.files);
            }
        });

        async function handleFiles(files) {
            const imageFiles = Array.from(files).filter(f => f.type.startsWith('image/'));
            if (imageFiles.length === 0) return;

            uploadContent.style.display = 'none';
            uploadProgress.style.display = 'block';
            progressFill.style.width = '0%';

            const total = imageFiles.length;
            let completed = 0;

            for (let i = 0; i < imageFiles.length; i++) {
                const file = imageFiles[i];
                progressText.textContent = `Processing ${i + 1} of ${total}: ${file.name}`;

                try {
                    const formData = new FormData();
                    formData.append('file', file);
                    formData.append('cmid', cmid);
                    formData.append('instanceid', instanceId);
                    formData.append('sesskey', sesskey);

                    const response = await fetch(wwwroot + '/mod/assignsubmission/upload.php', {
                        method: 'POST',
                        body: formData,
                    });

                    const result = await response.json();
                    if (result.status === 'success') {
                        // Log OCR debug info to console.
                        if (result.data.ocr_debug) {
                            console.group('OCR Debug — ' + file.name);
                            result.data.ocr_debug.forEach(msg => console.log(msg));
                            console.groupEnd();
                        }
                        addRowToTable(result.data);
                    }
                } catch (err) {
                    console.error('Upload error:', err);
                }

                completed++;
                progressFill.style.width = ((completed / total) * 100) + '%';
            }

            progressText.textContent = `Done! ${completed} files processed.`;
            setTimeout(function() {
                uploadContent.style.display = '';
                uploadProgress.style.display = 'none';
                fileInput.value = '';
            }, 2000);
        }
    }

    // ---- Add new row to table ----
    function addRowToTable(sub) {
        const wrapper = document.getElementById('submissions-table-wrapper');
        const emptyState = document.getElementById('empty-state');

        // Remove empty state and create table if needed.
        if (emptyState) {
            emptyState.remove();
        }

        let table = document.getElementById('submissions-table');
        if (!table) {
            table = document.createElement('table');
            table.className = 'table table-striped table-hover table-bordered mb-0';
            table.id = 'submissions-table';
            table.innerHTML = `
                <thead class="thead-light"><tr>
                    <th style="width: 50px;">#</th>
                    <th style="width: 100px;">${strings.image}</th>
                    <th>${strings.studentname}</th>
                    <th style="width: 250px;">${strings.ocrtext}</th>
                    <th style="width: 100px;">${strings.mark}</th>
                    <th style="width: 300px;">${strings.feedback}</th>
                    <th style="width: 120px;">${strings.status}</th>
                    <th style="width: 180px;">${strings.actions}</th>
                </tr></thead>
                <tbody id="submissions-tbody"></tbody>
            `;
            wrapper.appendChild(table);
        }

        const tbody = document.getElementById('submissions-tbody');
        const rowCount = tbody.querySelectorAll('tr').length + 1;

        const tr = document.createElement('tr');
        tr.id = 'row-' + sub.id;
        tr.dataset.id = sub.id;

        const imgHtml = sub.fileurl
            ? `<a href="${sub.fileurl}" target="_blank"><img src="${sub.fileurl}" alt="Submission" class="img-thumbnail" style="max-width: 80px; max-height: 80px;"></a>`
            : '<span class="text-muted">—</span>';

        const diagnoseBtn = canGrade
            ? `<button class="btn btn-primary btn-sm btn-diagnose mb-1" data-id="${sub.id}">
                   ${strings.diagnose}
               </button>`
            : '';

        const ocrtextHtml = sub.ocrtext
            ? `<div style="max-height: 100px; overflow-y: auto; font-size: 0.85em;">${escapeHtml(sub.ocrtext).replace(/\n/g, '<br>')}</div>`
            : '<span class="text-muted">—</span>';

        tr.innerHTML = `
            <td>${rowCount}</td>
            <td>${imgHtml}</td>
            <td><strong>${escapeHtml(sub.studentname)}</strong></td>
            <td>${ocrtextHtml}</td>
            <td><span id="mark-${sub.id}"><span class="text-muted">—</span></span></td>
            <td><div id="feedback-${sub.id}" style="max-height: 100px; overflow-y: auto;"><span class="text-muted">—</span></div></td>
            <td><span class="badge badge-warning bg-warning text-dark" id="status-${sub.id}">${strings.pending}</span></td>
            <td>
                ${diagnoseBtn}
                ${canGrade ? `<button class="btn btn-outline-secondary btn-sm btn-edit mb-1" data-id="${sub.id}" data-name="${escapeHtml(sub.studentname)}" data-mark="" data-feedback="">${strings.editsubmission}</button>` : ''}
                <button class="btn btn-outline-danger btn-sm btn-delete mb-1" data-id="${sub.id}" title="${strings.deletesubmission}">
                    ${strings.deletesubmission}
                </button>
            </td>
        `;

        tbody.appendChild(tr);

        // Update count.
        const countEl = document.getElementById('submission-count');
        if (countEl) {
            countEl.textContent = tbody.querySelectorAll('tr').length;
        }

        // Show autograde button if not present.
        const autogradeBtn = document.getElementById('btn-autograde-all');
        if (!autogradeBtn && canGrade) {
            const cardHeader = document.querySelector('.card-header');
            if (cardHeader) {
                const div = document.createElement('div');
                div.innerHTML = `<button class="btn btn-warning btn-sm" id="btn-autograde-all">Auto-grade All</button>`;
                cardHeader.appendChild(div);
                bindAutogradeAll();
            }
        }
    }

    // ---- Individual Diagnose ----
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-diagnose');
        if (!btn || btn.disabled) return;

        const subId = btn.dataset.id;
        diagnoseSubmission(subId, btn);
    });

    async function diagnoseSubmission(subId, btn) {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `${strings.diagnosing}`;
        }

        // Update status badge.
        const statusEl = document.getElementById('status-' + subId);
        if (statusEl) {
            statusEl.className = 'badge badge-info bg-info text-white';
            statusEl.textContent = strings.processing;
        }

        try {
            const formData = new FormData();
            formData.append('subid', subId);
            formData.append('cmid', cmid);
            formData.append('sesskey', sesskey);
            formData.append('action', 'single');

            const response = await fetch(wwwroot + '/mod/assignsubmission/grade.php', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();

            if (result.status === 'success') {
                const data = result.data;

                // Update mark.
                const markEl = document.getElementById('mark-' + subId);
                if (markEl) {
                    markEl.innerHTML = `<strong>${data.mark}</strong> / ${maxMark}`;
                }

                // Update feedback.
                const feedbackEl = document.getElementById('feedback-' + subId);
                if (feedbackEl) {
                    feedbackEl.innerHTML = data.feedback;
                }

                // Update status.
                if (statusEl) {
                    statusEl.className = 'badge badge-success bg-success text-white';
                    statusEl.textContent = strings.graded;
                }

                // Remove diagnose button.
                if (btn) {
                    btn.remove();
                }
            } else {
                if (statusEl) {
                    statusEl.className = 'badge badge-danger bg-danger text-white';
                    statusEl.textContent = strings.error;
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = `${strings.diagnose}`;
                }
            }
        } catch (err) {
            console.error('Diagnose error:', err);
            if (statusEl) {
                statusEl.className = 'badge badge-danger bg-danger text-white';
                statusEl.textContent = strings.error;
            }
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `${strings.diagnose}`;
            }
        }
    }

    // ---- Auto-grade All ----
    function bindAutogradeAll() {
        const btn = document.getElementById('btn-autograde-all');
        if (!btn) return;

        btn.addEventListener('click', function() {
            document.getElementById('autograde-modal').style.display = 'flex';
        });
    }
    bindAutogradeAll();

    const autogradeCancelBtn = document.getElementById('autograde-cancel');
    if (autogradeCancelBtn) {
        autogradeCancelBtn.addEventListener('click', function() {
            document.getElementById('autograde-modal').style.display = 'none';
        });
    }

    const autogradeConfirmBtn = document.getElementById('autograde-confirm');
    if (autogradeConfirmBtn) {
        autogradeConfirmBtn.addEventListener('click', async function() {
            autogradeConfirmBtn.style.display = 'none';
            autogradeCancelBtn.style.display = 'none';

            const progressDiv = document.getElementById('autograde-progress');
            const progressFill = document.getElementById('autograde-progress-fill');
            const progressTextEl = document.getElementById('autograde-progress-text');
            progressDiv.style.display = 'block';

            // Gather all ungraded rows.
            const diagnoseBtns = document.querySelectorAll('.btn-diagnose');
            const total = diagnoseBtns.length;
            let done = 0;

            for (const dbtn of diagnoseBtns) {
                const subId = dbtn.dataset.id;
                progressTextEl.textContent = `Grading ${done + 1} of ${total}...`;

                await diagnoseSubmission(subId, dbtn);

                done++;
                progressFill.style.width = ((done / total) * 100) + '%';
            }

            progressTextEl.textContent = `Done! ${done} submissions graded.`;
            setTimeout(function() {
                document.getElementById('autograde-modal').style.display = 'none';
                // Reset modal state.
                autogradeConfirmBtn.style.display = '';
                autogradeCancelBtn.style.display = '';
                progressDiv.style.display = 'none';
                progressFill.style.width = '0%';
            }, 2000);
        });
    }

    // ---- Edit ----
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-edit');
        if (!btn) return;

        const subId = btn.dataset.id;
        document.getElementById('edit-subid').value = subId;
        document.getElementById('edit-studentname').value = btn.dataset.name || '';
        document.getElementById('edit-mark').value = btn.dataset.mark || '';
        document.getElementById('edit-feedback').value = btn.dataset.feedback || '';
        document.getElementById('edit-modal').style.display = 'flex';
    });

    document.getElementById('edit-cancel').addEventListener('click', function() {
        document.getElementById('edit-modal').style.display = 'none';
    });

    document.getElementById('edit-save').addEventListener('click', async function() {
        const subId = document.getElementById('edit-subid').value;
        const studentname = document.getElementById('edit-studentname').value;
        const mark = document.getElementById('edit-mark').value;
        const feedback = document.getElementById('edit-feedback').value;

        const saveBtn = document.getElementById('edit-save');
        saveBtn.disabled = true;
        saveBtn.textContent = '...';

        try {
            const formData = new FormData();
            formData.append('subid', subId);
            formData.append('cmid', cmid);
            formData.append('sesskey', sesskey);
            formData.append('action', 'edit');
            formData.append('studentname', studentname);
            if (mark !== '') {
                formData.append('mark', mark);
            }
            formData.append('feedback', feedback);

            const response = await fetch(wwwroot + '/mod/assignsubmission/grade.php', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();

            if (result.status === 'success') {
                const data = result.data;

                // Update student name in the row.
                const row = document.getElementById('row-' + subId);
                if (row) {
                    const nameTd = row.querySelectorAll('td')[2];
                    if (nameTd) {
                        nameTd.innerHTML = '<strong>' + escapeHtml(data.studentname) + '</strong>';
                    }
                }

                // Update mark.
                const markEl = document.getElementById('mark-' + subId);
                if (markEl) {
                    if (data.mark !== null && data.mark !== '') {
                        markEl.innerHTML = '<strong>' + data.mark + '</strong> / ' + maxMark;
                    }
                }

                // Update feedback.
                const feedbackEl = document.getElementById('feedback-' + subId);
                if (feedbackEl) {
                    if (data.feedback) {
                        feedbackEl.innerHTML = data.feedback;
                    }
                }

                // Update status if mark was set.
                if (data.mark !== null && data.mark !== '') {
                    const statusEl = document.getElementById('status-' + subId);
                    if (statusEl) {
                        statusEl.className = 'badge badge-success bg-success text-white';
                        statusEl.textContent = strings.graded;
                    }
                }

                // Update the edit button data attributes.
                const editBtn = row ? row.querySelector('.btn-edit') : null;
                if (editBtn) {
                    editBtn.dataset.name = data.studentname;
                    editBtn.dataset.mark = data.mark ?? '';
                    editBtn.dataset.feedback = data.feedback ?? '';
                }

                document.getElementById('edit-modal').style.display = 'none';
            }
        } catch (err) {
            console.error('Edit error:', err);
        }

        saveBtn.disabled = false;
        saveBtn.textContent = <?php echo json_encode(get_string('edit_save', 'assignsubmission')); ?>;
    });

    // ---- Delete ----
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-delete');
        if (!btn) return;

        if (!confirm(strings.deleteconfirm)) return;

        const subId = btn.dataset.id;

        const formData = new FormData();
        formData.append('subid', subId);
        formData.append('cmid', cmid);
        formData.append('sesskey', sesskey);
        formData.append('action', 'delete');

        fetch(wwwroot + '/mod/assignsubmission/grade.php', {
            method: 'POST',
            body: formData,
        }).then(r => r.json()).then(result => {
            if (result.status === 'success') {
                const row = document.getElementById('row-' + subId);
                if (row) {
                    row.remove();
                }
                // Update count.
                const tbody = document.getElementById('submissions-tbody');
                const countEl = document.getElementById('submission-count');
                if (tbody && countEl) {
                    countEl.textContent = tbody.querySelectorAll('tr').length;
                }
                // Renumber.
                if (tbody) {
                    tbody.querySelectorAll('tr').forEach((tr, i) => {
                        const numTd = tr.querySelector('td');
                        if (numTd) numTd.textContent = i + 1;
                    });
                }
            }
        }).catch(console.error);
    });

    // ---- Utility ----
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ---- Edit Description ----
    const editDescBtn = document.getElementById('btn-edit-description');
    if (editDescBtn) {
        editDescBtn.addEventListener('click', function() {
            document.getElementById('editdesc-modal').style.display = 'flex';
        });
    }

    const editdescCancel = document.getElementById('editdesc-cancel');
    if (editdescCancel) {
        editdescCancel.addEventListener('click', function() {
            document.getElementById('editdesc-modal').style.display = 'none';
        });
    }

    const editdescSave = document.getElementById('editdesc-save');
    if (editdescSave) {
        editdescSave.addEventListener('click', async function() {
            const questiontext = document.getElementById('editdesc-questiontext').value;
            const saveBtn = editdescSave;
            saveBtn.disabled = true;
            saveBtn.textContent = '...';

            try {
                const formData = new FormData();
                formData.append('cmid', cmid);
                formData.append('sesskey', sesskey);
                formData.append('action', 'editdescription');
                formData.append('questiontext', questiontext);

                const response = await fetch(wwwroot + '/mod/assignsubmission/grade.php', {
                    method: 'POST',
                    body: formData,
                });
                const result = await response.json();

                if (result.status === 'success') {
                    const descEl = document.getElementById('description-text');
                    if (descEl) {
                        if (questiontext.trim()) {
                            descEl.innerHTML = escapeHtml(questiontext);
                            descEl.classList.remove('font-italic');
                        } else {
                            descEl.innerHTML = '<em>' + escapeHtml(strings.descriptionsaved) + '</em>';
                            descEl.classList.add('font-italic');
                        }
                    }
                    document.getElementById('editdesc-modal').style.display = 'none';
                }
            } catch (err) {
                console.error('Edit description error:', err);
            }

            saveBtn.disabled = false;
            saveBtn.textContent = <?php echo json_encode(get_string('edit_save', 'assignsubmission')); ?>;
        });
    }
})();
</script>

<?php
echo $OUTPUT->footer();
