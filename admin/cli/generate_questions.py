"""
AI Question Generator for Moodle.

Generates essay-type practice questions for a given theme/topic using Gemini.
Called by chat_ai_ajax.php when no matching questions exist in the question bank.

Usage:
    python generate_questions.py --theme "sorting algorithms" --section "Week 2" --course "Algorithm Programming 1" --count 1

Output: JSON array of question objects to stdout.
"""

import argparse
import json
import os
import sys

from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.messages import SystemMessage, HumanMessage


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


def generate_questions(
    theme: str, section: str, course: str, count: int, api_key: str
) -> list[dict]:
    """
    Generate essay-type questions using Gemini 2.5 Flash.

    Returns a list of dicts with 'name' and 'text' keys.
    """
    llm = ChatGoogleGenerativeAI(
        model="gemini-2.5-flash",
        google_api_key=api_key,
        temperature=0.8,
    )

    system_prompt = (
        "You are a university lecturer creating practice questions for students.\n"
        "You must generate programming/pseudocode questions that require students to write programs.\n\n"
        "Rules:\n"
        "- Each question must ask the student to CREATE a program or write pseudocode.\n"
        "- Each question must clearly specify the **Input** and **Output** format.\n"
        "- Questions should be practical and test understanding of the given topic.\n"
        "- Vary the difficulty across the questions (some easier, some harder).\n"
        "- Question names should be short and descriptive (max 8 words).\n"
        "- Question text should use bold (**text**) for emphasis on key terms.\n\n"
        "Your response MUST be a valid JSON array of objects, each with:\n"
        '- "name": a short title for the question\n'
        '- "text": the full question text with Input/Output specification\n\n'
        "Example output:\n"
        "```json\n"
        "[\n"
        '  {"name": "Sum of Array Elements", "text": "Create a program that calculates the sum of all elements in an array.\\n\\n'
        '**Input:** The first line contains an integer **n** (the number of elements). The second line contains **n** integers separated by spaces.\\n\\n'
        '**Output:** A single integer representing the sum of all elements."},\n'
        '  {"name": "Find Maximum Value", "text": "..."}\n'
        "]\n"
        "```\n"
        "Return ONLY the JSON array, no other text."
    )

    user_prompt = (
        f"Generate exactly {count} practice questions about **{theme}** "
        f"for the course \"{course}\", section \"{section}\".\n\n"
        f"Topic focus: {theme}\n"
        f"Question type: Essay / Program creation\n"
        f"Format: Students must write pseudocode or a program with clear input/output."
    )

    messages = [
        SystemMessage(content=system_prompt),
        HumanMessage(content=user_prompt),
    ]

    response = llm.invoke(messages)
    raw = response.content.strip() if response.content else ""

    # Parse the JSON response
    parsed = try_parse_json_array(raw)
    if parsed and len(parsed) > 0:
        # Validate structure
        valid = []
        for q in parsed:
            if isinstance(q, dict) and "name" in q and "text" in q:
                valid.append({"name": q["name"], "text": q["text"]})
        return valid

    raise ValueError(f"Failed to parse AI response as question array: {raw[:200]}")


def try_parse_json_array(text: str) -> list | None:
    """Extract and parse a JSON array from the AI response."""
    clean = text.strip()

    # Strip markdown code fences
    if clean.startswith("```"):
        first_newline = clean.index("\n") if "\n" in clean else len(clean)
        clean = clean[first_newline + 1 :]
    if clean.endswith("```"):
        clean = clean[:-3]
    clean = clean.strip()

    # Find the outermost JSON array
    start = clean.find("[")
    end = clean.rfind("]")
    if start != -1 and end != -1 and end > start:
        try:
            return json.loads(clean[start : end + 1])
        except json.JSONDecodeError:
            pass

    return None


def main():
    parser = argparse.ArgumentParser(
        description="Generate AI practice questions for Moodle"
    )
    parser.add_argument("--theme", required=True, help="Topic/theme for the questions")
    parser.add_argument("--section", default="", help="Course section name")
    parser.add_argument("--course", default="", help="Course name")
    parser.add_argument(
        "--count", type=int, default=1, help="Number of questions to generate"
    )

    args = parser.parse_args()

    load_env()

    gemini_api = os.environ.get("GEMINI_API")
    if not gemini_api:
        print(json.dumps({"status": "error", "message": "GEMINI_API missing in .env"}))
        sys.exit(1)

    try:
        questions = generate_questions(
            theme=args.theme,
            section=args.section,
            course=args.course,
            count=args.count,
            api_key=gemini_api,
        )
        print(
            json.dumps(
                {"status": "success", "questions": questions},
                ensure_ascii=False,
            )
        )
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
