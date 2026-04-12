<?php
/**
 * Practice Hub — AFS v2 Practice Pipeline UI
 *
 * A standalone Moodle page that lets students practice a chosen topic through
 * a 3-stage AI-driven pipeline:
 *   Stage 0: Generate a personalized problem.
 *   Stage 1: Answer a conceptual "Why/How" question.
 *   Stage 2: Answer an application/transfer question.
 *   Result:  Get diagnostic feedback + a recommendation.
 *
 * URL: /local/practice/index.php?courseid=<id>
 *
 * @package    local_practice
 */

require_once(__DIR__ . '/../../public/config.php');

require_login();

$courseid = optional_param('courseid', 0, PARAM_INT);

// Load course if given
$course = null;
$kc_topics = [];
$course_name = 'CS101';

if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if ($course) {
        $coursecontext = context_course::instance($courseid);
        require_capability('local/practice:view', $coursecontext);
        $course_name = $course->shortname ?: 'CS101';
    }
}

// Predefined KC topics per course — in the future this can query the Python registry
$kc_map = [
    'CS101' => ['variables', 'loops', 'conditionals', 'functions', 'recursion'],
    'CS202' => ['sql_basics', 'joins', 'normalization', 'indexing', 'transactions'],
];

// Match nearest course name
foreach ($kc_map as $cid => $topics) {
    if (stripos($course_name, $cid) !== false || strtoupper($course_name) === $cid) {
        $kc_topics = $topics;
        break;
    }
}
if (empty($kc_topics)) {
    $kc_topics = array_values($kc_map)[0]; // default to CS101
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/practice/index.php', ['courseid' => $courseid]));
$PAGE->set_title('Practice Hub — AI Tutor');
$PAGE->set_heading('🧠 Practice Hub');
$PAGE->requires->js_init_code("window.M_COURSEID = " . (int)$courseid . ";");
$PAGE->requires->js_init_code("window.M_SESSKEY = '" . sesskey() . "';");

echo $OUTPUT->header();
?>

<style>
/* ─── Google Font ─────────────────────────────────────────────────────── */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');

/* ─── Design Tokens ───────────────────────────────────────────────────── */
:root {
    --bg:         #0d1117;
    --surface:    #161b22;
    --card:       #1c2230;
    --border:     #30363d;
    --accent:     #58a6ff;
    --accent2:    #a371f7;
    --success:    #3fb950;
    --warning:    #d29922;
    --danger:     #f85149;
    --text:       #e6edf3;
    --muted:      #8b949e;
    --radius:     14px;
    --shadow:     0 8px 32px rgba(0,0,0,.45);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); }

/* ─── Layout ──────────────────────────────────────────────────────────── */
#practice-root {
    max-width: 860px;
    margin: 2rem auto;
    padding: 0 1rem 4rem;
}

/* ─── Header ──────────────────────────────────────────────────────────── */
.ph-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}
.ph-header-icon {
    width: 56px; height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem;
    flex-shrink: 0;
    box-shadow: 0 4px 20px rgba(88,166,255,.35);
}
.ph-header h1 {
    font-size: 1.7rem; font-weight: 700;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.ph-header p { color: var(--muted); font-size: .9rem; margin-top: .2rem; }

/* ─── Cards ───────────────────────────────────────────────────────────── */
.ph-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    margin-bottom: 1.25rem;
    box-shadow: var(--shadow);
    transition: transform .2s ease;
}
.ph-card:hover { transform: translateY(-2px); }
.ph-card-title {
    font-size: .75rem; font-weight: 600; letter-spacing: .08em;
    text-transform: uppercase; color: var(--accent); margin-bottom: .9rem;
    display: flex; align-items: center; gap: .5rem;
}

/* ─── Topic Selector ──────────────────────────────────────────────────── */
.topic-grid {
    display: flex; flex-wrap: wrap; gap: .6rem;
    margin-bottom: 1rem;
}
.topic-chip {
    padding: .45rem 1rem; border-radius: 999px; font-size: .85rem; font-weight: 500;
    cursor: pointer; border: 2px solid var(--border);
    background: var(--surface); color: var(--muted);
    transition: all .2s;
    user-select: none;
}
.topic-chip:hover { border-color: var(--accent); color: var(--accent); }
.topic-chip.selected {
    border-color: var(--accent); background: rgba(88,166,255,.12);
    color: var(--accent);
    box-shadow: 0 0 0 3px rgba(88,166,255,.15);
}

/* ─── Problem Display ─────────────────────────────────────────────────── */
.problem-text {
    line-height: 1.7; color: var(--text); font-size: .95rem;
    white-space: pre-wrap;
}
.skeleton-block {
    background: #0d1117;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: .85rem;
    color: #79c0ff;
    white-space: pre-wrap;
    overflow-x: auto;
    margin-top: .75rem;
}

/* ─── Quiz Stage Badge ────────────────────────────────────────────────── */
.stage-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .25rem .75rem; border-radius: 999px; font-size: .75rem; font-weight: 600;
    background: rgba(163,113,247,.15); color: var(--accent2); border: 1px solid rgba(163,113,247,.3);
    margin-bottom: .85rem;
}

/* ─── Textarea ────────────────────────────────────────────────────────── */
.ph-textarea {
    width: 100%; min-height: 110px;
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: 10px; padding: .85rem 1rem;
    font-family: 'Inter', sans-serif; font-size: .9rem; color: var(--text);
    resize: vertical; line-height: 1.6;
    transition: border-color .2s, box-shadow .2s;
}
.ph-textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(88,166,255,.15); }
.ph-textarea::placeholder { color: var(--muted); }

/* ─── Buttons ─────────────────────────────────────────────────────────── */
.btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .7rem 1.5rem; border-radius: 10px;
    font-family: 'Inter', sans-serif; font-size: .9rem; font-weight: 600;
    cursor: pointer; border: none; transition: all .2s;
}
.btn-primary {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff;
    box-shadow: 0 4px 15px rgba(88,166,255,.3);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(88,166,255,.4); }
.btn-primary:active { transform: translateY(0); }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.btn-ghost {
    background: transparent; color: var(--muted); border: 1.5px solid var(--border);
}
.btn-ghost:hover { border-color: var(--accent); color: var(--accent); }

/* ─── Feedback Panel ──────────────────────────────────────────────────── */
.feedback-panel {
    border-radius: 10px; padding: 1rem 1.25rem;
    font-size: .9rem; line-height: 1.65;
    border-left: 4px solid;
    margin-top: .9rem;
}
.feedback-pass  { background: rgba(63,185,80,.1);  border-color: var(--success); color: #7ee787; }
.feedback-fail  { background: rgba(248,81,73,.1);   border-color: var(--danger);  color: #ffa198; }
.feedback-warn  { background: rgba(210,153,34,.1);  border-color: var(--warning); color: #e3b341; }

/* ─── Score Ring ──────────────────────────────────────────────────────── */
.score-row { display: flex; align-items: center; gap: 1rem; margin: .75rem 0; }
.score-pill {
    font-size: 1.25rem; font-weight: 700;
    padding: .3rem .85rem; border-radius: 999px;
}
.score-high  { background: rgba(63,185,80,.15);  color: var(--success); }
.score-mid   { background: rgba(210,153,34,.15); color: var(--warning); }
.score-low   { background: rgba(248,81,73,.15);  color: var(--danger);  }

/* ─── Progress Stepper ────────────────────────────────────────────────── */
.stepper { display: flex; align-items: center; gap: 0; margin-bottom: 1.75rem; }
.step-item { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
.step-item:not(:last-child)::after {
    content: ''; position: absolute; top: 16px; left: 50%; width: 100%;
    height: 2px; background: var(--border); z-index: 0;
}
.step-item.done::after,
.step-item.active::after { background: var(--accent); }
.step-dot {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; z-index: 1;
    border: 2px solid var(--border); background: var(--surface); color: var(--muted);
    transition: all .3s;
}
.step-item.done .step-dot  { background: var(--success); border-color: var(--success); color: #fff; }
.step-item.active .step-dot{ background: var(--accent);  border-color: var(--accent);  color: #fff; box-shadow: 0 0 0 4px rgba(88,166,255,.2); }
.step-label { font-size: .72rem; color: var(--muted); margin-top: .35rem; font-weight: 500; }
.step-item.active .step-label { color: var(--accent); }
.step-item.done .step-label  { color: var(--success); }

/* ─── Final Result Card ───────────────────────────────────────────────── */
.result-card {
    text-align: center; padding: 2rem 1.5rem;
}
.result-emoji { font-size: 3rem; margin-bottom: .75rem; }
.result-title { font-size: 1.3rem; font-weight: 700; }
.result-subtitle { color: var(--muted); font-size: .9rem; margin-top: .4rem; margin-bottom: 1.25rem; }
.result-recommendation {
    background: rgba(88,166,255,.08); border: 1px solid rgba(88,166,255,.25);
    border-radius: 10px; padding: 1rem 1.25rem;
    color: var(--accent); font-size: .9rem; line-height: 1.6;
    margin-bottom: 1.25rem;
}

/* ─── Spinner ─────────────────────────────────────────────────────────── */
.spinner {
    width: 20px; height: 20px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── Utility ─────────────────────────────────────────────────────────── */
.hidden { display: none !important; }
.mt-1 { margin-top: .5rem; }
.mt-2 { margin-top: 1rem; }
.gap-1 { gap: .5rem; }
.flex { display: flex; }
.items-center { align-items: center; }
</style>

<div id="practice-root">

    <!-- HEADER -->
    <div class="ph-header">
        <div class="ph-header-icon">🧠</div>
        <div>
            <h1>Practice Hub</h1>
            <p>AI-powered 2-stage verification to make sure you truly understand each concept.</p>
        </div>
    </div>

    <!-- PROGRESS STEPPER -->
    <div class="stepper" id="stepper">
        <div class="step-item active" id="step-0">
            <div class="step-dot">1</div>
            <div class="step-label">Pick Topic</div>
        </div>
        <div class="step-item" id="step-1">
            <div class="step-dot">2</div>
            <div class="step-label">Conceptual</div>
        </div>
        <div class="step-item" id="step-2">
            <div class="step-dot">3</div>
            <div class="step-label">Application</div>
        </div>
        <div class="step-item" id="step-3">
            <div class="step-dot">✓</div>
            <div class="step-label">Result</div>
        </div>
    </div>

    <!-- ── PANEL 0: TOPIC SELECTOR ─────────────────────────────────────── -->
    <div class="ph-card" id="panel-topic">
        <div class="ph-card-title">🎯 Select a Topic</div>
        <div class="topic-grid" id="topic-grid">
            <?php foreach ($kc_topics as $kc): ?>
                <div class="topic-chip" data-topic="<?= htmlspecialchars($kc) ?>">
                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $kc))) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" id="btn-generate" disabled>
            <span id="gen-spinner" class="spinner hidden"></span>
            <span id="gen-label">⚡ Generate Problem</span>
        </button>
    </div>

    <!-- ── PANEL 1: PROBLEM DISPLAY ────────────────────────────────────── -->
    <div class="ph-card hidden" id="panel-problem">
        <div class="ph-card-title">📋 Your Practice Problem</div>
        <div class="problem-text" id="problem-text"></div>
        <div class="skeleton-block hidden" id="skeleton-block"></div>
        <div class="flex mt-2 gap-1">
            <button class="btn btn-ghost" id="btn-new-problem" style="font-size:.8rem;padding:.5rem 1rem">🔄 New Problem</button>
        </div>
    </div>

    <!-- ── PANEL 2: STAGE 1 — CONCEPTUAL ───────────────────────────────── -->
    <div class="ph-card hidden" id="panel-stage1">
        <div class="ph-card-title">
            <span class="stage-badge">Stage 1 · Conceptual</span>
        </div>
        <p id="q1-text" style="line-height:1.7;margin-bottom:.85rem;"></p>
        <textarea class="ph-textarea" id="answer-stage1" placeholder="Explain your reasoning here… (no code needed, just your understanding)"></textarea>
        <div id="feedback-stage1" class="feedback-panel hidden"></div>
        <div class="flex mt-2 gap-1">
            <button class="btn btn-primary" id="btn-submit-stage1">
                <span id="s1-spinner" class="spinner hidden"></span>
                <span>Submit Answer</span>
            </button>
        </div>
    </div>

    <!-- ── PANEL 3: STAGE 2 — APPLICATION ──────────────────────────────── -->
    <div class="ph-card hidden" id="panel-stage2">
        <div class="ph-card-title">
            <span class="stage-badge" style="background:rgba(88,166,255,.12);color:var(--accent);border-color:rgba(88,166,255,.3)">Stage 2 · Application</span>
        </div>
        <p id="q2-text" style="line-height:1.7;margin-bottom:.85rem;"></p>
        <textarea class="ph-textarea" id="answer-stage2" placeholder="Apply your knowledge here…"></textarea>
        <div id="feedback-stage2" class="feedback-panel hidden"></div>
        <div class="flex mt-2 gap-1">
            <button class="btn btn-primary" id="btn-submit-stage2">
                <span id="s2-spinner" class="spinner hidden"></span>
                <span>Submit Answer</span>
            </button>
        </div>
    </div>

    <!-- ── PANEL 4: FINAL RESULT ────────────────────────────────────────── -->
    <div class="ph-card hidden result-card" id="panel-result">
        <div class="result-emoji" id="result-emoji">🎉</div>
        <div class="result-title" id="result-title">Great work!</div>
        <div class="result-subtitle" id="result-subtitle"></div>
        <div class="score-row" style="justify-content:center">
            <div class="score-pill" id="result-score-pill">—</div>
            <div style="color:var(--muted);font-size:.85rem">application score</div>
        </div>
        <div class="result-recommendation" id="result-recommendation"></div>
        <button class="btn btn-primary" id="btn-restart" style="margin:0 auto">
            🔄 Practice Another Topic
        </button>
    </div>

</div><!-- /#practice-root -->

<script>
(function () {
    'use strict';

    const AJAX_URL  = M.cfg.wwwroot + '/local/practice_ajax.php';
    const SESSKEY   = window.M_SESSKEY;
    const COURSEID  = window.M_COURSEID;

    // ── State ────────────────────────────────────────────────────────────
    let selectedTopic   = null;
    let currentProblem  = '';
    let stage1Question  = '';
    let stage2Question  = '';

    // ── Helpers ──────────────────────────────────────────────────────────
    function show(id)  { document.getElementById(id).classList.remove('hidden'); }
    function hide(id)  { document.getElementById(id).classList.add('hidden'); }
    function setText(id, text) { document.getElementById(id).textContent = text; }
    function setHTML(id, html) { document.getElementById(id).innerHTML = html; }

    function setSpinner(spinnerId, btnId, loading) {
        const sp = document.getElementById(spinnerId);
        const btn = document.getElementById(btnId);
        sp.classList.toggle('hidden', !loading);
        btn.disabled = loading;
    }

    function setStepperState(activeStep) {
        for (let i = 0; i <= 3; i++) {
            const el = document.getElementById('step-' + i);
            el.classList.remove('done', 'active');
            if (i < activeStep)  el.classList.add('done');
            if (i === activeStep) el.classList.add('active');
        }
    }

    function scoreClass(score) {
        if (score >= 75) return 'score-high';
        if (score >= 50) return 'score-mid';
        return 'score-low';
    }

    function showFeedback(panelId, passed, score, feedback) {
        const el = document.getElementById(panelId);
        const cls = passed ? 'feedback-pass' : 'feedback-fail';
        el.className = 'feedback-panel ' + cls;
        el.innerHTML =
            '<strong>' + (passed ? '✅ Correct!' : '❌ Needs Improvement') + '</strong>' +
            ' &nbsp;<span class="score-pill ' + scoreClass(score) + '" style="font-size:.85rem;padding:.15rem .6rem">' + score.toFixed(1) + '/100</span>' +
            '<br><br>' + escapeHtml(feedback);
        show(panelId);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/\n/g, '<br>');
    }

    async function postAjax(params) {
        const form = new URLSearchParams({sesskey: SESSKEY, courseid: COURSEID, ...params});
        const res  = await fetch(AJAX_URL, { method: 'POST', body: form });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    // ── Topic Selection ───────────────────────────────────────────────────
    document.querySelectorAll('.topic-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.topic-chip').forEach(c => c.classList.remove('selected'));
            chip.classList.add('selected');
            selectedTopic = chip.dataset.topic;
            document.getElementById('btn-generate').disabled = false;
        });
    });

    // ── Generate Problem ──────────────────────────────────────────────────
    document.getElementById('btn-generate').addEventListener('click', async () => {
        if (!selectedTopic) return;

        setSpinner('gen-spinner', 'btn-generate', true);
        hide('panel-problem');
        hide('panel-stage1');
        hide('panel-stage2');
        hide('panel-result');
        document.getElementById('feedback-stage1').classList.add('hidden');
        document.getElementById('feedback-stage2').classList.add('hidden');
        document.getElementById('answer-stage1').value = '';
        document.getElementById('answer-stage2').value = '';

        try {
            const data = await postAjax({ action: 'generate', topic: selectedTopic });

            if (!data.success) {
                alert('⚠️ ' + (data.message || 'Failed to generate problem. Please try again.'));
                return;
            }

            currentProblem = data.problem || '';
            stage1Question = data.stage1_question || '';

            setText('problem-text', currentProblem);

            if (data.skeleton_code && data.skeleton_code.trim()) {
                setText('skeleton-block', data.skeleton_code);
                show('skeleton-block');
            } else {
                hide('skeleton-block');
            }

            setHTML('q1-text', '<strong>Pertanyaan Konseptual:</strong><br>' + escapeHtml(stage1Question));

            show('panel-problem');
            show('panel-stage1');
            setStepperState(1);

        } catch (e) {
            alert('Network error: ' + e.message);
        } finally {
            setSpinner('gen-spinner', 'btn-generate', false);
        }
    });

    // ── New Problem (Reset to topic selector) ─────────────────────────────
    document.getElementById('btn-new-problem').addEventListener('click', () => {
        hide('panel-problem');
        hide('panel-stage1');
        hide('panel-stage2');
        hide('panel-result');
        setStepperState(0);
        document.getElementById('answer-stage1').value = '';
        document.getElementById('answer-stage2').value = '';
    });

    // ── Submit Stage 1 ────────────────────────────────────────────────────
    document.getElementById('btn-submit-stage1').addEventListener('click', async () => {
        const answer = document.getElementById('answer-stage1').value.trim();
        if (!answer) { alert('Jawaban tidak boleh kosong.'); return; }

        setSpinner('s1-spinner', 'btn-submit-stage1', true);
        hide('feedback-stage1');

        try {
            const data = await postAjax({ action: 'verify_stage1', answer });

            if (!data.success) {
                alert('⚠️ ' + (data.message || 'Error evaluating your answer.'));
                return;
            }

            showFeedback('feedback-stage1', data.passed, data.score || 0, data.feedback || '');

            if (data.passed && data.stage2_question) {
                stage2Question = data.stage2_question;
                setHTML('q2-text', '<strong>Pertanyaan Aplikasi:</strong><br>' + escapeHtml(stage2Question));
                setTimeout(() => {
                    show('panel-stage2');
                    document.getElementById('panel-stage2').scrollIntoView({ behavior: 'smooth' });
                    setStepperState(2);
                }, 800);
            } else if (!data.passed) {
                // Stage 1 failed: show encouraging note
                const fb = document.getElementById('feedback-stage1');
                fb.innerHTML += '<br><br>💡 <em>Review the problem again and try re-generating with the same topic for more practice.</em>';
            }

        } catch (e) {
            alert('Network error: ' + e.message);
        } finally {
            setSpinner('s1-spinner', 'btn-submit-stage1', false);
        }
    });

    // ── Submit Stage 2 ────────────────────────────────────────────────────
    document.getElementById('btn-submit-stage2').addEventListener('click', async () => {
        const answer = document.getElementById('answer-stage2').value.trim();
        if (!answer) { alert('Jawaban tidak boleh kosong.'); return; }

        setSpinner('s2-spinner', 'btn-submit-stage2', true);
        hide('feedback-stage2');

        try {
            const data = await postAjax({ action: 'verify_stage2', answer });

            if (!data.success) {
                alert('⚠️ ' + (data.message || 'Error evaluating your answer.'));
                return;
            }

            showFeedback('feedback-stage2', data.passed, data.score || 0, data.feedback || '');

            // Short delay then show final result panel
            setTimeout(() => {
                renderResult(data);
                setStepperState(3);
            }, 1000);

        } catch (e) {
            alert('Network error: ' + e.message);
        } finally {
            setSpinner('s2-spinner', 'btn-submit-stage2', false);
        }
    });

    // ── Render Final Result ───────────────────────────────────────────────
    function renderResult(data) {
        const passed = data.passed;
        const status = data.final_status || 'needs_practice';
        const score  = data.score || 0;

        const emojiMap = {
            'next_topic':      '🏆',
            'practice_more':   '💪',
            'revisit_concept': '📚',
        };
        const titleMap = {
            'next_topic':      'Topik Dikuasai!',
            'practice_more':   'Terus Berlatih!',
            'revisit_concept': 'Pelajari Lagi',
        };
        const subtitleMap = {
            'next_topic':      'Kamu siap untuk topik berikutnya.',
            'practice_more':   'Sedikit lagi, kamu pasti bisa!',
            'revisit_concept': 'Tidak apa-apa, ulang materinya dulu.',
        };

        setText('result-emoji', emojiMap[status] || (passed ? '🎉' : '📖'));
        setText('result-title', titleMap[status] || (passed ? 'Bagus!' : 'Perlu Ditingkatkan'));
        setText('result-subtitle', subtitleMap[status] || 'Terus belajar!');

        const pill = document.getElementById('result-score-pill');
        pill.textContent = score.toFixed(1) + '/100';
        pill.className = 'score-pill ' + scoreClass(score);

        setText('result-recommendation', data.recommendation || '');
        show('panel-result');
        document.getElementById('panel-result').scrollIntoView({ behavior: 'smooth' });
    }

    // ── Restart ────────────────────────────────────────────────────────────
    document.getElementById('btn-restart').addEventListener('click', () => {
        document.querySelectorAll('.topic-chip').forEach(c => c.classList.remove('selected'));
        selectedTopic = null;
        document.getElementById('btn-generate').disabled = true;
        hide('panel-problem');
        hide('panel-stage1');
        hide('panel-stage2');
        hide('panel-result');
        setStepperState(0);
        document.getElementById('answer-stage1').value = '';
        document.getElementById('answer-stage2').value = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

})();
</script>

<?php
echo $OUTPUT->footer();
