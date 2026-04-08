from logic_scratch.utils import normalize_whitespace

def moodle_evidence_to_state(evidence: dict) -> dict:
    """
    Minimal adapter from Moodle observer evidence.
    evidence keys from your PHP:
      - mode: submit_answer
      - student_profile
      - task_context {courseid,module,instanceid,name,instructions}
      - student_submission {text, files[]}
      - history/resources/policies...
    """
    sp = evidence.get("student_profile", {}) or {}
    sub = evidence.get("student_submission", {}) or {}
    text = sub.get("text","") or ""

    # In real integration you map Moodle courseid/module/instanceid -> course_id/assessment_id.
    # Here we keep placeholders and rely on provided course_id/assessment_id if included.
    state = {
      "role":"student",
      "mode": "submit_answer" if evidence.get("mode")=="submit_answer" else evidence.get("mode","submission_review"),
      "preferred_language": ((sp.get("preferences") or {}).get("language","id")),
      "user_id": str(sp.get("id") or sp.get("userid") or "UNKNOWN"),
      "student_profile": sp,
      "integrity": sp.get("integrity", {"hint_only":True,"no_full_solution":True,"exam_mode":False}),
      "raw_input": text,
      "normalized_input": normalize_whitespace(text),
      "input_type":"text",
      "moodle_task_context": evidence.get("task_context", {}),
      "moodle_files": sub.get("files", []),
      "history": evidence.get("history", {}),
      "resources": evidence.get("resources", {}),
      # Optional direct mapping if Moodle already passes them:
      "course_id": evidence.get("course_id",""),
      "assessment_id": evidence.get("assessment_id","")
    }
    return state
