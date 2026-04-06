# AI Diagnosis (Grading & Feedback) Component

This component is responsible for evaluating student responses, providing a score, and generating constructive feedback.

## 📂 Mapping

- **PHP Gateway**: `public/local/diagnose_ajax.php`
- **Python Engine**: `admin/cli/diagnose.py`
- **PHP Trigger**: `public/local/orchestrator/classes/event/observer.php` (via `attempt_submitted` event)
- **Execution Task**: `public/local/orchestrator/classes/task/diagnose_quiz_attempt_task.php` (ADHOC task)

---

## 🏗️ Technical Details

### Models Supported
- **OpenAI GPT-4o**: Reasoning and Structured output (JSON).
- **Gemini 2.5 Flash**: Optimized for reasoning and structured output (JSON).

### Process Flow
-   **AJAX Script**: Loads the student's submission (text, audio, or image) from the question attempt usage. It also passes the original question text.
-   **Student Language Resolution**: The PHP scripts (`diagnose_ajax.php`, `diagnose_quiz_attempt_task.php`, `diagnose_assign_submission_task.php`) look up the **student's** language preference from the `user` table (`lang` column) — *not* the grader's `current_language()`. This is passed to the Python engine via `--language`.
-   **Execution Mode**: Passes the student's text, media files, question description, and language to the Python script.
-   **Dual-Agent Evaluation**: The Python engine evaluates the response sequentially:
    1. **Syntax Agent**: Assesses the pseudo-code structure (e.g. `program`, `dictionary`, `algorithm`). Output is translated to the student's language.
    2. **Logic Agent**: Considers the syntax feedback and original context to grade logic and deduce a final score. Output is also translated.
-   **Quality Gate (Critic)**: After receiving the combined diagnosis, the PHP script runs an internal **Critic/Quality Gate validation** located in `local/orchestrator/classes/critic.php` to ensure the response adheres to safety and pedagogical policies (e.g., no full solutions, hint-only).

---

## 📊 JSON Response Schemas

### Python CLI Output (admin/cli/diagnose.py)
The CLI returns a structured JSON object. The `diagnosis` dynamically combines feedback from the Syntax and Logic components:

```json
{
  "status": "success",
  "diagnosis": "Pemeriksaan Sintaks:\nSintaks pseudo-code sudah benar...\n\nPemeriksaan Logika:\nLogika perulangan sudah sesuai...",
  "mark": 8.5
}
```

### AJAX Gateway Response (public/local/diagnose_ajax.php)
The final web response is formatted with HTML (strong tags, line breaks), includes the `mark`, and is checked against the Quality Gate:

```json
{
  "status": "success",
  "diagnosis": "Pemeriksaan Sintaks:<br>Sintaks pseudo-code sudah benar...<br><br>Pemeriksaan Logika:<br>Logika perulangan sudah sesuai...",
  "mark": 8.5
}
```

---

## 💾 Operational Details

### Structured Output (Pydantic)
The Python engine uses a dual-agent structure via LangChain:
- **SyntaxOutput**: Validates syntax (`is_valid` bool, `syntax_feedback` string).
- **LogicDiagnosisOutput**: Assesses logic and mark (`mark` float, `logic_feedback` string).
Both feedbacks are combined dynamically into a single `diagnosis` field string before being relayed back to PHP.

### Dual-Agent Architecture (Pros & Cons)
**Pros:**
- **Separation of Concerns:** The Logic agent isn't overwhelmed by simultaneously attempting to parse rigorous syntax rules while assessing high-level logic correctness.
- **Accurate Granular Feedback:** The student receives specific, segmented points about what went wrong syntactically versus what went wrong logically.
- **Improved Context Parsing:** The Logic agent can be explicitly informed of syntax violations, allowing for more nuanced final grading.

**Cons:**
- **Higher Latency:** Requires sequential LLM invocations, causing slower response times.
- **Increased Cost:** Sending the context twice consumes more tokens compared to a single comprehensive prompt.
- **Potential Redundancy:** High token repetition and potential overlapping feedback if the logic agent points out syntax-based breakdown points again.

### Quality Gate (`critic.php`)
The AI response is checked against several constraints:
- `no_full_solution`: `true`
- `hint_only`: `true`
- `max_hint_tier`: `2`

> [!WARNING]
> If the response fails the Quality Gate check, the AJAX response returns a status of `error` with the reason for blocking.
