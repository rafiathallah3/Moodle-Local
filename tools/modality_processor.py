
"""
Multimodal Processor - OCR, ASR, and Fusion
Dari paper "Handwritten Code Recognition" dan "Agentic Multimodal AI Tutor"
"""
from typing import Dict, Any, Optional
from logic_scratch.schemas import FusedContext

class OCREngine:
    """Optical Character Recognition untuk handwritten pseudocode"""
    
    def __init__(self, provider: str = "tesseract"):
        self.provider = provider
    
    def extract(self, image_data: bytes) -> Dict[str, Any]:
        """Extract text dari image"""
        try:
            if self.provider == "tesseract":
                import pytesseract
                from PIL import Image
                import io
                
                image = Image.open(io.BytesIO(image_data))
                text = pytesseract.image_to_string(image)
                return {
                    "text": text,
                    "confidence": 0.85,  # Tesseract confidence estimation
                    "provider": "tesseract"
                }
            elif self.provider == "easyocr":
                import easyocr
                reader = easyocr.Reader(['en'])
                results = reader.readtext(image_data)
                text = "\\n".join([r[1] for r in results])
                avg_conf = sum([r[2] for r in results]) / len(results) if results else 0
                return {
                    "text": text,
                    "confidence": avg_conf,
                    "provider": "easyocr"
                }
        except ImportError:
            print(f"[OCR] Warning: {self.provider} not installed")
            return {"text": "[OCR unavailable - install pytesseract/easyocr]", "confidence": 0}
        except Exception as e:
            return {"text": f"[OCR Error: {str(e)}]", "confidence": 0}

class ASREngine:
    """Automatic Speech Recognition untuk oral explanation"""
    
    def __init__(self, provider: str = "whisper"):
        self.provider = provider
    
    def transcribe(self, audio_data: bytes) -> Dict[str, Any]:
        """Transcribe audio ke text"""
        try:
            if self.provider == "whisper":
                import whisper
                model = whisper.load_model("base")
                
                # Save to temp file karena whisper butuh file path
                import tempfile
                with tempfile.NamedTemporaryFile(delete=False, suffix=".wav") as tmp:
                    tmp.write(audio_data)
                    tmp_path = tmp.name
                
                result = model.transcribe(tmp_path)
                return {
                    "text": result["text"],
                    "language": result.get("language", "en"),
                    "confidence": result.get("confidence", 0.8),
                    "provider": "whisper"
                }
            elif self.provider == "google":
                # Placeholder untuk Google Speech-to-Text
                return {"text": "[Google ASR placeholder]", "language": "en", "confidence": 0.9}
        except ImportError:
            print(f"[ASR] Warning: {self.provider} not installed")
            return {"text": "[ASR unavailable - install openai-whisper]", "confidence": 0}
        except Exception as e:
            return {"text": f"[ASR Error: {str(e)}]", "confidence": 0}

class ModalityFusion:
    """Fusion multimodal inputs"""
    
    def fuse(self, text: str, ocr_result: Optional[Dict], asr_result: Optional[Dict]) -> FusedContext:
        """Fusion strategy: weighted concatenation"""
        
        fused = FusedContext(
            primary_text=text,
            confidence_scores={"text": 1.0}
        )
        
        components = [text]
        
        # Tambahkan OCR jika ada dan confidence cukup
        if ocr_result and ocr_result.get("confidence", 0) > 0.6:
            fused.ocr_text = ocr_result["text"]
            fused.confidence_scores["ocr"] = ocr_result["confidence"]
            components.append(f"[Handwritten: {ocr_result['text']}]")
        
        # Tambahkan ASR jika ada dan confidence cukup
        if asr_result and asr_result.get("confidence", 0) > 0.6:
            fused.asr_text = asr_result["text"]
            fused.confidence_scores["asr"] = asr_result["confidence"]
            components.append(f"[Spoken: {asr_result['text']}]")
        
        # Determine dominant modality
        if len(fused.confidence_scores) == 1:
            fused.dominant_modality = "text"
        else:
            # Pilih yang confidence tertinggi
            dom = max(fused.confidence_scores.items(), key=lambda x: x[1])
            fused.dominant_modality = dom[0]
        
        # Final fused text
        if len(components) > 1:
            fused.primary_text = " | ".join(components)
        
        return fused

class ModalityProcessor:
    """Main entry untuk multimodal processing"""
    
    def __init__(self, ocr_provider: str = "tesseract", asr_provider: str = "whisper"):
        self.ocr = OCREngine(ocr_provider)
        self.asr = ASREngine(asr_provider)
        self.fusion = ModalityFusion()
    
    def process(self, evidence: Dict[str, Any]) -> FusedContext:
        """Process evidence berdasarkan modality"""
        modality = evidence.get("modality", "text")
        content = evidence.get("content", "")
        attachments = evidence.get("attachments", {})
        
        text_input = content if isinstance(content, str) else ""
        ocr_result = None
        asr_result = None
        
        if modality == "image" or "image" in attachments:
            img_data = attachments.get("image")
            if img_data:
                ocr_result = self.ocr.extract(img_data)
                print(f"[Modality] OCR extracted: {len(ocr_result['text'])} chars")
        
        if modality == "audio" or "audio" in attachments:
            audio_data = attachments.get("audio")
            if audio_data:
                asr_result = self.asr.transcribe(audio_data)
                print(f"[Modality] ASR transcribed: {len(asr_result['text'])} chars")
        
        return self.fusion.fuse(text_input, ocr_result, asr_result)
