
"""
Practice Manager - 2-Stage Verification Pipeline
Manages: Problem → Solve → Verify → Update Mastery
"""
from typing import Dict, Any
from logic_scratch.schemas import PracticeStage, ContentMode
from tools.quiz_verifier import QuizVerifier

class PracticeManager:
    """Manage practice session state machine"""
    
    def __init__(self, llm_config=None):
        self.verifier = QuizVerifier(llm_config)
    
    def get_next_stage(self, current_stage: PracticeStage, action: str) -> PracticeStage:
        """State transition"""
        transitions = {
            (PracticeStage.PROBLEM_PRESENTED, "submit"): PracticeStage.SOLUTION_SUBMITTED,
            (PracticeStage.SOLUTION_SUBMITTED, "verify"): PracticeStage.VERIFIED,
            (PracticeStage.VERIFIED, "next"): PracticeStage.PROBLEM_PRESENTED
        }
        return transitions.get((current_stage, action), current_stage)
    
    def handle_submission(self, 
                         state: Dict, 
                         student_solution: str) -> Dict:
        """Handle student solution submission"""
        content = state.get("content_package")
        
        # Generate verification question kalau belum ada
        if not content.verification_question:
            content.verification_question = self.verifier.generate_verification_question(content)
        
        # Verify conceptual understanding
        verification = self.verifier.verify_answer(
            content.verification_question,
            student_solution,
            expected_concepts=content.concepts
        )
        
        # Update state
        state["practice_stage"] = PracticeStage.VERIFIED
        state["verification_result"] = verification
        
        # Update mastery berdasarkan verification
        if verification["is_verified"]:
            self._update_mastery(state, success=True)
        else:
            self._update_mastery(state, success=False)
        
        return state
    
    def _update_mastery(self, state: Dict, success: bool):
        """Update student model mastery"""
        from logic_scratch.registry import registry
        
        student = state.get("student_model")
        if not student:
            return
        
        target_kc = state.get("content_package", {}).metadata.get("target_kc", "general")
        
        # Bayesian update simplified
        current = student.mastery.get(target_kc, 0.5)
        learning_rate = 0.2
        new_mastery = current + learning_rate * (1.0 if success else -0.3)
        student.mastery[target_kc] = max(0.0, min(1.0, new_mastery))
        
        # Save back
        registry.update_student_model(student.user_id, student.course_id, student)
