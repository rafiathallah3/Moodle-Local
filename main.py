
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
        if intent.value == "submission":
            return "submission"
        elif intent.value == "practice_request":
            return "practice_with_quiz"  # NEW: Practice dengan quiz
        elif intent.value == "analytics_request":
            return "analytics"
        return "content"
    
    builder.add_conditional_edges(
        "orchestrator",
        route_from_orchestrator,
        {
            "blocked": "formatter",
            "submission": "analyzer",
            "practice_with_quiz": "lesson_generator",  # Practice dengan quiz
            "analytics": "student_dashboard",  # NEW: Route ke dashboard
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
        
        # NEW: Jika ada learning style preference, adapt content
        if state.get("detected_learning_style") and not state.get("style_adapted"):
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
