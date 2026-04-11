
"""
Complete Schemas for Agentic Tutor v3
"""
from typing import Dict, List, Optional, Any, TypedDict, Literal, Union
from enum import Enum
from datetime import datetime
from pydantic import BaseModel, Field

# Enums
class Intent(str, Enum):
    SUBMISSION = "submission"
    CHAT = "chat"
    PRACTICE = "practice"
    STUDY_PLANNER = "study_planner"
    ANALYTICS_REQUEST = "analytics_request"

class Severity(str, Enum):
    HIGH = "high"
    MEDIUM = "medium"
    LOW = "low"

class ContentMode(str, Enum):
    STUDENT_HELP = "student_help"
    TEACHER_FULL = "teacher_full"
    PRACTICE_PROBLEM = "practice_problem"
    STUDY_PLAN = "study_plan"

class LLMProvider(str, Enum):
    GEMINI = "gemini"
    OPENAI = "openai"
    ANTHROPIC = "anthropic"
    LOCAL = "local"

class CFFType(str, Enum):
    """Cognitive Forcing Functions"""
    NONE = "none"
    ON_DEMAND = "on_demand"
    WAIT_DELAY = "wait_delay"
    UPDATE_FIRST = "update_first"

class PracticeStage(str, Enum):
    PROBLEM_PRESENTED = "problem_presented"
    SOLUTION_SUBMITTED = "solution_submitted"
    VERIFIED = "verified"

# Models
class CourseConfig(BaseModel):
    course_id: str
    course_name: str
    kc_set: List[str]
    difficulty_baseline: Dict[str, float] = Field(default_factory=dict)
    cff_enabled: bool = True
    cff_type: CFFType = CFFType.ON_DEMAND
    wait_seconds: int = 30
    llm_preset: str = "default"
    modality_support: List[str] = ["text", "image", "audio"]

class KCGraph(BaseModel):
    course_id: str
    kcs: List[str]
    edges: List[tuple] = Field(default_factory=list)
    difficulty_map: Dict[str, float] = Field(default_factory=dict)
    last_updated: Optional[str] = None

class MisconceptionItem(BaseModel):
    label: str
    evidence: str
    severity: Severity = Severity.MEDIUM
    timestamp: Optional[str] = None

class RubricBreakdown(BaseModel):
    form: int = Field(0, ge=0, le=10)
    logic: int = Field(0, ge=0, le=10)
    style: int = Field(0, ge=0, le=10)

class ScoringV2Result(BaseModel):
    score_0_100: int = Field(0, ge=0, le=100)
    is_correct: bool = False
    confidence: float = Field(0.5, ge=0.0, le=1.0)
    summary: str = ""
    misconceptions: List[MisconceptionItem] = []
    rubric_breakdown: RubricBreakdown = Field(default_factory=RubricBreakdown)
    suggested_next_fix: Optional[str] = None

class FusedContext(BaseModel):
    primary_text: str = ""
    ocr_text: Optional[str] = None
    asr_text: Optional[str] = None
    confidence_scores: Dict[str, float] = Field(default_factory=dict)
    dominant_modality: str = "text"

class ContentPackage(BaseModel):
    explanation: str = ""
    skeleton_code: Optional[str] = None
    full_solution: Optional[str] = None
    questions: List[str] = []
    concepts: List[str] = []
    verification_question: Optional[str] = None
    expected_answer: Optional[str] = None
    cff_applied: bool = False
    metadata: Dict[str, Any] = Field(default_factory=dict)

class CriticAssessment(BaseModel):
    passed: bool = False
    revision_needed: bool = False
    leakage_detected: bool = False
    feedback: str = ""
    iteration: int = 0

class StudentModel(BaseModel):
    user_id: str = ""
    course_id: str = ""
    mastery: Dict[str, float] = Field(default_factory=dict)
    misconceptions: List[MisconceptionItem] = Field(default_factory=list)
    preferences: Dict[str, Any] = Field(default_factory=dict)
    need_for_cognition: Optional[float] = None
    last_active: Optional[str] = None

# State
class AgenticState(TypedDict, total=False):
    user_id: str
    course_id: str
    assessment_id: Optional[str]
    course_config: Optional[CourseConfig]
    evidence: Dict[str, Any]
    fused_context: Optional[FusedContext]
    intent: Intent
    next_node: Optional[str]
    policy_blocked: bool
    policy_reason: Optional[str]
    cff_triggered: bool
    scoring_result: Optional[ScoringV2Result]
    content_package: Optional[ContentPackage]
    critic_assessment: Optional[CriticAssessment]
    student_model: Optional[StudentModel]
    kc_graph: Optional[KCGraph]
    weak_concepts: List[str]
    practice_stage: Optional[PracticeStage]
    practice_attempts: int
    final_response: Optional[Dict[str, Any]]
    nodes_visited: List[str]
    error: Optional[str]
    processing_time_ms: int
