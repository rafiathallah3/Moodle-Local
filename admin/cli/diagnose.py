import argparse
import base64
import json
import mimetypes
import os
import sys

from pydantic import BaseModel, Field
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_core.messages import SystemMessage, HumanMessage


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


class DiagnosisOutput(BaseModel):
    mark: float = Field(description="The score determined for the student's answer out of the maximum mark.")
    diagnosis: str = Field(description="A short summary and a light diagnosis/feedback of the student's answer.")


def diagnose_gemini(text, media_path, question_text, max_mark, api_key):
    # Initialize the LLM with LangChain
    llm = ChatGoogleGenerativeAI(
        model="gemini-2.5-flash", 
        google_api_key=api_key
    )
    
    # Force structured output returning our Pydantic model
    structured_llm = llm.with_structured_output(DiagnosisOutput)

    instruction = (
        "You are an AI assistant tasked with evaluating a student's answer.\n"
        "Please provide a short summary and a light diagnosis/feedback of the student's answer. "
        "Keep it concise, helpful, and constructive.\n"
        f"You must determine a score for the student's answer out of a maximum of {max_mark}.\n"
    )
    
    if question_text:
        instruction += f"\n\nQuestion/Assignment Description:\n{question_text}"

    messages = [
        SystemMessage(content=instruction)
    ]
    
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
            human_content.append({
                "type": "image_url",
                "image_url": {"url": f"data:{mime_type};base64,{media_data}"}
            })
        else:
            human_content.append({
                "type": "media",
                "mime_type": mime_type,
                "data": media_data
            })
            if not text:
               human_content.append({"type": "text", "text": "Please transcribe and evaluate this audio/video directly."})

    if human_content:
        messages.append(HumanMessage(content=human_content))

    try:
        response = structured_llm.invoke(messages)
        
        return {
            "mark": float(response.mark),
            "diagnosis": response.diagnosis
        }
        
    except Exception as e:
        error_msg = str(e)
        if "SAFETY" in error_msg.upper():
            return {"mark": 0.0, "diagnosis": "The AI response was blocked due to safety filters."}
        raise Exception(f"LangChain Gemini API error: {error_msg}")


def main():
    parser = argparse.ArgumentParser(description="Diagnose student answer using Gemini")
    parser.add_argument("--textfile", help="Path to text file containing student text")
    parser.add_argument("--file", help="Path to media file (audio/image/video)")
    parser.add_argument("--questiontextfile", help="Path to text file containing question description")
    parser.add_argument("--maxmark", type=float, help="Maximum mark", default=100.0)

    args = parser.parse_args()

    load_env()

    gemini_api = os.environ.get("GEMINI_API")

    if not gemini_api:
        print(json.dumps({"status": "error", "message": "GEMINI_API missing in .env"}))
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
        result = diagnose_gemini(text, args.file, question_text, args.maxmark, gemini_api)
        print(json.dumps({
            "status": "success", 
            "diagnosis": result["diagnosis"],
            "mark": result["mark"]
        }))
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
