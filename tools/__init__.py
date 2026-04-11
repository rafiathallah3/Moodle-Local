"""
Tools Package - Agentic Multimodal AI Tutor
"""

from .modality_processor import ModalityProcessor, OCREngine, ASREngine
from .quiz_verifier import QuizVerifier, PracticePipeline, VerificationResult, VerificationStage
from .practice_manager import PracticeManager
from .study_planner import StudyPlanner, MemoryManager
from .response_formatter import ResponseFormatter
from .teacher_refinement import TeacherRefinementAgent
from .peer_matching import PeerMatchingAgent
from .learning_style_agent import LearningStyleRecommendationsAgent
from .fusion_agent import FusionAgent, ModalityInput, FusedContext
from .student_dashboard import StudentDashboard

__all__ = [
    # Modality Processing
    'ModalityProcessor', 'OCREngine', 'ASREngine',
    # Assessment & Quiz
    'QuizVerifier', 'PracticePipeline', 'VerificationResult', 'VerificationStage',
    'PracticeManager',
    # Planning & Memory
    'StudyPlanner', 'MemoryManager',
    # Response & Refinement
    'ResponseFormatter',
    'TeacherRefinementAgent',
    # Collaboration
    'PeerMatchingAgent',
    # Learning Style
    'LearningStyleRecommendationsAgent',
    # Multimodal Fusion
    'FusionAgent', 'ModalityInput', 'FusedContext',
    # Dashboard
    'StudentDashboard'
]
