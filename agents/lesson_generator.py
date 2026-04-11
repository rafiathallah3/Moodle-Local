
"""
Lesson Generator Agent - Extended dengan:
1. Peer Teaching Mode
2. Teacher Refinement Integration
3. Standard modes (Student Help, Practice, Study Plan)
"""
from typing import Dict, Any
from logic_scratch.schemas import ContentPackage, ContentMode, AgenticState
from logic_scratch.llm_factory import LLMFactory
from logic_scratch.utils import log_action
from tools.peer_matching import PeerMatchingAgent
from tools.teacher_refinement import TeacherRefinementAgent

class LessonGeneratorAgent:
    def __init__(self, llm_config=None):
        self.llm = LLMFactory(llm_config)
        self.peer_matcher = PeerMatchingAgent()
        self.refinement = TeacherRefinementAgent(llm_config)
    
    def process(self, state: AgenticState) -> AgenticState:
        try:
            intent = state.get("intent")
            role = state.get("evidence", {}).get("metadata", {}).get("role", "student")
            
            intent_value = intent.value if hasattr(intent, "value") else str(intent)
            print(f"[LessonGenerator] Intent: {intent_value} | Role: {role}")
            
            # Determine mode
            if intent_value == "analytics_request" or role == "teacher":
                mode = ContentMode.TEACHER_FULL
            elif intent_value == "practice":
                mode = ContentMode.PRACTICE_PROBLEM
            elif intent_value == "study_planner":
                mode = ContentMode.STUDY_PLAN
            elif intent_value == "peer_teaching":  # BARU
                mode = ContentMode.PEER_TEACHING
            else:
                mode = ContentMode.STUDENT_HELP
            
            # Generate content
            if mode == ContentMode.STUDENT_HELP:
                content = self._gen_student_help(state)
            elif mode == ContentMode.TEACHER_FULL:
                content = self._gen_teacher_content(state)
            elif mode == ContentMode.PRACTICE_PROBLEM:
                content = self._gen_practice_problem(state)
            elif mode == ContentMode.PEER_TEACHING:  # BARU
                content = self._gen_peer_teaching(state)
            else:
                content = self._gen_study_plan(state)
            
            state["content_package"] = content
            state["next_node"] = "critic"
            
        except Exception as e:
            print(f"[LessonGenerator] Error: {e}")
            state["content_package"] = ContentPackage(
                explanation="Let me help you with this.",
                metadata={"mode": "error_fallback", "error": str(e)}
            )
            state["next_node"] = "critic"
        
        return log_action(state, "lesson_generator", "generated", "content")
    
    def _gen_student_help(self, state):
        weak = state.get("weak_concepts") or []
        if not weak:
            weak = ["programming"]
        
        cff = state.get("cff_triggered", False)
        
        return ContentPackage(
            explanation=f"Let's work through {weak[0]}. What do you think?",
            skeleton_code="# Your code",
            questions=["What is input?", "What is output?"],
            concepts=weak,
            cff_applied=cff,
            metadata={"mode": "student_help"}
        )
    
    def _gen_teacher_content(self, state):
        # TAMBAHAN: Teacher Refinement integration
        evidence = state.get("evidence", {})
        raw_problem = evidence.get("content", "")
        
        # Jika ada teacher feedback, refine dulu
        if "teacher_feedback" in evidence:
            refined = self.refinement.refine_problem(
                raw_problem,
                evidence["teacher_feedback"]
            )
            return ContentPackage(
                explanation="Refined problem based on teacher feedback",
                full_solution=refined["refined_problem"],
                metadata={"mode": "teacher_full", "refined": True}
            )
        
        return ContentPackage(
            explanation="Complete solution",
            full_solution="# Solution",
            metadata={"mode": "teacher_full", "refined": False}
        )
    
    def _gen_practice_problem(self, state):
        course = state.get("course_config")
        target = "general"
        if course and hasattr(course, "kc_set") and course.kc_set:
            target = course.kc_set[0]
        
        return ContentPackage(
            explanation=f"Practice problem for {target}",
            skeleton_code=f"def practice_{target}():\n    pass",
            verification_question="Explain your solution",
            expected_answer="logical explanation",
            metadata={"mode": "practice_problem", "target_kc": target}
        )
    
    def _gen_peer_teaching(self, state):
        """BARU: Peer Teaching Mode"""
        user_id = state.get("user_id")
        course_id = state.get("course_id")
        weak = state.get("weak_concepts", ["general"])
        target_kc = weak[0] if weak else "general"
        
        # Cari peer match
        match_result = self.peer_matcher.find_peer_match(
            user_id, course_id, target_kc
        )
        
        if "error" in match_result:
            # Fallback ke mode biasa kalau tidak ada peer
            return self._gen_student_help(state)
        
        return ContentPackage(
            explanation=f"Peer Teaching Session for {target_kc}",
            metadata={
                "mode": "peer_teaching",
                "peer_match": match_result,
                "collaborative": True,
                "session_type": match_result["match_type"]
            }
        )
    
    def _gen_study_plan(self, state):
        weak = state.get("weak_concepts") or ["topic1"]
        return ContentPackage(
            explanation="Study plan created",
            metadata={"mode": "study_plan", "tasks": weak[:3]}
        )
