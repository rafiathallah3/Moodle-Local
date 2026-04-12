# Agentic Quiz & Grading System Overview

This note documents the integration of the **Agentic AI Pipeline** into the Moodle Practice Quiz system. 

## 1. System Overview
The system replaces legacy standalone scripts with a modular, agentic architecture. It uses two primary agents from the `agents/` directory:
- **LessonGeneratorAgent**: Responsible for generating high-quality, personalized practice problems based on course context.
- **AnalyzerAgent**: Responsible for multi-stage evaluation (Syntax + Logic) of student submissions.

## 2. User Flow

### Phase A: Quiz Generation
1. **Request**: A student asks the AI Assistant for a practice quiz (e.g., "Give me a quiz about Factorials").
2. **Detection**: `chat.py` detects the `create_quiz` tool action.
3. **Generation**: `chat.py` instantiates `LessonGeneratorAgent`.
4. **Agent Logic**:
    - Agent analyzes the theme/topic.
    - Agent generates a structured `ContentPackage` (Explanation, Pseudocode, Concepts).
5. **Payload**: The generated content is sent to `chat_ai_ajax.php`.
6. **Moodle Integration**: PHP inserts the pre-generated content directly into the Moodle Question Bank, bypassing secondary AI calls.

### Phase B: Submission & Grading
1. **Submission**: The student completes the quiz and submits their answer (text or media).
2. **Trigger**: Moodle fires an `attempt_submitted` event, caught by `observer.php`.
3. **Task Queue**: An adhoc task `diagnose_quiz_attempt_task.php` is executed.
4. **Grading Pipeline**:
    - PHP calls `grade_submission.py`.
    - `grade_submission.py` invokes `AnalyzerAgent`.
5. **Evaluation**:
    - **Syntax Check**: `StyleCheckerAgent` verifies pseudocode grammar (program/endprogram structure, assignment operators).
    - **Logic Check**: `LogicCheckerAgent` compares the student's algorithm against the expected logic and assigns a mark.
6. **Result**: The final mark and detailed feedback are saved back to the Moodle Gradebook.

## 3. How It Works (Technical Bridge)

### The Python CLI Bridges
To maintain compatibility with Moodle's legacy PHP environment, we use "Bridge" scripts:
- **`chat.py`**: The main entry point for the assistant. Now includes the `generate_practice_content` function which wraps the `LessonGeneratorAgent`.
- **`grade_submission.py`**: A new bridge that replaces the old `diagnose.py`. It provides a clean CLI interface for the `AnalyzerAgent` sub-agents while outputting the exact JSON format Moodle expects.

### Data Consistency
- **JSON Contract**: All scripts communicate via structured JSON on stdout.
- **Path Management**: `sys.path` is dynamically updated in the CLI scripts to ensure they can import the root `agents/`, `config/`, and `logic_scratch/` modules regardless of where they are called from.

## 4. Key Configurations
- **Language Support**: Both agents support multi-language output (Indonesian, English, Sundanese, Javanese) by injecting localization instructions into the LLM system prompts.
- **Pseudocode Standards**: The system enforces specific pseudocode grammar to ensure consistency across generation and grading.

---
*Last Updated: 2026-04-12*
