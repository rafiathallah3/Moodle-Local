
"""
Utility Functions
"""
import hashlib
import time
from datetime import datetime
from typing import Any, Dict

def gen_id(prefix: str = "id") -> str:
    ts = datetime.now().strftime("%Y%m%d%H%M%S")
    hash_part = hashlib.md5(str(time.time()).encode()).hexdigest()[:6]
    return f"{prefix}_{ts}_{hash_part}"

def log_action(state: Dict, agent: str, action: str, detail: str = ""):
    if "log" not in state:
        state["log"] = []
    state["log"].append({
        "timestamp": datetime.now().isoformat(),
        "agent": agent,
        "action": action,
        "detail": detail
    })
    return state

def calculate_processing_time(start_time: float) -> int:
    return int((time.time() - start_time) * 1000)
