
"""
Analyzer Agent - AFS v2 Scoring dengan Sub-Agent Pattern
"""
from typing import Dict, Any, List
from logic_scratch.schemas import ScoringV2Result, MisconceptionItem, Severity, RubricBreakdown, AgenticState
from logic_scratch.llm_factory import LLMFactory
from logic_scratch.utils import log_action


class StyleCheckerAgent:
    def check(self, code: str, language: str) -> Dict[str, Any]:
        issues = []
        score = 10
        code_clean = code.strip()
        
        if language == "python":
            lines = code_clean.split("\n")
            for i, line in enumerate(lines):
                if line.strip() and not line.startswith(" ") and not line.startswith("#") and i > 0:
                    if line.strip() not in ["def ", "class ", "if ", "for ", "while "]:
                        issues.append(f"Line {i+1}: Possible indentation issue")
                        score -= 1
            
            if "def " in code:
                func_names = []
                for line in lines:
                    if "def " in line:
                        parts = line.split("def ")[1].split("(")
                        if parts:
                            func_names.append(parts[0])
                for name in func_names:
                    if name and not name.islower() and "_" not in name and not name.startswith("__"):
                        issues.append(f"Function '{name}' should use snake_case")
                        score -= 1
        
        elif language == "sql":
            if code_clean and not code_clean.endswith(";"):
                issues.append("SQL statement should end with semicolon")
                score -= 1
        
        return {"style_score": max(0, score), "style_issues": issues, "passed": score >= 8}


class LogicCheckerAgent:
    def check(self, code: str, language: str) -> Dict[str, Any]:
        issues = []
        logic_score = 10
        is_correct = True
        code_clean = code.strip()
        code_lower = code_clean.lower()
        
        if language == "python":
            if "range(1, n)" in code and "range(1, n+1)" not in code:
                is_correct = False
                logic_score = 6
                issues.append(MisconceptionItem(label="OFF_BY_ONE_ERROR", evidence="range(1, n) excludes n", severity=Severity.HIGH))
            
            if "def " in code and "return" not in code:
                is_correct = False
                logic_score = 7
                issues.append(MisconceptionItem(label="MISSING_RETURN", evidence="No return statement found", severity=Severity.HIGH))
            
            if "while True" in code and "break" not in code and "return" not in code:
                logic_score = 5
                issues.append(MisconceptionItem(label="POTENTIAL_INFINITE_LOOP", evidence="while True without break/return", severity=Severity.MEDIUM))
        
        elif language == "sql":
            has_select = "select" in code_lower
            has_from = "from" in code_lower
            
            if has_select and not has_from:
                is_correct = False
                logic_score = 5
                issues.append(MisconceptionItem(label="SQL_MISSING_FROM", evidence="SELECT missing FROM", severity=Severity.HIGH))
            elif has_select and has_from:
                after_from = code_lower.split("from")[-1].strip()
                if not after_from or after_from in [")", "", ";", "where", "group", "order"]:
                    is_correct = False
                    logic_score = 5
                    issues.append(MisconceptionItem(label="SQL_MISSING_FROM", evidence="SELECT without table name", severity=Severity.HIGH))
        
        return {"logic_score": logic_score, "is_correct": is_correct, "logic_issues": issues}


class AnalyzerAgent:
    def __init__(self, llm_config=None):
        self.llm = LLMFactory(llm_config)
        self.style_checker = StyleCheckerAgent()
        self.logic_checker = LogicCheckerAgent()
    
    def process(self, state: AgenticState) -> AgenticState:
        print("[Analyzer] Starting AFS v2 Scoring...")
        evidence = state.get("evidence", {})
        code = evidence.get("content", "")
        language = evidence.get("metadata", {}).get("language", "python")
        
        style_result = self.style_checker.check(code, language)
        logic_result = self.logic_checker.check(code, language)
        
        final_score = self._calculate_total(style_result, logic_result)
        all_misconceptions = logic_result["logic_issues"]
        
        scoring = ScoringV2Result(
            score_0_100=final_score,
            is_correct=logic_result["is_correct"] and final_score >= 70,
            confidence=0.9 if logic_result["is_correct"] else 0.7,
            summary=self._generate_summary(style_result, logic_result),
            misconceptions=all_misconceptions,
            rubric_breakdown=RubricBreakdown(form=style_result["style_score"], logic=logic_result["logic_score"])
        )
        
        state["scoring_result"] = scoring
        state["style_result"] = style_result
        state["logic_result"] = logic_result
        state["next_node"] = "critic"
        
        print(f"[Analyzer] Score: {scoring.score_0_100}/100")
        return log_action(state, "analyzer", "scored", f"{scoring.score_0_100}/100")
    
    def _calculate_total(self, style: Dict, logic: Dict) -> int:
        return int((style["style_score"] * 0.3 + logic["logic_score"] * 0.7) * 10)
    
    def _generate_summary(self, style: Dict, logic: Dict) -> str:
        parts = []
        if not logic["is_correct"]:
            parts.append(f"Logic issues: {len(logic['logic_issues'])}")
        if style["style_issues"]:
            parts.append(f"Style issues: {len(style['style_issues'])}")
        return " | ".join(parts) if parts else "Code looks good!"
