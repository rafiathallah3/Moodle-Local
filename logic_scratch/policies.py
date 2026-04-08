from logic_scratch.utils import normalize_whitespace, load_json

def validate_user_input(text: str):
    t = normalize_whitespace(text)
    if not t: return {"ok": False, "issue": "empty"}
    if len(t) < 2: return {"ok": False, "issue": "too_short"}
    if len(t) > 8000: return {"ok": False, "issue": "too_long"}
    return {"ok": True, "issue": None}

def detect_prompt_injection(text: str) -> bool:
    t = normalize_whitespace(text).lower()
    patterns = [
        "ignore previous instruction","ignore the system prompt",
        "abaikan instruksi sebelumnya","lupakan semua aturan",
        "reveal the answer key","tampilkan kunci jawaban","berikan jawaban langsung"
    ]
    return any(p in t for p in patterns)

def is_soft_greeting(text: str) -> bool:
    t = normalize_whitespace(text).lower()
    greets = ["halo","hai","hi","hello","pagi","siang","malam","permisi"]
    return any(t == g or t.startswith(g+" ") for g in greets)

def is_off_topic(text: str) -> bool:
    t = normalize_whitespace(text).lower()
    markers = ["cuaca","weather","presiden","bola","film","lagu","zodiak","gosip"]
    return any(m in t for m in markers)

def get_attempt_policy(course_id: str) -> str:
    pol = load_json("teacher_policies.json")
    return pol.get("course_policies", {}).get(course_id, pol.get("default_attempt_policy","strict"))

def compute_permissions(assessment_type: str, agent_enabled: bool):
    review_allowed = True
    chat_allowed = bool(agent_enabled and assessment_type in {"quiz","exercise"})
    return {"review_allowed": review_allowed, "chat_allowed": chat_allowed}

def student_chat_allowed(assessment_type: str, agent_enabled: bool, integrity: dict):
    # essay/exam chat is blocked by design (integrity/exam_mode also can block)
    if assessment_type in {"essay","exam"}:
        return False
    if integrity and integrity.get("exam_mode") is True:
        return False
    return bool(agent_enabled and assessment_type in {"quiz","exercise"})
