from logic_scratch.utils import load_json, save_json

def add_kc_if_missing(kc_id: str, name: str, description: str, domain_tags: list):
    reg = load_json("kc_registry.json")
    if kc_id not in reg:
        reg[kc_id] = {"kc_id": kc_id, "name": name, "description": description, "domain_tags": domain_tags or []}
        save_json("kc_registry.json", reg)
        return True
    return False

def get_course(course_id: str):
    courses = load_json("courses.json")
    return courses.get(course_id, {})

def get_assessment(assessment_id: str):
    assessments = load_json("assessments.json")
    return assessments.get(assessment_id, {})

def get_teacher(teacher_id: str):
    teachers = load_json("teachers.json")
    return teachers.get(teacher_id, {})

def get_student(student_id: str):
    students = load_json("students.json")
    return students.get(student_id, {})
