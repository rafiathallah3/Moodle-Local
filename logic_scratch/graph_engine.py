from langgraph.graph import StateGraph, START, END
from logic_scratch.state import AgenticState
from logic_scratch.llm import LLMClient
from logic_scratch.utils import append_log

from agents.orchestrator import Orchestrator
from agents.analyzer import Analyzer
from agents.lesson_generator import LessonGenerator
from agents.reflective_critic import ReflectiveCritic
from agents.memory_updater import MemoryUpdater
from agents.response_formatter import response_formatter
from logic_scratch.learning_events import record_learning_event

def build_graph(use_llm: bool = False, provider: str = "gemini", model: str = "gemini-2.5-flash"):
    llm = LLMClient(use_llm=use_llm, provider=provider, model=model)

    orch = Orchestrator(llm)
    ana  = Analyzer(llm)
    gen  = LessonGenerator(llm)
    crit = ReflectiveCritic(llm)
    mem  = MemoryUpdater()

    builder = StateGraph(AgenticState)
    builder.add_node("orchestrator", orch.run)
    builder.add_node("analyzer", ana.run)
    builder.add_node("lesson_generator", gen.run)
    builder.add_node("reflective_critic", crit.run)
    builder.add_node("memory_updater", mem.run)
    builder.add_node("response_formatter", response_formatter)

    def route_after_orchestrator(state: dict):
        if state.get("route_target") == "analyzer":
            return "analyzer"
        if state.get("route_target") == "lesson_generator":
            return "lesson_generator"
        return "response_formatter"

    def route_after_critic(state: dict):
        verdict = state.get("critic_verdict","PASS")
        cap = int(state.get("revision_cap", 2))
        count = int(state.get("revision_count", 0))

        if verdict == "BLOCK":
            # safe refusal for student; policy message for teacher
            if state.get("role") == "student":
                state["generated_response"] = ("Maaf, saya tidak bisa memberikan jawaban seperti itu. Kirim draft attempt, saya bantu dengan skeleton."
                                               if state.get("preferred_language")=="id"
                                               else "Sorry, I can't provide that. Send a draft attempt and I'll help with a skeleton.")
            else:
                state["generated_response"] = ("Blocked by policy (outside managed course/domain)."
                                               if state.get("preferred_language")=="en"
                                               else "Diblok oleh kebijakan (di luar course/domain yang dikelola).")
            append_log(state, "reflective_critic", "block_action", "Applied safe block response")
            return "memory_updater" if state.get("role")=="student" else "response_formatter"

        if verdict == "REVISE" and count < cap:
            return "lesson_generator"

        return "memory_updater" if state.get("role")=="student" else "response_formatter"

    builder.add_edge(START, "orchestrator")
    builder.add_conditional_edges("orchestrator", route_after_orchestrator)

    builder.add_edge("analyzer", "lesson_generator")
    builder.add_edge("lesson_generator", "reflective_critic")
    builder.add_conditional_edges("reflective_critic", route_after_critic)

    builder.add_edge("memory_updater", "response_formatter")
    builder.add_edge("response_formatter", END)

    app = builder.compile()

    # wrapper to record learning event after invoke (optional)
    def invoke_with_event(payload: dict):
        out = app.invoke(payload)
        # record event for student submission_review only
        if out.get("role")=="student" and out.get("mode")=="submission_review":
            record_learning_event(out)
        return out

    return app, invoke_with_event
