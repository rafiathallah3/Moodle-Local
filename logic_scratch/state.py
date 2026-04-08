from typing import TypedDict, List, Dict, Any

class AgenticState(TypedDict, total=False):
    # IDs
    run_id: str
    session_id: str
    timestamp: str

    # Identity & mode
    role: str              # student/teacher
    mode: str              # submission_review/student_chat/teacher_chat (or moodle submit_answer)
    preferred_language: str  # id/en
    user_id: str

    # Student/Teacher context
    student_id: str
    student_name: str
    student_level: str
    nfc_profile: str
    student_profile: dict

    teacher_id: str
    teacher_name: str
    managed_courses: list

    # Course context
    course_id: str
    course_name: str
    course_domain: str
    course_teacher_id: str
    kc_set: list

    # Assessment context
    assessment_id: str
    assessment_title: str
    assessment_type: str
    difficulty: str
    task_prompt: str
    answer_key: str
    expected_concepts: list
    kc_targets: list
    grading_spec: dict

    # Policy/permissions
    agent_enabled: bool
    attempt_policy: str
    review_allowed: bool
    chat_allowed: bool
    integrity: dict

    # Input
    input_type: str
    raw_input: str
    normalized_input: str
    answer_snapshot: str
    detected_intent: str

    # Routing
    policy_decision: str
    route_target: str

    # Analysis
    grading_score: float
    rubric_label: str
    qualitative_band: str
    diagnosis_tag: str
    diagnosis_detail: str
    weak_concepts: list
    priority_level: str
    recommended_next_action: str
    prereq_suspects: list

    # Generation & critic
    generated_response: str
    response_mode: str
    critic_verdict: str
    reason_code: str
    critic_reason: str

    # Revision controls
    revision_count: int
    revision_cap: int

    # Memory updates (Moodle-compatible)
    memory_updates: dict

    # Output packaging
    final_response: str
    final_payload: dict

    # Audit
    agents_called: list
    risk_flags: list
    logs: List[Dict[str, Any]]
