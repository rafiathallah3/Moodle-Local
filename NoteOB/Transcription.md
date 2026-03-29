# Audio Transcription Component

This component provides audio-to-text transcription services. It supports multiple models and implements a caching layer.

## 📂 Mapping

- **PHP Gateway**: `public/local/transcribe_ajax.php`
- **Python Engine**: `admin/cli/transcribe.py`
- **PHP Caller**: `public/question/engine/renderer.php` (via `core_question_renderer::question`)

---

## 🏗️ Technical Details

### Models Supported
1.  **Gemini (`gemini-2.5-flash`)**: Used for multimodal audio processing (default).
2.  **OpenAI Whisper (`whisper-1`)**: High-accuracy transcription via OpenAI's REST API.
3.  **Azure Speech**: Used for low-latency specialized transcription.

### Process Flow
-   **AJAX Script**: Receives the Moodle file parameters, copies the audio file to a temporary location (`make_request_directory`), and executes the Python script specifying `--model gemini`.
-   **Python Engine**: Uses the LLM to transcribe the audio and prints a JSON object to `stdout`.

---

## 📊 JSON Response Schemas

### Python CLI Output (admin/cli/transcribe.py)
The CLI returns a simple JSON object:

```json
{
  "status": "success",
  "model": "gemini",
  "transcript": "Hello, this is a sample transcript text.",
  "transcribe_time": 1.234
}
```

### AJAX Gateway Response (public/local/transcribe_ajax.php)
The final web response is wrapped for client consumption:

```json
{
  "success": true,
  "transcript": "Hello, this is a sample transcript text.",
  "cached": false
}
```

---

## 💾 Storage & Caching

### Moodle Table: `local_transcribe_results`
Successful transcriptions are stored in this table to prevent redundant API calls.

| Column | Type | Description |
| :--- | :--- | :--- |
| `contextid` | int | Moodle context ID. |
| `component` | varchar | Component name (e.g., `mod_quiz`). |
| `filearea` | varchar | File area (e.g., `essay_answer`). |
| `itemid` | int | Item ID. |
| `transcript` | text | Transcribed text result. |
| `timecreated` | int | Unix timestamp. |

> [!TIP]
> Use `cached: true` in the AJAX response to identify when a result was retrieved from the database without invoking the AI engine.
