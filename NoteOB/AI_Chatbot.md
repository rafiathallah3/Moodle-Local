# AI Chatbot Assistant Component

This component provides a persistent, context-aware AI assistant for students on Moodle course pages. It leverages LangChain and Gemini 2.5 Flash to answer course-related questions, check assignments, and create practice materials.

## 📂 Mapping

- **PHP Gateway**: `public/local/chat_ai_ajax.php`
- **Python Engine**: `admin/cli/chat.py`
- **UI Template**: `public/course/format/templates/local/chat.mustache`

---

## 🏗️ Technical Details

### Models & Logic
1.  **Gemini 2.5 Flash**: Primary model used for conversational reasoning and structured JSON output.
2.  **LangChain**: Orchestrates the conversation flow, manages history, and handles tool calling.

### Hybrid Execution Flow
-   **Context Discovery (PHP)**: The PHP gateway automatically gathers all relevant course context before calling the AI:
    -   Course summary and metadata.
    -   Section names and summaries.
    -   Upcoming/overdue assignments with status.
    -   Quizzes and student attempts.
-   **Conversation Memory**: Previous messages are stored in the Moodle user session (`$_SESSION`) and passed to the Python engine on every turn.
-   **Tool Calling**: The AI can decide to trigger "Moodle Tools" like creating a personal quiz. These actions are returned as structured JSON for the PHP gateway to execute.

---

## 🛠️ Tool Actions

The AI can perform the following actions:

| Action             | Description                                               | Implementation                  |
| :----------------- | :-------------------------------------------------------- | :------------------------------ |
| `explain_course`   | Summarizes the course based on injected context.          | LLM-based.                      |
| `list_assignments` | Lists tasks and deadlines from the context.               | LLM-based.                      |
| `create_quiz`      | Triggers a personal quiz creation for a specific section. | PHP-executed via `tool_action`. |

---

## 📊 JSON Response Schemas

### Python CLI Output (`admin/cli/chat.py`)
The engine returns a structured JSON object to the PHP gateway:

```json
{
  "status": "success",
  "message": "I've created a practice quiz for you in Week 1!",
  "tool_action": {
    "action": "create_quiz",
    "sectionid": 10
  }
}
```

### AJAX Gateway Response (`public/local/chat_ai_ajax.php`)
The final response includes the AI message and any tool execution results:

```json
{
  "success": true,
  "message": "I've created a practice quiz for you in Week 1!",
  "tool_result": {
    "success": true,
    "message": "Quiz created! Refresh the page to see it."
  }
}
```

---

## 💾 Storage & Caching

### Conversation History
History is kept in the user's session, keyed by `user_id` and `course_id`. It is limited to the last **20 messages** to prevent token overflow.

### Session Key
`chat_{userid}_{courseid}`

> [!TIP]
> Use the **Suggestion Chips** in the UI to quickly trigger the AI's core capabilities without typing long prompts.
