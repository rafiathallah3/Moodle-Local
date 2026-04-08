from logic_scratch.utils import append_log, add_agent_called

def response_formatter(state: dict) -> dict:
    # if already has final_response (blocked earlier), keep it
    if state.get("final_response"):
        add_agent_called(state, "response_formatter", "OK", "used_existing_final")
        append_log(state, "response_formatter", "formatting", "Used existing final_response")
    else:
        gen = (state.get("generated_response") or "").strip()
        if not gen:
            gen = ("Tidak ada respon." if state.get("preferred_language")=="id" else "No response.")
        state["final_response"] = gen
        add_agent_called(state, "response_formatter", "OK", "assembled_final")
        append_log(state, "response_formatter", "formatting", "Assembled final_response", {"len": len(gen)})

    # Moodle-friendly payload
    state["final_payload"] = {
      "run_id": state.get("run_id"),
      "mode": state.get("mode"),
      "policy": {
        "policy_decision": state.get("policy_decision"),
        "attempt_policy": state.get("attempt_policy"),
        "integrity": state.get("integrity", {})
      },
      "routing": {
        "route_target": state.get("route_target"),
        "detected_intent": state.get("detected_intent")
      },
      "agents_called": state.get("agents_called", []),
      "final_payload": {
        "student_visible_outcome": state.get("final_response"),
        "grading_score": state.get("grading_score"),
        "rubric_label": state.get("rubric_label"),
        "diagnosis_tag": state.get("diagnosis_tag"),
        "weak_concepts": state.get("weak_concepts"),
        "prereq_suspects": state.get("prereq_suspects", [])
      },
      "memory_updates": state.get("memory_updates", {}),
      "risk_flags": state.get("risk_flags", []),
      "logs": (state.get("logs", []) or [])[:200]
    }
    return state
