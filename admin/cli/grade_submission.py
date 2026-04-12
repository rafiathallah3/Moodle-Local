"""
AI Grading Script for Moodle Quiz Submissions.

Uses the AnalyzerAgent's sub-agents (StyleCheckerAgent + LogicCheckerAgent)
from the agentic pipeline to grade student essay/pseudo-code answers.

Called by diagnose_quiz_attempt_task.php after a student submits a quiz attempt.

Usage:
    python grade_submission.py --textfile student.txt --questiontextfile question.txt --maxmark 100 --language en [--file media.png]

Output: JSON to stdout:
    {"status": "success", "mark": 85.0, "diagnosis": "Syntax Check: ...\n\nLogic Check: ..."}
"""

import argparse
import json
import os
import sys


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


# Add project root to sys.path so we can import agents/
_project_root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
if _project_root not in sys.path:
    sys.path.insert(0, _project_root)


def grade_with_analyzer(text, media_path, question_text, max_mark, language):
    """
    Grade a student submission using the AnalyzerAgent's LLM-based sub-agents.

    Uses StyleCheckerAgent.check_with_llm() for syntax analysis and
    LogicCheckerAgent.check_with_llm() for logic evaluation and scoring.

    Returns:
        dict with 'mark' (float) and 'diagnosis' (str) keys.
    """
    from agents.analyzer import StyleCheckerAgent, LogicCheckerAgent

    style_checker = StyleCheckerAgent()
    logic_checker = LogicCheckerAgent()

    # Resolve language labels for the diagnosis output
    lang_map = {
        "id": "Indonesian",
        "en": "English",
        "sn": "Sundanese",
        "jw": "Javanese",
        "fr": "French",
    }
    raw_lang = language.lower().strip()

    if raw_lang == "id":
        label_syntax, label_logic = "Pemeriksaan Sintaks", "Pemeriksaan Logika"
    elif raw_lang == "sn":
        label_syntax, label_logic = "Pamariksaan Sintaks", "Pamariksaan Logika"
    elif raw_lang == "jw":
        label_syntax, label_logic = "Pemeriksaan Sintaks", "Pemeriksaan Logika"
    else:
        label_syntax, label_logic = "Syntax Check", "Logic Check"

    # Step 1: Syntax check via StyleCheckerAgent (LLM mode)
    syntax_result = style_checker.check_with_llm(text, raw_lang, media_path)

    # Step 2: Logic check via LogicCheckerAgent (LLM mode)
    logic_result = logic_checker.check_with_llm(
        text, raw_lang, max_mark, question_text, syntax_result, media_path
    )

    # Compose the final diagnosis string
    diagnosis = (
        f"{label_syntax}:\n{syntax_result['syntax_feedback']}\n\n"
        f"{label_logic}:\n{logic_result['logic_feedback']}"
    )

    mark = float(logic_result["mark"])
    if mark > max_mark:
        mark = max_mark
    if mark < 0:
        mark = 0

    return {"mark": mark, "diagnosis": diagnosis}


def main():
    parser = argparse.ArgumentParser(
        description="Grade student submission using AnalyzerAgent"
    )
    parser.add_argument("--textfile", help="Path to text file containing student text")
    parser.add_argument("--file", help="Path to media file (audio/image/video)")
    parser.add_argument(
        "--questiontextfile", help="Path to text file containing question description"
    )
    parser.add_argument("--maxmark", type=float, help="Maximum mark", default=100.0)
    parser.add_argument(
        "--language",
        help="Output language code (e.g., 'id', 'en')",
        default="en",
    )

    args = parser.parse_args()

    load_env()

    openai_api = os.environ.get("OPENAI_API")
    if not openai_api:
        print(json.dumps({"status": "error", "message": "OPENAI_API missing in .env"}))
        sys.exit(1)

    text = ""
    if args.textfile and os.path.exists(args.textfile):
        with open(args.textfile, "r", encoding="utf-8") as f:
            text = f.read().strip()

    question_text = ""
    if args.questiontextfile and os.path.exists(args.questiontextfile):
        with open(args.questiontextfile, "r", encoding="utf-8") as f:
            question_text = f.read().strip()

    if not text and not args.file:
        print(json.dumps({"status": "error", "message": "No text or file provided"}))
        sys.exit(1)

    try:
        result = grade_with_analyzer(
            text, args.file, question_text, args.maxmark, args.language
        )
        print(
            json.dumps(
                {
                    "status": "success",
                    "diagnosis": result["diagnosis"],
                    "mark": result["mark"],
                }
            )
        )
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
