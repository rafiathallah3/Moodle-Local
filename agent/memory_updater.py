from logic_scratch.utils import append_log, add_agent_called
from logic_scratch.graders import grade_router

def _update_mastery(old: dict, kc_targets: list, score: float):
    new = dict(old or {})
    for kc in (kc_targets or []):
        cur = float(new.get(kc, 0.5))
        if score >= 0.85:
            cur = min(1.0, cur + 0.08)
        elif score >= 0.6:
            cur = min(1.0, cur + 0.03)
        else:
            cur = max(0.0, cur - 0.05)
        new[kc] = round(cur, 2)
    return new

class MemoryUpdater:
    """
    Keep memory updates for BOTH:
    - submission_review: use analyzer grading_score
    - student_chat: compute draft_score from answer_snapshot (deterministic), then update mastery lightly
    """
    def run(self, state: dict) -> dict:
        if state.get("role") != "student":
            add_agent_called(state, "memory_updater", "SKIP", "teacher")
            return state

        profile = state.get("student_profile", {}) or {}
        mastery_old = profile.get("mastery_by_kc") if isinstance(profile.get("mastery_by_kc"), dict) else {}
        misconceptions_old = profile.get("misconceptions") if isinstance(profile.get("misconceptions"), list) else []
        prefs_new = (profile.get("preferences") or {"language": state.get("preferred_language","id")})
        integrity_new = state.get("integrity", {"hint_only":True,"no_full_solution":True,"exam_mode":False})

        kc_targets = state.get("kc_targets", []) or []
        mode = state.get("mode","")

        # Decide score source
        score_source = "none"
        score = None

        if mode == "submission_review":
            score = float(state.get("grading_score", 0.0) or 0.0)
            score_source = "submission"
        elif mode == "student_chat":
            # compute from student's draft attempt (answer_snapshot) if available
            draft = (state.get("answer_snapshot","") or "").strip()
            if draft:
                g = grade_router(
                    course_domain=state.get("course_domain","General"),
                    assessment_type=state.get("assessment_type",""),
                    grading_spec=state.get("grading_spec", {}),
                    student_ans=draft,
                    answer_key=state.get("answer_key",""),
                    task_prompt=state.get("task_prompt","")
                )
                score = float(g.get("grading_score", 0.0) or 0.0)
                score_source = "draft_attempt"
            else:
                # no draft => do not change mastery (still allowed to log session)
                score = None
                score_source = "chat_no_draft"

        # Update mastery only if we have a score signal
        mastery_new = mastery_old
        if score is not None:
            mastery_new = _update_mastery(mastery_old, kc_targets, score)

        misconceptions_new = list(dict.fromkeys(
            misconceptions_old + (state.get("weak_concepts") or []) + (state.get("prereq_suspects") or [])
        ))

        state["memory_updates"] = {
          "student_model_update":{
            "level": state.get("student_level","unknown"),
            "mastery_by_kc_new": mastery_new,
            "misconceptions_new": misconceptions_new,
            "preferences_new": prefs_new,
            "integrity_new": integrity_new
          },
          "session_log_update":{
            "summary": f"mode={mode}, score_source={score_source}, score={score}, kc={kc_targets}, weak={state.get('weak_concepts')}",
            "tags": kc_targets + (state.get("weak_concepts") or []) + (state.get("prereq_suspects") or []),
            "last_next_steps": [state.get("recommended_next_action","")],
            "student_visible_outcome": state.get("final_response") or state.get("generated_response","")
          }
        }

        add_agent_called(state, "memory_updater", "OK", f"kc={kc_targets}, source={score_source}")
        append_log(state, "memory_updater", "memory_update", "Prepared memory_updates", {
            "kc_targets": kc_targets, "score_source": score_source, "score": score
        })
        return state
