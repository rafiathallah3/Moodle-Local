from difflib import SequenceMatcher
from logic_scratch.utils import append_log, add_agent_called, normalize_whitespace
from logic_scratch.llm import LLMClient
from logic_scratch.schemas import CriticOut

SYSTEM_ID = """Anda adalah Reflective Critic (Quality Gate).
Rules:
- Student_chat: skeleton-only; no full solution; do not be too close to answer_key.
- Teacher: full answer key allowed but only for managed courses.
Return JSON: { "critic_verdict":"PASS/REVISE/BLOCK", "reason_code":"...", "critic_reason":"..." }"""

SYSTEM_EN = """You are the Reflective Critic (Quality Gate).
Rules:
- Student_chat: skeleton-only; no full solution; not too close to answer_key.
- Teacher: full answer key allowed only within managed courses.
Return JSON: { "critic_verdict":"PASS/REVISE/BLOCK", "reason_code":"...", "critic_reason":"..." }"""

def overlap_ratio(a: str, b: str) -> float:
    if not a or not b: return 0.0
    a = normalize_whitespace(a).lower()
    b = normalize_whitespace(b).lower()
    if b in a: return 1.0
    return round(SequenceMatcher(None, a, b).ratio(), 2)

def looks_too_complete_for_student(text: str) -> bool:
    # Domain-agnostic heuristic: too many "complete" steps or code-like lines
    t = text.strip()
    lines = [ln for ln in t.splitlines() if ln.strip()]
    if len(lines) >= 10:
        return True
    # if contains many concrete code-ish tokens
    code_tokens = ["for ", "range(", "select ", "from ", "join ", "print(", "def ", "return "]
    hits = sum(1 for tok in code_tokens if tok in t.lower())
    return hits >= 4 and len(lines) >= 4

class ReflectiveCritic:
    def __init__(self, llm: LLMClient):
        self.llm = llm

    def _rule_verdict(self, state: dict):
        role = state.get("role","student")
        mode = state.get("mode","")
        assessment_type = state.get("assessment_type","")
        gen = state.get("generated_response","") or ""
        key = state.get("answer_key","") or ""

        # Essay/exam student chat blocked (even if earlier allowed by mistake)
        if role=="student" and mode=="student_chat" and assessment_type in {"essay","exam"}:
            return {"critic_verdict":"BLOCK","reason_code":"OFF_POLICY","critic_reason":"Student chat disabled for essay/exam."}

        if len(normalize_whitespace(gen)) < 20:
            return {"critic_verdict":"REVISE","reason_code":"TOO_SHORT_UNHELPFUL","critic_reason":"Response too short to be helpful."}

        if role=="student" and mode=="student_chat":
            if looks_too_complete_for_student(gen):
                return {"critic_verdict":"REVISE","reason_code":"TOO_COMPLETE_FOR_STUDENT","critic_reason":"Student chat must be skeleton-only."}
            if key and overlap_ratio(gen, key) >= 0.85:
                return {"critic_verdict":"BLOCK","reason_code":"LEAKAGE_RISK","critic_reason":"Too close to answer key."}

        if role=="teacher":
            managed = set(state.get("managed_courses",[]) or [])
            cid = state.get("course_id")
            if cid and managed and cid not in managed:
                return {"critic_verdict":"BLOCK","reason_code":"OFF_POLICY","critic_reason":"Teacher requested content outside managed courses."}

        return {"critic_verdict":"PASS","reason_code":"OK","critic_reason":"Meets policy and quality constraints."}

    def run(self, state: dict) -> dict:
        lang = state.get("preferred_language","id")
        system = SYSTEM_EN if lang=="en" else SYSTEM_ID
        rule = self._rule_verdict(state)

        prompt = f"""Role: {state.get('role')}
Mode: {state.get('mode')}
Course: {state.get('course_name')}
Assessment type: {state.get('assessment_type')}
Generated response:
{state.get('generated_response')}
Answer key:
{state.get('answer_key')}
Rule verdict:
{rule}
Return strict JSON only."""
        out = self.llm.generate_json(state, "reflective_critic", system, prompt, CriticOut, rule)

        state["critic_verdict"] = out.get("critic_verdict", rule["critic_verdict"])
        state["reason_code"] = out.get("reason_code", rule["reason_code"])
        state["critic_reason"] = out.get("critic_reason", rule["critic_reason"])

        # revision_count increments per critic pass
        state["revision_count"] = int(state.get("revision_count",0)) + 1

        add_agent_called(state, "reflective_critic", "OK", f"{state['critic_verdict']}:{state['reason_code']}")
        append_log(state, "reflective_critic", "quality_gate", "Critic evaluated", {
            "verdict": state["critic_verdict"], "reason_code": state["reason_code"],
            "revision_count": state["revision_count"], "cap": state.get("revision_cap")
        })
        return state
