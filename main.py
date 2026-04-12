
"""
================================================================================
AGENTIC MULTIMODAL AI TUTOR - AFS v2 ARCHITECTURE (COMPLETE)
================================================================================

AFS v2 (Automated Feedback System v2) Pipeline:
    Input -> Orchestrator -> Analyzer -> Critic -> Formatter -> Output
                ↓              ↓          ↓
            [Intent      [Style     [Summary
             Classify]   + Logic]    + Introspection]

NEW: Practice Pipeline dengan Quiz Verifier (2-Stage)
    Practice Request -> Problem Generator -> Student Solve 
        -> Quiz Verifier (Stage 1: Conceptual, Stage 2: Application)
        -> Decision: Next Topic / Revisit / Practice More

Architecture Components:
- 4 Core Agents: Orchestrator, Analyzer, Critic, LessonGenerator
- 11 Tools: Formatter, Refinement, PeerMatching, StudyPlanner, 
            LearningStyle, Fusion, StudentDashboard, QuizVerifier, dll.
- Extensions: Introspective, Peer Teaching, Motivational, Decomposition, 
              Bloom's Taxonomy, Learning Style, Multimodal Fusion, 
              2-Stage Quiz Verification

================================================================================
"""

from logic_scratch.graph_engine import StateGraph, END
from logic_scratch.moodle_adapter import MoodleAdapter
from logic_scratch.registry import registry
from agents.orchestrator import OrchestratorAgent
from agents.analyzer import AnalyzerAgent  # With Style & Logic Sub-Agents
from agents.reflective_critic import ReflectiveCriticAgent  # With Summary Agent
from agents.lesson_generator import LessonGeneratorAgent
from tools import (
    ResponseFormatter,
    TeacherRefinementAgent,
    PeerMatchingAgent,
    StudyPlanner,
    LearningStyleRecommendationsAgent,  # NEW: Learning Style
    FusionAgent,  # NEW: Multimodal Fusion
    StudentDashboard,  # NEW: Student Dashboard
    QuizVerifier,  # NEW: 2-Stage Quiz
    PracticePipeline  # NEW: Practice Pipeline
)
from config.llm_config import default_llm_config

def create_app(llm_config=None):
    """
    Create AFS v2 Agentic System dengan semua fitur:
    - Practice Pipeline & Quiz Verifier (2-Stage)
    - Learning Style Recommendations
    - Multimodal Fusion
    - Student Dashboard
    """
    config = llm_config or default_llm_config
    
    # Initialize AFS v2 Agents
    orchestrator = OrchestratorAgent()
    analyzer = AnalyzerAgent(config)  # Parent with Style & Logic children
    critic = ReflectiveCriticAgent(config)  # With Summary Agent & Introspection
    lesson_gen = LessonGeneratorAgent(config)  # With Peer Teaching & Bloom's
    
    # Initialize Tools
    formatter = ResponseFormatter()
    quiz_verifier = QuizVerifier(config)
    practice_pipeline = PracticePipeline(config)
    learning_style_agent = LearningStyleRecommendationsAgent(config)
    fusion_agent = FusionAgent()
    student_dashboard = StudentDashboard(registry)
    
    # Build AFS v2 State Graph
    builder = StateGraph()
    
    # Add nodes
    builder.add_node("orchestrator", orchestrator.process)
    builder.add_node("analyzer", analyzer.process)
    builder.add_node("critic", critic.process)
    builder.add_node("lesson_generator", lesson_gen.process)
    builder.add_node("formatter", formatter.process)
    builder.add_node("quiz_verifier", lambda state: quiz_verifier_process(state, quiz_verifier))
    builder.add_node("learning_style_adapter", lambda state: adapt_learning_style(state, learning_style_agent))
    
    # AFS v2 Conditional Routing
    def route_from_orchestrator(state):
        """Route berdasarkan intent classification"""
        if state.get("policy_blocked"):
            return "blocked"
        intent = state.get("intent")
        # Use Intent enum value strings (from schemas.py)
        intent_val = intent.value if hasattr(intent, "value") else str(intent)
        if intent_val == "submission":
            return "submission"
        elif intent_val == "practice":  # Fixed: Intent.PRACTICE = "practice"
            return "practice_with_quiz"  # Practice + 2-Stage Quiz pipeline
        elif intent_val == "analytics_request":
            return "analytics"
        return "content"
    
    builder.add_conditional_edges(
        "orchestrator",
        route_from_orchestrator,
        {
            "blocked": "formatter",
            "submission": "analyzer",
            "practice_with_quiz": "lesson_generator",  # Practice + Quiz pipeline
            "analytics": "student_dashboard",  # Route ke dashboard
            "content": "lesson_generator"
        }
    )
    
    # Critic routing dengan quiz integration
    def route_from_critic(state):
        """AFS v2 Quality Gate dengan max 2 iterasi + Quiz routing"""
        assessment = state.get("critic_assessment")
        
        # Jika revision needed dan belum max iterasi
        if assessment and assessment.revision_needed and assessment.iteration < 2:
            return "revise"
        
        # NEW: Jika practice mode dan quiz belum selesai, ke quiz verifier
        if state.get("practice_mode") and not state.get("quiz_completed"):
            return "quiz"
        
        # OLD: Adaptive Learning Style - ONLY trigger if student struggles (score < 70 or not correct)
        # This approach skipped the learning_style_adapter node entirely when the
        # student scored >= 70, which meant the JSON response never contained a
        # "learning_tips" key.  As a result, the PHP front-end (diagnose_ajax.php)
        # had nothing to render in the "Tips (Sesuai Gaya Belajarmu)" section —
        # even though the student might still benefit from personalised tips on
        # correct answers (e.g. pseudo-code formatting feedback).
        #
        # score_result = state.get("scoring_result")
        # is_struggling = False
        # if score_result and (not score_result.is_correct or score_result.score_0_100 < 70):
        #     is_struggling = True
        #
        # if is_struggling and not state.get("style_adapted"):
        #     return "adapt_style"

        # NEW: Always trigger for quiz submissions so students get personalised
        # tips regardless of score.
        trigger = state.get("evidence", {}).get("trigger", "")
        if trigger in ("diagnose", "on_submit") and not state.get("style_adapted"):
            return "adapt_style"
        
        return "done"
    
    builder.add_conditional_edges(
        "critic",
        route_from_critic,
        {
            "revise": "lesson_generator",
            "quiz": "quiz_verifier",  # NEW: Ke quiz verifier
            "adapt_style": "learning_style_adapter",  # NEW: Adapt content
            "done": "formatter"
        }
    )
    
    # Quiz verifier routing
    def route_from_quiz(state):
        """Route setelah quiz verification"""
        quiz_result = state.get("quiz_result")
        if quiz_result:
            if quiz_result.is_verified and quiz_result.stage.value == "application":
                return "verified_both_stages"
            elif quiz_result.is_verified:
                return "stage2_needed"
            else:
                return "needs_revision"
        return "done"
    
    builder.add_conditional_edges(
        "quiz_verifier",
        route_from_quiz,
        {
            "verified_both_stages": "formatter",  # Lolos, kasih feedback sukses
            "stage2_needed": "quiz_verifier",  # Stage 2 quiz
            "needs_revision": "lesson_generator",  # Revisit materi
            "done": "formatter"
        }
    )
    
    # Learning style adapter routing
    def route_from_style_adapter(state):
        """Route setelah learning style adaptation"""
        return "done"
    
    builder.add_conditional_edges(
        "learning_style_adapter",
        route_from_style_adapter,
        {"done": "formatter"}
    )
    
    # Static edges (AFS v2 pipeline)
    builder.add_edge("analyzer", "critic")
    builder.add_edge("lesson_generator", "critic")
    builder.add_edge("formatter", END)
    builder.set_entry_point("orchestrator")
    
    return builder.compile()


def quiz_verifier_process(state, verifier):
    """Process quiz verification dalam graph"""
    content = state.get("content_package")
    if content:
        question = verifier.generate_stage1_question(content)
        state["quiz_question"] = question
        state["quiz_stage"] = 1
        state["quiz_mode"] = True
    return state


def adapt_learning_style(state, ls_agent):
    """Adapt content berdasarkan learning style"""
    user_id = state.get("user_id", "unknown")
    topic = state.get("topic", "general")
    
    # Try to extract topic from misconceptions if available
    score_result = state.get("scoring_result")
    if score_result and score_result.misconceptions:
        # e.g., 'loops_infinite' -> 'loops'
        topic = score_result.misconceptions[0].label.split('_')[0].lower()
    
    # Detect learning style dari behavior
    interactions = state.get("interaction_history", [])
    result = ls_agent.detect_and_recommend(user_id, topic, interactions)
    
    state["detected_learning_style"] = result["detected_style"]
    state["style_adapted"] = True
    state["learning_recommendations"] = result["recommendations"]
    
    return state


# Global AFS v2 instance
graph = create_app()

def process_request(evidence: dict, user_id: str, course_id: str) -> dict:
    """
    AFS v2 Entry Point - Process single request through the pipeline
    
    Args:
        evidence: Input data (code, text, trigger, metadata)
        user_id: Student/Teacher ID
        course_id: Course identifier
    
    Returns:
        AFS v2 formatted response dengan scoring, summary, dan motivational message
    """
    adapter = MoodleAdapter()
    state = adapter.evidence_to_state(evidence, user_id, course_id)
    result = graph.invoke(state)
    return adapter.state_to_response(result)


def process_practice_request(stage: str, user_id: str, course_id: str,
                              topic: str = "general",
                              student_answer: str = "",
                              question: str = "",
                              problem_explanation: str = "") -> dict:
    """
    Stateless Practice Pipeline Entry Point — called once per stage.

    PHP manages session state between turns. This function handles each
    individual stage:

    Stage "generate":
        Generates a practice problem + Stage 1 conceptual question.
        Returns: {problem, skeleton_code, concepts, stage1_question}

    Stage "verify_stage1":
        Evaluates student's conceptual answer and generates Stage 2 question.
        Returns: {passed, score, feedback, stage2_question}

    Stage "verify_stage2":
        Evaluates student's application answer and returns final recommendation.
        Returns: {passed, score, feedback, final_status, recommendation}
    """
    from logic_scratch.schemas import ContentPackage
    from tools.quiz_verifier import QuizVerifier

    verifier = QuizVerifier()

    if stage == "generate":
        # Drive the main graph with practice intent
        evidence = {
            "content": f"I want to practice {topic}",
            "trigger": "practice_request",
            "metadata": {"role": "student", "topic": topic}
        }
        adapter = MoodleAdapter()
        state = adapter.evidence_to_state(evidence, user_id, course_id)
        result = graph.invoke(state)

        # Extract generated content_package
        content = result.get("content_package")
        if not content:
            # Fallback minimal problem
            content = ContentPackage(
                explanation=f"Practice problem for {topic}: write a program that demonstrates {topic}.",
                skeleton_code=f"def practice_{topic.replace(' ', '_')}():\n    # Your code here\n    pass",
                concepts=[topic, "logic", "algorithm"],
                metadata={"mode": "practice_problem", "target_kc": topic}
            )

        # Generate Stage 1 conceptual question
        q1 = verifier.generate_stage1_question(content, topic)

        return {
            "status": "success",
            "stage": "generated",
            "problem": content.explanation,
            "skeleton_code": content.skeleton_code or "",
            "concepts": content.concepts,
            "stage1_question": q1
        }

    elif stage == "verify_stage1":
        # Reconstruct minimal ContentPackage from PHP-provided context
        content = ContentPackage(
            explanation=problem_explanation,
            concepts=[topic, "logic", "algorithm"],
            metadata={"mode": "practice_problem", "target_kc": topic}
        )
        result = verifier.evaluate_stage1(
            question=question,
            student_answer=student_answer,
            expected_concepts=content.concepts
        )

        response = {
            "status": "success",
            "stage": "stage1_evaluated",
            "passed": result.is_verified,
            "score": round(result.score, 1),
            "feedback": result.feedback,
            "missing_concepts": result.missing_concepts,
            "next_action": result.next_action
        }

        # If stage 1 passed, pre-generate stage 2 question
        if result.is_verified:
            q2 = verifier.generate_stage2_question(
                content, previous_answer=student_answer, topic=topic
            )
            response["stage2_question"] = q2

        return response

    elif stage == "verify_stage2":
        result = verifier.evaluate_stage2(
            question=question,
            student_answer=student_answer,
            original_problem=problem_explanation
        )

        final_map = {
            "next_topic": "Selamat! Kamu sudah menguasai topik ini. Lanjut ke topik berikutnya!",
            "practice_more": "Kamu perlu lebih banyak latihan untuk topik ini. Coba lagi!",
            "revisit_concept": "Ada konsep yang perlu diulang. Pelajari kembali materinya."
        }
        recommendation = final_map.get(
            result.next_action,
            "Lanjutkan belajar dan jangan menyerah!"
        )

        return {
            "status": "success",
            "stage": "stage2_evaluated",
            "passed": result.is_verified,
            "score": round(result.score, 1),
            "feedback": result.feedback,
            "final_status": result.next_action,
            "recommendation": recommendation
        }

    else:
        return {"status": "error", "message": f"Unknown practice stage: {stage}"}


def process_practice_with_quiz(user_id: str, course_id: str, topic: str, 
                                student_code: str, quiz_answers: dict) -> dict:
    """
    End-to-end practice pipeline dengan 2-stage quiz verification
    
    Workflow:
        1. Generate practice problem
        2. Student solve (code)
        3. Stage 1 Quiz: Conceptual understanding
        4. Stage 2 Quiz: Application/transfer
        5. Return: verification result + next action
    
    Args:
        quiz_answers: {"stage1": "...", "stage2": "..."}
    
    Returns:
        Verification result + recommendation
    """
    from logic_scratch.schemas import ContentPackage
    
    # Get practice problem
    evidence = {
        "content": f"I want to practice {topic}",
        "trigger": "practice_request",
        "metadata": {"role": "student", "topic": topic}
    }
    
    # Generate problem
    problem_result = process_request(evidence, user_id, course_id)
    
    # Run quiz verifier
    verifier = QuizVerifier()
    problem = ContentPackage(
        explanation=problem_result.get("content", {}).get("explanation", f"Practice {topic}"),
        full_solution="",
        skeleton_code="",
        questions=[],
        metadata={"topic": topic, "practice_mode": True}
    )
    
    verification = verifier.run_full_verification(problem, quiz_answers, topic)
    
    return {
        "practice_problem": problem_result,
        "verification": verification,
        "next_action": verification.get("final_status"),
        "recommendation": verification.get("recommendation"),
        "scores": {
            "stage1": verification.get("stage1", {}).score if verification.get("stage1") else 0,
            "stage2": verification.get("stage2", {}).score if verification.get("stage2") else 0
        }
    }


def get_student_dashboard(user_id: str, course_id: str) -> dict:
    """
    Get student dashboard dengan progress, analytics, dan recommendations
    
    Returns:
        Complete dashboard data dengan learning style
    """
    dashboard = StudentDashboard(registry)
    data = dashboard.get_dashboard_data(user_id, course_id)
    
    # Tambahkan learning style recommendations
    ls_agent = LearningStyleRecommendationsAgent()
    weak_concepts = [c["concept"] for c in data.get("weak_concepts", [])]
    
    recommendations = {}
    for concept in weak_concepts[:2]:  # Top 2 weak concepts
        rec = ls_agent.get_recommendations(user_id, concept)
        recommendations[concept] = rec
    
    data["personalized_recommendations"] = recommendations
    
    return data


if __name__ == "__main__":
    import argparse
    import sys
    import json
    
    parser = argparse.ArgumentParser(description="AMT-CS1 Agentic Process Runner")
    parser.add_argument("--stdin", action="store_true", help="Read JSON payload from STDIN")
    args = parser.parse_args()
    
    if args.stdin:
        # Read payload from standard input (used by python_runner.php)
        try:
            input_json = sys.stdin.read()
            data = json.loads(input_json)
        except Exception as e:
            print(json.dumps({"error": f"Failed to parse STDIN JSON: {str(e)}"}))
            sys.exit(1)
            
        action = data.get("action", "run")
        input_mode = data.get("input_mode", "moodle_evidence")
        
        # Extract default identifiers
        user_id = data.get("evidence", {}).get("user_id", "unknown_user")
        course_id = data.get("evidence", {}).get("course_id", "CS101")
        
        try:
            if action == "practice":
                # Practice Pipeline: stateless-per-stage dispatch
                practice_data = data.get("practice", {})
                result = process_practice_request(
                    stage=practice_data.get("stage", "generate"),
                    user_id=data.get("user_id", user_id),
                    course_id=data.get("course_id", course_id),
                    topic=practice_data.get("topic", "general"),
                    student_answer=practice_data.get("student_answer", ""),
                    question=practice_data.get("question", ""),
                    problem_explanation=practice_data.get("problem_explanation", "")
                )
            elif input_mode == "moodle_evidence":
                evidence = data.get("evidence", {})
                result = process_request(evidence, user_id, course_id)
            else:
                # Direct state manipulation or payload
                payload = data.get("payload", {})
                result = graph.invoke(payload)
                
            # Must output strictly JSON for PHP to decode
            print(json.dumps(result))
            
        except Exception as e:
            print(json.dumps({"error": f"Execution failed: {str(e)}"}))
            sys.exit(1)
    else:
        # Original simple text output for local execution
        print("=" * 70)
        print("AGENTIC MULTIMODAL AI TUTOR - AFS v2 COMPLETE")
        print("=" * 70)
        print("Architecture: 4 Agents + 11 Tools + Extensions")
        print("Features:")
        print("  ✓ Core: Orchestrator, Analyzer (Style+Logic), Critic (Summary+Introspection)")
        print("  ✓ Practice: Problem Generator + Quiz Verifier (2-Stage)")
        print("  ✓ Personalization: Learning Style + Student Dashboard")
        print("  ✓ Multimodal: Fusion Agent (Text+Visual+Audio)")
        print("  ✓ Collaboration: Peer Teaching + Teacher Refinement")
        print("  ✓ Assessment: Bloom's Taxonomy + Motivational Layer")
        print("=" * 70)
        print("Run with '--stdin' for programmatic integration with Moodle.")
