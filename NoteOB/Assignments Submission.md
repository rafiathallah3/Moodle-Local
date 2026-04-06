# Assignment Submission Plugin Overview

The **Assignment Submission** (`mod_assignsubmission`) is a custom Moodle activity plugin designed for lecturers and teaching assistants to streamline the grading of handwritten or physical student assignments.

## 🚀 Key Features

### 1. Bulk Upload & OCR
-   **Bulk Image Processing:** Upload multiple student assignment images (JPG, PNG, WebP) at once using a drag-and-drop zone.
-   **Automated OCR Extraction:** Automatically extracts text from uploaded images to help lecturers review handwritten or printed work digitally.
-   **Student Identification:** Attempts to automatically identify student names within the uploaded images.

### 2. AI-Powered Auto-Grading
-   **Single/Bulk Diagnosis:** Lecturers can "Diagnose" individual submissions or use the "Auto-grade All" feature to process an entire class.
-   **Gemini AI Integration:** Uses the Gemini API (via an external `diagnose.py` script) to analyze the extracted text.
-   **Context-Aware Grading:** The AI considers the **Assignment Description** as a rubric or set of requirements to provide tailored feedback and marks.

### 3. Dynamic Management
-   **Inline Description Editing:** (New Feature) Lecturers can update the assignment description directly from the activity view page without navigating to settings. This allows for quick adjustments to grading criteria.
-   **Manual Overrides:** Full capability to manually edit student names, marks, and feedback after the AI has provided an initial assessment.

## 🛠 Project Structure

-   `view.php`: The main dashboard for uploading, viewing submissions, and editing the assignment description.
-   `grade.php`: The AJAX handler for AI grading, manual edits, and deletions.
-   `upload.php`: Handles image processing and OCR entry.
-   `db/install.xml`: Defines two primary tables:
    -   `assignsubmission`: Stores activity instances (name, description, max mark).
    -   `assignsubmission_files`: Stores uploaded images, OCR text, marks, and feedback.
-   `admin/cli/diagnose.py`: The core AI engine that evaluates submissions based on the assignment context.

## 📋 Grading Workflow

1.  **Setup:** The lecturer creates the activity and provides a detailed **Assignment Description** (requirements/rubric).
2.  **Upload:** Use the drag-and-drop zone to upload student images.
3.  **Refine (Optional):** Edit the description directly on the page to ensure the AI grader has the latest requirements.
4.  **Grade:** Click "Auto-grade All" or "Diagnose" on specific rows.
5.  **Review:** Audit the AI-generated feedback and marks, making manual adjustments where necessary.

---
## Notes that need to take
- Do we have to let the students know how much mark do they get?
- Since the server call 2 API for each submission, it needs to be limited on uploading students' assignments.