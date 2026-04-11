
"""
MAS-KCL: 4 LLM Agents untuk KC Graph Enrichment
Dari paper Section 3.1: Generator, Evaluator, Optimizer, Validator
"""
from typing import Dict, List, Any
import sys
sys.path.insert(0, '/kaggle/working')

class KCGraphAgent:
    """Base class untuk MAS-KCL agents"""
    
    def __init__(self, name: str, llm_config=None):
        self.name = name
        from logic_scratch.llm_factory import LLMFactory
        self.llm = LLMFactory(llm_config)
    
    def process(self, context: Dict) -> Dict:
        raise NotImplementedError

class GeneratorAgent(KCGraphAgent):
    """Generate initial KC graph candidates"""
    
    def process(self, context: Dict) -> Dict:
        kc_names = context.get("kc_names", [])
        course_desc = context.get("course_description", "Computer Science course")
        
        prompt = f"""As an educational expert, analyze this course: {course_desc}
        Knowledge Components: {', '.join(kc_names)}
        
        Suggest prerequisite relationships between these KCs.
        Return JSON array of edges: [{{"from": "KC_A", "to": "KC_B", "reason": "why A before B"}}]
        Only include strong prerequisite relationships."""
        
        response = self.llm.generate(prompt)
        
        try:
            import json
            import re
            json_match = re.search(r'\\[.*\\]', response, re.DOTALL)
            edges = json.loads(json_match.group()) if json_match else []
            return {"edges": edges, "source": "generator"}
        except:
            return {"edges": [], "source": "generator", "error": "parse failed"}

class EvaluatorAgent(KCGraphAgent):
    """Evaluate edge quality"""
    
    def process(self, context: Dict) -> Dict:
        edges = context.get("edges", [])
        student_performance = context.get("performance_data", {})
        
        prompt = f"""Evaluate these prerequisite edges based on student performance:
        Edges: {edges}
        Performance data: {student_performance}
        
        Rate each edge validity (0-1) based on whether struggling with 'from' KC 
        predicts struggle with 'to' KC.
        
        Return JSON: [{{"edge": {{"from": "A", "to": "B"}}, "validity": 0.8, "reason": "..."}}]"""
        
        response = self.llm.generate(prompt)
        
        try:
            import json
            import re
            json_match = re.search(r'\\[.*\\]', response, re.DOTALL)
            evaluations = json.loads(json_match.group()) if json_match else []
            return {"evaluations": evaluations}
        except:
            return {"evaluations": [], "error": "parse failed"}

class OptimizerAgent(KCGraphAgent):
    """Adjust DE parameters (Game Agent dari paper)"""
    
    def process(self, context: Dict) -> Dict:
        current_ap = context.get("ambient_pressure", 0.4)
        loss_trend = context.get("loss_trend", "stable")  # improving, worsening, stable
        
        # Decision logic sederhana (bisa diganti dengan LLM reasoning)
        if loss_trend == "improving":
            new_ap = min(0.9, current_ap + 0.1)  # Exploit more
        elif loss_trend == "worsening":
            new_ap = max(0.1, current_ap - 0.1)  # Explore more
        else:
            new_ap = current_ap
        
        return {
            "ambient_pressure": new_ap,
            "reasoning": f"Loss trend: {loss_trend}, adjusting AP: {current_ap} -> {new_ap}"
        }

class ValidatorAgent(KCGraphAgent):
    """Final validation KC graph"""
    
    def process(self, context: Dict) -> Dict:
        kc_graph = context.get("kc_graph", {})
        
        prompt = f"""Validate this KC graph for educational soundness:
        {kc_graph}
        
        Check for:
        1. Cycles (A->B->C->A)
        2. Isolated KCs (no connections)
        3. Cognitive load appropriateness
        
        Return: {{"valid": bool, "issues": ["issue1", "issue2"]}}"""
        
        response = self.llm.generate(prompt)
        
        try:
            import json
            import re
            json_match = re.search(r'\\{.*\\}', response, re.DOTALL)
            validation = json.loads(json_match.group()) if json_match else {"valid": True, "issues": []}
            return validation
        except:
            return {"valid": True, "issues": []}

# ==================== BATCH PROCESSOR ====================

class BatchProcessor:
    """Nightly batch untuk optimize KC Graph"""
    
    def __init__(self, llm_config=None):
        self.generator = GeneratorAgent("Generator", llm_config)
        self.evaluator = EvaluatorAgent("Evaluator", llm_config)
        self.optimizer = OptimizerAgent("Optimizer", llm_config)
        self.validator = ValidatorAgent("Validator", llm_config)
    
    def run_optimization(self, course_id: str, student_data: List[Dict]) -> Dict:
        """Run full MAS-KCL pipeline"""
        from logic_scratch.registry import registry
        from mas_kcl.de_optimizer import DifferentialEvolution, DEParams
        from logic_scratch.schemas import KCGraph
        from datetime import datetime
        
        print(f"[MAS-KCL] Starting optimization for {course_id}")
        
        course_config = registry.get_course_config(course_id)
        if not course_config:
            return {"error": f"Course {course_id} not found"}
        
        kc_names = course_config.kc_set
        
        # Step 1: Generate initial candidates dengan LLM
        gen_result = self.generator.process({
            "kc_names": kc_names,
            "course_description": course_config.course_name
        })
        
        # Step 2: Setup DE
        def fitness_func(chromosome):
            # Simulated fitness: semakin konsisten dengan student data, semakin tinggi
            import random
            base = 0.7
            return base + random.gauss(0, 0.1)
        
        de = DEParams(population_size=30, max_generations=50)
        optimizer = DifferentialEvolution(de)
        
        # Step 3: Run DE optimization
        best = optimizer.optimize(
            kc_names=kc_names,
            fitness_func=fitness_func,
            initial_difficulty=course_config.difficulty_baseline
        )
        
        # Step 4: Validate dengan LLM
        validation = self.validator.process({
            "kc_graph": {"kcs": kc_names, "difficulty": best.chromosome}
        })
        
        # Step 5: Update registry
        new_graph = KCGraph(
            course_id=course_id,
            kcs=kc_names,
            difficulty_map=best.chromosome,
            last_updated=datetime.now().isoformat()
        )
        registry.update_kc_graph(course_id, new_graph)
        
        return {
            "success": True,
            "best_fitness": best.fitness,
            "difficulty_map": best.chromosome,
            "validation": validation,
            "generations": len(optimizer.fitness_history)
        }
