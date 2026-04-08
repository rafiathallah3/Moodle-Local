from logic_scratch.utils import now_iso, gen_id, normalize_whitespace, append_log, add_agent_called
from logic_scratch.policies import (
    validate_user_input, detect_prompt_injection, compute_permissions,
    get_attempt_policy, student_chat_allowed, is_soft_greeting, is_off_topic
)
from logic_scratch.llm import LLMClient
from logic_scratch.schemas import IntentOut
from logic_scratch.registry import get_course, get_assessment, get_student, get_teacher

SYSTEM_ID = """Anda adalah Orchestrator Agent pada LMS. Klasifikasikan intent user secara aman.
Daftar intent:
- submit_answer
- ask_hint
- request_exercise
- teacher_generate_quiz
- teacher_generate_exercise
- teacher_generate_answer_key
- teacher_review_progress
- teacher_review_item_analytics
- teacher_approve_difficulty
- teacher_reject_difficulty
- off_topic_question
- general_question
Return JSON: { "intent": "...", "reason":"...", "confidence": 0.0 }"""

SYSTEM_EN = """You are the Orchestrator Agent for an LMS. Classify user intent safely.
Intents:
- submit_answer
- ask_hint
- request_exercise
- teacher_generate_quiz
- teacher_generate_exercise
- teacher_generate_answer_key
- teacher_review_progress
- teacher_review_item_analytics
- teacher_approve_difficulty
- teacher_reject_difficulty
- off_topic_question
- general_question
Return JSON: { "intent": "...", "reason":"...", "confidence": 0.0 }"""

def _teacher_intent_rule(text: str):
    t = normalize_whitespace(text).lower()

    # approve/reject difficulty suggestions
    if any(k in t for k in ["approve", "setujui", "acc", "terima"]) and any(k in t for k in ["difficulty", "kesulitan", "level soal"]):
        return {"intent":"teacher_approve_difficulty", "reason":"keyword:approve_difficulty", "confidence":0.8}
    if any(k in t for k in ["reject", "tolak"]) and any(k in t for k in ["difficulty", "kesulitan", "level soal"]):
        return {"intent":"teacher_reject_difficulty", "reason":"keyword:reject_difficulty", "confidence":0.8}

    # item analytics / difficulty monitoring
    if any(k in t for k in ["success rate", "tingkat keberhasilan", "tingkat kesulitan", "difficulty", "kesulitan soal", "soal terlalu sulit", "soal terlalu mudah"]):
        return {"intent":"teacher_review_item_analytics", "reason":"keyword:item_analytics", "confidence":0.75}

    # answer key / rubric / variants
    if any(k in t for k in ["kunci jawaban", "answer key", "rubrik", "rubric", "variasi", "variants", "skema penilaian"]):
        return {"intent":"teacher_generate_answer_key", "reason":"keyword:answer_key_rubric", "confidence":0.75}

    # quiz/exercise drafting
    if "quiz" in t or "kuis" in t:
        return {"intent":"teacher_generate_quiz", "reason":"keyword:quiz", "confidence":0.7}
    if any(k in t for k in ["exercise", "latihan", "tugas", "praktik"]):
        return {"intent":"teacher_generate_exercise", "reason":"keyword:exercise", "confidence":0.65}

    # progress monitoring
    if any(k in t for k in ["progres", "progress", "siapa yang belum", "nilai rendah", "ringkasan kelas", "summary kelas"]):
        return {"intent":"teacher_review_progress", "reason":"keyword:progress", "confidence":0.7}

    return None

class Orchestrator:
    def __init__(self, llm: LLMClient):
        self.llm = llm

    def _resolve_language(self, state: dict):
        lang = state.get("preferred_language")
        if lang in {"id","en"}:
            return lang
        sp = (state.get("student_profile") or {})
        lang2 = ((sp.get("preferences") or {}).get("language"))
        return lang2 if lang2 in {"id","en"} else "id"

    def _enrich_student_context(self, state: dict):
        user_id = state.get("user_id","UNKNOWN")
        s = get_student(user_id)

        course_id = state.get("course_id") or state.get("selected_course_id") or state.get("active_course")
        assessment_id = state.get("assessment_id") or state.get("selected_assessment_id") or state.get("active_assessment")
        c = get_course(course_id) if course_id else {}
        a = get_assessment(assessment_id) if assessment_id else {}

        integrity = state.get("integrity") or s.get("integrity") or {"hint_only":True,"no_full_solution":True,"exam_mode":False}
        perms = compute_permissions(a.get("assessment_type",""), a.get("agent_enabled",False))
        chat_ok = student_chat_allowed(a.get("assessment_type",""), a.get("agent_enabled",False), integrity)

        state.update({
            "student_id": user_id,
            "student_name": state.get("student_name") or s.get("name","Unknown Student"),
            "student_level": state.get("student_level") or s.get("level","unknown"),
            "nfc_profile": state.get("nfc_profile") or s.get("nfc_profile","medium"),
            "student_profile": state.get("student_profile") or {
                "mastery_by_kc": s.get("mastery_by_kc", {}),
                "misconceptions": [],
                "preferences": s.get("preferences", {"language": state.get("preferred_language","id")}),
                "integrity": integrity
            },
            "integrity": integrity,

            "course_id": course_id,
            "course_name": state.get("course_name") or c.get("course_name",""),
            "course_domain": state.get("course_domain") or c.get("domain","General"),
            "course_teacher_id": c.get("teacher_id",""),
            "kc_set": c.get("kc_set", []),

            "assessment_id": assessment_id,
            "assessment_title": state.get("assessment_title") or a.get("title",""),
            "assessment_type": state.get("assessment_type") or a.get("assessment_type",""),
            "difficulty": a.get("difficulty","unknown"),
            "agent_enabled": a.get("agent_enabled", False),
            "task_prompt": state.get("task_prompt") or a.get("task_prompt",""),
            "answer_key": state.get("answer_key") or a.get("answer_key",""),
            "expected_concepts": state.get("expected_concepts") or a.get("expected_concepts", []),
            "kc_targets": state.get("kc_targets") or a.get("kc_targets", []),
            "grading_spec": state.get("grading_spec") or a.get("grading_spec", {}),

            "review_allowed": perms["review_allowed"],
            "chat_allowed": chat_ok,
            "attempt_policy": get_attempt_policy(course_id) if course_id else "strict"
        })
        return state

    def _enrich_teacher_context(self, state: dict):
        user_id = state.get("user_id","UNKNOWN")
        t = get_teacher(user_id)
        state.update({
            "teacher_id": user_id,
            "teacher_name": state.get("teacher_name") or t.get("name","Unknown Teacher"),
            "managed_courses": state.get("managed_courses") or t.get("managed_courses", [])
        })
        return state

    def _intent_fallback(self, state: dict, text: str):
        role = state.get("role","student")
        mode = state.get("mode","")
        if role == "teacher":
            if is_soft_greeting(text):
                return {"intent":"general_question","reason":"teacher_greeting","confidence":0.6}
            rule = _teacher_intent_rule(text)
            if rule:
                return rule
            return {"intent":"teacher_review_progress","reason":"teacher_default", "confidence":0.5}

        if mode in {"submission_review","submit_answer"}:
            return {"intent":"submit_answer","reason":"mode_default","confidence":0.7}
        if is_soft_greeting(text):
            return {"intent":"general_question","reason":"greeting","confidence":0.7}
        if is_off_topic(text):
            return {"intent":"off_topic_question","reason":"off_topic","confidence":0.7}
        return {"intent":"ask_hint","reason":"student_default","confidence":0.4}

    def run(self, state: dict) -> dict:
        state.setdefault("run_id", gen_id("RUN"))
        state.setdefault("session_id", gen_id("SES"))
        state.setdefault("timestamp", now_iso())
        state.setdefault("logs", [])
        state.setdefault("agents_called", [])
        state.setdefault("risk_flags", [])
        state.setdefault("revision_count", 0)

        state["preferred_language"] = self._resolve_language(state)

        raw = state.get("raw_input","")
        clean = normalize_whitespace(raw)
        state["normalized_input"] = clean
        append_log(state, "orchestrator", "input_received", "Input received", {"len": len(clean), "input_type": state.get("input_type","text")})

        v = validate_user_input(clean)
        if not v["ok"]:
            state["policy_decision"] = "INVALID_INPUT"
            state["route_target"] = "end"
            state["final_response"] = ("Input Anda tidak valid." if state["preferred_language"]=="id" else "Your input is invalid.")
            add_agent_called(state, "orchestrator", "OK", "invalid_input")
            append_log(state, "orchestrator", "policy_block", "Invalid input blocked", v)
            return state

        if detect_prompt_injection(clean):
            state["policy_decision"] = "PROMPT_INJECTION_BLOCKED"
            state["route_target"] = "end"
            state["final_response"] = ("Permintaan terindikasi melanggar aturan sistem." if state["preferred_language"]=="id"
                                       else "Request appears to violate system rules.")
            state["risk_flags"].append("prompt_injection")
            add_agent_called(state, "orchestrator", "OK", "prompt_injection_blocked")
            append_log(state, "orchestrator", "policy_block", "Prompt injection blocked")
            return state

        # enrich context
        if state.get("role","student") == "student":
            if state.get("mode") == "submit_answer":
                state["mode"] = "submission_review"
            self._enrich_student_context(state)
            state["revision_cap"] = 2
        else:
            self._enrich_teacher_context(state)
            state["revision_cap"] = 3

        system = SYSTEM_EN if state["preferred_language"]=="en" else SYSTEM_ID
        prompt = f"""Role: {state.get('role')}
Mode: {state.get('mode')}
Course: {state.get('course_name','')}
Assessment type: {state.get('assessment_type','')}
Task: {state.get('task_prompt','')}
Message: {clean}
Return strict JSON only."""

        fallback = self._intent_fallback(state, clean)
        intent_obj = self.llm.generate_json(state, "orchestrator", system, prompt, IntentOut, fallback)
        state["detected_intent"] = intent_obj.get("intent", fallback["intent"])

        # routing
        if state.get("role") == "student":
            if state.get("mode") == "submission_review":
                state["policy_decision"] = "SUBMISSION_REVIEW_ALLOWED"
                state["route_target"] = "analyzer"
                add_agent_called(state, "orchestrator", "OK", "route:analyzer")
                append_log(state, "orchestrator", "routing", "Routed to analyzer", {"intent": state["detected_intent"]})
                return state

            if state.get("mode") == "student_chat":
                if not state.get("chat_allowed", False):
                    state["policy_decision"] = "CHAT_BLOCKED"
                    state["route_target"] = "end"
                    state["final_response"] = ("Chat AI dinonaktifkan untuk assessment ini." if state["preferred_language"]=="id"
                                               else "AI chat is disabled for this assessment.")
                    add_agent_called(state, "orchestrator", "OK", "chat_blocked")
                    append_log(state, "orchestrator", "policy_block", "Chat blocked", {"assessment_type": state.get("assessment_type")})
                    return state

                if state.get("attempt_policy") == "strict":
                    snap = (state.get("answer_snapshot","") or "").strip()
                    if len(snap) < 3:
                        state["policy_decision"] = "ATTEMPT_FIRST_REQUIRED"
                        state["route_target"] = "end"
                        state["final_response"] = ("Kebijakan dosen: attempt-first. Kirim draft attempt dulu."
                                                   if state["preferred_language"]=="id"
                                                   else "Instructor policy: attempt-first. Send a draft attempt first.")
                        add_agent_called(state, "orchestrator", "OK", "attempt_first")
                        append_log(state, "orchestrator", "policy_block", "Attempt-first enforced")
                        return state

                state["policy_decision"] = "STUDENT_CHAT_ALLOWED"
                state["route_target"] = "lesson_generator"
                add_agent_called(state, "orchestrator", "OK", "route:lesson_generator")
                append_log(state, "orchestrator", "routing", "Routed to generator", {"intent": state["detected_intent"]})
                return state

        # teacher routes to generator (which will execute analytics/approval logic deterministically)
        state["policy_decision"] = "TEACHER_CHAT_ALLOWED"
        state["route_target"] = "lesson_generator"
        add_agent_called(state, "orchestrator", "OK", "route:lesson_generator")
        append_log(state, "orchestrator", "routing", "Teacher routed", {"intent": state["detected_intent"]})
        return state
