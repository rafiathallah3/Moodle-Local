from logic_scratch.utils import append_log, add_agent_called, load_json
from logic_scratch.graders import grade_router
from logic_scratch.course_intelligence import infer_weak_concepts_generic
from logic_scratch.llm import LLMClient
from logic_scratch.schemas import AnalyzerOut

SYSTEM_ID = """Anda adalah Analyzer Agent. Gunakan grading deterministik + rubric untuk diagnosis pedagogis.
Return JSON:
{ "diagnosis_tag":"...", "diagnosis_detail":"...", "weak_concepts":["..."],
  "recommended_next_action":"...", "priority_level":"low/medium/high", "confidence":0.0 }"""

SYSTEM_EN = """You are the Analyzer Agent. Use deterministic grading + rubric to produce a pedagogical diagnosis.
Return JSON:
{ "diagnosis_tag":"...", "diagnosis_detail":"...", "weak_concepts":["..."],
  "recommended_next_action":"...", "priority_level":"low/medium/high", "confidence":0.0 }"""

def load_kc_graph(course_id: str):
    db = load_json("kc_graph_db.json")
    return db.get(course_id, {"edges":[], "confidence":{}, "last_updated":None})

def prereq_suspects(course_id: str, target_kcs: list):
    g = load_kc_graph(course_id)
    edges = g.get("edges", [])
    suspects = []
    for prereq, target in edges:
        if target in (target_kcs or []):
            suspects.append(prereq)
    out = []
    for s in suspects:
        if s not in out:
            out.append(s)
    return out

class Analyzer:
    def __init__(self, llm: LLMClient):
        self.llm = llm

    def _default_priority(self, score: float, rubric_label: str):
        if rubric_label == "correct": return "low"
        if score < 0.5: return "high"
        if score < 0.75: return "medium"
        return "low"

    def run(self, state: dict) -> dict:
        g = grade_router(
            course_domain=state.get("course_domain","General"),
            assessment_type=state.get("assessment_type",""),
            grading_spec=state.get("grading_spec", {}),
            student_ans=state.get("normalized_input",""),
            answer_key=state.get("answer_key",""),
            task_prompt=state.get("task_prompt","")
        )
        state["grading_score"] = g["grading_score"]
        state["rubric_label"] = g["rubric_label"]
        if g.get("qualitative_band"):
            state["qualitative_band"] = g["qualitative_band"]
        state["rubric_detail"] = g.get("rubric_detail")

        kc_targets = state.get("kc_targets", []) or []
        suspects = prereq_suspects(state.get("course_id",""), kc_targets)
        state["prereq_suspects"] = suspects

        rule_diag = infer_weak_concepts_generic(
            preferred_language=state.get("preferred_language","id"),
            course_domain=state.get("course_domain","General"),
            assessment_type=state.get("assessment_type",""),
            expected_concepts=state.get("expected_concepts", []),
            normalized_input=state.get("normalized_input",""),
            grading_score=state.get("grading_score", 0.0),
            rubric_detail=state.get("rubric_detail"),
            answer_key=state.get("answer_key","")
        )

        system = SYSTEM_EN if state.get("preferred_language")=="en" else SYSTEM_ID
        prompt = f"""Course: {state.get('course_name')}
Domain: {state.get('course_domain')}
Assessment type: {state.get('assessment_type')}
Task: {state.get('task_prompt')}
Expected concepts: {state.get('expected_concepts')}
Student answer: {state.get('normalized_input')}
Deterministic grading: {g}
Rule diagnosis: {rule_diag}
KC targets: {kc_targets}
Prereq suspects from KC graph: {suspects}
Return strict JSON only."""

        # bilingual fallback already handled by rule_diag
        fallback = {
            "diagnosis_tag": rule_diag["diagnosis_tag"],
            "diagnosis_detail": rule_diag["diagnosis_detail"] + (f" (Possible prereq gaps: {suspects})" if (suspects and state.get("preferred_language")=="en")
                                                                else (f" (Dugaan prasyarat lemah: {suspects})" if suspects else "")),
            "weak_concepts": rule_diag["weak_concepts"],
            "recommended_next_action": rule_diag["recommended_next_action"],
            "priority_level": self._default_priority(state["grading_score"], state["rubric_label"]),
            "confidence": 0.75
        }

        llm_diag = self.llm.generate_json(state, "analyzer", system, prompt, AnalyzerOut, fallback)

        state["diagnosis_tag"] = llm_diag.get("diagnosis_tag", fallback["diagnosis_tag"])
        state["diagnosis_detail"] = llm_diag.get("diagnosis_detail", fallback["diagnosis_detail"])
        state["weak_concepts"] = llm_diag.get("weak_concepts", fallback["weak_concepts"])
        state["recommended_next_action"] = llm_diag.get("recommended_next_action", fallback["recommended_next_action"])
        state["priority_level"] = llm_diag.get("priority_level", fallback["priority_level"])

        add_agent_called(state, "analyzer", "OK", f"score={state['grading_score']} rubric={state['rubric_label']}")
        append_log(state, "analyzer", "analysis", "Analysis completed", {
            "score": state["grading_score"],
            "rubric": state["rubric_label"],
            "weak": state.get("weak_concepts", []),
            "prereq_suspects": suspects
        })
        return state
