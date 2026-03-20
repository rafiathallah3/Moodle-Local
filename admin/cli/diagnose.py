import argparse
import base64
import json
import mimetypes
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


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


def diagnose_gemini(text, media_path, question_text, max_mark, api_key):
    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={api_key}"

    parts = []

    instruction = (
        "You are an AI assistant tasked with evaluating a student's answer.\n"
        "Please provide a short summary and a light diagnosis/feedback of the student's answer. "
        "Keep it concise, helpful, and constructive.\n"
        f"You must also determine a score for the student's answer out of a maximum of {max_mark}.\n"
        "IMPORTANT: Your response MUST be valid JSON in the exact following format:\n"
        "```json\n"
        "{\n"
        '  "mark": <float>,\n'
        '  "diagnosis": "<string>"\n'
        "}\n"
        "```\n"
        "Do not include any text outside of the JSON block."
    )
    
    if question_text:
        instruction += f"\n\nQuestion/Assignment Description:\n{question_text}"

    if text:
        instruction += f"\n\nStudent Text Answer:\n{text}"

    parts.append({"text": instruction})

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

        parts.append({"inlineData": {"mimeType": mime_type, "data": media_data}})

    payload = {"contents": [{"role": "user", "parts": parts}]}

    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req) as response:
            res_data = json.loads(response.read().decode("utf-8"))

            diagnosis = ""
            if "candidates" in res_data and res_data["candidates"]:
                candidate = res_data["candidates"][0]
                content = candidate.get("content", {})
                for part in content.get("parts", []):
                    if "text" in part:
                        diagnosis += part["text"]

                if not diagnosis:
                    if candidate.get("finishReason") == "SAFETY":
                        return {"mark": 0.0, "diagnosis": "The AI response was blocked due to safety filters."}
                    if "thought" in content.get("parts", [{}])[0]:
                        return {"mark": 0.0, "diagnosis": "The AI started thinking but didn't provide a final answer."}

            if not diagnosis:
                return {"mark": 0.0, "diagnosis": "No text response received from AI. This can happen if the audio was unclear or safety filters were triggered."}

            diagnosis = diagnosis.strip()
            if diagnosis.startswith("```json"):
                diagnosis = diagnosis[7:]
            if diagnosis.startswith("```"):
                diagnosis = diagnosis[3:]
            if diagnosis.endswith("```"):
                diagnosis = diagnosis[:-3]
            diagnosis = diagnosis.strip()

            try:
                result_json = json.loads(diagnosis)
                return {
                    "mark": float(result_json.get("mark", 0.0)),
                    "diagnosis": result_json.get("diagnosis", diagnosis)
                }
            except Exception as e:
                return {"mark": 0.0, "diagnosis": diagnosis}

    except urllib.error.HTTPError as e:
        error_body = e.read().decode("utf-8")
        try:
            error_json = json.loads(error_body)
            error_msg = error_json.get("error", {}).get("message", str(e))
        except:
            error_msg = error_body if error_body else str(e)
        raise Exception(f"Gemini API error ({e.code}): {error_msg}")
    except Exception as e:
        raise Exception(f"Gemini API error: {e}")


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
