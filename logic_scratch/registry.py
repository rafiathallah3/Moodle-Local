
"""
Course Registry - Dynamic Course Configuration & Data Access
"""
from typing import Dict, Any, Optional
from logic_scratch.schemas import CourseConfig, KCGraph, StudentModel

class CourseRegistry:
    """Registry dinamis untuk manage multiple courses"""
    
    def __init__(self):
        self.courses: Dict[str, CourseConfig] = {}
        self.kc_graphs: Dict[str, KCGraph] = {}
        self.students: Dict[str, StudentModel] = {}  # key: "{user_id}:{course_id}"
        
        # Load default courses
        self._init_default_courses()
    
    def _init_default_courses(self):
        """Inisialisasi course default (CS101, Math101, etc)"""
        # Course: Intro to Programming
        self.register_course(CourseConfig(
            course_id="CS101",
            course_name="Introduction to Programming",
            kc_set=["variables", "loops", "conditionals", "functions", "recursion"],
            difficulty_baseline={"variables": 0.3, "loops": 0.6, "conditionals": 0.4, 
                               "functions": 0.7, "recursion": 0.8},
            cff_enabled=True,
            cff_type="on_demand"
        ))
        
        # Course: Database Systems
        self.register_course(CourseConfig(
            course_id="CS202",
            course_name="Database Systems",
            kc_set=["sql_basics", "joins", "normalization", "indexing", "transactions"],
            difficulty_baseline={"sql_basics": 0.3, "joins": 0.7, "normalization": 0.6,
                               "indexing": 0.8, "transactions": 0.7},
            cff_enabled=True,
            cff_type="wait_delay",
            wait_seconds=20
        ))
    
    def register_course(self, config: CourseConfig):
        """Register course baru secara dinamis"""
        self.courses[config.course_id] = config
        # Init empty KC graph untuk course ini
        self.kc_graphs[config.course_id] = KCGraph(
            course_id=config.course_id,
            kcs=config.kc_set,
            difficulty_map=config.difficulty_baseline
        )
    
    def get_course_config(self, course_id: str) -> Optional[CourseConfig]:
        return self.courses.get(course_id)
    
    def get_kc_graph(self, course_id: str) -> KCGraph:
        return self.kc_graphs.get(course_id, KCGraph(course_id=course_id, kcs=[]))
    
    def update_kc_graph(self, course_id: str, graph: KCGraph):
        self.kc_graphs[course_id] = graph
    
    def get_student_model(self, user_id: str, course_id: str) -> StudentModel:
        key = f"{user_id}:{course_id}"
        if key not in self.students:
            self.students[key] = StudentModel(
                user_id=user_id,
                course_id=course_id,
                mastery={kc: 0.5 for kc in self.get_course_config(course_id).kc_set} 
                        if self.get_course_config(course_id) else {}
            )
        return self.students[key]
    
    def update_student_model(self, user_id: str, course_id: str, model: StudentModel):
        key = f"{user_id}:{course_id}"
        self.students[key] = model
    
    def list_courses(self) -> list:
        return list(self.courses.keys())

# Global instance
registry = CourseRegistry()
