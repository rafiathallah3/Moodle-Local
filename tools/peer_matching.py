
"""
Peer Matching Agent - Tools untuk collaborative learning
"""
from typing import Dict, List, Any
import random

class PeerMatchingAgent:
    """Match students untuk peer teaching"""
    
    def __init__(self):
        self.active_sessions = {}
    
    def find_peer_match(self, 
                       student_id: str, 
                       course_id: str, 
                       kc_target: str,
                       registry=None) -> Dict[str, Any]:
        """
        Cari peer yang bisa mengajar (mastery lebih tinggi) atau belajar bareng
        """
        if not registry:
            return {"error": "Registry not available"}
        
        # Get all students in course
        all_students = []
        for key, student in registry.students.items():
            if key.endswith(f":{course_id}") and not key.startswith(student_id):
                all_students.append(student)
        
        if not all_students:
            return {"error": "No peers available"}
        
        # Cari peer dengan mastery lebih tinggi untuk kc_target (peer teacher)
        current_student = registry.get_student_model(student_id, course_id)
        current_mastery = current_student.mastery.get(kc_target, 0.5)
        
        potential_teachers = [
            s for s in all_students 
            if s.mastery.get(kc_target, 0) > current_mastery + 0.2
        ]
        
        if potential_teachers:
            # Pilih peer teacher dengan mastery tertinggi
            teacher = max(potential_teachers, key=lambda x: x.mastery.get(kc_target, 0))
            return {
                "match_type": "peer_teacher",
                "peer_id": teacher.user_id,
                "peer_mastery": teacher.mastery.get(kc_target, 0),
                "your_mastery": current_mastery,
                "kc": kc_target,
                "message": f"Matched with peer who has strong understanding of {kc_target}"
            }
        else:
            # Cari peer dengan level mirip untuk collaborative learning
            peers_similar = [
                s for s in all_students
                if abs(s.mastery.get(kc_target, 0) - current_mastery) < 0.2
            ]
            
            if peers_similar:
                partner = random.choice(peers_similar)
                return {
                    "match_type": "study_partner",
                    "peer_id": partner.user_id,
                    "peer_mastery": partner.mastery.get(kc_target, 0),
                    "your_mastery": current_mastery,
                    "kc": kc_target,
                    "message": f"Study together with peer at similar level"
                }
            
            return {"error": "No suitable peers found"}
    
    def generate_peer_prompt(self, match_result: Dict, problem_context: str) -> str:
        """Generate prompt untuk peer teaching session"""
        if match_result["match_type"] == "peer_teacher":
            return f"""You are helping a peer understand: {problem_context}
            They are struggling with this concept. Explain it clearly and ask guiding questions.
            Do not give the direct answer, help them discover it."""
        else:
            return f"""Work together with your study partner on: {problem_context}
            Discuss your approaches and learn from each other."""
