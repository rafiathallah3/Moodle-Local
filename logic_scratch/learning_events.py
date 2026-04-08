from logic_scratch.utils import load_json, save_json, now_iso
from logic_scratch.item_analytics import maybe_create_pending_difficulty_suggestion

DEFAULT_MIN_ATTEMPTS = 30  # chosen for robustness

def record_learning_event(state: dict):
    """
    MAS-KCL + item analytics friendly event:
      { course_id, assessment_id, student_id, kc_targets, success, score, timestamp }
    """
    if state.get("role") != "student":
        return None

    course_id = state.get("course_id","")
    assessment_id = state.get("assessment_id","")
    student_id = state.get("student_id","") or state.get("user_id","")
    kc_targets = state.get("kc_targets", []) or []
    score = float(state.get("grading_score", 0.0) or 0.0)
    success = 1 if score >= 0.75 else 0

    ev = {
      "timestamp": now_iso(),
      "course_id": course_id,
      "assessment_id": assessment_id,
      "student_id": student_id,
      "kc_targets": kc_targets,
      "score": score,
      "success": success
    }

    events = load_json("learning_events.json")
    events.append(ev)
    save_json("learning_events.json", events)

    # Auto difficulty suggestion (pending approval)
    if assessment_id:
        maybe_create_pending_difficulty_suggestion(assessment_id, min_attempts=DEFAULT_MIN_ATTEMPTS)

    return ev

def count_events_for_course(course_id: str) -> int:
    events = load_json("learning_events.json")
    return sum(1 for e in events if e.get("course_id")==course_id)

def get_events_for_course(course_id: str):
    events = load_json("learning_events.json")
    return [e for e in events if e.get("course_id")==course_id]
