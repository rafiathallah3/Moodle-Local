
"""
Reflective Critic Agent - AFS v2 Quality Gate
Features: Summary Agent (Eksplisit) + Introspective Self-Correction
"""
from typing import Dict, Any
from logic_scratch.schemas import CriticAssessment, AgenticState, ContentPackage
from logic_scratch.llm_factory import LLMFactory
from logic_scratch.utils import log_action

class SummaryAgent:
    """Eksplisit Summary Agent - Generate ringkasan sebelum output final"""
    def summarize(self, state: AgenticState) -> Dict[str, Any]:
        """
        Generate summary dari seluruh analysis pipeline
        Dipanggil setelah Analyzer, sebelum Formatter
        """
        scoring = state.get("scoring_result")
        style = state.get("style_result", {})
        logic = state.get("logic_result", {})
        
        summary_parts = []
        
        # Summary dari scoring
        if scoring:
            summary_parts.append(f"Score: {scoring.score_0_100}/100")
            if scoring.is_correct:
                summary_parts.append("Status: Correct")
            else:
                summary_parts.append("Status: Needs improvement")
            
            if scoring.misconceptions:
                misc_labels = [m.label for m in scoring.misconceptions[:2]]
                summary_parts.append(f"Key issues: {', '.join(misc_labels)}")
        
        # Summary dari style
        if style.get("style_issues"):
            summary_parts.append(f"Style: {len(style['style_issues'])} issues")
        
        # Summary dari logic
        if logic.get("logic_issues"):
            summary_parts.append(f"Logic: {len(logic['logic_issues'])} issues")
        
        return {
            "summary_text": " | ".join(summary_parts) if summary_parts else "Analysis complete",
            "key_findings": self._extract_key_findings(scoring),
            "recommendation": self._generate_recommendation(scoring)
        }
    
    def _extract_key_findings(self, scoring) -> list:
        if not scoring:
            return []
        return [
            {"type": "score", "value": scoring.score_0_100},
            {"type": "correctness", "value": scoring.is_correct},
            {"type": "misconceptions", "value": len(scoring.misconceptions)}
        ]
    
    def _generate_recommendation(self, scoring) -> str:
        if not scoring:
            return "No scoring data available"
        if scoring.score_0_100 >= 90:
            return "Excellent work! Consider exploring advanced topics."
        elif scoring.score_0_100 >= 70:
            return "Good job! Review the hints to improve further."
        elif scoring.score_0_100 >= 50:
            return "Keep practicing! Focus on the identified misconceptions."
        else:
            return "Let's review the basics. Check the learning resources."

class ReflectiveCriticAgent:
    """
    AFS v2 Quality Gate dengan:
    1. Summary Agent (Eksplisit)
    2. Introspective Self-Correction
    3. Leakage Detection
    """
    def __init__(self, llm_config=None):
        self.max_iterations = 2
        self.llm = LLMFactory(llm_config)
        self.summary_agent = SummaryAgent()
    
    def process(self, state: AgenticState) -> AgenticState:
        print("[Critic] Running AFS v2 Quality Checks...")
        
        # Step 1: Generate Summary (Eksplisit)
        summary = self.summary_agent.summarize(state)
        state["analysis_summary"] = summary
        print(f"[Critic] Summary: {summary['summary_text']}")
        
        # Step 2: Check quality
        prev_assessment = state.get("critic_assessment")
        iteration = prev_assessment.iteration if prev_assessment else 0
        
        content = state.get("content_package")
        intent = state.get("intent")
        
        # Check 1: Answer Leakage
        leakage = False
        if content and intent.value != "analytics_request":
            leakage = self._check_leakage(content)
        
        # Check 2: Scoring Quality
        scoring = state.get("scoring_result")
        quality_ok = True
        if scoring:
            quality_ok = scoring.confidence > 0.5
        
        # Check 3: CFF Compliance
        if state.get("cff_triggered") and content:
            if content.full_solution and len(content.full_solution) > 100:
                leakage = True
        
        # Check 4: Introspective Evaluation
        introspection = self._introspective_evaluation(state)
        
        # Final Assessment
        assessment = CriticAssessment(
            passed=not leakage and quality_ok and introspection["passed"],
            revision_needed=leakage or not quality_ok or not introspection["passed"],
            leakage_detected=leakage,
            feedback=introspection["feedback"] if not introspection["passed"] else summary["recommendation"],
            iteration=iteration + 1
        )
        
        state["critic_assessment"] = assessment
        
        if assessment.revision_needed and iteration < self.max_iterations:
            state["next_node"] = "lesson_generator"
            print(f"[Critic] Revision needed (iter {iteration+1}): {assessment.feedback}")
        else:
            state["next_node"] = "formatter"
            print("[Critic] Quality check passed")
        
        return log_action(state, "critic", "assessed", f"passed={assessment.passed}")
    
    def _check_leakage(self, content: ContentPackage) -> bool:
        """Deteksi answer leakage"""
        explanation = content.explanation.lower() if content else ""
        full_sol = content.full_solution if content else None
        has_full_code = full_sol is not None and len(full_sol) > 50
        
        direct_answer = any(phrase in explanation for phrase in [
            "the answer is", "you should use", "correct solution",
            "simply do this", "just write", "the correct code is",
            "here is the solution", "answer:"
        ])
        
        return has_full_code or (len(explanation) > 300 and direct_answer)
    
    def _introspective_evaluation(self, state: AgenticState) -> Dict[str, Any]:
        """
        Introspective Agent: Evaluasi kualitas pedagogical sebelum kirim
        """
        content = state.get("content_package")
        if not content:
            return {"passed": True, "feedback": ""}
        
        issues = []
        
        # 1. Apakah explanation terlalu verbose?
        if len(content.explanation) > 500:
            issues.append("Explanation too verbose")
        
        # 2. Apakah ada Socratic questioning?
        socratic_phrases = ["what do you think", "why", "how", "consider", "can you explain"]
        has_socratic = any(phrase in content.explanation.lower() for phrase in socratic_phrases)
        if not has_socratic and not state.get("cff_triggered"):
            issues.append("Add Socratic questions")
        
        # 3. Apakah scaffolded appropriately?
        if content.skeleton_code and len(content.skeleton_code) < 20:
            issues.append("Skeleton code too minimal")
        
        # 4. Balance questions
        if len(content.questions) > 5:
            issues.append("Too many questions")
        
        if issues:
            return {
                "passed": False,
                "feedback": "Quality issues: " + "; ".join(issues)
            }
        
        return {"passed": True, "feedback": "Quality check passed"}
