
"""
Moodle Adapter - Course-Aware Evidence Converter
"""
from logic_scratch.schemas import AgenticState, Intent, FusedContext
from logic_scratch.registry import registry

class MoodleAdapter:
    """Adapter yang memahami context course tertentu"""
    
    def evidence_to_state(self, 
                         evidence: dict, 
                         user_id: str, 
                         course_id: str, 
                         assessment_id: str = None) -> AgenticState:
        """Convert Moodle POST ke state dengan course config"""
        
        # Load course config dynamically
        course_config = registry.get_course_config(course_id)
        kc_graph = registry.get_kc_graph(course_id)
        student_model = registry.get_student_model(user_id, course_id)
        
        return AgenticState(
            user_id=user_id,
            course_id=course_id,
            assessment_id=assessment_id,
            course_config=course_config,
            evidence=evidence,
            intent=Intent.CHAT,  # Akan ditentukan orchestrator
            nodes_visited=[],
            fused_context=None,
            policy_blocked=False,
            cff_triggered=False,
            student_model=student_model,
            kc_graph=kc_graph,
            weak_concepts=[],
            practice_stage=None,
            practice_attempts=0,
            final_response=None,
            error=None
        )
    
    def state_to_response(self, state: AgenticState) -> dict:
        """Convert final state ke response Moodle"""
        if state.get("error"):
            return {
                "status": "error",
                "message": state["error"],
                "course_id": state.get("course_id"),
                "processing_time_ms": state.get("processing_time_ms", 0)
            }
        
        response = {
            "status": "success",
            "course_id": state.get("course_id"),
            "nodes_executed": state.get("nodes_visited", []),
            "processing_time_ms": state.get("processing_time_ms", 0),
            "cff_applied": state.get("cff_triggered", False)
        }
        
        # Tambahkan hasil scoring jika ada
        if state.get("scoring_result"):
            r = state["scoring_result"]
            response["scoring"] = {
                "score": r.score_0_100,
                "is_correct": r.is_correct,
                "summary": r.summary,
                "misconceptions": [m.label for m in r.misconceptions]
            }
        
        # Tambahkan content jika ada
        if state.get("content_package"):
            c = state["content_package"]
            response["content"] = {
                "explanation": c.explanation,
                "skeleton": c.skeleton_code,
                "questions": c.questions,
                "mode": c.metadata.get("mode", "unknown")
            }
        
        return response
