import os
import sys
import json
import argparse
import base64
import urllib.request
import urllib.parse
import mimetypes

def load_env():
    env_path = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), '.env')
    if os.path.exists(env_path):
        with open(env_path, 'r') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'): continue
                if '=' in line:
                    k, v = line.split('=', 1)
                    os.environ[k.strip()] = v.strip().strip("'").strip('"')

def diagnose_gemini(text, media_path, api_key):
    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={api_key}"
    
    parts = []
    
    # Base prompt
    prompt = "Please provide a short summary and a light diagnosis/feedback of the following student answer. Keep it concise, helpful, and constructive."
    parts.append({"text": prompt})
    
    if text:
        parts.append({"text": f"Student Text Answer:\n{text}"})
        
    if media_path and os.path.exists(media_path):
        mime_type, _ = mimetypes.guess_type(media_path)
        if not mime_type:
            # simple guess based on extension
            if media_path.lower().endswith(('.jpg', '.jpeg', '.png', '.gif', '.webp')):
                mime_type = 'image/jpeg'
            elif media_path.lower().endswith(('.mp4', '.avi', '.mov')):
                mime_type = 'video/mp4'
            else:
                mime_type = 'audio/mp3' # fallback
                
        with open(media_path, "rb") as f:
            media_data = base64.b64encode(f.read()).decode('utf-8')
            
        parts.append({
            "inline_data": {
                "mime_type": mime_type,
                "data": media_data
            }
        })
    
    payload = {
        "contents": [
            {
                "parts": parts
            }
        ]
    }
    
    req = urllib.request.Request(url, data=json.dumps(payload).encode('utf-8'), headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req) as response:
            res_data = json.loads(response.read().decode('utf-8'))
            return res_data['candidates'][0]['content']['parts'][0]['text']
    except Exception as e:
        raise Exception(f"Gemini API error: {e}")

def main():
    parser = argparse.ArgumentParser(description="Diagnose student answer using Gemini")
    parser.add_argument("--textfile", help="Path to text file containing student text")
    parser.add_argument("--file", help="Path to media file (audio/image/video)")
    
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
            
    if not text and not args.file:
        print(json.dumps({"status": "error", "message": "No text or file provided"}))
        sys.exit(1)
            
    try:
        diagnosis = diagnose_gemini(text, args.file, gemini_api)
        
        print(json.dumps({
            "status": "success",
            "diagnosis": diagnosis
        }))
        
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
