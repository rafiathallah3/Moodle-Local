import json, os, re, uuid
from datetime import datetime
from typing import Any, Dict, Optional

BASE_MEMORY = "memory"

def load_json(filename: str):
    with open(os.path.join(BASE_MEMORY, filename), "r", encoding="utf-8") as f:
        return json.load(f)

def save_json(filename: str, data: Any):
    with open(os.path.join(BASE_MEMORY, filename), "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)

def now_iso():
    return datetime.utcnow().isoformat()

def gen_id(prefix: str):
    return f"{prefix}-{str(uuid.uuid4())[:8]}"

def normalize_whitespace(text: str) -> str:
    if not text: return ""
    return re.sub(r"\s+", " ", str(text)).strip()

def normalize_code(text: str) -> str:
    if not text: return ""
    lines = [line.rstrip() for line in str(text).strip().splitlines()]
    return "\n".join(lines).strip()

def normalize_sql(text: str) -> str:
    if not text: return ""
    t = str(text).replace("\n", " ")
    t = re.sub(r"\s+", " ", t).strip().rstrip(";")
    return t.lower()

def safe_json_parse(raw: str, fallback: Dict[str, Any]):
    if not raw:
        return fallback
    cleaned = raw.strip().replace("```json","").replace("```","").strip()
    try:
        return json.loads(cleaned)
    except Exception:
        try:
            s = cleaned.find("{")
            e = cleaned.rfind("}")
            if s != -1 and e != -1 and e > s:
                return json.loads(cleaned[s:e+1])
        except Exception:
            pass
        return fallback

def append_log(state: dict, agent: str, event_type: str, message: str, extra: Optional[Dict[str, Any]] = None):
    if "logs" not in state or state["logs"] is None:
        state["logs"] = []
    item = {"timestamp": now_iso(), "agent": agent, "event_type": event_type, "message": message}
    if extra is not None:
        item["extra"] = extra
    state["logs"].append(item)
    return state

def add_agent_called(state: dict, agent: str, status: str, detail: str = ""):
    if "agents_called" not in state or state["agents_called"] is None:
        state["agents_called"] = []
    state["agents_called"].append({"agent": agent, "status": status, "detail": detail})
    return state
