import re
from logic_scratch.utils import append_log, add_agent_called, normalize_whitespace
from logic_scratch.llm import LLMClient
from logic_scratch.schemas import GeneratorOut
from logic_scratch.policies import is_soft_greeting, is_off_topic
from logic_scratch.item_analytics import (
    list_pending_suggestions_for_teacher,
    approve_difficulty_suggestion,
    reject_difficulty_suggestion
)

SYSTEM_ID = """Anda adalah Lesson Generator Agent.
Rules:
- Student_chat: hanya skeleton/template, bukan solusi lengkap.
- Submission_review: feedback + langkah perbaikan, jangan full solution untuk siswa.
- Teacher_chat: boleh full answer key + rubrik + variasi jawaban untuk course yang dikelola.
Return JSON: { "response_mode":"...", "generated_response":"..." }"""

SYSTEM_EN = """You are the Lesson Generator Agent.
Rules:
- Student_chat: skeleton/template only, not full solutions.
- Submission_review: feedback + next steps, avoid full solution for students.
- Teacher_chat: full answer key + rubric + variants allowed for managed courses.
Return JSON: { "response_mode":"...", "generated_response":"..." }"""

def _extract_assessment_id(text: str) -> str:
    # Find tokens like ASM-XXX-YYY
    m = re.search(r"\bASM-[A-Za-z0-9\-]+\b", text)
    return m.group(0) if m else ""

class LessonGenerator:
    def __init__(self, llm: LLMClient):
        self.llm = llm

    def _student_skeleton(self, lang: str):
        if lang == "en":
            return (
              "I will provide a skeleton (template), not a full final solution.\n\n"
              "Try this structure:\n"
              "1) Restate the goal.\n"
              "2) Write a minimal draft attempt.\n"
              "3) Identify missing concept(s).\n"
              "4) Make one small change and test.\n\n"
              "Send your updated draft and tell me where you got stuck."
            )
        return (
          "Saya akan memberi skeleton (kerangka), bukan jawaban final.\n\n"
          "Coba struktur ini:\n"
          "1) Tulis ulang tujuan soal.\n"
          "2) Buat draft attempt minimal.\n"
          "3) Tandai konsep yang masih hilang.\n"
          "4) Tambahkan satu perubahan kecil lalu uji.\n\n"
          "Kirim draft revisimu dan sebut bagian yang masih membingungkan."
        )

    def _student_review_feedback(self, state: dict):
        lang = state.get("preferred_language","id")
        score = state.get("grading_score", 0.0)
        rubric = state.get("rubric_label","-")
        diag = state.get("diagnosis_detail","-")
        weak = state.get("weak_concepts", [])
        next_step = state.get("recommended_next_action","-")
        prereq = state.get("prereq_suspects", [])

        if lang == "en":
            return (
              f"Submission review:\n- Score: {score}\n- Label: {rubric}\n"
              f"- Diagnosis: {diag}\n- Weak points: {', '.join(weak) if weak else '-'}\n"
              f"- Possible prerequisite gaps: {', '.join(prereq) if prereq else '-'}\n\n"
              f"Next step:\n{next_step}"
            )
        return (
          f"Review submission:\n- Skor: {score}\n- Label: {rubric}\n"
          f"- Diagnosis: {diag}\n- Poin lemah: {', '.join(weak) if weak else '-'}\n"
          f"- Dugaan prasyarat lemah: {', '.join(prereq) if prereq else '-'}\n\n"
          f"Langkah berikutnya:\n{next_step}"
        )

    # ---------- TEACHER: analytics + approval ----------
    def _teacher_pending_summary(self, teacher_id: str, lang: str):
        pending = list_pending_suggestions_for_teacher(teacher_id)
        if not pending:
            return ("No pending difficulty suggestions right now."
                    if lang == "en"
                    else "Tidak ada saran perubahan tingkat kesulitan (pending) saat ini.")

        lines = []
        if lang == "en":
            lines.append("Pending difficulty suggestions (auto-adjust, requires approval):")
        else:
            lines.append("Saran perubahan tingkat kesulitan (auto-adjust, butuh persetujuan):")

        for p in pending[:20]:
            stats = p.get("stats", {})
            lines.append(
                f"- {p['assessment_id']} | {p['course_name']} | {p['title']} | "
                f"current={p['current_difficulty']} → suggested={p['suggested_difficulty']} | "
                f"attempts={stats.get('attempts')} success_rate={stats.get('success_rate')}"
            )

        if lang == "en":
            lines.append("\nTo approve: 'approve difficulty ASM-XXX'  | To reject: 'reject difficulty ASM-XXX'")
        else:
            lines.append("\nUntuk approve: 'setujui difficulty ASM-XXX' | Untuk reject: 'tolak difficulty ASM-XXX'")

        return "\n".join(lines)

    def _teacher_handle_approve_reject(self, state: dict):
        lang = state.get("preferred_language","id")
        teacher_id = state.get("teacher_id","")
        text = normalize_whitespace(state.get("normalized_input",""))
        aid = _extract_assessment_id(text)

        if not aid:
            return ("Sertakan assessment_id seperti 'ASM-...'."
                    if lang != "en" else "Please include an assessment_id like 'ASM-...'.")

        if state.get("detected_intent") == "teacher_approve_difficulty":
            res = approve_difficulty_suggestion(teacher_id, aid)
            if res.get("ok"):
                return (f"Disetujui. Difficulty {aid} diubah menjadi '{res.get('new_difficulty')}'."
                        if lang != "en" else f"Approved. Difficulty for {aid} updated to '{res.get('new_difficulty')}'.")
            return (f"Gagal approve: {res.get('reason')}"
                    if lang != "en" else f"Approve failed: {res.get('reason')}")

        if state.get("detected_intent") == "teacher_reject_difficulty":
            res = reject_difficulty_suggestion(teacher_id, aid)
            if res.get("ok"):
                return (f"Ditolak. Saran difficulty untuk {aid} ditandai rejected."
                        if lang != "en" else f"Rejected. Difficulty suggestion for {aid} marked as rejected.")
            return (f"Gagal reject: {res.get('reason')}"
                    if lang != "en" else f"Reject failed: {res.get('reason')}")

        return self._teacher_pending_summary(teacher_id, lang)

    def _teacher_fallback(self, state: dict):
        lang = state.get("preferred_language","id")
        intent = state.get("detected_intent","general_question")
        teacher_id = state.get("teacher_id","")

        if intent in {"teacher_review_item_analytics", "teacher_review_progress"}:
            # Include pending suggestions summary
            return self._teacher_pending_summary(teacher_id, lang)

        if intent in {"teacher_approve_difficulty", "teacher_reject_difficulty"}:
            return self._teacher_handle_approve_reject(state)

        # Other intents keep minimal fallback
        if lang == "en":
            return (
              f"Teacher mode ({intent}).\n\n"
              "You can ask for:\n- progress summary\n- item difficulty analytics\n- quiz/exercise drafts\n- answer key + rubric\n"
            )
        return (
          f"Mode dosen ({intent}).\n\n"
          "Anda bisa meminta:\n- ringkasan progres\n- analitik tingkat kesulitan soal\n- draft quiz/exercise\n- kunci jawaban + rubrik\n"
        )

    def run(self, state: dict) -> dict:
        lang = state.get("preferred_language","id")
        role = state.get("role","student")
        mode = state.get("mode","student_chat")
        text = normalize_whitespace(state.get("normalized_input",""))

        revise_constraint = ""
        if state.get("critic_verdict") == "REVISE" and state.get("critic_reason"):
            revise_constraint = f"\n\n[REVISION CONSTRAINT]\nFix: {state.get('reason_code')} - {state.get('critic_reason')}\n"

        system = SYSTEM_EN if lang=="en" else SYSTEM_ID

        if role == "student":
            if mode == "student_chat":
                if is_soft_greeting(text):
                    fallback = ("Hello! Send a draft attempt; I will provide a skeleton."
                                if lang=="en" else "Halo! Kirim draft attempt dulu; saya bantu dengan skeleton.")
                elif is_off_topic(text):
                    fallback = ("Let’s focus on your current task:\n" + (state.get("task_prompt","-"))
                                if lang=="en" else "Kita fokus ke task aktif:\n" + (state.get("task_prompt","-")))
                else:
                    fallback = self._student_skeleton(lang)

                prompt = f"""Role: student
Mode: student_chat
Task: {state.get('task_prompt')}
Student draft: {state.get('answer_snapshot','')}
Student message: {text}
You MUST provide skeleton/template only, not full solution.{revise_constraint}
Return strict JSON only."""
                out = self.llm.generate_json(
                    state, "lesson_generator", system, prompt, GeneratorOut,
                    {"response_mode":"student_chat_skeleton","generated_response": fallback}
                )
                state["response_mode"] = out.get("response_mode","student_chat_skeleton")
                state["generated_response"] = out.get("generated_response", fallback)
            else:
                fallback = self._student_review_feedback(state)
                prompt = f"""Role: student
Mode: submission_review
Task: {state.get('task_prompt')}
Score/label: {state.get('grading_score')} / {state.get('rubric_label')}
Diagnosis: {state.get('diagnosis_detail')}
Weak concepts: {state.get('weak_concepts')}
Prereq suspects: {state.get('prereq_suspects')}
Provide feedback and next steps; avoid giving full final solution.{revise_constraint}
Return strict JSON only."""
                out = self.llm.generate_json(
                    state, "lesson_generator", system, prompt, GeneratorOut,
                    {"response_mode":"student_review_feedback","generated_response": fallback}
                )
                state["response_mode"] = out.get("response_mode","student_review_feedback")
                state["generated_response"] = out.get("generated_response", fallback)

        else:
            # Teacher path: deterministic analytics & approval should work even with LLM off
            fallback = self._teacher_fallback(state)

            # You can still allow LLM polish if enabled, but fallback is already correct
            prompt = f"""Role: teacher
Mode: teacher_chat
Intent: {state.get('detected_intent')}
Managed courses: {state.get('managed_courses')}
Message: {text}
If the intent is item analytics or approval, respond with structured actionable info.{revise_constraint}
Return strict JSON only."""
            out = self.llm.generate_json(
                state, "lesson_generator", system, prompt, GeneratorOut,
                {"response_mode":"teacher_admin","generated_response": fallback}
            )
            state["response_mode"] = out.get("response_mode","teacher_admin")
            state["generated_response"] = out.get("generated_response", fallback)

        add_agent_called(state, "lesson_generator", "OK", state.get("response_mode",""))
        append_log(state, "lesson_generator", "generation", "Response generated", {"response_mode": state.get("response_mode")})
        return state
