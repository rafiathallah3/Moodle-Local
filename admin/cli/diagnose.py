import argparse
import base64
import json
import mimetypes
import os
import sys

from langchain_core.messages import HumanMessage, SystemMessage
from langchain_openai import ChatOpenAI
from pydantic import BaseModel, Field


def load_env():
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


class SyntaxOutput(BaseModel):
    is_valid: bool = Field(description="True if the pseudo-code has no major syntax errors")
    syntax_feedback: str = Field(
        description="A short feedback focusing solely on pseudo-code syntax. Point out structural errors based on grammar."
    )

class LogicDiagnosisOutput(BaseModel):
    mark: float = Field(
        description="The score determined for the student's answer out of the maximum mark."
    )
    logic_feedback: str = Field(
        description="A short summary and a light diagnosis/feedback of the student's logic."
    )

def diagnose_openai(text, media_path, question_text, max_mark, api_key, language):
    lang_map = {
        "id": "Indonesian",
        "en": "English",
        "sn": "Sundanese",
        "jw": "Javanese",
        "fr": "French",
    }
    raw_lang = language.lower().strip()
    lang_name = lang_map.get(raw_lang, raw_lang)

    if raw_lang == "id":
        label_syntax = "Pemeriksaan Sintaks"
        label_logic = "Pemeriksaan Logika"
    elif raw_lang == "sn":
        label_syntax = "Pamariksaan Sintaks"
        label_logic = "Pamariksaan Logika"
    elif raw_lang == "jw":
        label_syntax = "Pemeriksaan Sintaks"
        label_logic = "Pemeriksaan Logika"
    else:
        label_syntax = "Syntax Check"
        label_logic = "Logic Check"

    # Initialize the LLM with LangChain
    llm = ChatOpenAI(model="gpt-4o", openai_api_key=api_key)
    
    syntax_llm = llm.with_structured_output(SyntaxOutput)
    logic_llm = llm.with_structured_output(LogicDiagnosisOutput)
    
    # Process the user submission content (text or media)
    human_content = []

    if text:
        human_content.append({"type": "text", "text": f"Student Text Answer:\n{text}"})

    if not text and not media_path:
        human_content.append({"type": "text", "text": "No answer provided."})

    if media_path and os.path.exists(media_path):
        size = os.path.getsize(media_path)
        if size == 0:
            raise Exception("The recording file is empty (0 bytes).")

        mime_type, _ = mimetypes.guess_type(media_path)
        ext = os.path.splitext(media_path)[1].lower()

        if ext == ".webm":
            mime_type = "audio/webm"
        elif not mime_type or "/" not in mime_type:
            if ext in (".jpg", ".jpeg", ".png", ".gif", ".webp"):
                mime_type = "image/jpeg"
            elif ext in (".mp4", ".avi", ".mov"):
                mime_type = "video/mp4"
            else:
                mime_type = "audio/mpeg"  # fallback

        with open(media_path, "rb") as f:
            media_data = base64.b64encode(f.read()).decode("utf-8")

        if mime_type.startswith("image/"):
            human_content.append(
                {
                    "type": "image_url",
                    "image_url": {"url": f"data:{mime_type};base64,{media_data}"},
                }
            )
        else:
            human_content.append(
                {"type": "media", "mime_type": mime_type, "data": media_data}
            )
            if not text:
                human_content.append(
                    {
                        "type": "text",
                        "text": "Please transcribe and evaluate this audio/video directly.",
                    }
                )
                
    # --- AGENT 1: SYNTAX CHECKER ---
    syntax_instruction = (
        "You are an AI Syntax Checker Agent for pseudo-code.\n"
        "Here are the pseudo-code grammar rules you MUST check for:\n"
        "1. MUST start with 'program [Name]'.\n"
        "2. MUST end with 'endprogram'.\n"
        "3. MAY contain a 'dictionary' (or 'kamus') section for variable declarations. If present, it must be labeled 'dictionary' or 'kamus'.\n"
        "4. MAY contain 'function' or 'procedure' blocks. These can appear after 'dictionary' and before 'algorithm'.\n"
        "5. MUST contain an 'algorithm' (or 'algoritma') section where the main logic starts.\n"
        "6. The assignment operator is `<-`. (Do NOT flag this as incorrect).\n\n"
        "IMPORTANT:\n"
        "- Never claim 'dictionary' or 'algorithm' is missing if the word is explicitly written in the pseudo-code.\n"
        "- Do NOT be confused by 'function' declarations existing outside the main algorithm.\n"
        "- Output your feedback in plain text without HTML tags (no <br>, <strong>, etc).\n"
        "- Do not evaluate logical correctness, only focus on structural grammar and syntax.\n"
        f"- TRANSLATION REQUIREMENT: You MUST write your 'syntax_feedback' strictly in {lang_name}."
    )
    
    syntax_messages = [SystemMessage(content=syntax_instruction)]
    if human_content:
        syntax_messages.append(HumanMessage(content=human_content))
        
    try:
        syntax_response = syntax_llm.invoke(syntax_messages)
    except Exception as e:
        error_msg = str(e)
        if "SAFETY" in error_msg.upper():
            return {
                "mark": 0.0,
                "diagnosis": "The AI Syntax Agent response was blocked due to safety filters.",
            }
        raise Exception(f"LangChain OpenAI API error (Syntax Agent): {error_msg}")

    # --- AGENT 2: LOGIC CHECKER ---
    logic_instruction = (
        "You are an AI Logic Checker Agent tasked with evaluating a student's answer and assigning a score.\n"
        "Please evaluate the logical correctness of the response inside the 'algorithm' block "
        "and determine a score for the student's answer out of a maximum of {max_mark}.\n"
        "Keep your diagnosis concise, helpful, and constructive. You will receive the Syntax Agent's feedback to help factor any severe syntax errors into your grading.\n"
        "Keep your output in plain text. Do NOT use HTML tags such as <br> or <strong>.\n"
        f"TRANSLATION REQUIREMENT: You MUST write your 'logic_feedback' strictly in {lang_name}."
    ).format(max_mark=max_mark)

    if question_text:
        logic_instruction += f"\n\nQuestion/Assignment Description:\n{question_text}"
        
    logic_instruction += (
        f"\n\n--- Syntax Agent Feedback ---\n"
        f"Is Valid: {syntax_response.is_valid}\n"
        f"Feedback: {syntax_response.syntax_feedback}\n"
    )

    logic_messages = [SystemMessage(content=logic_instruction)]
    if human_content:
        logic_messages.append(HumanMessage(content=human_content))
        
    try:
        logic_response = logic_llm.invoke(logic_messages)
        
        final_diagnosis = (
            f"{label_syntax}:\n{syntax_response.syntax_feedback}\n\n"
            f"{label_logic}:\n{logic_response.logic_feedback}"
        )
        
        # --- DEBUG LOGGING ---
        try:
            debug_path = os.path.join(os.path.dirname(__file__), "debug_diagnose.log")
            with open(debug_path, "a", encoding="utf-8") as df:
                df.write(f"--- DEBUG DIAGNOSE RUN ---\n")
                df.write(f"Raw Lang: {raw_lang} | Resolved Lang Name: {lang_name}\n")
                df.write(f"SYNTAX PROMPT:\n{syntax_instruction}\n\n")
                df.write(f"LOGIC PROMPT:\n{logic_instruction}\n\n")
                if human_content:
                    df.write(f"HUMAN CONTENT:\n{json.dumps(human_content, indent=2)}\n\n")
                df.write(f"SYNTAX RESULT:\n{syntax_response.model_dump_json()}\n\n")
                df.write(f"LOGIC RESULT:\n{logic_response.model_dump_json()}\n\n")
                df.write("----------------------------\n")
        except Exception:
            pass
        # ---------------------

        return {"mark": float(logic_response.mark), "diagnosis": final_diagnosis}

    except Exception as e:
        error_msg = str(e)
        if "SAFETY" in error_msg.upper():
            return {
                "mark": 0.0,
                "diagnosis": "The AI Logic Agent response was blocked due to safety filters.",
            }
        raise Exception(f"LangChain OpenAI API error (Logic Agent): {error_msg}")


def main():
    parser = argparse.ArgumentParser(description="Diagnose student answer using Gemini")
    parser.add_argument("--textfile", help="Path to text file containing student text")
    parser.add_argument("--file", help="Path to media file (audio/image/video)")
    parser.add_argument(
        "--questiontextfile", help="Path to text file containing question description"
    )
    parser.add_argument("--maxmark", type=float, help="Maximum mark", default=100.0)
    parser.add_argument("--language", help="Output language code (e.g., 'id', 'en')", default="en")

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
        result = diagnose_openai(
            text, args.file, question_text, args.maxmark, openai_api, args.language
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
