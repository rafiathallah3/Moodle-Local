
"""
Learning Style Recommendations Agent
Memberikan tips belajar personal berdasarkan learning style siswa
"""
from typing import Dict, Any, List
from config.policies import LearningStyleProfile, LearningStyle

# Re-export LearningStyle supaya test cell bisa import dari sini juga
__all__ = ['LearningStyleRecommendationsAgent', 'LearningStyle', 'LearningStyleProfile']

class LearningStyleRecommendationsAgent:
    """
    Agent yang merekomendasikan cara belajar optimal 
    berdasarkan profil learning style siswa
    
    Supports: Visual, Textual, Auditory, Kinesthetic
    """
    
    def __init__(self, llm_config=None):
        """
        FIXED: Terima llm_config parameter (untuk consistency dengan agent lain)
        llm_config bisa None karena agent ini pakai database hardcoded
        """
        self.llm_config = llm_config  # Simpan untuk future use (jika butuh LLM nanti)
        self.style_database = self._initialize_style_database()
    
    def _initialize_style_database(self) -> Dict:
        """Database rekomendasi per learning style per topik"""
        return {
            "loops": {
                LearningStyle.VISUAL: {
                    "tips": [
                        "Gambarkan flowchart untuk setiap jenis loop (for, while)",
                        "Buat diagram yang menunjukkan iterasi variabel",
                        "Gunakan animasi/visualisasi alur loop"
                    ],
                    "resources": ["video_loop_animation", "flowchart_template"],
                    "example": r"for i in range(5):\n    print(i)\n# Gambar panah dari 0->1->2->3->4"
                },
                LearningStyle.TEXTUAL: {
                    "tips": [
                        "Baca penjelasan detail tentang inisialisasi, kondisi, increment",
                        "Catat perbedaan for vs while dalam tabel",
                        "Pelajari pseudocode loop secara bertahap"
                    ],
                    "resources": ["loop_documentation", "comparison_table"],
                    "example": r"Loop for: for var in range -> Inisialisasi -> Cek kondisi -> Eksekusi -> Increment"
                },
                LearningStyle.AUDITORY: {
                    "tips": [
                        "Dengarkan penjelasan konsep loop secara verbal",
                        "Ucapkan langkah-langkah loop sambil coding",
                        "Diskusikan dengan teman: 'Kenapa loop ini berhenti?'"
                    ],
                    "resources": ["audio_explanation", "discussion_guide"],
                    "example": r"Ucapkan: 'Mulai dari 0, selama kurang dari 5, tambah 1, cetak nilai'"
                },
                LearningStyle.KINESTHETIC: {
                    "tips": [
                        "Tulis loop di kertas dulu sebelum coding",
                        "Praktik modifikasi loop dengan berbagai kondisi",
                        "Buat game sederhana menggunakan loop"
                    ],
                    "resources": ["practice_exercises", "loop_challenges"],
                    "example": r"Coba: Ubah range(5) jadi range(2,10,2) dan amati perbedaannya"
                }
            },
            "if_else": {
                LearningStyle.VISUAL: {
                    "tips": [
                        "Gambarkan decision tree untuk kondisi if-else",
                        "Gunakan warna berbeda untuk cabang true/false",
                        "Buat diagram alur dengan diamond untuk decision"
                    ],
                    "resources": ["decision_tree_template", "flowchart_guide"],
                    "example": r"if x > 0: [kotak hijau] else: [kotak merah]"
                },
                LearningStyle.TEXTUAL: {
                    "tips": [
                        "Baca tentang operator perbandingan (==, !=, <, >)",
                        "Buat tabel kebenaran untuk kondisi kompleks",
                        "Pelajari struktur if-elif-else bertingkat"
                    ],
                    "resources": ["operator_reference", "truth_table"],
                    "example": r"if kondisi1: A\nelif kondisi2: B\nelse: C"
                },
                LearningStyle.AUDITORY: {
                    "tips": [
                        "Ucapkan kondisi dengan suara lantang",
                        "Jelaskan alur logika ke diri sendiri",
                        "Tanyakan 'Apa yang terjadi jika...?'"
                    ],
                    "resources": ["logic_puzzle_audio", "self_exercise"],
                    "example": r"'Jika x lebih besar dari 0, lakukan A, kalau tidak lakukan B'"
                },
                LearningStyle.KINESTHETIC: {
                    "tips": [
                        "Praktik dengan berbagai skenario kondisi",
                        "Modifikasi program dengan menambah elif",
                        "Debug kode dengan kesalahan kondisi"
                    ],
                    "resources": ["debugging_exercises", "scenario_challenges"],
                    "example": r"Coba: Tambahkan elif untuk menangani kasus x == 0"
                }
            },
            "recursion": {
                LearningStyle.VISUAL: {
                    "tips": [
                        "Gambarkan call stack sebagai tumpukan kotak",
                        "Buat animasi base case vs recursive case",
                        "Visualisasi pohon rekursi (untuk fibonacci)"
                    ],
                    "resources": ["stack_visualization", "recursion_tree"],
                    "example": r"f(3) -> f(2) -> f(1) -> base case -> unwind"
                },
                LearningStyle.TEXTUAL: {
                    "tips": [
                        "Identifikasi base case dan recursive case secara eksplisit",
                        "Tulis langkah rekursi dalam bahasa manusia",
                        "Pelajari perbandingan iteratif vs rekursif"
                    ],
                    "resources": ["recursion_patterns", "comparison_text"],
                    "example": r"Base case: n <= 1 return 1\nRecursive: return n * f(n-1)"
                },
                LearningStyle.AUDITORY: {
                    "tips": [
                        "Ucapkan 'fungsi memanggil dirinya sendiri dengan...'",
                        "Ceritakan alur rekursi seperti cerita",
                        "Diskusikan kapan rekursi berhenti"
                    ],
                    "resources": ["storytelling_guide", "termination_discussion"],
                    "example": r"'Faktorial 3 adalah 3 dikali faktorial 2, yaitu...'"
                },
                LearningStyle.KINESTHETIC: {
                    "tips": [
                        "Trace rekursi manually dengan kertas dan pena",
                        "Implementasi rekursi untuk masalah nyata",
                        "Coba ubah loop jadi rekursi dan sebaliknya"
                    ],
                    "resources": ["tracing_exercises", "conversion_practice"],
                    "example": r"Tulis di kertas: f(4) -> 4*f(3) -> 4*3*f(2) -> ..."
                }
            },
            "arrays": {
                LearningStyle.VISUAL: {
                    "tips": [
                        "Gambarkan array sebagai kotak-kotak berurutan",
                        "Warnai indeks dan nilai dengan warna berbeda",
                        "Animasikan proses insert/delete element"
                    ],
                    "resources": ["array_visualizer", "memory_diagram"],
                    "example": r"[0][1][2][3] dengan panah indeks"
                },
                LearningStyle.TEXTUAL: {
                    "tips": [
                        "Buat tabel indeks vs nilai",
                        "Dokumentasikan kompleksitas akses O(1)",
                        "Catat perbedaan static vs dynamic array"
                    ],
                    "resources": ["array_cheatsheet", "complexity_guide"],
                    "example": r"Index: 0 1 2 3\nValue: A B C D"
                },
                LearningStyle.AUDITORY: {
                    "tips": [
                        r"Ucapkan: 'Indeks 0 berisi nilai A, indeks 1 berisi nilai B'",
                        "Dengarkan penjelasan memory layout",
                        "Diskusikan kenapa array mulai dari 0"
                    ],
                    "resources": ["audio_lecture", "discussion_forum"],
                    "example": r"'Array adalah kumpulan data dengan indeks berurutan'"
                },
                LearningStyle.KINESTHETIC: {
                    "tips": [
                        "Gunakan kursi berbaris untuk simulasikan array",
                        "Praktik implementasi array sederhana",
                        "Eksperimen dengan berbagai operasi array"
                    ],
                    "resources": ["hands_on_lab", "array_implementation"],
                    "example": r"Buat array fisik dengan kartu remi"
                }
            }
        }
    
    def get_recommendations(self, user_id: str, topic: str, 
                           student_model=None) -> Dict[str, Any]:
        """
        Get personalized learning recommendations
        
        Input:
            user_id: ID siswa
            topic: Topik yang sedang dipelajari (loops, if_else, recursion, dll)
            student_model: Optional student model untuk deteksi otomatis
        
        Output:
            Dictionary dengan rekomendasi personal
        """
        # Deteksi atau ambil learning style
        if student_model and hasattr(student_model, 'learning_style'):
            style_profile = student_model.learning_style
        else:
            # Default ke visual jika belum ada data
            style_profile = LearningStyleProfile(user_id)
        
        # Ambil rekomendasi dari database
        topic_recommendations = self.style_database.get(topic, {})
        style_recommendations = topic_recommendations.get(
            style_profile.primary_style, 
            topic_recommendations.get(LearningStyle.VISUAL, {
                "tips": ["Pelajari konsep dasar", "Praktik dengan contoh", "Baca dokumentasi"],
                "resources": ["general_guide"],
                "example": "Start with basics"
            })
        )
        
        return {
            "user_id": user_id,
            "topic": topic,
            "learning_style": style_profile.primary_style.value,
            "confidence": style_profile.confidence,
            "recommendations": style_recommendations,
            "general_tips": self._get_general_tips(topic),
            "adaptive_message": self._generate_adaptive_message(
                style_profile.primary_style, topic
            )
        }
    
    def _get_general_tips(self, topic: str) -> List[str]:
        """Tips umum yang berlaku untuk semua learning style"""
        general_tips = {
            "loops": [
                "Selalu periksa kondisi terminasi untuk menghindari infinite loop",
                "Gunakan variabel iterasi yang bermakna (i, j, k untuk indeks)",
                "Test loop dengan nilai kecil dulu sebelum nilai besar"
            ],
            "if_else": [
                "Pastikan semua kasus sudah ditangani (gunakan else sebagai fallback)",
                "Perhatikan urutan pengecekan kondisi (yang spesifik dulu)",
                "Gunakan elif untuk kondisi yang saling eksklusif"
            ],
            "recursion": [
                "Selalu definisikan base case yang jelas",
                "Pastikan recursive case mendekati base case",
                "Hati-hati dengan stack overflow untuk input besar"
            ],
            "arrays": [
                "Ingat bahwa indeks array dimulai dari 0 di kebanyakan bahasa",
                "Perhatikan bounds checking untuk menghindari index out of range",
                "Pahami perbedaan antara array statis dan dinamis"
            ]
        }
        return general_tips.get(topic, ["Praktik secara konsisten", "Review dokumentasi"])
    
    def _generate_adaptive_message(self, style: LearningStyle, topic: str) -> str:
        """Generate pesan adaptif berdasarkan learning style"""
        messages = {
            LearningStyle.VISUAL: f"Untuk memahami {topic}, coba gambarkan konsepnya secara visual. Diagram akan membantu Anda melihat pola.",
            LearningStyle.TEXTUAL: f"Baca penjelasan detail tentang {topic} dan catat poin-poin penting. Dokumentasi adalah teman Anda.",
            LearningStyle.AUDITORY: f"Dengarkan penjelasan tentang {topic} dan diskusikan dengan teman. Mengajar adalah cara terbaik belajar.",
            LearningStyle.KINESTHETIC: f"Langsung praktikkan {topic} dengan coding. Learning by doing adalah cara Anda belajar terbaik."
        }
        return messages.get(style, f"Pelajari {topic} dengan cara yang Anda sukai.")
    
    def update_learning_style(self, user_id: str, interactions: list) -> LearningStyle:
        """
        Update learning style berdasarkan interaksi terbaru
        
        Input:
            interactions: List of interaction logs
                [{"type": "video", "duration": 120}, 
                 {"type": "text", "duration": 300}, ...]
        
        Output:
            Learning style yang terdeteksi
        """
        profile = LearningStyleProfile(user_id)
        detected = profile.detect_from_behavior(interactions)
        
        return detected
    
    def generate_multimodal_content(self, topic: str, style: LearningStyle) -> Dict[str, Any]:
        """
        Generate konten yang sesuai dengan learning style
        
        Output berisi berbagai format konten untuk topik tersebut
        """
        content_templates = {
            LearningStyle.VISUAL: {
                "format": "diagram + animation",
                "delivery": "Tampilkan visualisasi interaktif",
                "engagement": "Ajak siswa menggambar solusi"
            },
            LearningStyle.TEXTUAL: {
                "format": "markdown + code blocks",
                "delivery": "Sajikan dokumentasi terstruktur",
                "engagement": "Mintalah siswa menulis penjelasan"
            },
            LearningStyle.AUDITORY: {
                "format": "audio + discussion",
                "delivery": "Sediakan penjelasan audio",
                "engagement": "Ajak diskusi verbal"
            },
            LearningStyle.KINESTHETIC: {
                "format": "interactive coding",
                "delivery": "Sediakan playground coding",
                "engagement": "Mintalah siswa modifikasi kode"
            }
        }
        
        return {
            "topic": topic,
            "style": style.value,
            "content_format": content_templates.get(style, {}),
            "resources": self.style_database.get(topic, {}).get(style, {})
        }
    
    def detect_and_recommend(self, user_id: str, topic: str, 
                            recent_interactions: list) -> Dict[str, Any]:
        """
        Kombinasi: Deteksi learning style dari behavior + berikan rekomendasi
        
        One-stop method untuk dipanggil dari orchestrator
        """
        # Update learning style berdasarkan behavior terbaru
        detected_style = self.update_learning_style(user_id, recent_interactions)
        
        # Get rekomendasi
        recommendations = self.get_recommendations(user_id, topic)
        
        return {
            "detected_style": detected_style.value,
            "recommendations": recommendations,
            "multimodal_content": self.generate_multimodal_content(topic, detected_style)
        }
