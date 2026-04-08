import os, sys, json, argparse
from typing import Any, Dict

# Ensure local imports work when called from Moodle server
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

def _read_stdin_json() -> Dict[str, Any]:
    raw = sys.stdin.read()
    if not raw.strip():
        return {}
    return json.loads(raw)

def _write_stdout_json(obj: Dict[str, Any]):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False))

def _auth_check(req: Dict[str, Any]) -> bool:
    """
    Optional shared token auth.
    If env AGENTIC_SHARED_TOKEN is set, request must include matching token.
    """
    shared = os.environ.get("AGENTIC_SHARED_TOKEN", "").strip()
    if not shared:
        return True
    token = (req.get("token") or "").strip()
    return token == shared

def build_agentic():
    from logic_scratch.graph_engine import build_graph
    use_llm = os.environ.get("USE_LLM", "false").lower() == "true"
    provider = os.environ.get("LLM_PROVIDER", "gemini")
    model = os.environ.get("LLM_MODEL", "gemini-2.5-flash")
    graph, invoke = build_graph(use_llm=use_llm, provider=provider, model=model)
    return graph, invoke

# Build once (important for performance)
GRAPH, INVOKE = build_agentic()

def handle_run(req: Dict[str, Any]) -> Dict[str, Any]:
    """
    input_mode:
      - payload: req["payload"] is AgenticState-like dict
      - moodle_evidence: req["evidence"] is Moodle observer evidence
    """
    input_mode = req.get("input_mode", "payload")
    if input_mode == "moodle_evidence":
        from logic_scratch.moodle_adapter import moodle_evidence_to_state
        evidence = req.get("evidence") or {}
        payload = moodle_evidence_to_state(evidence)
    else:
        payload = req.get("payload") or {}

    out = INVOKE(payload)
    # Return Moodle-friendly top-level payload if available
    return out.get("final_payload") or {"ok": True, "raw_state": out}

def handle_mas_kcl_trigger(req: Dict[str, Any]) -> Dict[str, Any]:
    from mas_kcl.mas_kcl_engine import mas_kcl_trigger
    course_id = req.get("course_id", "")
    mode = req.get("mode", "threshold")
    threshold_n = int(req.get("threshold_n", 30))
    return mas_kcl_trigger(course_id, mode=mode, threshold_n=threshold_n)

def handle_list_pending(req: Dict[str, Any]) -> Dict[str, Any]:
    from logic_scratch.item_analytics import list_pending_suggestions_for_teacher
    teacher_id = req.get("teacher_id", "")
    pending = list_pending_suggestions_for_teacher(teacher_id)
    return {"ok": True, "teacher_id": teacher_id, "pending": pending}

def handle_approve(req: Dict[str, Any]) -> Dict[str, Any]:
    from logic_scratch.item_analytics import approve_difficulty_suggestion
    teacher_id = req.get("teacher_id", "")
    assessment_id = req.get("assessment_id", "")
    return approve_difficulty_suggestion(teacher_id, assessment_id)

def handle_reject(req: Dict[str, Any]) -> Dict[str, Any]:
    from logic_scratch.item_analytics import reject_difficulty_suggestion
    teacher_id = req.get("teacher_id", "")
    assessment_id = req.get("assessment_id", "")
    note = req.get("note", "")
    return reject_difficulty_suggestion(teacher_id, assessment_id, note=note)

def process(req: Dict[str, Any]) -> Dict[str, Any]:
    if not _auth_check(req):
        return {"ok": False, "error": "unauthorized"}

    action = req.get("action", "run")
    if action == "run":
        return handle_run(req)
    if action == "mas_kcl_trigger":
        return handle_mas_kcl_trigger(req)
    if action == "list_pending":
        return handle_list_pending(req)
    if action == "approve_difficulty":
        return handle_approve(req)
    if action == "reject_difficulty":
        return handle_reject(req)

    return {"ok": False, "error": f"unknown_action:{action}"}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--stdin", action="store_true", help="Read JSON request from STDIN (recommended)")
    parser.add_argument("--request_file", type=str, default="", help="Read JSON request from file (debug)")
    args = parser.parse_args()

    try:
        if args.request_file:
            with open(args.request_file, "r", encoding="utf-8") as f:
                req = json.load(f)
        else:
            req = _read_stdin_json()

        res = process(req)
        _write_stdout_json(res)
    except Exception as e:
        _write_stdout_json({"ok": False, "error": "exception", "message": str(e)})

if __name__ == "__main__":
    main()
