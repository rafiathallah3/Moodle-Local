"""
LangChain AI Chatbot Engine for Moodle.

This script powers the Moodle AI chatbot assistant. It receives Moodle context
(course info, assignments, sections) from the PHP gateway and uses Gemini 2.5 Flash
via LangChain to provide intelligent, context-aware responses.

Usage:
    python chat.py --prompt "What is this course about?" --contextfile context.json [--historyfile history.json]

The script outputs a JSON object to stdout:
    {"message": "...", "tool_action": null}
    {"message": "...", "tool_action": {"action": "create_quiz", "sectionid": 42}}
"""

import argparse
import json
import os
import sys

from langchain_openai import ChatOpenAI
from langchain_core.messages import SystemMessage, HumanMessage, AIMessage


def load_env():
    """Load environment variables from the project root .env file."""
    env_path = os.path.join(
        os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
        ".env",
    )
    if os.path.exists(env_path):
        with open(env_path, "r") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if "=" in line:
                    k, v = line.split("=", 1)
                    os.environ[k.strip()] = v.strip().strip("'").strip('"')


def build_system_prompt(context: dict) -> str:
    """
    Build a rich system prompt that gives the AI full awareness of the
    student's Moodle environment.
    """
    user = context.get("user", {})
    course = context.get("course", {})
    sections = context.get("sections", [])
    assignments = context.get("assignments", [])

    # --- Core identity ---
    prompt = (
        "You are **MoodleBot**, a friendly and knowledgeable AI assistant embedded in a Moodle LMS. "
        "You help students understand their courses, check assignments, and create practice quizzes.\n\n"
        "Rules:\n"
        "- Be concise. Use Markdown formatting (bold, bullet points, numbered lists).\n"
        "- Never fabricate information. Only use the context provided below.\n"
        "- If the student asks something outside your context, politely say you don't have that data.\n"
        "- When the student asks to create a quiz, you MUST respond with a tool action.\n"
        "- **Language**: Detect the language the student is writing in or explicitly requesting. "
        "Respond in the SAME language the student uses. For example, if a student writes in Indonesian, "
        "respond in Indonesian. If they write in English, respond in English.\n\n"
    )

    # --- Student info ---
    if user:
        prompt += f"**Current Student**: {user.get('firstname', '')} {user.get('lastname', '')} (ID: {user.get('id', 'N/A')})\n\n"

    # --- Course info ---
    if course:
        prompt += "## Current Course\n"
        prompt += f"- **Name**: {course.get('fullname', 'Unknown')}\n"
        prompt += f"- **Short name**: {course.get('shortname', '')}\n"
        summary = course.get("summary", "").strip()
        if summary:
            prompt += f"- **Summary**: {summary}\n"
        prompt += "\n"

    # --- Sections ---
    if sections:
        prompt += "## Course Sections\n"
        for sec in sections:
            sec_name = sec.get("name", f"Section {sec.get('num', '?')}")
            prompt += f"- **{sec_name}** (Section ID: {sec.get('id', '?')})"
            sec_summary = sec.get("summary", "").strip()
            if sec_summary:
                prompt += f" — {sec_summary}"
            prompt += "\n"
        prompt += "\n"

    # --- Assignments ---
    if assignments:
        prompt += "## Assignments & Activities\n"
        for a in assignments:
            line = f"- **{a.get('name', 'Untitled')}** ({a.get('type', 'activity')})"
            if a.get("duedate"):
                line += f" — Due: {a['duedate']}"
            if a.get("status"):
                line += f" [{a['status']}]"
            if a.get("section_name"):
                line += f" (in: {a['section_name']})"
            if a.get("sectionid"):
                line += f" [sectionid={a['sectionid']}]"
            prompt += line + "\n"
        prompt += "\n"
    else:
        prompt += "## Assignments\nNo assignments found for this course.\n\n"

    # --- Tool instructions ---
    prompt += (
        "## Tool Actions\n"
        "When the student explicitly asks to **create a quiz** or **practice quiz**, you MUST:\n"
        "1. Identify the best matching section from the course sections above.\n"
        "2. If the student mentions a specific **topic or theme** (e.g., 'sorting algorithms', 'binary search', 'linked lists'),\n"
        "   extract it and include it as the `theme` field.\n"
        "3. Detect the **language** the student is using or explicitly requesting for the quiz. "
        "Use the full language name (e.g., 'Indonesian', 'English', 'Japanese', 'Spanish'). "
        "If the student writes in a non-English language, assume they want the quiz in that language. "
        "If they explicitly request a language (e.g., 'in Indonesian', 'dalam bahasa Indonesia'), use that. "
        "Default to 'English' if no other language is detected.\n"
        "4. Output your response so that the `tool_action` field contains:\n"
        '   `{"action": "create_quiz", "sectionid": <the section ID>, "theme": "<topic>" or null, "language": "<language name>"}`\n'
        "5. In your message, confirm which section, theme, and language you are creating the quiz for.\n"
        "6. If the student does not specify a section, or you cannot find the requested section in the context, ask them which section they want and set `tool_action` to `null`.\n"
        "7. If there is only one section (besides section 0), use that one.\n"
        "8. If no specific theme is mentioned, set `theme` to `null`.\n"
        "9. **CRITICAL**: If you return `create_quiz`, `sectionid` must be an integer ID found in the Course Sections. Do NOT use `null` or a string for `sectionid`. If you don't know the ID, you cannot create the quiz.\n\n"
        "Examples:\n"
        '- Student: "Create a quiz about sorting for Week 2" → `{"action": "create_quiz", "sectionid": 10, "theme": "sorting algorithms", "language": "English"}`\n'
        '- Student: "Give me a practice quiz for Week 1" → `{"action": "create_quiz", "sectionid": 5, "theme": null, "language": "English"}`\n'
        '- Student: "Buatkan kuis tentang linked list untuk Week 3" → `{"action": "create_quiz", "sectionid": 15, "theme": "linked list", "language": "Indonesian"}`\n'
        '- Student: "Create a quiz about arrays in Japanese" → `{"action": "create_quiz", "sectionid": 8, "theme": "arrays", "language": "Japanese"}`\n\n'
        "For all other requests, set `tool_action` to `null`.\n\n"
        "IMPORTANT: Your entire response MUST be a valid JSON object with exactly two keys:\n"
        '- "message": your response text (Markdown allowed)\n'
        '- "tool_action": null or a tool action object\n'
    )

    return prompt


def run_chat(prompt: str, context: dict, history: list, api_key: str) -> dict:
    """
    Run the LangChain chatbot with the given prompt, context, and history.

    Returns a dict with 'message' and 'tool_action' keys.
    """
    llm = ChatOpenAI(
        model="gpt-4o",
        openai_api_key=api_key,
        temperature=0.7,
    )

    system_prompt = build_system_prompt(context)

    messages = [SystemMessage(content=system_prompt)]

    # Rebuild conversation history
    for entry in history:
        role = entry.get("role", "")
        content = entry.get("content", "")
        if role == "user":
            messages.append(HumanMessage(content=content))
        elif role == "ai":
            messages.append(AIMessage(content=content))

    # Add the current user message
    messages.append(HumanMessage(content=prompt))

    try:
        response = llm.invoke(messages)
        raw = response.content.strip() if response.content else ""

        # Try to parse as JSON (the AI is instructed to return JSON)
        parsed = try_parse_json(raw)
        if parsed and "message" in parsed:
            return {
                "message": parsed["message"],
                "tool_action": parsed.get("tool_action"),
            }

        # Fallback: if the AI returned plain text instead of JSON
        return {"message": raw, "tool_action": None}

    except Exception as e:
        error_msg = str(e)
        if "SAFETY" in error_msg.upper():
            return {
                "message": "I'm sorry, I can't respond to that due to safety guidelines.",
                "tool_action": None,
            }
        raise


def try_parse_json(text: str) -> dict | None:
    """
    Attempt to extract and parse a JSON object from the AI response.
    Handles cases where the AI wraps JSON in markdown code blocks.
    """
    # Strip markdown code fences
    clean = text.strip()
    if clean.startswith("```"):
        # Remove opening fence (```json or ```)
        first_newline = clean.index("\n") if "\n" in clean else len(clean)
        clean = clean[first_newline + 1 :]
    if clean.endswith("```"):
        clean = clean[: -3]
    clean = clean.strip()

    # Find the outermost JSON object
    start = clean.find("{")
    end = clean.rfind("}")
    if start != -1 and end != -1 and end > start:
        try:
            return json.loads(clean[start : end + 1])
        except json.JSONDecodeError:
            pass

    return None


def main():
    parser = argparse.ArgumentParser(description="Moodle AI Chatbot powered by LangChain")
    parser.add_argument("--prompt", required=True, help="The user's message")
    parser.add_argument("--contextfile", required=True, help="Path to JSON file with Moodle context")
    parser.add_argument("--historyfile", help="Path to JSON file with conversation history")

    args = parser.parse_args()

    load_env()

    openai_api = os.environ.get("OPENAI_API")
    if not openai_api:
        print(json.dumps({"status": "error", "message": "OPENAI_API missing in .env"}))
        sys.exit(1)

    # Load Moodle context
    context = {}
    if args.contextfile and os.path.exists(args.contextfile):
        with open(args.contextfile, "r", encoding="utf-8") as f:
            context = json.load(f)

    # Load conversation history
    history = []
    if args.historyfile and os.path.exists(args.historyfile):
        with open(args.historyfile, "r", encoding="utf-8") as f:
            history = json.load(f)

    try:
        result = run_chat(args.prompt, context, history, openai_api)
        print(
            json.dumps(
                {
                    "status": "success",
                    "message": result["message"],
                    "tool_action": result.get("tool_action"),
                }
            )
        )
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
