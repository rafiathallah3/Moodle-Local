
"""
Policies Configuration - CFF, RBAC, dan Learning Policies
"""
from typing import Dict, Any, Optional
from enum import Enum

class CFFType(Enum):
    ON_DEMAND = "on_demand"      # AI suggestion hidden until clicked
    WAIT_DELAY = "wait_delay"    # 30s delay before AI muncul
    UPDATE_FIRST = "update_first" # Student decide first then see AI

class LearningStyle(Enum):
    VISUAL = "visual"      # Prefer gambar, diagram, video
    TEXTUAL = "textual"    # Prefer bacaan, teks
    AUDITORY = "auditory"  # Prefer audio, penjelasan lisan
    KINESTHETIC = "kinesthetic"  # Prefer praktik hands-on

class PolicyConfig:
    """Konfigurasi policies per course"""
    def __init__(self):
        self.cff_policies = {
            "CS101": CFFType.ON_DEMAND,
            "CS202": CFFType.WAIT_DELAY,
            "CS303": CFFType.UPDATE_FIRST
        }
        self.rbac_policies = {
            "teacher": ["analytics", "content_management", "student_view"],
            "student": ["submission", "practice", "study_plan"]
        }
    
    def get_cff(self, course_id: str) -> CFFType:
        return self.cff_policies.get(course_id, CFFType.ON_DEMAND)
    
    def check_permission(self, role: str, action: str) -> bool:
        allowed = self.rbac_policies.get(role, [])
        return action in allowed

class LearningStyleProfile:
    """Profil learning style siswa"""
    def __init__(self, user_id: str):
        self.user_id = user_id
        self.primary_style = LearningStyle.VISUAL  # Default
        self.secondary_style = None
        self.confidence = 0.5  # 0-1, seberapa yakin deteksinya
    
    def detect_from_behavior(self, interactions: list) -> LearningStyle:
        """
        Deteksi learning style dari pola interaksi:
        - Banyak klik video -> VISUAL
        - Banyak baca teks -> TEXTUAL  
        - Banyak audio -> AUDITORY
        """
        video_count = sum(1 for i in interactions if i.get("type") == "video")
        text_count = sum(1 for i in interactions if i.get("type") == "text")
        audio_count = sum(1 for i in interactions if i.get("type") == "audio")
        
        counts = {"visual": video_count, "textual": text_count, "auditory": audio_count}
        detected = max(counts, key=counts.get)
        
        self.primary_style = LearningStyle(detected)
        self.confidence = min(0.9, max(counts.values()) / len(interactions)) if interactions else 0.5
        
        return self.primary_style
    
    def get_recommendations(self, topic: str) -> Dict[str, Any]:
        """Get learning recommendations berdasarkan style"""
        recommendations = {
            LearningStyle.VISUAL: {
                "tips": [
                    f"Gambarkan diagram alur untuk {topic}",
                    f"Buat mind map konsep {topic}",
                    f"Tonton video penjelasan {topic}"
                ],
                "tools": ["diagram", "flowchart", "video"],
                "description": "Anda belajar lebih baik dengan visualisasi"
            },
            LearningStyle.TEXTUAL: {
                "tips": [
                    f"Baca dokumentasi lengkap tentang {topic}",
                    f"Catat poin-poin penting {topic}",
                    f"Pelajari contoh kode bertekstur untuk {topic}"
                ],
                "tools": ["documentation", "notes", "examples"],
                "description": "Anda belajar lebih baik dengan membaca"
            },
            LearningStyle.AUDITORY: {
                "tips": [
                    f"Dengarkan penjelasan tentang {topic}",
                    f"Diskusikan {topic} dengan teman",
                    f"Rekam penjelasan Anda sendiri tentang {topic}"
                ],
                "tools": ["audio", "discussion", "recording"],
                "description": "Anda belajar lebih baik dengan mendengar"
            },
            LearningStyle.KINESTHETIC: {
                "tips": [
                    f"Praktik langsung coding {topic}",
                    f"Buat project kecil menggunakan {topic}",
                    f"Eksperimen dengan berbagai kasus {topic}"
                ],
                "tools": ["practice", "project", "experiment"],
                "description": "Anda belajar lebih baik dengan praktik"
            }
        }
        
        return {
            "primary_style": self.primary_style.value,
            "confidence": self.confidence,
            "recommendations": recommendations.get(self.primary_style, {}),
            "alternative_style": self.secondary_style.value if self.secondary_style else None
        }
