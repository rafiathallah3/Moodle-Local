
"""
Orchestrator Agent - Intent Classification, CFF & Dynamic Routing
"""
from logic_scratch.schemas import Intent, AgenticState, CFFType
from logic_scratch.utils import log_action

class OrchestratorAgent:
    """Main entry dengan CFF support"""
    
    def __init__(self):
        self.injection_patterns = [
            "ignore previous", "system prompt", "jailbreak", 
            "disregard all", "you are now", "DAN mode"
        ]
    
    def process(self, state: AgenticState) -> AgenticState:
        print("[Orchestrator] Processing request...")
        
        evidence = state.get("evidence", {})
        content = evidence.get("content", "").lower()
        trigger = evidence.get("trigger", "on_submit")
        role = evidence.get("metadata", {}).get("role", "student")
        course_config = state.get("course_config")
        
        # 1. Security Check
        if self._check_injection(content):
            state["policy_blocked"] = True
            state["policy_reason"] = "Potential prompt injection detected"
            state["next_node"] = "formatter"
            return log_action(state, "orchestrator", "blocked", "injection detected")
        
        # 2. Intent Classification
        intent = self._classify_intent(trigger, content, role)
        state["intent"] = intent
        
        # 3. CFF (Cognitive Forcing Function) Check
        # Dari paper "To Trust or to Think" - reduce overreliance
        if course_config and course_config.cff_enabled and role == "student":
            cff_type = course_config.cff_type
            
            if cff_type == CFFType.ON_DEMAND and trigger == "on_submit":
                # AI suggestion hidden until explicitly requested
                state["cff_triggered"] = True
                # Route ke lesson_generator tapi dengan flag untuk hide AI suggestion initially
                
            elif cff_type == CFFType.WAIT_DELAY:
                # Delay mekanisme - akan dihandle di frontend, tapi kita track
                state["cff_triggered"] = True
                
            elif cff_type == CFFType.UPDATE_FIRST:
                # User must submit first before seeing AI
                if evidence.get("student_initial_answer") is None:
                    state["cff_triggered"] = True
        
        # 4. Routing Decision
        route_map = {
            Intent.SUBMISSION: "analyzer",
            Intent.CHAT: "lesson_generator",
            Intent.PRACTICE: "lesson_generator",
            Intent.STUDY_PLANNER: "lesson_generator",
            Intent.ANALYTICS_REQUEST: "lesson_generator"
        }
        
        # 5. Permission Check
        if intent == Intent.ANALYTICS_REQUEST and role != "teacher":
            state["policy_blocked"] = True
            state["policy_reason"] = "Analytics requires teacher role"
            state["next_node"] = "formatter"
            return log_action(state, "orchestrator", "blocked", "unauthorized")
        
        next_node = route_map.get(intent, "lesson_generator")
        state["next_node"] = next_node
        
        print(f"[Orchestrator] Intent: {intent.value} → {next_node} | CFF: {state.get('cff_triggered', False)}")
        return log_action(state, "orchestrator", "routed", f"{intent.value} to {next_node}")
    
    def _check_injection(self, content: str) -> bool:
        content_lower = content.lower()
        return any(pattern in content_lower for pattern in self.injection_patterns)
    
    def _classify_intent(self, trigger: str, content: str, role: str) -> Intent:
        if trigger == "practice_request" or any(kw in content for kw in ["latihan", "practice", "exercise"]):
            return Intent.PRACTICE
        
        if trigger == "on_click" and any(kw in content for kw in ["study", "plan", "rencana", "schedule"]):
            return Intent.STUDY_PLANNER
        
        if trigger == "analytics_request" or (role == "teacher" and "analytics" in content):
            return Intent.ANALYTICS_REQUEST
        
        if trigger == "on_submit":
            return Intent.SUBMISSION
        
        return Intent.CHAT
