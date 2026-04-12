
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
        import json
        evidence = state.get("evidence", {})
        topic = evidence.get("metadata", {}).get("topic", "general programming")
        language = evidence.get("metadata", {}).get("language", "English")
        
        lang_instruction = ""
        if language.lower() != "english":
            lang_instruction = (
                f"\n**IMPORTANT LANGUAGE INSTRUCTION**: Write ALL content "
                f"(explanation, skeleton_code comments, concepts, verification_question, expected_answer) "
                f"entirely in **{language}**. Do NOT use English for the content. "
                f"Only the JSON keys remain in English.\n"
            )
        
        prompt = f"""You are an AI Practice Problem Generator. 
        Generate ONE unique coding problem for the topic: {topic}.
        {lang_instruction} 
        Here are the pseudo-code grammar rules you MUST check for:\n
        1. MUST start with 'program [Name]'.\n
        2. MUST end with 'endprogram'.\n"
        3. MAY contain a 'dictionary' (or 'kamus') section for variable declarations. If present, it must be labeled 'dictionary' or 'kamus'.\n"
        4. MAY contain 'function' or 'procedure' blocks. These can appear after 'dictionary' and before 'algorithm'.\n"
        5. MUST contain an 'algorithm' (or 'algoritma') section where the main logic starts.\n"
        6. The assignment operator is `<-`. (Do NOT flag this as incorrect).\n\n"
        IMPORTANT:
        - Never claim 'dictionary' or 'algorithm' is missing if the word is explicitly written in the pseudo-code.\n"
        - Do NOT be confused by 'function' declarations existing outside the main algorithm.\n"
        The response MUST be a JSON object with:
        - "explanation": A clear problem statement for a student.
        - "skeleton_code": A starting code snippet (strictly using Pseudocode WITH NO ANSWER IN IT).
        - "concepts": A list of 3-5 key concepts needed to solve this.
        - "verification_question": A conceptual question related to the solution logic.
        - "expected_answer": A brief description of the correct logic.

        Return only the JSON object."""
        
        raw_response = self.llm.generate(prompt)
        
        # Simple JSON extraction
        try:
            # Strip markdown if present
            clean_raw = raw_response.strip()
            if clean_raw.startswith("```json"):
                clean_raw = clean_raw.split("```json")[-1].split("```")[0].strip()
            elif clean_raw.startswith("```"):
                clean_raw = clean_raw.split("```")[-1].split("```")[0].strip()
                
            data = json.loads(clean_raw)
            return ContentPackage(
                explanation=data.get("explanation", f"Practice problem for {topic}"),
                skeleton_code=data.get("skeleton_code", ""),
                concepts=data.get("concepts", [topic]),
                verification_question=data.get("verification_question", "How did you solve this?"),
                expected_answer=data.get("expected_answer", "Logical explanation"),
                metadata={"mode": "practice_problem", "target_kc": topic}
            )
        except Exception as e:
            print(f"[LessonGenerator] JSON Parse Error: {e} | Raw: {raw_response[:100]}")
            return ContentPackage(
                explanation=f"Write a program that demonstrates {topic}.",
                skeleton_code=f"def solve_{topic.replace(' ', '_')}():\n    pass",
                concepts=[topic],
                metadata={"mode": "practice_problem", "target_kc": topic, "error": str(e)}
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
