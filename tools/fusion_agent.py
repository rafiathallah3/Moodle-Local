
"""
Fusion Agent - Menggabungkan hasil dari berbagai modalitas input
Text + Visual (OCR) + Audio (ASR) → Unified Representation
"""
from typing import Dict, Any, List, Optional
from dataclasses import dataclass

@dataclass
class ModalityInput:
    """Input dari satu modalitas"""
    modality_type: str  # "text", "visual", "audio"
    content: str
    confidence: float  # 0-1
    metadata: Dict[str, Any]

@dataclass
class FusedContext:
    """Hasil fusion dari multiple modalitas"""
    unified_text: str
    confidence: float
    source_modalities: List[str]
    conflicts: List[Dict[str, Any]]
    metadata: Dict[str, Any]

class FusionAgent:
    """
    Agent untuk menggabungkan input dari berbagai modalitas:
    - Textual Feature Extractor (code/text langsung)
    - Visual Feature Extractor (OCR dari handwritten)
    - Audio Feature Extractor (ASR dari speech)
    """
    
    def __init__(self):
        self.fusion_weights = {
            "text": 1.0,    # Text paling reliable
            "visual": 0.8,  # OCR bisa error
            "audio": 0.7    # ASR bisa salah dengar
        }
    
    def fuse_inputs(self, inputs: List[ModalityInput]) -> FusedContext:
        """
        Fusion multiple modalitas menjadi unified representation
        
        Input Example:
            inputs = [
                ModalityInput("text", "def sum(n): return n", 1.0, {}),
                ModalityInput("visual", "def sum(n): return n", 0.9, {"ocr_engine": "tesseract"}),
                ModalityInput("audio", "define sum of n return n", 0.8, {"asr_engine": "whisper"})
            ]
        
        Output:
            FusedContext dengan unified text dan confidence score
        """
        if not inputs:
            return FusedContext(
                unified_text="",
                confidence=0.0,
                source_modalities=[],
                conflicts=[],
                metadata={"error": "No inputs provided"}
            )
        
        # Jika hanya satu modalitas, gunakan itu
        if len(inputs) == 1:
            return FusedContext(
                unified_text=inputs[0].content,
                confidence=inputs[0].confidence,
                source_modalities=[inputs[0].modality_type],
                conflicts=[],
                metadata={"single_modality": True}
            )
        
        # Deteksi konflik antar modalitas
        conflicts = self._detect_conflicts(inputs)
        
        # Resolve konflik dan gabungkan
        unified_text = self._resolve_and_merge(inputs, conflicts)
        
        # Hitung confidence gabungan
        total_confidence = self._calculate_fusion_confidence(inputs, conflicts)
        
        return FusedContext(
            unified_text=unified_text,
            confidence=total_confidence,
            source_modalities=[inp.modality_type for inp in inputs],
            conflicts=conflicts,
            metadata={
                "num_modalities": len(inputs),
                "conflict_count": len(conflicts)
            }
        )
    
    def _detect_conflicts(self, inputs: List[ModalityInput]) -> List[Dict[str, Any]]:
        """Deteksi perbedaan antar modalitas"""
        conflicts = []
        
        # Bandingkan setiap pasangan
        for i in range(len(inputs)):
            for j in range(i+1, len(inputs)):
                text_i = inputs[i].content.lower().strip()
                text_j = inputs[j].content.lower().strip()
                
                # Hitung similarity sederhana
                similarity = self._calculate_similarity(text_i, text_j)
                
                if similarity < 0.8:  # Threshold konflik
                    conflicts.append({
                        "between": [inputs[i].modality_type, inputs[j].modality_type],
                        "similarity": similarity,
                        "text_a": text_i[:50],
                        "text_b": text_j[:50],
                        "resolution": "use_higher_confidence"
                    })
        
        return conflicts
    
    def _calculate_similarity(self, text1: str, text2: str) -> float:
        """Hitung similarity sederhana antara dua teks"""
        # Tokenisasi sederhana
        words1 = set(text1.split())
        words2 = set(text2.split())
        
        if not words1 or not words2:
            return 0.0
        
        intersection = words1.intersection(words2)
        union = words1.union(words2)
        
        return len(intersection) / len(union)
    
    def _resolve_and_merge(self, inputs: List[ModalityInput], 
                          conflicts: List[Dict[str, Any]]) -> str:
        """Resolve konflik dan gabungkan menjadi unified text"""
        # Urutkan berdasarkan confidence * weight
        weighted_inputs = []
        for inp in inputs:
            weight = self.fusion_weights.get(inp.modality_type, 0.5)
            weighted_inputs.append((inp, inp.confidence * weight))
        
        weighted_inputs.sort(key=lambda x: x[1], reverse=True)
        
        # Gunakan input dengan confidence tertinggi sebagai base
        best_input = weighted_inputs[0][0]
        unified = best_input.content
        
        # Jika ada konflik, log dan gunakan yang terbaik
        for conflict in conflicts:
            # Sudah resolved karena kita pakai yang confidence tertinggi
            conflict["resolved_with"] = best_input.modality_type
        
        return unified
    
    def _calculate_fusion_confidence(self, inputs: List[ModalityInput],
                                     conflicts: List[Dict[str, Any]]) -> float:
        """Hitung confidence score gabungan"""
        if not conflicts:
            # Tidak ada konflik, confidence tinggi
            avg_confidence = sum(inp.confidence for inp in inputs) / len(inputs)
            return min(0.95, avg_confidence)
        else:
            # Ada konflik, confidence lebih rendah
            avg_confidence = sum(inp.confidence for inp in inputs) / len(inputs)
            conflict_penalty = len(conflicts) * 0.1
            return max(0.5, avg_confidence - conflict_penalty)
    
    def extract_pseudocode(self, fused_context: FusedContext) -> Dict[str, Any]:
        """
        Ekstrak struktur pseudocode dari fused context
        
        Format pseudocode yang didukung:
        program [nama]
        kamus/dictionary
        [variabel]
        algoritma
        [langkah-langkah]
        endprogram
        """
        text = fused_context.unified_text
        
        # Deteksi format pseudocode
        is_pseudocode = any(keyword in text.lower() for keyword in 
                          ["program ", "kamus", "algoritma", "endprogram"])
        
        if not is_pseudocode:
            return {
                "is_pseudocode": False,
                "original_text": text,
                "structured": None
            }
        
        # Parse struktur pseudocode
        structured = self._parse_pseudocode_structure(text)
        
        return {
            "is_pseudocode": True,
            "original_text": text,
            "structured": structured,
            "confidence": fused_context.confidence
        }
    
    def _parse_pseudocode_structure(self, text: str) -> Dict[str, Any]:
        """Parse teks menjadi struktur pseudocode"""
        lines = text.split('\n')
        
        structure = {
            "program_name": None,
            "kamus": [],
            "algoritma": [],
            "raw_lines": lines
        }
        
        current_section = None
        
        for line in lines:
            line_stripped = line.strip().lower()
            
            if line_stripped.startswith("program "):
                structure["program_name"] = line.strip().split()[1] if len(line.strip().split()) > 1 else "unknown"
                current_section = "header"
            elif "kamus" in line_stripped or "dictionary" in line_stripped:
                current_section = "kamus"
            elif "algoritma" in line_stripped or "algo" in line_stripped:
                current_section = "algoritma"
            elif "endprogram" in line_stripped:
                current_section = "footer"
            elif line_stripped and current_section:
                if current_section == "kamus":
                    structure["kamus"].append(line.strip())
                elif current_section == "algoritma":
                    structure["algoritma"].append(line.strip())
        
        return structure
    
    def generate_fusion_report(self, fused_context: FusedContext) -> str:
        """Generate laporan fusion untuk debugging"""
        report = []
        report.append("=" * 50)
        report.append("MULTIMODAL FUSION REPORT")
        report.append("=" * 50)
        report.append(f"Unified Text: {fused_context.unified_text[:100]}...")
        report.append(f"Confidence: {fused_context.confidence:.2f}")
        report.append(f"Sources: {', '.join(fused_context.source_modalities)}")
        
        if fused_context.conflicts:
            report.append(f"\nConflicts Detected: {len(fused_context.conflicts)}")
            for i, conflict in enumerate(fused_context.conflicts, 1):
                report.append(f"  {i}. Between {conflict['between'][0]} and {conflict['between'][1]}")
                report.append(f"     Similarity: {conflict['similarity']:.2f}")
                report.append(f"     Resolution: {conflict.get('resolved_with', 'N/A')}")
        else:
            report.append("\nNo conflicts detected")
        
        report.append("=" * 50)
        return "\n".join(report)
