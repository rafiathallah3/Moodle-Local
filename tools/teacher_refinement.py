
"""
Teacher Refinement Agent dengan Bloom's Taxonomy
"""
from typing import Dict, Any, List
from logic_scratch.llm_factory import LLMFactory


class BloomTaxonomy:
    LEVELS = {
        "remember": {"level": 1, "verbs": ["define", "list", "name", "identify", "recall"]},
        "understand": {"level": 2, "verbs": ["explain", "describe", "summarize", "classify", "compare"]},
        "apply": {"level": 3, "verbs": ["implement", "use", "execute", "apply", "demonstrate"]},
        "analyze": {"level": 4, "verbs": ["analyze", "differentiate", "organize", "compare", "contrast"]},
        "evaluate": {"level": 5, "verbs": ["evaluate", "check", "critique", "judge", "test"]},
        "create": {"level": 6, "verbs": ["design", "construct", "develop", "formulate", "create"]}
    }
    
    @classmethod
    def classify_question(cls, question: str) -> str:
        question_lower = question.lower()
        for level, data in cls.LEVELS.items():
            if any(verb in question_lower for verb in data["verbs"]):
                return level
        return "apply"
    
    @classmethod
    def get_level_number(cls, level_name: str) -> int:
        return cls.LEVELS.get(level_name.lower(), {}).get("level", 3)


class TeacherRefinementAgent:
    def __init__(self, llm_config=None):
        self.llm = LLMFactory(llm_config)
        self.bloom = BloomTaxonomy()
    
    def refine_problem(self, original_problem, teacher_feedback, difficulty_target="medium", bloom_target="apply"):
        current_bloom = self.bloom.classify_question(original_problem)
        target_level = self.bloom.get_level_number(bloom_target)
        current_level = self.bloom.get_level_number(current_bloom)
        
        return {
            "refined_problem": original_problem,
            "bloom_level": {
                "target": bloom_target,
                "target_number": target_level,
                "original": current_bloom,
                "original_number": current_level
            },
            "test_cases": [],
            "hints": [],
            "misconceptions": [],
            "solution_approach": "",
            "original": original_problem,
            "feedback_incorporated": teacher_feedback,
            "difficulty": difficulty_target,
            "status": "refined_with_bloom"
        }
    
    def validate_pedagogy(self, content, target_kc, target_bloom="apply"):
        actual_bloom = self.bloom.classify_question(content)
        actual_level = self.bloom.get_level_number(actual_bloom)
        target_level = self.bloom.get_level_number(target_bloom)
        alignment_score = 1.0 - (abs(actual_level - target_level) / 6.0)
        
        return {
            "valid": alignment_score >= 0.7,
            "target_kc": target_kc,
            "target_bloom": target_bloom,
            "actual_bloom": actual_bloom,
            "alignment_score": round(alignment_score, 2),
            "suggestions": []
        }
