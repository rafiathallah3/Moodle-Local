
"""
Quiz Verifier - 2-Stage Verification untuk Practice Pipeline
Dari paper: Problem Generator → Student Solve → Quiz Verifier → Next/Revisit

Stage 1: Conceptual Understanding Check (Why/How)
Stage 2: Application Verification (Transfer knowledge)
"""
from typing import Dict, Any, List, Optional
from dataclasses import dataclass
from enum import Enum
from logic_scratch.llm_factory import LLMFactory
from logic_scratch.schemas import ContentPackage

class VerificationStage(Enum):
    STAGE_1_CONCEPTUAL = "conceptual"      # Cek pemahaman konsep
    STAGE_2_APPLICATION = "application"    # Cek aplikasi/transfer knowledge
    PASSED = "passed"                      # Lolos verifikasi
    NEEDS_REVISION = "revision"            # Perlu belajar ulang

@dataclass
class VerificationResult:
    stage: VerificationStage
    is_verified: bool
    confidence: float
    question: str
    student_answer: str
    expected_concepts: List[str]
    missing_concepts: List[str]
    feedback: str
    next_action: str  # "next_topic", "revisit", "practice_more"
    score: float  # 0-100

class QuizVerifier:
    """
    2-Stage Verification Agent
    Memastikan siswa benar-benar paham sebelum lanjut ke topik berikutnya
    """
    
    def __init__(self, llm_config=None):
        self.llm = LLMFactory(llm_config)
        self.current_stage = VerificationStage.STAGE_1_CONCEPTUAL
        self.verification_history = []
    
    def generate_stage1_question(self, problem_content: ContentPackage, 
                                  topic: str = "general") -> str:
        """
        Stage 1: Pertanyaan konseptual (Why/How)
        Contoh: "Kenapa loop menggunakan range(n) bukan range(n+1)?"
        """
        prompt = f"""Given this programming problem context:
        Topic: {topic}
        Problem: {problem_content.explanation[:200]}
        
        Generate ONE conceptual "Why" or "How" question to verify deep understanding.
        The question should test conceptual understanding, NOT syntax or code-writing.
        
        Rules:
        1. Ask about the logic/algoritma, not syntax
        2. Use Socratic questioning style
        3. Focus on common misconceptions
        
        Good examples:
        - "Why do we need to initialize the variable before the loop?"
        - "What would happen if we changed the condition from '<' to '<='?"
        - "How does the algorithm ensure all elements are checked?"
        
        Bad examples:
        - "Write the code for..."
        - "What is the syntax for..."
        
        Return only the question text, no explanation."""
        
        return self.llm.generate(prompt).strip().strip('"')
    
    def generate_stage2_question(self, problem_content: ContentPackage,
                                  previous_answer: str,
                                  topic: str = "general") -> str:
        """
        Stage 2: Pertanyaan aplikasi/modifikasi
        Contoh: "Jika input berubah jadi X, apa yang perlu diubah?"
        """
        prompt = f"""Given:
        Topic: {topic}
        Original Problem: {problem_content.explanation[:150]}
        Student's previous answer: {previous_answer[:100]}
        
        Generate ONE application/modification question.
        This tests if student can transfer knowledge to slightly different scenarios.
        
        Good examples:
        - "If we wanted to find the maximum instead of minimum, what would change?"
        - "How would you modify this to handle negative numbers?"
        - "Can you apply this same logic to solve [related problem]?"
        
        Return only the question text."""
        
        return self.llm.generate(prompt).strip().strip('"')
    
    def evaluate_stage1(self, question: str, student_answer: str, 
                       expected_concepts: List[str]) -> VerificationResult:
        """Evaluasi jawaban Stage 1"""
        
        # Analisis menggunakan LLM
        analysis = self._analyze_understanding(question, student_answer, expected_concepts)
        
        is_passed = analysis["confidence"] >= 0.7 and len(analysis["missing_concepts"]) <= 1
        
        if is_passed:
            self.current_stage = VerificationStage.STAGE_2_APPLICATION
            next_action = "proceed_stage2"
        else:
            next_action = "revisit_concept"
        
        result = VerificationResult(
            stage=VerificationStage.STAGE_1_CONCEPTUAL,
            is_verified=is_passed,
            confidence=analysis["confidence"],
            question=question,
            student_answer=student_answer,
            expected_concepts=expected_concepts,
            missing_concepts=analysis["missing_concepts"],
            feedback=analysis["feedback"],
            next_action=next_action,
            score=analysis["confidence"] * 100
        )
        
        self.verification_history.append(result)
        return result
    
    def evaluate_stage2(self, question: str, student_answer: str,
                       original_problem: str) -> VerificationResult:
        """Evaluasi jawaban Stage 2 (Application)"""
        
        analysis = self._analyze_application(question, student_answer, original_problem)
        
        is_passed = analysis["confidence"] >= 0.6
        
        if is_passed:
            self.current_stage = VerificationStage.PASSED
            next_action = "next_topic"
        else:
            self.current_stage = VerificationStage.NEEDS_REVISION
            next_action = "practice_more"
        
        return VerificationResult(
            stage=VerificationStage.STAGE_2_APPLICATION,
            is_verified=is_passed,
            confidence=analysis["confidence"],
            question=question,
            student_answer=student_answer,
            expected_concepts=["transfer_knowledge", "adaptation"],
            missing_concepts=analysis.get("gaps", []),
            feedback=analysis["feedback"],
            next_action=next_action,
            score=analysis["confidence"] * 100
        )
    
    def _analyze_understanding(self, question: str, answer: str, 
                              concepts: List[str]) -> Dict[str, Any]:
        """Analisis pemahaman menggunakan LLM"""
        
        prompt = f"""Analyze this student response for conceptual understanding:

Question: {question}
Student Answer: {answer}
Key Concepts That Should Be Mentioned: {', '.join(concepts)}

Evaluate and return JSON:
{{
    "confidence": float (0.0-1.0),
    "missing_concepts": [list of concepts not mentioned or misunderstood],
    "feedback": "specific constructive feedback",
    "reasoning": "brief explanation of evaluation"
}}"""
        
        try:
            import json, re
            response = self.llm.generate(prompt)
            json_match = re.search(r'\{.*\}', response, re.DOTALL)
            if json_match:
                result = json.loads(json_match.group())
            else:
                result = json.loads(response)
            
            return {
                "confidence": result.get("confidence", 0.5),
                "missing_concepts": result.get("missing_concepts", []),
                "feedback": result.get("feedback", "Review the concepts")
            }
        except:
            # Fallback heuristic
            answer_lower = answer.lower()
            matched = sum(1 for c in concepts if c.lower() in answer_lower)
            confidence = 0.5 + (matched / len(concepts) * 0.5) if concepts else 0.5
            
            return {
                "confidence": confidence,
                "missing_concepts": [c for c in concepts if c.lower() not in answer_lower],
                "feedback": "Good understanding!" if confidence > 0.7 else "Please review the key concepts"
            }
    
    def _analyze_application(self, question: str, answer: str, 
                            context: str) -> Dict[str, Any]:
        """Analisis kemampuan aplikasi"""
        # Similar to _analyze_understanding but focused on adaptation
        return self._analyze_understanding(question, answer, ["adaptation", "transfer"])
    
    def run_full_verification(self, problem_content: ContentPackage,
                             student_answers: Dict[str, str],
                             topic: str = "general") -> Dict[str, Any]:
        """
        Run both stages of verification
        
        Input:
            student_answers: {"stage1": "...", "stage2": "..."}
        
        Output:
            Complete verification report
        """
        results = {
            "stage1": None,
            "stage2": None,
            "final_status": "incomplete",
            "recommendation": ""
        }
        
        # Stage 1
        if "stage1" in student_answers:
            q1 = self.generate_stage1_question(problem_content, topic)
            results["stage1"] = self.evaluate_stage1(
                q1, student_answers["stage1"], 
                ["logic", "algorithm", "concept"]
            )
        
        # Stage 2 (only if stage 1 passed)
        if results["stage1"] and results["stage1"].is_verified and "stage2" in student_answers:
            q2 = self.generate_stage2_question(problem_content, student_answers["stage1"], topic)
            results["stage2"] = self.evaluate_stage2(
                q2, student_answers["stage2"],
                problem_content.explanation
            )
        
        # Determine final status
        if results["stage2"] and results["stage2"].is_verified:
            results["final_status"] = "verified"
            results["recommendation"] = "Student understood the concept. Proceed to next topic."
        elif results["stage1"] and not results["stage1"].is_verified:
            results["final_status"] = "needs_revision"
            results["recommendation"] = "Student needs to review basic concepts first."
        else:
            results["final_status"] = "needs_practice"
            results["recommendation"] = "More practice needed for application."
        
        return results
    
    def get_verification_summary(self) -> str:
        """Generate summary report"""
        if not self.verification_history:
            return "No verification history"
        
        total = len(self.verification_history)
        passed = sum(1 for r in self.verification_history if r.is_verified)
        avg_score = sum(r.score for r in self.verification_history) / total
        
        return f"""Verification Summary:
- Total Attempts: {total}
- Passed: {passed}
- Failed: {total - passed}
- Average Score: {avg_score:.1f}/100
- Current Stage: {self.current_stage.value}"""


# Integration helper untuk Practice Pipeline
class PracticePipeline:
    """Integrasi Quiz Verifier ke dalam Practice Pipeline"""
    
    def __init__(self, llm_config=None):
        self.verifier = QuizVerifier(llm_config)
    
    def after_problem_solved(self, problem: ContentPackage, 
                            student_code: str,
                            student_id: str) -> Dict[str, Any]:
        """Workflow setelah siswa menyelesaikan soal"""
        
        # 1. Generate verification question
        question = self.verifier.generate_stage1_question(problem)
        
        return {
            "status": "verification_needed",
            "question": question,
            "message": "Sebelum lanjut, jawab pertanyaan konseptual ini untuk memastikan pemahaman:",
            "hint": "Jelaskan konsepnya, tidak perlu menulis kode."
        }
    
    def process_verification_answer(self, problem: ContentPackage,
                                   question: str,
                                   answer: str) -> VerificationResult:
        """Proses jawaban verifikasi"""
        return self.verifier.evaluate_stage1(question, answer, ["concept", "logic"])
