# OCR (Optical Character Recognition) Component

This component handles text extraction from image files. It uses `Gemini 2.5 Flash` and `GPT-4o` vision capabilities.

## 📂 Mapping

- **PHP Gateway**: `public/local/ocr_ajax.php`
- **Python Engine**: `admin/cli/ocr.py`
- **PHP Caller**: `public/question/engine/renderer.php` (via `core_question_renderer::question`)

---

## 🏗️ Technical Details

### Models Supported
1.  **Gemini (`gemini-2.5-flash`)**: High-performance vision and extraction (default).
2.  **OpenAI Vision (`gpt-4o`)**: Used for high-precision OCR and complex document layouts.

### Process Flow
-   **AJAX Script**: Accepts Moodle file storage parameters, validates the file is an image (`image/jpeg`, `image/png`, etc.), and runs the Python OCR script.
-   **Execution Mode**: Uses `proc_open` with a **45-second hardware timeout** to prevent hanging requests.
-   **Python Engine**: Encodes the image in base64, passes it to the vision model with specific instructions to act as an OCR engine, and prints the result.

---

## 📊 JSON Response Schemas

### Python CLI Output (admin/cli/ocr.py)
The CLI returns the extracted text and execution metrics:

```json
{
  "status": "success",
  "model": "gemini",
  "text": "EXTRACTED TEXT FROM IMAGE",
  "elapsed_time": 2.15
}
```

### AJAX Gateway Response (public/local/ocr_ajax.php)
The final response includes a `cached` flag and the extracted text:

```json
{
  "success": true,
  "text": "EXTRACTED TEXT FROM IMAGE",
  "cached": false
}
```

---

## 💾 Storage & Caching

### Moodle Table: `local_ocr_results`
OCR results are stored in the database to optimize costs and prevent repeated vision API calls.

| Column | Type | Description |
| :--- | :--- | :--- |
| `contextid` | int | Moodle context ID. |
| `component` | varchar | Component name. |
| `filearea` | varchar | File area. |
| `ocr_text` | text | Extracted text from image. |
| `timecreated` | int | Unix timestamp. |

> [!CAUTION]
> If the image contains no readable text, the system defaults to: `Text not found inside the image.`
