
"""
Response Formatter - Now in Tools with Motivational Layer
"""
from typing import Dict, Any
from datetime import datetime

class ResponseFormatter:
    def __init__(self, student_model=None):
        self.student_model = student_model
    
    def process(self, state: Dict[str, Any]) -> Dict[str, Any]:
        """Format final response dengan motivational wrapper"""
        
        # Base formatting
        if state.get("error"):
            response = {
                "status": "error",
                "message": state["error"],
                "course_id": state.get("course_id"),
                "timestamp": datetime.now().isoformat()
            }
        elif state.get("scoring_result"):
            r = state["scoring_result"]
            response = {
                "status": "success",
                "type": "submission_feedback",
                "score": r.score_0_100,
                "is_correct": r.is_correct,
                "summary": r.summary,
                "misconceptions": [{"label": m.label, "severity": m.severity} for m in r.misconceptions],
                "suggestion": r.suggested_next_fix,
                "course_id": state.get("course_id"),
                "timestamp": datetime.now().isoformat()
            }
        elif state.get("content_package"):
            c = state["content_package"]
            response = {
                "status": "success",
                "type": "content",
                "mode": c.metadata.get("mode", "unknown"),
                "explanation": c.explanation,
                "skeleton": c.skeleton_code,
                "questions": c.questions,
                "cff_applied": c.cff_applied,
                "course_id": state.get("course_id"),
                "timestamp": datetime.now().isoformat()
            }
        else:
            response = {
                "status": "success",
                "type": "empty",
                "course_id": state.get("course_id")
            }
        
        # TAMBAHAN: Motivational Layer berdasarkan progress
        response = self._add_motivational_layer(state, response)
        
        state["final_response"] = response
        state["next_node"] = "__end__"
        return state
    
    def _add_motivational_layer(self, state: Dict, response: Dict) -> Dict:
        """Add motivational message berdasarkan context"""
        student = state.get("student_model")
        scoring = state.get("scoring_result")
        
        motivation = ""
        
        # Motivasi berdasarkan performance
        if scoring:
            if scoring.score_0_100 >= 90:
                motivation = "Excellent work! You're mastering this concept!"
            elif scoring.score_0_100 >= 70:
                motivation = "Good job! Keep practicing to reach excellence."
            elif scoring.score_0_100 >= 50:
                motivation = "You're on the right track! Review the hints and try again."
            else:
                motivation = "Learning takes time. Focus on the basics and you'll improve!"
        
        # Motivasi berdasarkan streak/effort (dari student model)
        if student and hasattr(student, 'preferences'):
            session_count = student.preferences.get("session_count", 0)
            if session_count > 5:
                motivation += f" You've completed {session_count} sessions. Keep the momentum!"
        
        if motivation:
            response["motivational_message"] = motivation
            response["encouragement"] = True
        
        return response
