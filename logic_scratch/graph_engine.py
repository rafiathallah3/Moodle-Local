
"""
Graph Engine - State Machine dengan Conditional Edges
"""
from typing import Dict, Any, Callable, Optional, List
import time

END = "__end__"

class StateGraph:
    def __init__(self):
        self.nodes = {}
        self.edges = {}  # static edges
        self.conditional_edges = {}  # conditional edges
        self.entry_point = None
    
    def add_node(self, name: str, func: Callable):
        self.nodes[name] = func
        return self
    
    def add_edge(self, from_node: str, to_node: str):
        """Static edge"""
        if from_node not in self.edges:
            self.edges[from_node] = []
        self.edges[from_node].append(to_node)
        return self
    
    def add_conditional_edges(self, from_node: str, condition_func: Callable, path_map: Dict[str, str]):
        """
        Conditional edges: condition_func returns key yang dipakai untuk lookup path_map
        """
        self.conditional_edges[from_node] = {
            "condition": condition_func,
            "paths": path_map
        }
        return self
    
    def set_entry_point(self, name: str):
        self.entry_point = name
        return self
    
    def compile(self):
        return CompiledGraph(self.nodes, self.edges, self.conditional_edges, self.entry_point)

class CompiledGraph:
    def __init__(self, nodes, edges, conditional_edges, entry_point):
        self.nodes = nodes
        self.edges = edges
        self.conditional_edges = conditional_edges
        self.entry_point = entry_point
    
    def invoke(self, state: Dict[str, Any]) -> Dict[str, Any]:
        """Execute graph dengan conditional routing"""
        current = self.entry_point
        state["nodes_visited"] = []
        state["start_time"] = time.time()
        max_iter = 20
        
        for i in range(max_iter):
            if current == END or current is None:
                break
            
            if current not in self.nodes:
                state["error"] = f"Unknown node: {current}"
                break
            
            state["nodes_visited"].append(current)
            print(f"[Graph] → {current}")
            
            # Execute node
            try:
                state = self.nodes[current](state)
            except Exception as e:
                print(f"[Graph] Error in {current}: {e}")
                state["error"] = str(e)
                break
            
            # Determine next node
            next_node = None
            
            # 1. Check if node set explicit next_node
            if state.get("next_node") and state["next_node"] != END:
                next_node = state["next_node"]
            # 2. Check conditional edges
            elif current in self.conditional_edges:
                cond = self.conditional_edges[current]
                result_key = cond["condition"](state)
                next_node = cond["paths"].get(result_key, END)
                print(f"[Graph] Conditional: {result_key} → {next_node}")
            # 3. Check static edges
            elif current in self.edges:
                candidates = self.edges[current]
                next_node = candidates[0] if candidates else END
            
            current = next_node
        
        state["processing_time_ms"] = int((time.time() - state["start_time"]) * 1000)
        return state
