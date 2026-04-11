
"""
Universal LLM Configuration - Dynamic Provider Switching
"""
import os
from typing import Dict, Any, Optional
from logic_scratch.schemas import LLMProvider

class LLMConfig:
    """Konfigurasi LLM yang bisa diganti-ganti providernya"""
    
    def __init__(self, 
                 provider: LLMProvider = LLMProvider.GEMINI,
                 model: Optional[str] = None,
                 api_key: Optional[str] = None,
                 temperature: float = 0.7,
                 max_tokens: int = 2048,
                 timeout: int = 30):
        self.provider = provider
        self.temperature = temperature
        self.max_tokens = max_tokens
        self.timeout = timeout
        self.api_key = api_key or os.getenv(self._get_env_key())
        
        # Default models per provider
        self.model = model or self._get_default_model()
        
    def _get_env_key(self) -> str:
        mapping = {
            LLMProvider.GEMINI: "GOOGLE_API_KEY",
            LLMProvider.OPENAI: "OPENAI_API_KEY",
            LLMProvider.ANTHROPIC: "ANTHROPIC_API_KEY",
            LLMProvider.LOCAL: "LOCAL_LLM_URL"
        }
        return mapping.get(self.provider, "GOOGLE_API_KEY")
    
    def _get_default_model(self) -> str:
        mapping = {
            LLMProvider.GEMINI: "gemini-1.5-flash",
            LLMProvider.OPENAI: "gpt-4",
            LLMProvider.ANTHROPIC: "claude-3-sonnet-20240229",
            LLMProvider.LOCAL: "llama3"
        }
        return mapping.get(self.provider, "gemini-1.5-flash")
    
    def get_config_dict(self) -> Dict[str, Any]:
        return {
            "provider": self.provider.value,
            "model": self.model,
            "temperature": self.temperature,
            "max_tokens": self.max_tokens,
            "timeout": self.timeout
        }

# Global instance - bisa di-override per course
default_llm_config = LLMConfig()
