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
 * Admin report page for local_ocr.
 *
 * Displays a paginated, filterable table of every OCR result stored in the
 * local_ocr_results database table.  Each row shows the submission context,
 * the image filename, the AI model used, when it was processed, and a
 * toggle to reveal the full extracted text without cluttering the table.
 *
 * Access is restricted to users with moodle/site:config (administrators).
 *
 * @package    local_ocr
 * @copyright  2026 Moodle OCR Plugin
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

// ── Pagination & filter parameters ──────────────────────────────────────────
$page       = optional_param('page',      0,  PARAM_INT);
$perpage    = optional_param('perpage',   25, PARAM_INT);
$filtercomp = optional_param('component', '', PARAM_COMPONENT);
$deleteall  = optional_param('deleteall', 0,  PARAM_INT);

// Clamp perpage to a sensible range.
$perpage = max(10, min(100, $perpage));

// ── Handle "Delete all results" action ──────────────────────────────────────
if ($deleteall && confirm_sesskey()) {
    $DB->delete_records('local_ocr_results');
    redirect(
        new moodle_url('/local/ocr/report.php'),
        get_string('noresults', 'local_ocr'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Page setup ───────────────────────────────────────────────────────────────
$url = new moodle_url('/local/ocr/report.php', [
    'page'      => $page,
    'perpage'   => $perpage,
    'component' => $filtercomp,
]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('ocrreport', 'local_ocr'));
$PAGE->set_heading(get_string('pluginname', 'local_ocr') . ' — ' . get_string('ocrreport', 'local_ocr'));
$PAGE->set_pagelayout('admin');

// ── Build the SQL query ──────────────────────────────────────────────────────
$where  = '';
$params = [];

if ($filtercomp !== '') {
    $where    = 'WHERE component = :component';
    $params[] = ['component' => $filtercomp];
    // Rebuild params as a flat array for get_records_sql.
    $params = ['component' => $filtercomp];
}

$total = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_ocr_results} $where",
    $params
);

$logs = $DB->get_records_sql(
    "SELECT * FROM {local_ocr_results} $where ORDER BY timecreated DESC",
    $params,
    $page * $perpage,
    $perpage
);

// ── Collect distinct components for the filter drop-down ─────────────────────
$components = $DB->get_fieldset_sql(
    "SELECT DISTINCT component FROM {local_ocr_results} ORDER BY component"
);

// ── Output ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ocrreport', 'local_ocr'), 2);

// ── Inline styles ─────────────────────────────────────────────────────────────
echo '<style>
.ocr-report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92em;
}
.ocr-report-table th,
.ocr-report-table td {
    border: 1px solid #dee2e6;
    padding: 8px 10px;
    vertical-align: top;
    text-align: left;
}
.ocr-report-table th {
    background: #f2f4f6;
    font-weight: 600;
    white-space: nowrap;
}
.ocr-report-table tr:hover td {
    background: #f9fbfc;
}
.ocr-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.8em;
    font-weight: 600;
    white-space: nowrap;
}
.ocr-badge-gemini  { background: #d6eaf8; color: #1a5276; border: 1px solid #aed6f1; }
.ocr-badge-openai  { background: #d5f5e3; color: #1e8449; border: 1px solid #a9dfbf; }
.ocr-badge-unknown { background: #f0f0f0; color: #555;    border: 1px solid #ccc; }
.ocr-text-toggle {
    cursor: pointer;
    color: #1a5276;
    text-decoration: underline;
    font-size: 0.88em;
    background: none;
    border: none;
    padding: 0;
}
.ocr-text-toggle:hover { color: #117a8b; }
.ocr-extracted-text {
    display: none;
    margin-top: 8px;
    padding: 8px 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: monospace;
    font-size: 0.9em;
    max-height: 300px;
    overflow-y: auto;
}
.ocr-filter-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    padding: 10px 14px;
    background: #f2f4f6;
    border: 1px solid #dee2e6;
    border-radius: 6px;
}
.ocr-filter-bar label { font-weight: 600; margin: 0; }
.ocr-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}
.ocr-stat-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    background: #eaf4fb;
    border: 1px solid #aed6f1;
    color: #1a5276;
    font-size: 0.88em;
    font-weight: 600;
}
</style>';

// ── JS for text toggles ───────────────────────────────────────────────────────
echo '<script>
function ocrToggleText(id) {
    var el = document.getElementById("ocr-text-" + id);
    if (!el) return;
    var btn = document.getElementById("ocr-btn-" + id);
    if (el.style.display === "block") {
        el.style.display = "none";
        if (btn) btn.textContent = "Show extracted text";
    } else {
        el.style.display = "block";
        if (btn) btn.textContent = "Hide extracted text";
    }
}
</script>';

if (empty($logs)) {
    echo $OUTPUT->notification(get_string('noresults', 'local_ocr'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// ── Actions bar ───────────────────────────────────────────────────────────────
echo '<div class="ocr-actions-bar">';
echo '<span class="ocr-stat-pill">' . $total . ' total result' . ($total !== 1 ? 's' : '') . '</span>';

// Delete-all button (with confirmation).
$delete_url = new moodle_url('/local/ocr/report.php', [
    'deleteall' => 1,
    'sesskey'   => sesskey(),
]);
echo html_writer::link(
    $delete_url,
    '🗑 Delete all OCR results',
    [
        'class'   => 'btn btn-sm btn-outline-danger',
        'onclick' => 'return confirm("Delete ALL OCR results? This cannot be undone.");',
    ]
);
echo '</div>';

// ── Filter bar ────────────────────────────────────────────────────────────────
if (!empty($components)) {
    echo '<div class="ocr-filter-bar">';
    echo '<label for="ocr-filter-component">Filter by component:</label>';

    $filter_options = ['' => '— All components —'];
    foreach ($components as $comp) {
        $filter_options[$comp] = $comp;
    }

    echo html_writer::select(
        $filter_options,
        'component',
        $filtercomp,
        false,
        [
            'id'       => 'ocr-filter-component',
            'class'    => 'form-control form-control-sm',
            'style'    => 'width:auto;',
            'onchange' => "window.location='" . (new moodle_url('/local/ocr/report.php',
                ['page' => 0, 'perpage' => $perpage]))->out(false) . "&component='+encodeURIComponent(this.value)",
        ]
    );

    if ($filtercomp !== '') {
        echo html_writer::link(
            new moodle_url('/local/ocr/report.php', ['page' => 0, 'perpage' => $perpage]),
            '✕ Clear filter',
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
    }

    echo '</div>';
}

// ── Results table ─────────────────────────────────────────────────────────────
echo '<div class="table-responsive">';
echo '<table class="ocr-report-table">';
echo '<thead><tr>';
echo '<th>#</th>';
echo '<th>Filename</th>';
echo '<th>Component</th>';
echo '<th>File Area</th>';
echo '<th>Item ID</th>';
echo '<th>Context ID</th>';
echo '<th>Model</th>';
echo '<th>Processed At</th>';
echo '<th>Extracted Text</th>';
echo '</tr></thead>';
echo '<tbody>';

$row_num = ($page * $perpage) + 1;

foreach ($logs as $log) {

    // ── Model badge ───────────────────────────────────────────────────────────
    $model       = htmlspecialchars($log->model ?? 'unknown');
    $badge_class = 'ocr-badge-unknown';
    if ($model === 'gemini') {
        $badge_class = 'ocr-badge-gemini';
    } elseif ($model === 'openai') {
        $badge_class = 'ocr-badge-openai';
    }
    $model_badge = '<span class="ocr-badge ' . $badge_class . '">' . $model . '</span>';

    // ── OCR text cell ─────────────────────────────────────────────────────────
    $raw_text = $log->ocr_text ?? '';

    if ($raw_text === '') {
        $text_cell = '<em style="color:#999;">empty</em>';
    } else {
        $is_not_found = (trim($raw_text) === 'Text not found inside the image.');
        $preview      = htmlspecialchars(mb_substr($raw_text, 0, 80));
        if (mb_strlen($raw_text) > 80) {
            $preview .= '…';
        }

        $full_text = htmlspecialchars($raw_text);

        if ($is_not_found) {
            $text_cell = '<em style="color:#888;">' . $full_text . '</em>';
        } else {
            $text_cell = '<span style="color:#555;font-size:0.88em;">' . $preview . '</span><br>'
                . '<button id="ocr-btn-' . $log->id . '" class="ocr-text-toggle" '
                . 'onclick="ocrToggleText(' . $log->id . ')">Show extracted text</button>'
                . '<pre id="ocr-text-' . $log->id . '" class="ocr-extracted-text">'
                . $full_text
                . '</pre>';
        }
    }

    // ── Render the row ────────────────────────────────────────────────────────
    $date_str  = userdate($log->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    $filename  = htmlspecialchars($log->filename ?? '');
    $component = htmlspecialchars($log->component ?? '');
    $filearea  = htmlspecialchars($log->filearea ?? '');

    echo "<tr>
        <td style='color:#999;font-size:0.85em;'>{$row_num}</td>
        <td><strong>{$filename}</strong></td>
        <td><code style='font-size:0.85em;'>{$component}</code></td>
        <td><code style='font-size:0.85em;'>{$filearea}</code></td>
        <td>{$log->itemid}</td>
        <td style='color:#999;font-size:0.85em;'>{$log->contextid}</td>
        <td>{$model_badge}</td>
        <td style='white-space:nowrap;font-size:0.88em;'>{$date_str}</td>
        <td>{$text_cell}</td>
    </tr>";

    $row_num++;
}

echo '</tbody></table>';
echo '</div>'; // .table-responsive

// ── Pagination bar ────────────────────────────────────────────────────────────
$pagination_url = new moodle_url('/local/ocr/report.php', [
    'perpage'   => $perpage,
    'component' => $filtercomp,
]);

echo $OUTPUT->paging_bar($total, $page, $perpage, $pagination_url);

// ── Per-page selector ─────────────────────────────────────────────────────────
echo '<div style="margin-top:12px;font-size:0.88em;color:#555;">';
echo 'Results per page: ';
foreach ([10, 25, 50, 100] as $pp) {
    $pp_url = new moodle_url('/local/ocr/report.php', [
        'page'      => 0,
        'perpage'   => $pp,
        'component' => $filtercomp,
    ]);
    if ($pp === $perpage) {
        echo '<strong style="margin:0 4px;">' . $pp . '</strong>';
    } else {
        echo html_writer::link($pp_url, $pp, ['style' => 'margin:0 4px;']);
    }
}
echo '</div>';

echo $OUTPUT->footer();
