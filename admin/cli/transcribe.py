import os
import sys
import json
import argparse
import subprocess
import tempfile
import urllib.request
import urllib.parse
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

def transcribe_openai(audio_path, api_key):
    # Whisper handles multiple formats including webm
    cmd = [
        "curl", "-s",
        "https://api.openai.com/v1/audio/transcriptions",
        "-H", f"Authorization: Bearer {api_key}",
        "-F", f"file=@{audio_path}",
        "-F", "model=whisper-1"
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        raise Exception(f"cURL failed: {result.stderr}")
    data = json.loads(result.stdout)
    if "text" in data:
        return data["text"]
    elif "error" in data:
         raise Exception(f"OpenAI error: {data['error'].get('message', str(data['error']))}")
    else:
        raise Exception(f"OpenAI unknown error: {result.stdout}")

def transcribe_azure(audio_path, api_key):
    region = os.environ.get("AZURE_REGION", os.environ.get("AZURESPEECH_REGION", "eastus"))
    
    # Azure REST API prefers 16kHz Mono WAV (PCM)
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        tmp_path = tmp.name
    
    try:
        # Convert to 16kHz mono PCM WAV using ffmpeg
        cmd_convert = [
            "ffmpeg", "-y", "-i", audio_path,
            "-ar", "16000", "-ac", "1", "-f", "wav", tmp_path
        ]
        subprocess.run(cmd_convert, check=True, capture_output=True)

        url = f"https://{region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=en-US"
        
        cmd = [
            "curl", "-s", "-X", "POST", url,
            "-H", f"Ocp-Apim-Subscription-Key: {api_key}",
            "-H", "Content-Type: audio/wav; codecs=audio/pcm; samplerate=16000",
            "--data-binary", f"@{tmp_path}"
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            raise Exception(f"cURL failed: {result.stderr}")
        
        data = json.loads(result.stdout)

        if "DisplayText" in data:
            return data["DisplayText"]
        elif "Message" in data:
             raise Exception(f"Azure error: {data['Message']}")
        else:
            raise Exception(f"Azure unknown error: {result.stdout}")
    finally:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)

def transcribe_gemini(audio_path, api_key):
    import base64
    import mimetypes
    # Use v1beta for 2.5-flash
    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={api_key}"
    
    mime_type, _ = mimetypes.guess_type(audio_path)
    ext = os.path.splitext(audio_path)[1].lower()
    if ext == '.webm':
        mime_type = 'audio/webm'
    elif not mime_type:
        mime_type = 'audio/mpeg'

    with open(audio_path, "rb") as f:
        audio_data = base64.b64encode(f.read()).decode('utf-8')
    
    payload = {
        "contents": [
            {
                "parts": [
                    {"text": "Please transcribe the following audio accurately. Output only the transcript text."},
                    {
                        "inlineData": {
                            "mimeType": mime_type,
                            "data": audio_data
                        }
                    }
                ]
            }
        ]
    }
    
    req = urllib.request.Request(url, data=json.dumps(payload).encode('utf-8'), headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req) as response:
            res_data = json.loads(response.read().decode('utf-8'))
            
            transcript = ""
            if 'candidates' in res_data and res_data['candidates']:
                content = res_data['candidates'][0].get('content', {})
                for part in content.get('parts', []):
                    if 'text' in part:
                        transcript += part['text']
            
            return transcript.strip()
    except urllib.error.HTTPError as e:
        error_body = e.read().decode('utf-8')
        try:
            error_json = json.loads(error_body)
            error_msg = error_json.get('error', {}).get('message', str(e))
        except:
            error_msg = error_body if error_body else str(e)
        raise Exception(f"Gemini API error ({e.code}): {error_msg}")
    except Exception as e:
        raise Exception(f"Gemini transcription error: {e}")

def main():
    parser = argparse.ArgumentParser(description="Transcribe audio using AI models")
    parser.add_argument("--audio", required=True, help="Path to the audio file")
    parser.add_argument("--model", required=True, choices=["openai", "azure", "gemini"], help="Model to use for transcription")
    parser.add_argument("--prompt", default="Please summarize the following transcript:", help="Prompt for Gemini")
    
    args = parser.parse_args()
    
    load_env()
    
    openai_api = os.environ.get("OPENAI_API")
    azure_api = os.environ.get("AZURESPEECH_API")
    gemini_api = os.environ.get("GEMINI_API")
    
    if args.model == "openai" and not openai_api:
        print(json.dumps({"status": "error", "message": "OPENAI_API missing in .env"}))
        sys.exit(1)
        
    if args.model == "azure" and not azure_api:
        print(json.dumps({"status": "error", "message": "AZURESPEECH_API missing in .env"}))
        sys.exit(1)
        
    if not gemini_api:
        print(json.dumps({"status": "error", "message": "GEMINI_API missing in .env"}))
        sys.exit(1)
        
    try:
        import time
        start_time = time.time()
        
        if args.model == "openai":
            transcript = transcribe_openai(args.audio, openai_api)
        elif args.model == "azure":
            transcript = transcribe_azure(args.audio, azure_api)
        elif args.model == "gemini":
            transcript = transcribe_gemini(args.audio, gemini_api)
        else:
            raise Exception("Invalid model selected")
            
        transcribe_time = time.time() - start_time
        
        print(json.dumps({
            "status": "success",
            "model": args.model,
            "transcript": transcript,
            "transcribe_time": transcribe_time,
        }))
        
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
