import os
import sys
import json
import argparse
import base64
import urllib.request
import urllib.parse
import mimetypes
import urllib.error

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
    # Use v1beta endpoint for gemini-2.5-flash as it is a preview/bleeding edge model
    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={api_key}"
    
    parts = []
    
    # Combined Instruction - Instruction should ideally come BEFORE media components
    instruction = "Please provide a short summary and a light diagnosis/feedback of the following student answer. Keep it concise, helpful, and constructive."
    if text:
        instruction += f"\n\nStudent Text Answer:\n{text}"
    
    parts.append({"text": instruction})
        
    if media_path and os.path.exists(media_path):
        size = os.path.getsize(media_path)
        if size == 0:
            raise Exception("The recording file is empty (0 bytes).")
            
        mime_type, _ = mimetypes.guess_type(media_path)
        ext = os.path.splitext(media_path)[1].lower()
        
        # More robust MIME type logic for Gemini 2.x
        if ext == '.webm':
            # Browser-recorded audio is often contained in video/webm. 
            # Trying audio/webm first, but video/webm is often safer for MediaRecorder files.
            mime_type = 'audio/webm'
        elif not mime_type or '/' not in mime_type:
            if ext in ('.jpg', '.jpeg', '.png', '.gif', '.webp'):
                mime_type = 'image/jpeg'
            elif ext in ('.mp4', '.avi', '.mov'):
                mime_type = 'video/mp4'
            else:
                mime_type = 'audio/mpeg' # fallback
                
        with open(media_path, "rb") as f:
            media_data = base64.b64encode(f.read()).decode('utf-8')
            
        parts.append({
            "inlineData": {
                "mimeType": mime_type,
                "data": media_data
            }
        })
    
    payload = {
        "contents": [
            {
                "role": "user",
                "parts": parts
            }
        ]
    }
    
    req = urllib.request.Request(url, data=json.dumps(payload).encode('utf-8'), headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req) as response:
            res_data = json.loads(response.read().decode('utf-8'))
            
            diagnosis = ""
            if 'candidates' in res_data and res_data['candidates']:
                candidate = res_data['candidates'][0]
                content = candidate.get('content', {})
                for part in content.get('parts', []):
                    # In Gemini 2.x, some parts might be 'thought' parts. We filter for 'text'.
                    if 'text' in part:
                        diagnosis += part['text']
                
                # If still empty, check if it was blocked or just didn't return text
                if not diagnosis:
                    if candidate.get('finishReason') == 'SAFETY':
                        return "The AI response was blocked due to safety filters."
                    if 'thought' in content.get('parts', [{}])[0]:
                        # Handle case where only thoughts are returned? (Unlikely for Flash)
                        return "The AI started thinking but didn't provide a final answer."
            
            if not diagnosis:
                return "No text response received from AI. This can happen if the audio was unclear or safety filters were triggered."
                
            return diagnosis
            
    except urllib.error.HTTPError as e:
        error_body = e.read().decode('utf-8')
        try:
            error_json = json.loads(error_body)
            error_msg = error_json.get('error', {}).get('message', str(e))
        except:
            error_msg = error_body if error_body else str(e)
        raise Exception(f"Gemini API error ({e.code}): {error_msg}")
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
        print(json.dumps({"status": "success", "diagnosis": diagnosis}))
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
