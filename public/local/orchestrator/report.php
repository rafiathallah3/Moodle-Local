<?php
/**
 * Orchestrator Log Report Viewer
 *
 * @package    local_orchestrator
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); // Only allow administrators to view AI logs

$url = new moodle_url('/local/orchestrator/report.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_orchestrator') . ' Logs');
$PAGE->set_heading('Orchestrator AI Evaluation Dashboard');

echo $OUTPUT->header();
echo $OUTPUT->heading('Orchestrator Debug Logs');

// Fetch the last 50 logs safely
global $DB;
$logs = $DB->get_records_sql("SELECT * FROM {local_orchestrator_log} ORDER BY timecreated DESC", [], 0, 50);

if (empty($logs)) {
    echo $OUTPUT->notification('No orchestrator logs found yet.', 'info');
    echo $OUTPUT->footer();
    exit;
}

echo '<style>
    .orch-log-view {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    .orch-log-view th, .orch-log-view td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    .orch-log-view th {
        background-color: #f2f2f2;
    }
    .orch-log-expandable {
        cursor: pointer;
        color: blue;
        text-decoration: underline;
    }
    .orch-log-details {
        display: none;
        background: #fafafa;
        padding: 10px;
        font-family: monospace;
        white-space: pre-wrap;
    }
</style>';

echo '<script>
function toggleLog(id) {
    var e = document.getElementById("log-details-" + id);
    if (e.style.display === "block") {
        e.style.display = "none";
    } else {
        e.style.display = "block";
    }
}
</script>';

echo '<table class="orch-log-view">
    <thead>
        <tr>
            <th>Run ID</th>
            <th>Date</th>
            <th>User ID</th>
            <th>Course ID</th>
            <th>Module</th>
            <th>Mode</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>';

foreach ($logs as $log) {
    $date = userdate($log->timecreated);
    $escaped_evidence = htmlspecialchars($log->input_evidence ?? 'NULL');
    $escaped_payload = htmlspecialchars($log->final_payload ?? 'NULL');
    $escaped_agents = htmlspecialchars($log->agents_called ?? 'NULL');
    $escaped_routing = htmlspecialchars($log->routing ?? 'NULL');

    echo "<tr>
        <td>{$log->run_id}</td>
        <td>{$date}</td>
        <td>{$log->userid}</td>
        <td>{$log->courseid}</td>
        <td>{$log->module} ({$log->instanceid})</td>
        <td><strong>{$log->mode}</strong></td>
        <td><a class='orch-log-expandable' onclick='toggleLog({$log->id})'>Expand Details</a></td>
    </tr>";

    echo "<tr id='log-details-{$log->id}' class='orch-log-details'>
        <td colspan='7'>
            <strong>Input Evidence Sent to AI:</strong><br>
            <pre>" . json_encode(json_decode(htmlspecialchars_decode($escaped_evidence)), JSON_PRETTY_PRINT) . "</pre>
            <br><strong>Routing Decisions:</strong><br>
            <pre>" . json_encode(json_decode(htmlspecialchars_decode($escaped_routing)), JSON_PRETTY_PRINT) . "</pre>
            <br><strong>Agents Triggered:</strong><br>
            <pre>" . json_encode(json_decode(htmlspecialchars_decode($escaped_agents)), JSON_PRETTY_PRINT) . "</pre>
            <br><strong>Final AI Payload Output:</strong><br>
            <pre>" . json_encode(json_decode(htmlspecialchars_decode($escaped_payload)), JSON_PRETTY_PRINT) . "</pre>
        </td>
    </tr>";
}

echo '</tbody></table>';

echo $OUTPUT->footer();
