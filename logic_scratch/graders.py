import re
from difflib import SequenceMatcher
from logic_scratch.utils import normalize_whitespace, normalize_code, normalize_sql

def rubric_label(score: float):
    if score >= 0.90: return "correct"
    if score >= 0.75: return "almost_correct"
    if score >= 0.50: return "partially_correct"
    return "incorrect"

# --------- Generic similarity (domain-agnostic baseline) ----------
def similarity(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    return round(SequenceMatcher(None, a, b).ratio(), 2)

# --------- Code plugins (examples). Not required for other domains. ----------
def python_plugin_grade(student_ans: str, answer_key: str, task_prompt: str = "") -> float:
    s = normalize_code(student_ans)
    k = normalize_code(answer_key)
    if normalize_whitespace(s) == normalize_whitespace(k):
        return 1.0
    # heuristic structure
    score = 0.0
    sl = s.lower()
    if "for " in sl: score += 0.25
    if "range(" in sl: score += 0.25
    if "print" in sl: score += 0.20
    # boundary check if key expects range(1,6)
    kl = k.lower()
    if "range(1, 6)" in kl or "range(1,6)" in kl:
        if "range(1, 6)" in sl or "range(1,6)" in sl:
            score += 0.25
        elif "range(1, 5)" in sl or "range(1,5)" in sl:
            score = min(score, 0.65)
    return round(min(score, 1.0), 2)

def sql_plugin_grade(student_ans: str, answer_key: str) -> float:
    s = normalize_sql(student_ans)
    k = normalize_sql(answer_key)
    if s == k:
        return 1.0
    score = 0.0
    if "select" in s: score += 0.35
    if "from" in s: score += 0.35
    if re.search(r"from\s+[a-z_][a-z0-9_]*", s): score += 0.20
    if "select *" in s: score += 0.10
    return round(min(score, 1.0), 2)

# --------- Rubric scorer for non-code domains (Rich rubric: format B) ----------
def rubric_score(text: str, rubric: dict):
    """
    Deterministic rubric scorer:
    - checks indicator coverage + example presence
    - returns score 0..1, band, weak criteria
    """
    t = normalize_whitespace(text).lower()
    criteria = (rubric or {}).get("criteria", [])
    if not criteria:
        return {"score": 0.4, "band":"fair", "weak_criteria":["rubric_missing"], "notes":"No rubric criteria provided.", "evidence":{}}

    total_weight = sum(float(c.get("weight", 0.0)) for c in criteria) or 1.0
    achieved = 0.0
    weak = []
    evidence = {}
    for c in criteria:
        cid = c.get("criterion_id","?")
        weight = float(c.get("weight",0.0))
        indicators = [str(x).lower() for x in c.get("indicators",[])]
        hits = 0
        for ind in indicators:
            if ind and ind in t:
                hits += 1
        # example presence heuristic for criteria mentioning example
        wants_example = "example" in (c.get("name","").lower() + " " + c.get("description","").lower())
        has_example_marker = any(m in t for m in ["for example","e.g.","misalnya","contoh"])

        # criterion score
        ratio = hits / max(1, len(indicators))
        crit_score = 0.0
        if ratio >= 0.66:
            crit_score = 1.0
        elif ratio >= 0.34:
            crit_score = 0.6
        else:
            crit_score = 0.2

        if wants_example and not has_example_marker:
            crit_score = min(crit_score, 0.5)

        achieved += (weight/total_weight) * crit_score
        if crit_score < 0.6:
            weak.append(f"{cid}:{c.get('name','')}")
        evidence[cid] = {"indicator_hits": hits, "indicator_total": len(indicators), "example_marker": has_example_marker, "crit_score": round(crit_score,2)}

    score = round(min(max(achieved, 0.0), 1.0), 2)
    band = "good" if score>=0.8 else ("fair" if score>=0.55 else "weak")
    notes = "Rubric-based scoring computed deterministically."
    return {"score": score, "band": band, "weak_criteria": weak, "notes": notes, "evidence": evidence}

def grade_router(course_domain: str, assessment_type: str, grading_spec: dict, student_ans: str, answer_key: str, task_prompt: str = ""):
    """
    Multi-domain:
    - If rubric type => rubric_score
    - Else if code/sql plugins exist => use them
    - Else if answer_key exists => generic similarity baseline
    - Else => qualitative fallback
    """
    gtype = (grading_spec or {}).get("type","generic")

    if gtype == "rubric" or assessment_type in {"essay","exam"}:
        rubric = (grading_spec or {}).get("rubric", {})
        r = rubric_score(student_ans, rubric)
        return {"grading_score": r["score"], "rubric_label":"qualitative_review", "qualitative_band": r["band"],
                "rubric_detail": r, "domain":"rubric"}

    # code-like
    dom = (course_domain or "").lower()
    if "python" in dom:
        score = python_plugin_grade(student_ans, answer_key, task_prompt)
        return {"grading_score": score, "rubric_label": rubric_label(score), "qualitative_band": None, "rubric_detail": None, "domain":"python"}
    if "sql" in dom:
        score = sql_plugin_grade(student_ans, answer_key)
        return {"grading_score": score, "rubric_label": rubric_label(score), "qualitative_band": None, "rubric_detail": None, "domain":"sql"}

    # generic with answer_key
    if answer_key:
        s = normalize_whitespace(student_ans).lower()
        k = normalize_whitespace(answer_key).lower()
        score = similarity(s, k)
        return {"grading_score": score, "rubric_label": rubric_label(score), "qualitative_band": None, "rubric_detail": None, "domain":"generic_keyed"}

    # fallback non-keyed
    tokens = len(normalize_whitespace(student_ans).split())
    score = 0.35 if tokens<5 else (0.6 if tokens<12 else 0.8)
    band = "weak" if score<0.55 else ("fair" if score<0.8 else "good")
    return {"grading_score": round(score,2), "rubric_label":"qualitative_review", "qualitative_band": band, "rubric_detail": None, "domain":"generic_unkeyed"}
