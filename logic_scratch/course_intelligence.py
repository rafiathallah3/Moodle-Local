import re
from logic_scratch.utils import normalize_whitespace, normalize_code, normalize_sql

def _lang_pick(preferred_language: str, id_text: str, en_text: str) -> str:
    return en_text if preferred_language == "en" else id_text

def _detect_python_off_by_one(student_code: str, answer_key: str) -> bool:
    s = normalize_code(student_code).lower()
    k = normalize_code(answer_key).lower()
    # common case: key expects range(1,6) but student uses range(1,5)
    if ("range(1, 6)" in k or "range(1,6)" in k) and ("range(1, 5)" in s or "range(1,5)" in s):
        return True
    return False

def _concept_present(domain: str, concept_label: str, student_text: str) -> bool:
    """
    Concept detector: avoid naive substring matching for code.
    concept_label comes from expected_concepts list (teacher metadata).
    """
    dom = (domain or "").lower()
    c = (concept_label or "").lower()
    t = student_text or ""

    if "python" in dom:
        code = normalize_code(t)
        low = code.lower()
        if "for loop" in c or ("loop" in c and "for" in c):
            # require 'for' and 'in'
            return bool(re.search(r"\bfor\b.+\bin\b", low))
        if "range" in c:
            return "range(" in low
        if "boundary" in c or "batas" in c:
            # detect range with two args: range(a, b)
            return bool(re.search(r"range\s*\(\s*[^,]+,\s*[^)]+\)", low))
        if "print" in c or "output" in c:
            return "print(" in low
        if "variable" in c or "assignment" in c:
            return "=" in low
        # fallback for unknown concept label
        return c in low

    if "sql" in dom:
        sql = normalize_sql(t)
        if "select" in c:
            return "select" in sql
        if "from" in c:
            return "from" in sql
        if "table" in c or "reference" in c:
            return bool(re.search(r"from\s+[a-z_][a-z0-9_]*", sql))
        if "join" in c:
            return " join " in f" {sql} "
        return c in sql

    # non-code/general: keyword fallback
    low = normalize_whitespace(t).lower()
    return c in low

def infer_weak_concepts_generic(
    preferred_language: str,
    course_domain: str,
    assessment_type: str,
    expected_concepts: list,
    normalized_input: str,
    grading_score: float,
    rubric_detail=None,
    answer_key: str = ""
):
    """
    Returns bilingual diagnosis + next action.
    Fixes:
    - bilingual consistency
    - code concept detection (no false 'missing:for loop' on code)
    - add off-by-one detection for python loop boundary
    """
    text = normalized_input or ""
    dom = course_domain or "General"
    weak = []

    # 1) rubric weak criteria
    if rubric_detail and isinstance(rubric_detail, dict):
        wc = rubric_detail.get("weak_criteria") or []
        for x in wc:
            if x not in weak:
                weak.append(x)

    # 2) concept coverage check (domain-aware)
    for c in (expected_concepts or [])[:6]:
        if not _concept_present(dom, str(c), text):
            weak.append(f"missing:{c}")

    # 3) special detector for python off-by-one
    if "python" in (dom.lower()) and answer_key:
        if _detect_python_off_by_one(text, answer_key):
            if "off_by_one_range_end" not in weak:
                weak.append("off_by_one_range_end")

    # normalize unique
    weak = list(dict.fromkeys(weak))

    # 4) diagnosis + next action (bilingual)
    if assessment_type in {"essay", "exam"}:
        tokens = len(normalize_whitespace(text).split())
        if tokens < 10:
            if "depth_of_explanation" not in weak:
                weak.append("depth_of_explanation")

        diagnosis_tag = _lang_pick(preferred_language, "Tinjauan Kualitatif", "Qualitative Review")
        diagnosis_detail = _lang_pick(
            preferred_language,
            "Jawaban dinilai berbasis rubrik/kriteria: kejelasan definisi, perbandingan, dan contoh.",
            "Rubric/criteria-based review: definition clarity, comparison, and example quality."
        )
        next_action = _lang_pick(
            preferred_language,
            "Perbaiki definisi, tulis perbandingan lebih eksplisit, dan tambahkan contoh konkret.",
            "Improve definitions, make the comparison explicit, and add a concrete example."
        )
        return {
            "weak_concepts": weak or ["clarity", "structure"],
            "diagnosis_tag": diagnosis_tag,
            "diagnosis_detail": diagnosis_detail,
            "recommended_next_action": next_action
        }

    if grading_score >= 0.90:
        return {
            "weak_concepts": [],
            "diagnosis_tag": _lang_pick(preferred_language, "Benar", "Correct"),
            "diagnosis_detail": _lang_pick(preferred_language, "Jawaban sudah benar secara umum.", "Answer is correct overall."),
            "recommended_next_action": _lang_pick(
                preferred_language,
                "Lanjutkan ke tugas berikutnya, atau jelaskan singkat alasan/penalaranmu.",
                "Proceed to the next task, or briefly explain your reasoning."
            )
        }

    diagnosis_tag = _lang_pick(preferred_language, "Ketidaksesuaian Konsep", "Concept Misalignment")
    diagnosis_detail = _lang_pick(
        preferred_language,
        "Masih ada konsep target yang belum tepat atau belum lengkap.",
        "Some target concepts are missing or incorrect."
    )
    next_action = _lang_pick(
        preferred_language,
        "Perbaiki draft secara bertahap berdasarkan konsep yang masih lemah, lalu uji lagi.",
        "Revise your draft step-by-step based on the weak concepts, then test again."
    )
    return {
        "weak_concepts": weak or ["concept_alignment"],
        "diagnosis_tag": diagnosis_tag,
        "diagnosis_detail": diagnosis_detail,
        "recommended_next_action": next_action
    }
