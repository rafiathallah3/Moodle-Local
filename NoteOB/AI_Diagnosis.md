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
-   **Gemini 2.5 Flash**: Optimized for reasoning and structured output (JSON).

### Process Flow
-   **AJAX Script**: Loads the student's submission (text, audio, or image) from the question attempt usage. It also passes the original question text.
-   **Execution Mode**: Passes the student's text, media files, and question description to the Python script.
-   **Quality Gate (Critic)**: After receiving the diagnosis, the PHP script runs an internal **Critic/Quality Gate validation** located in `local/orchestrator/classes/critic.php` to ensure the response adheres to safety and pedagogical policies (e.g., no full solutions, hint-only).

---

## 📊 JSON Response Schemas

### Python CLI Output (admin/cli/diagnose.py)
The CLI returns a structured JSON object defined by a Pydantic model:

```json
{
  "status": "success",
  "diagnosis": "Short summary and light diagnosis/feedback... Keep it concise and constructive.",
  "mark": 8.5
}
```

### AJAX Gateway Response (public/local/diagnose_ajax.php)
The final web response is formatted with HTML (strong tags, line breaks) and checked against the Quality Gate:

```json
{
  "status": "success",
  "diagnosis": "<strong>Feedback summary</strong><br/>Detailed diagnosis details..."
}
```

---

## 💾 Operational Details

### Structured Output (Pydantic)
The Python engine uses `langchain_google_genai` with `with_structured_output` to ensure the model returns a valid `DiagnosisOutput` object:
- `mark`: float (Score out of the maximum mark).
- `diagnosis`: string (Short summary and feedback).

### Quality Gate (`critic.php`)
The AI response is checked against several constraints:
- `no_full_solution`: `true`
- `hint_only`: `true`
- `max_hint_tier`: `2`

> [!WARNING]
> If the response fails the Quality Gate check, the AJAX response returns a status of `error` with the reason for blocking.
