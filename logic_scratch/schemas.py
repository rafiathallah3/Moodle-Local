from pydantic import BaseModel
from typing import List, Literal, Dict, Any

class IntentOut(BaseModel):
    intent: str
    reason: str = ""
    confidence: float = 0.5

class AnalyzerOut(BaseModel):
    diagnosis_tag: str
    diagnosis_detail: str
    weak_concepts: List[str] = []
    recommended_next_action: str = ""
    priority_level: Literal["low","medium","high"] = "medium"
    confidence: float = 0.7

class GeneratorOut(BaseModel):
    response_mode: str
    generated_response: str

class CriticOut(BaseModel):
    critic_verdict: Literal["PASS","REVISE","BLOCK"]
    reason_code: str
    critic_reason: str

class RubricScoreOut(BaseModel):
    score: float
    band: str
    weak_criteria: List[str]
    notes: str
    evidence: Dict[str, Any] = {}
