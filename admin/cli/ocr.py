import argparse
import base64
import json
import mimetypes
import os
import ssl
import sys
import time
import urllib.error
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


def make_ssl_context():
    try:
        ctx = ssl.create_default_context()
        return ctx
    except Exception:
        ctx = ssl._create_unverified_context()
        return ctx


def urlopen_with_fallback(req, timeout=30):
    try:
        ctx = ssl.create_default_context()
        return urllib.request.urlopen(req, context=ctx, timeout=timeout)
    except ssl.SSLError:
        pass
    except urllib.error.URLError as e:
        if "SSL" in str(e) or "CERTIFICATE" in str(e):
            pass
        else:
            raise

    ctx = ssl._create_unverified_context()
    return urllib.request.urlopen(req, context=ctx, timeout=timeout)


def get_image_mimetype(image_path):
    mime_type, _ = mimetypes.guess_type(image_path)
    ext = os.path.splitext(image_path)[1].lower()
    if not mime_type or not mime_type.startswith("image/"):
        mime_map = {
            ".jpg": "image/jpeg",
            ".jpeg": "image/jpeg",
            ".png": "image/png",
            ".gif": "image/gif",
            ".webp": "image/webp",
            ".bmp": "image/bmp",
            ".tiff": "image/tiff",
            ".tif": "image/tiff",
        }
        mime_type = mime_map.get(ext, "image/jpeg")
    return mime_type


def ocr_gemini(image_path, api_key):
    """
    Extract text from an image using Gemini 2.5 Flash vision.
    Returns the extracted text string, or the fallback message if no text found.
    """
    url = (
        "https://generativelanguage.googleapis.com/v1beta/models/"
        f"gemini-2.5-flash:generateContent?key={api_key}"
    )

    mime_type = get_image_mimetype(image_path)

    with open(image_path, "rb") as f:
        image_data = base64.b64encode(f.read()).decode("utf-8")

    payload = {
        "contents": [
            {
                "parts": [
                    {
                        "text": (
                            "You are an OCR engine. Your only task is to extract every piece "
                            "of text that is visible in the provided image. "
                            "Output the extracted text exactly as it appears, preserving "
                            "line breaks and spacing where possible. "
                            "Do NOT add any commentary, explanation, or formatting. "
                            "If the image contains absolutely no readable text, respond with "
                            "exactly the following sentence and nothing else: "
                            "Text not found inside the image."
                        )
                    },
                    {"inlineData": {"mimeType": mime_type, "data": image_data}},
                ]
            }
        ],
        "generationConfig": {"temperature": 0, "maxOutputTokens": 4096},
    }

    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
    )

    try:
        with urlopen_with_fallback(req, timeout=30) as response:
            res_data = json.loads(response.read().decode("utf-8"))

            extracted_text = ""
            if "candidates" in res_data and res_data["candidates"]:
                candidate = res_data["candidates"][0]

                finish_reason = candidate.get("finishReason", "")
                if finish_reason == "SAFETY":
                    return "Text not found inside the image."

                content = candidate.get("content", {})
                for part in content.get("parts", []):
                    if "text" in part:
                        extracted_text += part["text"]

            extracted_text = extracted_text.strip()
            if not extracted_text:
                return "Text not found inside the image."

            return extracted_text

    except urllib.error.HTTPError as e:
        error_body = e.read().decode("utf-8")
        try:
            error_json = json.loads(error_body)
            error_msg = error_json.get("error", {}).get("message", str(e))
        except Exception:
            error_msg = error_body if error_body else str(e)
        raise Exception(f"Gemini API error ({e.code}): {error_msg}")
    except urllib.error.URLError as e:
        raise Exception(f"Gemini network error: {e.reason}")
    except TimeoutError:
        raise Exception("Gemini API timed out after 30 seconds.")
    except Exception as e:
        raise Exception(f"Gemini OCR error: {e}")


def ocr_openai(image_path, api_key):
    import subprocess

    mime_type = get_image_mimetype(image_path)

    with open(image_path, "rb") as f:
        image_data = base64.b64encode(f.read()).decode("utf-8")

    payload = {
        "model": "gpt-4o",
        "messages": [
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": (
                            "You are an OCR engine. Your only task is to extract every piece "
                            "of text that is visible in the provided image. "
                            "Output the extracted text exactly as it appears, preserving "
                            "line breaks and spacing where possible. "
                            "Do NOT add any commentary, explanation, or formatting. "
                            "If the image contains absolutely no readable text, respond with "
                            "exactly the following sentence and nothing else: "
                            "Text not found inside the image."
                        ),
                    },
                    {
                        "type": "image_url",
                        "image_url": {
                            "url": f"data:{mime_type};base64,{image_data}",
                            "detail": "high",
                        },
                    },
                ],
            }
        ],
        "max_tokens": 4096,
        "temperature": 0,
    }

    payload_json = json.dumps(payload)

    cmd = [
        "curl",
        "-s",
        "--max-time",
        "30",
        "-X",
        "POST",
        "https://api.openai.com/v1/chat/completions",
        "-H",
        f"Authorization: Bearer {api_key}",
        "-H",
        "Content-Type: application/json",
        "-d",
        payload_json,
    ]

    result = subprocess.run(cmd, capture_output=True, text=True, timeout=35)
    if result.returncode != 0:
        raise Exception(f"cURL failed (exit {result.returncode}): {result.stderr}")

    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError:
        raise Exception(f"Could not parse OpenAI response: {result.stdout[:300]}")

    if "error" in data:
        raise Exception(
            f"OpenAI error: {data['error'].get('message', str(data['error']))}"
        )

    extracted = (
        data.get("choices", [{}])[0].get("message", {}).get("content", "").strip()
    )

    if not extracted:
        return "Text not found inside the image."

    return extracted


def main():
    parser = argparse.ArgumentParser(
        description="OCR: Extract text from an image using AI vision models"
    )
    parser.add_argument(
        "--image", required=True, help="Absolute path to the image file"
    )
    parser.add_argument(
        "--model",
        default="gemini",
        choices=["gemini", "openai"],
        help="AI model to use for OCR (default: gemini)",
    )

    args = parser.parse_args()

    load_env()

    gemini_api = os.environ.get("GEMINI_API")
    openai_api = os.environ.get("OPENAI_API")

    # Validate API key availability
    if args.model == "gemini" and not gemini_api:
        print(
            json.dumps(
                {"status": "error", "message": "GEMINI_API key is missing in .env"}
            ),
            flush=True,
        )
        sys.exit(1)

    if args.model == "openai" and not openai_api:
        print(
            json.dumps(
                {"status": "error", "message": "OPENAI_API key is missing in .env"}
            ),
            flush=True,
        )
        sys.exit(1)

    # Validate file existence
    if not os.path.exists(args.image):
        print(
            json.dumps(
                {"status": "error", "message": f"Image file not found: {args.image}"}
            ),
            flush=True,
        )
        sys.exit(1)

    # Validate file is non-empty
    if os.path.getsize(args.image) == 0:
        print(
            json.dumps(
                {"status": "error", "message": "Image file is empty (0 bytes)."}
            ),
            flush=True,
        )
        sys.exit(1)

    try:
        start_time = time.time()

        if args.model == "gemini":
            text = ocr_gemini(args.image, gemini_api)
        elif args.model == "openai":
            text = ocr_openai(args.image, openai_api)
        else:
            raise Exception(f"Unsupported model: {args.model}")

        elapsed = round(time.time() - start_time, 3)

        print(
            json.dumps(
                {
                    "status": "success",
                    "model": args.model,
                    "text": text,
                    "elapsed_time": elapsed,
                }
            ),
            flush=True,
        )

    except Exception as e:
        print(
            json.dumps({"status": "error", "message": str(e)}),
            flush=True,
        )
        sys.exit(1)


if __name__ == "__main__":
    main()
