<?php
/**
 * OCR Debug Probe
 *
 * Accessible only to site administrators.
 * Runs every component of the OCR pipeline inside the real Apache/PHP
 * environment so failures that only appear in the web context are visible.
 *
 * URL: https://moodle.test/local/ocr_debug.php
 *
 * @package    local_ocr
 */

require_once(__DIR__ . '/../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/ocr_debug.php'));
$PAGE->set_context($context);
$PAGE->set_title('OCR Debug Probe');
$PAGE->set_heading('OCR Debug Probe');
$PAGE->set_pagelayout('admin');

// ── Helpers ──────────────────────────────────────────────────────────────────

function probe_ok(string $label, string $detail = ''): void {
    echo '<tr><td class="label">✅ ' . htmlspecialchars($label) . '</td>'
       . '<td class="detail">' . nl2br(htmlspecialchars($detail)) . '</td></tr>';
}

function probe_warn(string $label, string $detail = ''): void {
    echo '<tr class="warn"><td class="label">⚠️ ' . htmlspecialchars($label) . '</td>'
       . '<td class="detail">' . nl2br(htmlspecialchars($detail)) . '</td></tr>';
}

function probe_fail(string $label, string $detail = ''): void {
    echo '<tr class="fail"><td class="label">❌ ' . htmlspecialchars($label) . '</td>'
       . '<td class="detail">' . nl2br(htmlspecialchars($detail)) . '</td></tr>';
}

function probe_info(string $label, string $detail = ''): void {
    echo '<tr class="info"><td class="label">ℹ️ ' . htmlspecialchars($label) . '</td>'
       . '<td class="detail">' . nl2br(htmlspecialchars($detail)) . '</td></tr>';
}

/**
 * Run a shell command with a hard timeout using proc_open so we never hang.
 * Returns [stdout+stderr output string, exit_code].
 */
function safe_exec(string $command, int $timeout_sec = 30): array {
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $process = proc_open($command, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        return ['proc_open() failed — could not start process.', -1];
    }

    fclose($pipes[0]); // no stdin needed

    // Set non-blocking so we can poll with a timeout.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output   = '';
    $deadline = time() + $timeout_sec;

    while (time() < $deadline) {
        $out = fread($pipes[1], 8192);
        $err = fread($pipes[2], 8192);
        if ($out !== false) { $output .= $out; }
        if ($err !== false) { $output .= $err; }

        $status = proc_get_status($process);
        if (!$status['running']) {
            // Drain remaining output.
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            break;
        }
        usleep(100000); // 100 ms polling interval
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $status   = proc_get_status($process);
    $exitcode = $status['exitcode'] ?? -1;
    proc_close($process);

    if (time() >= $deadline) {
        return [$output . "\n[TIMED OUT after {$timeout_sec}s]", -2];
    }

    return [$output, $exitcode];
}

// ── Resolve base path (handles both /public and flat layouts) ────────────────
$base_dir = $CFG->dirroot;
if (basename($base_dir) === 'public') {
    $base_dir = dirname($base_dir);
}
$ocr_script   = $base_dir . '/admin/cli/ocr.py';
$env_file     = $base_dir . '/.env';
$test_image   = $CFG->dirroot . '/pix/moodlelogo.png';

// ── Build the page ────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading('OCR Pipeline Debug Probe', 2);

echo '<style>
body { font-size: 0.93em; }
.probe-table { width:100%; border-collapse:collapse; margin-bottom:28px; }
.probe-table th { background:#2c3e50; color:#fff; padding:8px 12px; text-align:left; }
.probe-table td { padding:8px 12px; border-bottom:1px solid #dee2e6; vertical-align:top; }
.probe-table td.label { white-space:nowrap; width:280px; font-weight:600; }
.probe-table td.detail { font-family:monospace; font-size:0.88em; word-break:break-all; }
.probe-table tr.warn td { background:#fff8e1; }
.probe-table tr.fail td { background:#fdecea; }
.probe-table tr.info td { background:#e8f4f8; }
.section-heading { margin:24px 0 6px; font-weight:700; font-size:1.05em;
    padding:6px 12px; background:#f2f4f6; border-left:4px solid #2c3e50; }
pre.raw { background:#1e1e1e; color:#d4d4d4; padding:14px; border-radius:6px;
    overflow-x:auto; white-space:pre-wrap; word-break:break-all; font-size:0.85em; margin:0; }
</style>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 1 – PHP Environment
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">1 · PHP Environment</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

probe_info('PHP version',         PHP_VERSION);
probe_info('PHP SAPI',            PHP_SAPI);
probe_info('CFG->dirroot',        $CFG->dirroot);
probe_info('Resolved base_dir',   $base_dir);
probe_info('max_execution_time',  ini_get('max_execution_time') . 's');
probe_info('memory_limit',        ini_get('memory_limit'));

$disabled = ini_get('disable_functions');
if (strpos($disabled, 'shell_exec') !== false || strpos($disabled, 'proc_open') !== false) {
    probe_fail('disable_functions', $disabled ?: '(none)');
} else {
    probe_ok('disable_functions', $disabled ?: '(none — shell_exec and proc_open are available)');
}

// Test shell_exec with a trivial echo
$echo_out = shell_exec('echo probe_ok 2>&1');
if ($echo_out && strpos(trim($echo_out), 'probe_ok') !== false) {
    probe_ok('shell_exec("echo probe_ok")', trim($echo_out));
} else {
    probe_fail('shell_exec("echo probe_ok")', var_export($echo_out, true));
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 2 – Python Executable
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">2 · Python Executable</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

$python_cmd = null;
foreach (['python', 'python3', 'py'] as $candidate) {
    [$ver_out, $ver_code] = safe_exec($candidate . ' --version 2>&1', 5);
    $ver_out = trim($ver_out);
    if ($ver_code === 0 && stripos($ver_out, 'python') !== false) {
        $python_cmd = $candidate;
        probe_ok("Executable: {$candidate}", $ver_out);
        break;
    } else {
        probe_warn("Executable: {$candidate}", $ver_out ?: "(no output, exit {$ver_code})");
    }
}

if ($python_cmd === null) {
    probe_fail('Python NOT found', 'None of python / python3 / py produced a valid version string when called from Apache PHP. Add Python to the system PATH or configure the full executable path.');
} else {
    // Show which Python is actually used
    [$which_out] = safe_exec(($python_cmd === 'py' ? 'py -c' : $python_cmd . ' -c') . ' "import sys; print(sys.executable)" 2>&1', 5);
    probe_info('sys.executable', trim($which_out));

    // Check PATH seen by Apache PHP
    [$path_out] = safe_exec($python_cmd . ' -c "import os; print(os.environ.get(\'PATH\',\'(not set)\'))" 2>&1', 5);
    probe_info('PATH inside Python process', trim($path_out));
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 3 – .env File & API Keys
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">3 · .env File &amp; API Keys</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

probe_info('Expected .env path', $env_file);

if (!file_exists($env_file)) {
    probe_fail('.env file', 'NOT FOUND at ' . $env_file);
} else {
    probe_ok('.env file exists', 'Found at ' . $env_file);

    // Parse it manually (same logic as Python load_env)
    $gemini_key = '';
    $openai_key = '';
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#') { continue; }
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim(trim($v), "'\"");
            if ($k === 'GEMINI_API') { $gemini_key = $v; }
            if ($k === 'OPENAI_API') { $openai_key = $v; }
        }
    }

    if ($gemini_key) {
        $masked = substr($gemini_key, 0, 6) . str_repeat('*', max(0, strlen($gemini_key) - 10)) . substr($gemini_key, -4);
        probe_ok('GEMINI_API', "Set — length " . strlen($gemini_key) . " — {$masked}");
    } else {
        probe_fail('GEMINI_API', 'NOT SET in .env — Gemini OCR will fail');
    }

    if ($openai_key) {
        $masked = substr($openai_key, 0, 6) . str_repeat('*', max(0, strlen($openai_key) - 10)) . substr($openai_key, -4);
        probe_ok('OPENAI_API', "Set — length " . strlen($openai_key) . " — {$masked}");
    } else {
        probe_info('OPENAI_API', 'Not set (only required when model=openai)');
    }
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 4 – OCR Script File
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">4 · OCR Script</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

probe_info('Expected script path', $ocr_script);

if (!file_exists($ocr_script)) {
    probe_fail('ocr.py exists', 'NOT FOUND at ' . $ocr_script);
} else {
    probe_ok('ocr.py exists', $ocr_script);
    probe_info('ocr.py size', number_format(filesize($ocr_script)) . ' bytes');

    if ($python_cmd !== null) {
        // Syntax check
        [$syn_out, $syn_code] = safe_exec($python_cmd . ' -m py_compile ' . escapeshellarg($ocr_script) . ' 2>&1', 5);
        if ($syn_code === 0) {
            probe_ok('ocr.py syntax check', 'No syntax errors');
        } else {
            probe_fail('ocr.py syntax check', $syn_out);
        }
    }
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 5 – Temp Directory & File Copy
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">5 · Temp Directory &amp; File Copy</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

$tempdir = sys_get_temp_dir();
probe_info('sys_get_temp_dir()', $tempdir);

$tempfile = $tempdir . DIRECTORY_SEPARATOR . 'ocr_probe_' . uniqid() . '.png';
if (file_exists($test_image)) {
    probe_ok('Test image exists', $test_image . ' (' . number_format(filesize($test_image)) . ' bytes)');

    if (@copy($test_image, $tempfile)) {
        probe_ok('copy() to temp', $tempfile . ' (' . number_format(filesize($tempfile)) . ' bytes)');
    } else {
        probe_fail('copy() to temp', 'Could not copy to ' . $tempfile . ' — check temp dir permissions');
    }
} else {
    probe_warn('Test image', $test_image . ' NOT found — using a generated PNG instead');

    // Generate a tiny 1x1 red PNG in memory (no GD needed, raw bytes)
    $tiny_png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8' .
        'z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
    );
    file_put_contents($tempfile, $tiny_png);
    if (file_exists($tempfile)) {
        probe_ok('Generated fallback test image', $tempfile . ' (' . filesize($tempfile) . ' bytes)');
    } else {
        probe_fail('Generated fallback test image', 'Could not write to ' . $tempfile);
    }
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 6 – Live OCR Test (the real call)
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">6 · Live OCR Test (Gemini API via proc_open with 45 s timeout)</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

if ($python_cmd === null) {
    probe_fail('Live OCR test', 'Skipped — Python executable not found (see Section 2)');
} elseif (!file_exists($ocr_script)) {
    probe_fail('Live OCR test', 'Skipped — ocr.py not found (see Section 4)');
} elseif (!file_exists($tempfile)) {
    probe_fail('Live OCR test', 'Skipped — no test image available (see Section 5)');
} else {
    $cmd = $python_cmd . ' '
        . escapeshellarg($ocr_script)
        . ' --image ' . escapeshellarg($tempfile)
        . ' --model gemini'
        . ' 2>&1';

    probe_info('Command to run', $cmd);

    $t_start = microtime(true);
    [$raw, $exit_code] = safe_exec($cmd, 45);
    $elapsed = round(microtime(true) - $t_start, 2);

    probe_info('Exit code',    (string)$exit_code);
    probe_info('Elapsed',      "{$elapsed}s");
    probe_info('Raw output',   $raw ?: '(empty)');

    if ($exit_code === -2) {
        probe_fail('Live OCR result', "TIMED OUT after 45 s — the Gemini API call is hanging.\n\n"
            . "Partial output so far:\n" . ($raw ?: '(none)'));
    } elseif ($exit_code !== 0) {
        probe_fail('Live OCR result', "Script exited with code {$exit_code}.\nOutput:\n" . $raw);
    } else {
        $decoded = json_decode(trim($raw), true);
        if (!$decoded || !isset($decoded['status'])) {
            probe_fail('Live OCR result', "Could not parse JSON output:\n" . $raw);
        } elseif ($decoded['status'] === 'success') {
            probe_ok('Live OCR result — SUCCESS',
                "Extracted text: " . ($decoded['text'] ?? '(empty)') . "\n"
              . "Elapsed (Python-side): " . ($decoded['elapsed_time'] ?? '?') . "s");
        } else {
            probe_fail('Live OCR result — error from script',
                $decoded['message'] ?? $raw);
        }
    }
}

// Clean up temp file
if (file_exists($tempfile)) { @unlink($tempfile); }

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 7 – DB Table Check
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">7 · Database Table (local_ocr_results)</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

$dbman = $DB->get_manager();
if ($dbman->table_exists('local_ocr_results')) {
    $count = $DB->count_records('local_ocr_results');
    probe_ok('Table local_ocr_results', "Exists — {$count} row(s) stored");
} else {
    probe_warn(
        'Table local_ocr_results does NOT exist',
        "The local_ocr plugin has not been installed yet.\n"
      . "Go to Site Administration → Notifications to install it.\n"
      . "OCR still works without the table (results won't be cached)."
    );
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 8 – Network connectivity to Gemini API
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">8 · Network Connectivity to Gemini API</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

if ($python_cmd !== null) {
    $net_test = <<<'PYCODE'
import urllib.request, ssl, json
try:
    ctx = ssl.create_default_context()
    req = urllib.request.Request("https://generativelanguage.googleapis.com/", headers={"User-Agent":"probe"})
    with urllib.request.urlopen(req, context=ctx, timeout=10) as r:
        print(json.dumps({"status":"ok","http":r.status}))
except Exception as e:
    print(json.dumps({"status":"error","message":str(e)}))
PYCODE;

    $net_cmd = $python_cmd . ' -c ' . escapeshellarg($net_test) . ' 2>&1';
    [$net_out, $net_exit] = safe_exec($net_cmd, 15);
    $net_out = trim($net_out);
    $net_json = json_decode($net_out, true);

    if ($net_json && $net_json['status'] === 'ok') {
        probe_ok('Gemini API reachable', 'HTTP ' . ($net_json['http'] ?? '?'));
    } elseif ($net_json && $net_json['status'] === 'error') {
        // 404 from Google is actually fine — it means we reached the server
        $msg = $net_json['message'] ?? '';
        if (strpos($msg, 'HTTP Error 404') !== false || strpos($msg, '404') !== false) {
            probe_ok('Gemini API reachable', 'Got 404 from Google (expected for root URL — connectivity is fine)');
        } else {
            probe_fail('Gemini API NOT reachable', $msg . "\n\nCheck firewall / proxy settings or SSL certificate store.");
        }
    } else {
        probe_warn('Gemini API connectivity unknown', $net_out ?: '(no output)');
    }

    // SSL context check
    $ssl_test = <<<'PYCODE'
import ssl, json
ctx = ssl.create_default_context()
print(json.dumps({"cafile": ctx.check_hostname, "verify_mode": str(ctx.verify_mode)}))
PYCODE;
    [$ssl_out] = safe_exec($python_cmd . ' -c ' . escapeshellarg($ssl_test) . ' 2>&1', 5);
    probe_info('Python SSL default context', trim($ssl_out));

} else {
    probe_warn('Network test', 'Skipped — Python not found');
}

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// SECTION 9 – ocr_ajax.php Endpoint Self-Test
// ═════════════════════════════════════════════════════════════════════════════
echo '<div class="section-heading">9 · ocr_ajax.php Endpoint Check</div>';
echo '<table class="probe-table"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

$ajax_path = $CFG->dirroot . '/../local/ocr_ajax.php';
if (!file_exists($ajax_path)) {
    // Try alternate path
    $ajax_path = dirname($CFG->dirroot) . '/local/ocr_ajax.php';
}
if (!file_exists($ajax_path)) {
    $ajax_path = $CFG->dirroot . '/local/ocr_ajax.php';
}

if (file_exists($ajax_path)) {
    probe_ok('ocr_ajax.php exists', $ajax_path);
} else {
    probe_fail('ocr_ajax.php NOT found', 'Tried: ' . $CFG->wwwroot . '/local/ocr_ajax.php');
}

probe_info('AJAX URL', $CFG->wwwroot . '/local/ocr_ajax.php');
probe_info(
    'How to test manually',
    "Open browser DevTools → Network tab, then visit a quiz review page with an image attachment.\n"
  . "Look for a POST to /local/ocr_ajax.php and check its Response tab for errors."
);

echo '</tbody></table>';

// ═════════════════════════════════════════════════════════════════════════════
// FOOTER
// ═════════════════════════════════════════════════════════════════════════════
echo '<p style="margin-top:20px;color:#666;font-size:0.85em;">'
   . 'Debug probe completed at ' . userdate(time()) . '. '
   . 'Delete or restrict access to this file once debugging is complete.'
   . '</p>';

echo $OUTPUT->footer();
