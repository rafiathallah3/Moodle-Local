
"""
Analyzer Agent - AFS v2 Scoring dengan Sub-Agent Pattern

Uses StyleCheckerAgent (syntax) and LogicCheckerAgent (logic) as sub-agents.
Both sub-agents support two modes:
  - Regex-based (fast, offline) for non-quiz triggers
  - LLM-based (OpenAI gpt-4o) for quiz diagnosis triggers
"""
import os
from typing import Dict, Any, List
from logic_scratch.schemas import ScoringV2Result, MisconceptionItem, Severity, RubricBreakdown, AgenticState
from logic_scratch.llm_factory import LLMFactory
from logic_scratch.utils import log_action


class StyleCheckerAgent:
    """Syntax/Style checking sub-agent with optional LLM-based pseudo-code analysis."""

    def check(self, code: str, language: str) -> Dict[str, Any]:
        """Regex-based style checking (fallback for non-quiz triggers)."""
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

    def check_with_llm(self, code: str, language: str, media_path: str = None) -> Dict[str, Any]:
        """
        LLM-based syntax checking for pseudo-code quiz submissions.
        Mirrors the Syntax Checker Agent logic from diagnose.py but lives
        entirely inside the AnalyzerAgent's sub-agent hierarchy.
        """
        import base64
        import mimetypes
        from langchain_openai import ChatOpenAI
        from langchain_core.messages import HumanMessage, SystemMessage
        from pydantic import BaseModel, Field

        class SyntaxOutput(BaseModel):
            is_valid: bool = Field(description="True if the pseudo-code has no major syntax errors")
            syntax_feedback: str = Field(
                description="A short feedback focusing solely on pseudo-code syntax. Point out structural errors based on grammar."
            )

        lang_map = {"id": "Indonesian", "en": "English", "sn": "Sundanese", "jw": "Javanese", "fr": "French"}
        lang_name = lang_map.get(language.lower().strip(), language)

        api_key = os.environ.get("OPENAI_API")
        llm = ChatOpenAI(model="gpt-4o", openai_api_key=api_key)
        syntax_llm = llm.with_structured_output(SyntaxOutput)

        syntax_instruction = (
            "You are an AI Syntax Checker Agent for pseudo-code.\n"
            "Here are the pseudo-code grammar rules you MUST check for:\n"
            "1. MUST start with 'program [Name]'.\n"
            "2. MUST end with 'endprogram'.\n"
            "3. MAY contain a 'dictionary' (or 'kamus') section for variable declarations. "
            "If present, it must be labeled 'dictionary' or 'kamus'.\n"
            "4. MAY contain 'function' or 'procedure' blocks. These can appear after 'dictionary' and before 'algorithm'.\n"
            "5. MUST contain an 'algorithm' (or 'algoritma') section where the main logic starts.\n"
            "6. The assignment operator is `<-`. (Do NOT flag this as incorrect).\n\n"
            "IMPORTANT:\n"
            "- Never claim 'dictionary' or 'algorithm' is missing if the word is explicitly written in the pseudo-code.\n"
            "- Do NOT be confused by 'function' declarations existing outside the main algorithm.\n"
            "- Output your feedback in plain text without HTML tags (no <br>, <strong>, etc).\n"
            "- Do not evaluate logical correctness, only focus on structural grammar and syntax.\n"
            f"- TRANSLATION REQUIREMENT: You MUST write your 'syntax_feedback' strictly in {lang_name}."
        )

        human_content = self._build_human_content(code, media_path)

        messages = [SystemMessage(content=syntax_instruction), HumanMessage(content=human_content)]

        try:
            result = syntax_llm.invoke(messages)
            return {
                "is_valid": result.is_valid,
                "syntax_feedback": result.syntax_feedback,
                "style_score": 10 if result.is_valid else 5,
                "style_issues": [] if result.is_valid else ["Syntax issues detected"],
                "passed": result.is_valid
            }
        except Exception as e:
            error_msg = str(e)
            if "SAFETY" in error_msg.upper():
                return {
                    "is_valid": False,
                    "syntax_feedback": "The AI Syntax Agent response was blocked due to safety filters.",
                    "style_score": 0, "style_issues": ["Safety filter block"], "passed": False
                }
            raise

    @staticmethod
    def _build_human_content(code: str, media_path: str = None) -> list:
        """Build the multimodal content list for the LLM."""
        import base64
        import mimetypes

        human_content = []
        if code:
            human_content.append({"type": "text", "text": f"Student Text Answer:\n{code}"})

        if media_path and os.path.exists(media_path):
            size = os.path.getsize(media_path)
            if size > 0:
                mime_type, _ = mimetypes.guess_type(media_path)
                ext = os.path.splitext(media_path)[1].lower()
                if ext == ".webm":
                    mime_type = "audio/webm"
                elif not mime_type:
                    if ext in (".jpg", ".jpeg", ".png", ".gif", ".webp"):
                        mime_type = "image/jpeg"
                    else:
                        mime_type = "audio/mpeg"

                with open(media_path, "rb") as f:
                    media_data = base64.b64encode(f.read()).decode("utf-8")

                if mime_type.startswith("image/"):
                    human_content.append({
                        "type": "image_url",
                        "image_url": {"url": f"data:{mime_type};base64,{media_data}"}
                    })
                else:
                    human_content.append({"type": "media", "mime_type": mime_type, "data": media_data})
                    if not code:
                        human_content.append({
                            "type": "text",
                            "text": "Please transcribe and evaluate this audio/video directly."
                        })

        if not human_content:
            human_content.append({"type": "text", "text": "No answer provided."})

        return human_content


class LogicCheckerAgent:
    """Logic checking sub-agent with optional LLM-based diagnosis and scoring."""

    def check(self, code: str, language: str) -> Dict[str, Any]:
        """Regex-based logic checking (fallback for non-quiz triggers)."""
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

    def check_with_llm(self, code: str, language: str, max_mark: float,
                       question_text: str, syntax_result: Dict,
                       media_path: str = None) -> Dict[str, Any]:
        """
        LLM-based logic checking and scoring for quiz submissions.
        Mirrors the Logic Checker Agent logic from diagnose.py but lives
        entirely inside the AnalyzerAgent's sub-agent hierarchy.
        """
        from langchain_openai import ChatOpenAI
        from langchain_core.messages import HumanMessage, SystemMessage
        from pydantic import BaseModel, Field

        class LogicDiagnosisOutput(BaseModel):
            mark: float = Field(
                description="The score determined for the student's answer out of the maximum mark."
            )
            logic_feedback: str = Field(
                description="A short summary and a light diagnosis/feedback of the student's logic."
            )

        lang_map = {"id": "Indonesian", "en": "English", "sn": "Sundanese", "jw": "Javanese", "fr": "French"}
        lang_name = lang_map.get(language.lower().strip(), language)

        api_key = os.environ.get("OPENAI_API")
        llm = ChatOpenAI(model="gpt-4o", openai_api_key=api_key)
        logic_llm = llm.with_structured_output(LogicDiagnosisOutput)

        logic_instruction = (
            "You are an AI Logic Checker Agent tasked with evaluating a student's answer and assigning a score.\n"
            "Please evaluate the logical correctness of the response inside the 'algorithm' block "
            f"and determine a score for the student's answer out of a maximum of {max_mark}.\n"
            "Keep your diagnosis concise, helpful, and constructive. "
            "You will receive the Syntax Agent's feedback to help factor any severe syntax errors into your grading.\n"
            "Keep your output in plain text. Do NOT use HTML tags such as <br> or <strong>.\n"
            f"TRANSLATION REQUIREMENT: You MUST write your 'logic_feedback' strictly in {lang_name}."
        )

        if question_text:
            logic_instruction += f"\n\nQuestion/Assignment Description:\n{question_text}"

        logic_instruction += (
            f"\n\n--- Syntax Agent Feedback ---\n"
            f"Is Valid: {syntax_result.get('is_valid', True)}\n"
            f"Feedback: {syntax_result.get('syntax_feedback', 'No syntax issues')}\n"
        )

        human_content = StyleCheckerAgent._build_human_content(code, media_path)
        messages = [SystemMessage(content=logic_instruction), HumanMessage(content=human_content)]

        try:
            result = logic_llm.invoke(messages)
            mark = float(result.mark)
            is_correct = mark >= (max_mark * 0.7)

            # Extract misconception label from feedback for Learning Style Agent
            misconceptions = []
            if not is_correct:
                diag_lower = result.logic_feedback.lower()
                label = "logic_error"
                if "while" in diag_lower or "loop" in diag_lower:
                    label = "loops"
                elif "if" in diag_lower or "else" in diag_lower:
                    label = "conditionals"
                elif "function" in diag_lower or "procedure" in diag_lower:
                    label = "functions"
                elif "array" in diag_lower or "dictionary" in diag_lower:
                    label = "data_structures"
                misconceptions.append(MisconceptionItem(
                    label=label, evidence="AI detected error", severity=Severity.HIGH
                ))

            return {
                "mark": mark,
                "logic_feedback": result.logic_feedback,
                "logic_score": min(10, int(mark / 10)),
                "is_correct": is_correct,
                "logic_issues": misconceptions
            }
        except Exception as e:
            error_msg = str(e)
            if "SAFETY" in error_msg.upper():
                return {
                    "mark": 0.0,
                    "logic_feedback": "The AI Logic Agent response was blocked due to safety filters.",
                    "logic_score": 0, "is_correct": False, "logic_issues": []
                }
            raise


class AnalyzerAgent:
    def __init__(self, llm_config=None):
        self.llm = LLMFactory(llm_config)
        self.style_checker = StyleCheckerAgent()
        self.logic_checker = LogicCheckerAgent()
    
    def process(self, state: AgenticState) -> AgenticState:
        print("[Analyzer] Starting AFS v2 Scoring...")
        evidence = state.get("evidence", {})
        code = evidence.get("content", "")
        metadata = evidence.get("metadata", {})
        language = metadata.get("language", "python")
        trigger = evidence.get("trigger", "")
        
        # Determine mode: LLM-based for quiz submissions, regex for everything else
        use_llm = (trigger == "diagnose" or trigger == "on_submit")
        
        final_score = 100
        is_correct = True
        summary = "Analysis complete"
        misconceptions = []
        
        if use_llm and (code or metadata.get("media_path")):
            try:
                # Load environment for API key
                import dotenv
                moodle_root = os.path.dirname(os.path.dirname(__file__))
                dotenv.load_dotenv(os.path.join(moodle_root, ".env"))
                
                media_path = metadata.get("media_path")
                question_text = metadata.get("question_text", "")
                
                # Step 1: Syntax check via StyleCheckerAgent (LLM mode)
                print("[Analyzer] Running StyleCheckerAgent (LLM)...")
                syntax_result = self.style_checker.check_with_llm(code, language, media_path)
                print(f"[Analyzer] Syntax valid={syntax_result['is_valid']}")
                
                # Step 2: Logic check via LogicCheckerAgent (LLM mode)
                print("[Analyzer] Running LogicCheckerAgent (LLM)...")
                logic_result = self.logic_checker.check_with_llm(
                    code, language, 100.0, question_text, syntax_result, media_path
                )
                print(f"[Analyzer] Logic mark={logic_result['mark']}")
                
                # Compose final score and summary
                final_score = int(logic_result["mark"])
                is_correct = logic_result["is_correct"]
                misconceptions = logic_result["logic_issues"]
                
                # Build localized diagnosis labels
                raw_lang = language.lower().strip()
                if raw_lang == "id":
                    label_syntax, label_logic = "Pemeriksaan Sintaks", "Pemeriksaan Logika"
                elif raw_lang == "sn":
                    label_syntax, label_logic = "Pamariksaan Sintaks", "Pamariksaan Logika"
                elif raw_lang == "jw":
                    label_syntax, label_logic = "Pemeriksaan Sintaks", "Pemeriksaan Logika"
                else:
                    label_syntax, label_logic = "Syntax Check", "Logic Check"
                
                summary = (
                    f"{label_syntax}:\n{syntax_result['syntax_feedback']}\n\n"
                    f"{label_logic}:\n{logic_result['logic_feedback']}"
                )
                
            except Exception as e:
                summary = f"LLM Diagnosis Error: {str(e)}"
                is_correct = False
                final_score = 0
        elif not use_llm:
            # Fallback to local regex-based checking
            style_result = self.style_checker.check(code, language)
            logic_result = self.logic_checker.check(code, language)
            final_score = self._calculate_total(style_result, logic_result)
            is_correct = logic_result["is_correct"] and final_score >= 70
            summary = self._generate_summary(style_result, logic_result)
            misconceptions = logic_result["logic_issues"]
        
        scoring = ScoringV2Result(
            score_0_100=final_score,
            is_correct=is_correct,
            confidence=0.9,
            summary=summary,
            misconceptions=misconceptions,
            rubric_breakdown=RubricBreakdown(form=min(10, final_score // 10), logic=min(10, final_score // 10))
        )
        
        state["scoring_result"] = scoring
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
