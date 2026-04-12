# Agentic Evaluation & Scoring System

The **Agentic Evaluation System (AFS v2)** is responsible for scoring and providing feedback on student submissions (Assignments and Quizzes). It uses a multi-agent approach to ensure deep analysis of both code quality and logical correctness.

## 🤖 The Scoring Agents

Every submission is analyzed by two primary agents working in parallel or sequence, as coordinated by the **Analyzer**:

### 1. 🧠 Logic Agent (`logic_agent`)
- **Focus**: Algorithmic correctness, edge cases, and logical flow.
- **Responsibility**:
    - Validates if the code solves the given problem.
    - Checks for logical fallacies or inefficient algorithms.
    - Verifies that the student understood the core "Business Logic" of the task.
- **Output**: Detailed feedback on *what* the code is doing incorrectly in terms of problem-solving.

### 2. 🎨 Style & Syntax Agent (`style_agent`)
- **Focus**: Syntax correctness, code formatting, and "Standard Pseudo Code" compliance.
- **Responsibility**:
    - Identifies syntax errors (if any).
    - Evaluates code readability, variable naming, and structure.
    - Ensures the student is following the requested programming paradigm or pseudocode conventions.
- **Output**: Feedback on *how* the code is written, focusing on readability and standard practices.

---

## 📈 The AFS v2 Feedback Loop

The evaluation process follows a "Self-Correction" cycle before the student receives feedback:

1.  **Drafting (`Analyzer`)**: The Logic and Style agents produce their initial findings.
2.  **Reviewing (`Critic`)**: The **Reflective Critic** reviews the feedback. 
    - Is it too encouraging? 
    - Does it give away the answer too early?
    - Is it pedagogically sound (Socratic)?
3.  **Refining**: If the Critic rejects the evaluation, the Analyzer must rewrite the feedback to be more helpful and less "spoiler-heavy".
4.  **Final Delivery**: The student receives a structured diagnostic which separate logic issues from style issues.

---

## 🌍 Bilingual Support (ID/EN)

The system is natively bilingual:
- **Input Detection**: Automatically detects the language of the student's submission and comments.
- **Consistent Response**: If a student submits pseudocode in Indonesian, the feedback will be provided in Indonesian, maintaining pedagogical continuity.

## 📂 Mapping
- **Logic Scratch**: `logic_scratch/` (Contains the core LLM prompts and rules).
- **Agents**: `agents/analyzer.py`, `agents/logic_agent.py`, `agents/style_agent.py`.
- **Moodle Bridge**: `public/local/orchestrator_ajax.php`.
