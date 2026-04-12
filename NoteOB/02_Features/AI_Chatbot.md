# AI Chatbot Assistant Component

This component provides a persistent, context-aware AI assistant for students on Moodle course pages. It leverages LangChain and **GPT-4o** to answer course-related questions, check assignments, and trigger agentic tools.

## 📂 Mapping

- **PHP Gateway**: `public/local/chat_ai_ajax.php`
- **Python Engine**: `admin/cli/chat.py`
- **Agentic Bridge**: `main.py`
- **UI Template**: `public/course/format/templates/local/chat.mustache`

---

## 🏗️ Technical Details

### Models & Logic
1.  **GPT-4o / GPT-4o-mini**: Primary models used for conversational reasoning and complex tool selection.
2.  **LangChain**: Orchestrates the conversation flow and manages session history.
3.  **Agentic Integration**: For practice requests, the chatbot hands over control to the **AMT-CS1 Agentic Pipeline** (`main.py`).

### Hybrid Execution Flow
-   **Context Discovery (PHP)**: The PHP gateway automatically gathers all relevant course context before calling the AI:
    -   Course summary and metadata.
    -   Section names and summaries.
    -   Upcoming/overdue assignments with status.
    -   Quizzes and student attempts.
-   **Conversation Memory**: Previous messages are stored in the Moodle user session (`$_SESSION`) and passed to the Python engine on every turn.
-   **Tool Calling**: The AI returns structured JSON actions. Some actions (like listing items) are handled by the LLM, while others (like quiz creation) are executed by PHP.

---

## 🛠️ Tool Actions

The AI can perform the following actions:

| Action | Description | Implementation |
| :--- | :--- | :--- |
| `explain_course` | Summarizes the course based on injected context. | LLM-based. |
| `list_assignments`| Lists tasks and deadlines from the context. | LLM-based. |
| `create_quiz` | Triggers a standard quiz for a specific section. | PHP (Standard `handle_create_quiz`). |
| `create_personal_quiz` | Triggers an AI-powered personal practice quiz. | Python (`LessonGenerator`) + PHP (`handle_create_personal_quiz`). |

---

## 📊 JSON Response Overrides

### Personal Quiz Generation
When the AI returns `create_personal_quiz`, `chat.py` intercepts the call to run the **Agentic Pipeline** before returning the final JSON to PHP:

```json
{
  "status": "success",
  "message": "I've generated a personal practice problem on Linked Lists for you!",
  "tool_action": {
    "action": "create_personal_quiz",
    "sectionid": 15,
    "theme": "linked list",
    "language": "English"
  },
  "personal_quiz": {
    "status": "success",
    "problem": "...",
    "skeleton_code": "...",
    "concepts": ["...", "..."],
    "stage1_question": "..."
  }
}
```

---

## 💾 Storage & Caching

### Conversation History
History is kept in the user's session, keyed by `user_id` and `course_id`. It is passed as an array of `role`/`content` pairs.

### Session Key
`chat_history_{userid}_{courseid}`

> [!TIP]
> The chatbot is bilingual. It will automatically detect if you are speaking **Indonesian** or **English** and adjust its responses and generated exercises accordingly.
