import os
from typing import Dict, Any
from logic_scratch.utils import safe_json_parse, append_log

class LLMClient:
    """
    Model-agnostic wrapper.
    - Default: disabled (deterministic fallbacks).
    - To switch models/providers later: modify _generate_text only.
    """
    def __init__(self, use_llm: bool = False, provider: str = "gemini", model: str = "gemini-2.5-flash"):
        self.use_llm = use_llm
        self.provider = provider
        self.model = model
        self._client = None

        if self.use_llm and provider == "gemini":
            try:
                from google import genai
                from kaggle_secrets import UserSecretsClient
                api_key = None
                try:
                    api_key = UserSecretsClient().get_secret("GEMINI_API_KEY")
                except Exception:
                    api_key = os.environ.get("GEMINI_API_KEY")
                if api_key:
                    self._client = genai.Client(api_key=api_key)
                else:
                    self.use_llm = False
            except Exception:
                self.use_llm = False

    def _generate_text(self, system: str, prompt: str) -> str:
        # Gemini implementation. Replace for Qwen or others.
        resp = self._client.models.generate_content(model=self.model, contents=f"{system}\n\n{prompt}")
        return getattr(resp, "text", "") or ""

    def generate_json(self, state: dict, agent: str, system: str, prompt: str, schema_cls, fallback: Dict[str, Any]):
        if not self.use_llm or self._client is None:
            append_log(state, agent, "llm_fallback", "LLM disabled; using fallback")
            return fallback
        raw = ""
        try:
            raw = self._generate_text(system, prompt)
            parsed = safe_json_parse(raw, fallback=fallback)
            obj = schema_cls(**parsed)
            return obj.model_dump()
        except Exception as e:
            append_log(state, agent, "llm_error", "LLM failed; using fallback", {"error": str(e), "raw": raw[:250]})
            return fallback
