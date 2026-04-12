# Personal Practice Quiz System

The **Personal Practice Quiz System** is an agentic feature that allows students to generate AI-powered, adaptive practice problems directly through the AI Chat Assistant.

## 🔄 The Pipeline (Agentic Practice Flow)

Unlike traditional static quizzes, personal quizzes are generated on-the-fly using the **Agentic Practice Pipeline**:

1.  **Intent Detection**: Student asks "I want to practice [Topic]" in the Chatbot.
2.  **Lesson Generation (`LessonGeneratorAgent`)**:
    - Analyzes the topic and student context.
    - Generates a **Content Package** containing:
        - A unique **Practice Problem**.
        - **Skeleton Code** (starting point).
        - **Key Concepts** to focus on.
3.  **Conceptual Verification (`QuizVerifier`)**:
    - Automatically generates a **Stage 1 (Conceptual Check)**.
    - This question forces the student to explain the *logic* behind the problem before they dive into the code.
4.  **Revision Loop (`Critic`)**:
    - Checks the generated problem for quality.
    - Ensures the problem isn't too easy and doesn't reveal the solution.
5.  **Moodle Injection**:
    - The final content is passed back to `chat_ai_ajax.php`.
    - A new Moodle Quiz is created, restricted specifically to that student.
    - An **Essay Question** is created with the rich content (HTML-formatted).

---

## 🏗️ Technical Architecture

### 1. Python Side (`admin/cli/chat.py`)
- **Function**: `generate_personal_quiz()`
- **Action**: Triggers `process_practice_request(stage="generate")` from `main.py`.
- **Output**: Returns a JSON object with `problem`, `skeleton_code`, `concepts`, and `stage1_question`.

### 2. PHP Side (`public/local/chat_ai_ajax.php`)
- **Function**: `handle_create_personal_quiz()`
- **Responsibility**:
    - Creates a Moodle `quiz` instance via `add_moduleinfo`.
    - Sets **Availability Restrictions** so only the requesting user can see it.
    - Injects the AI content into an **Essay Question** via `insert_essay_question`.
    - Links the question to the quiz via `add_questions_to_quiz`.

---

## 📝 Quiz Format

Every personal quiz consists of **one high-quality essay question** containing:

| Component | Description |
| :--- | :--- |
| **📝 Practice Problem** | A descriptive coding challenge or algorithmic problem. |
| **💻 Skeleton Code** | A boilerplate or starting template for the solution. |
| **📚 Key Concepts** | A bulleted list of topics covered by the problem. |
| **🤔 Conceptual Check** | A "Socratic" question from the Quiz Verifier to test logic understanding. |
| **⌨️ Response Area** | A text/code editor where the student writes their solution (pseudocode or code). |

---

## 🌍 Language & Format
- **Bilingual**: Supports English and Indonesian.
- **Pseudo Code Priority**: The system encourages the use of **Standard Pseudo Code** to focus on logic over syntax errors.
