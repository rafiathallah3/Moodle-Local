# setup.py
import subprocess
import sys
import os

def setup():
    print("🚀 Setup Agentic Multimodal AI Tutor...")
    
    # Install dependencies
    print("📦 Installing dependencies...")
    subprocess.check_call([sys.executable, "-m", "pip", "install", "-r", "requirements.txt"])
    
    # Create necessary directories
    os.makedirs("logs", exist_ok=True)
    os.makedirs("temp", exist_ok=True)
    
    print("✅ Setup complete!")
    print("\nRun: python main.py")

if __name__ == "__main__":
    setup()