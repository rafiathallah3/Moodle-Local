
"""
Study Planner - Extended dengan Decomposition Agent
Memory Manager - CRUD Student Model
"""
from typing import List, Dict, Any
from datetime import datetime

class DecompositionAgent:
    """Memecah task kompleks menjadi sub-task belajar"""
    
    def decompose_learning_objective(self, kc: str, difficulty: float) -> List[Dict]:
        """
        Break down complex KC into micro-learning steps
        """
        # Template dekomposisi berdasarkan difficulty
        if difficulty > 0.7:  # Hard
            steps = [
                {"step": 1, "name": f"Understand {kc} basics", "duration": 20, "type": "video"},
                {"step": 2, "name": f"Analyze {kc} examples", "duration": 25, "type": "interactive"},
                {"step": 3, "name": f"Practice {kc} with hints", "duration": 30, "type": "practice"},
                {"step": 4, "name": f"Apply {kc} independently", "duration": 25, "type": "exercise"},
                {"step": 5, "name": f"Teach {kc} to peer (Feynman technique)", "duration": 20, "type": "peer"}
            ]
        elif difficulty > 0.4:  # Medium
            steps = [
                {"step": 1, "name": f"Review {kc} concepts", "duration": 15, "type": "reading"},
                {"step": 2, "name": f"Guided practice for {kc}", "duration": 25, "type": "practice"},
                {"step": 3, "name": f"Independent exercise {kc}", "duration": 20, "type": "exercise"}
            ]
        else:  # Easy
            steps = [
                {"step": 1, "name": f"Quick review {kc}", "duration": 10, "type": "video"},
                {"step": 2, "name": f"Practice {kc}", "duration": 15, "type": "exercise"}
            ]
        
        return steps
    
    def estimate_completion_time(self, steps: List[Dict]) -> int:
        return sum(step["duration"] for step in steps)

class StudyPlanner:
    """Generate personalized study plans dengan decomposition"""
    
    def __init__(self):
        self.decomposer = DecompositionAgent()
    
    def create_plan(self, 
                   weak_kcs: List[str], 
                   student_model: Dict,
                   course_config: Any = None) -> Dict:
        """Create adaptive study plan dengan task decomposition"""
        
        tasks = []
        
        for kc in weak_kcs[:3]:  # Max 3 KCs per plan
            # Get difficulty
            difficulty = 0.5
            if course_config and kc in course_config.difficulty_baseline:
                difficulty = course_config.difficulty_baseline[kc]
            
            # DECOMPOSITION: Break down each KC into steps
            steps = self.decomposer.decompose_learning_objective(kc, difficulty)
            total_time = self.decomposer.estimate_completion_time(steps)
            
            tasks.append({
                "kc": kc,
                "difficulty": "hard" if difficulty > 0.7 else "medium" if difficulty > 0.4 else "easy",
                "estimated_minutes": total_time,
                "decomposed_steps": steps,  # BARU: Micro-learning steps
                "resources": [f"Video: {kc} fundamentals", f"Exercise: {kc} advanced"]
            })
        
        total_time = sum(t["estimated_minutes"] for t in tasks)
        
        return {
            "objectives": [f"Master {kc} through structured micro-learning" for kc in weak_kcs[:3]],
            "tasks": tasks,
            "total_minutes": total_time,
            "has_decomposition": True,  # Flag baru
            "recommendations": [
                "Complete each micro-step before moving to next",
                "Use Feynman technique in final step",
                "Review misconceptions from previous attempts"
            ]
        }

class MemoryManager:
    """Manage Student Model persistence"""
    
    def __init__(self):
        from logic_scratch.registry import registry
        self.registry = registry
    
    def get_or_create(self, user_id: str, course_id: str):
        return self.registry.get_student_model(user_id, course_id)
    
    def update_from_scoring(self, 
                           user_id: str, 
                           course_id: str, 
                           scoring_result: Any):
        student = self.get_or_create(user_id, course_id)
        
        for misc in scoring_result.misconceptions:
            kc = misc.label.split("_")[0].lower()
            if kc in student.mastery:
                student.mastery[kc] = max(0.0, student.mastery[kc] - 0.1)
        
        self.registry.update_student_model(user_id, course_id, student)
        return student
    
    def extract_weak_concepts(self, user_id: str, course_id: str) -> List[str]:
        student = self.get_or_create(user_id, course_id)
        weak = [kc for kc, score in student.mastery.items() if score < 0.6]
        return weak if weak else list(student.mastery.keys())[:2]
    
    def record_session(self, 
                      user_id: str, 
                      course_id: str, 
                      session_data: Dict):
        student = self.get_or_create(user_id, course_id)
        
        if "session_history" not in student.preferences:
            student.preferences["session_history"] = []
        
        student.preferences["session_history"].append({
            "timestamp": datetime.now().isoformat(),
            "action": session_data.get("action"),
            "score": session_data.get("score")
        })
        
        student.last_active = datetime.now().isoformat()
        self.registry.update_student_model(user_id, course_id, student)
