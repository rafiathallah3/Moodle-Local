import re
from typing import Dict, Any, List
from logic_scratch.utils import load_json, save_json, now_iso

DIFFICULTY_LEVELS = ["easy", "medium", "hard"]

def propose_difficulty_from_metrics(success_rate: float, avg_score: float) -> Dict[str, Any]:
    """
    Mode B (recommended):
    Combine success_rate + avg_score into a single difficulty_index.

    difficulty_index = 0.6*(1-success_rate) + 0.4*(1-avg_score)
      - hard   if index >= 0.60
      - easy   if index <= 0.30
      - medium otherwise

    Rationale:
    - success_rate captures "how many truly pass"
    - avg_score captures "partial credit / near-miss pattern"
    - weighted to emphasize success_rate but still account for avg_score
    """
    sr = float(success_rate)
    av = float(avg_score)
    idx = round(0.6 * (1.0 - sr) + 0.4 * (1.0 - av), 2)

    if idx >= 0.60:
        diff = "hard"
    elif idx <= 0.30:
        diff = "easy"
    else:
        diff = "medium"

    return {"suggested_difficulty": diff, "difficulty_index": idx}

def compute_assessment_stats(assessment_id: str) -> Dict[str, Any]:
    """
    Aggregate from learning_events.json (must include assessment_id).
    """
    events = load_json("learning_events.json")
    rows = [e for e in events if e.get("assessment_id") == assessment_id]
    attempts = len(rows)
    if attempts == 0:
        return {"attempts": 0, "success_rate": None, "avg_score": None}

    success = sum(1 for e in rows if int(e.get("success", 0)) == 1)
    avg_score = round(sum(float(e.get("score", 0.0) or 0.0) for e in rows) / attempts, 2)
    success_rate = round(success / attempts, 2)
    return {"attempts": attempts, "success_rate": success_rate, "avg_score": avg_score}

def maybe_create_pending_difficulty_suggestion(assessment_id: str, min_attempts: int = 30) -> Dict[str, Any]:
    """
    Auto-adjust BUT pending approval:
    - If attempts >= min_attempts, compute success_rate & avg_score and suggest difficulty
    - Store into assessments.json as difficulty_suggestion {status:'pending', ...}
    """
    assessments = load_json("assessments.json")
    a = assessments.get(assessment_id)
    if not a:
        return {"ok": False, "reason": "assessment_not_found"}

    stats = compute_assessment_stats(assessment_id)
    attempts = stats["attempts"]
    if attempts < min_attempts:
        return {"ok": False, "reason": f"below_min_attempts:{attempts}/{min_attempts}", "stats": stats}

    current = (a.get("difficulty") or "unknown").lower()
    sr = stats["success_rate"]
    av = stats["avg_score"]

    # Defensive
    if sr is None or av is None:
        return {"ok": False, "reason": "insufficient_stats", "stats": stats}

    proposed = propose_difficulty_from_metrics(sr, av)
    suggested = proposed["suggested_difficulty"]
    difficulty_index = proposed["difficulty_index"]

    # Keep stats record always updated
    a["difficulty_stats"] = {"updated_at": now_iso(), **stats, "difficulty_index": difficulty_index}

    # If no change, update stats and return
    if current == suggested:
        assessments[assessment_id] = a
        save_json("assessments.json", assessments)
        return {"ok": True, "action": "no_change", "current": current, "suggested": suggested, "stats": a["difficulty_stats"]}

    # If already pending with same suggestion, refresh
    sug = a.get("difficulty_suggestion") or {}
    if sug.get("status") == "pending" and sug.get("suggested_difficulty") == suggested:
        sug["stats"] = a["difficulty_stats"]
        sug["updated_at"] = now_iso()
        a["difficulty_suggestion"] = sug
        assessments[assessment_id] = a
        save_json("assessments.json", assessments)
        return {"ok": True, "action": "refresh_pending", "current": current, "suggested": suggested, "stats": a["difficulty_stats"]}

    # Create/replace pending suggestion
    a["difficulty_suggestion"] = {
        "status": "pending",
        "created_at": now_iso(),
        "updated_at": now_iso(),
        "current_difficulty": current,
        "suggested_difficulty": suggested,
        "reason": f"success_rate={sr}, avg_score={av}, difficulty_index={difficulty_index} (attempts={attempts})",
        "stats": a["difficulty_stats"],
        "approved_by": None,
        "approved_at": None,
        "rejected_by": None,
        "rejected_at": None
    }

    assessments[assessment_id] = a
    save_json("assessments.json", assessments)
    return {"ok": True, "action": "created_pending", "current": current, "suggested": suggested, "stats": a["difficulty_stats"]}

def list_pending_suggestions_for_teacher(teacher_id: str) -> List[Dict[str, Any]]:
    teachers = load_json("teachers.json")
    courses = load_json("courses.json")
    assessments = load_json("assessments.json")

    t = teachers.get(teacher_id, {})
    managed = set(t.get("managed_courses", []))

    pending = []
    for aid, a in assessments.items():
        cid = a.get("course_id")
        if managed and cid not in managed:
            continue
        sug = a.get("difficulty_suggestion") or {}
        if sug.get("status") == "pending":
            cname = courses.get(cid, {}).get("course_name", cid)
            st = sug.get("stats", {}) or {}
            pending.append({
                "assessment_id": aid,
                "course_id": cid,
                "course_name": cname,
                "title": a.get("title"),
                "current_difficulty": sug.get("current_difficulty"),
                "suggested_difficulty": sug.get("suggested_difficulty"),
                "reason": sug.get("reason"),
                "stats": {
                    "attempts": st.get("attempts"),
                    "success_rate": st.get("success_rate"),
                    "avg_score": st.get("avg_score"),
                    "difficulty_index": st.get("difficulty_index")
                }
            })
    return pending

def _teacher_can_manage_assessment(teacher_id: str, assessment_id: str) -> bool:
    teachers = load_json("teachers.json")
    assessments = load_json("assessments.json")
    t = teachers.get(teacher_id, {})
    managed = set(t.get("managed_courses", []))
    a = assessments.get(assessment_id, {})
    return bool(a and (a.get("course_id") in managed))

def approve_difficulty_suggestion(teacher_id: str, assessment_id: str) -> Dict[str, Any]:
    if not _teacher_can_manage_assessment(teacher_id, assessment_id):
        return {"ok": False, "reason": "not_authorized"}

    assessments = load_json("assessments.json")
    a = assessments.get(assessment_id, {})
    sug = a.get("difficulty_suggestion") or {}
    if sug.get("status") != "pending":
        return {"ok": False, "reason": "no_pending_suggestion"}

    suggested = sug.get("suggested_difficulty")
    a["difficulty"] = suggested
    sug["status"] = "approved"
    sug["approved_by"] = teacher_id
    sug["approved_at"] = now_iso()
    a["difficulty_suggestion"] = sug

    assessments[assessment_id] = a
    save_json("assessments.json", assessments)
    return {"ok": True, "action": "approved", "assessment_id": assessment_id, "new_difficulty": suggested}

def reject_difficulty_suggestion(teacher_id: str, assessment_id: str, note: str = "") -> Dict[str, Any]:
    if not _teacher_can_manage_assessment(teacher_id, assessment_id):
        return {"ok": False, "reason": "not_authorized"}

    assessments = load_json("assessments.json")
    a = assessments.get(assessment_id, {})
    sug = a.get("difficulty_suggestion") or {}
    if sug.get("status") != "pending":
        return {"ok": False, "reason": "no_pending_suggestion"}

    sug["status"] = "rejected"
    sug["rejected_by"] = teacher_id
    sug["rejected_at"] = now_iso()
    if note:
        sug["rejection_note"] = note
    a["difficulty_suggestion"] = sug

    assessments[assessment_id] = a
    save_json("assessments.json", assessments)
    return {"ok": True, "action": "rejected", "assessment_id": assessment_id}
