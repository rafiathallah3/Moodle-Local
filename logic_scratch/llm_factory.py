
"""
LLM Factory - Provider Agnostic Interface
"""
from typing import Any, Optional
import os

class LLMFactory:
    """Factory untuk create LLM client berdasarkan config"""
    
    def __init__(self, config=None):
        from config.llm_config import LLMConfig, default_llm_config
        self.config = config or default_llm_config
        
    def get_client(self):
        """Return client sesuai provider"""
        if self.config.provider.value == "gemini":
            return self._init_gemini()
        elif self.config.provider.value == "openai":
            return self._init_openai()
        elif self.config.provider.value == "anthropic":
            return self._init_anthropic()
        else:
            return self._init_local()
    
    def _init_gemini(self):
        try:
            import google.generativeai as genai
            genai.configure(api_key=self.config.api_key)
            return genai.GenerativeModel(self.config.model)
        except Exception as e:
            print(f"[LLMFactory] Gemini init error: {e}")
            return None
    
    def _init_openai(self):
        try:
            import openai
            openai.api_key = self.config.api_key
            return openai
        except Exception as e:
            print(f"[LLMFactory] OpenAI init error: {e}")
            return None
    
    def _init_anthropic(self):
        try:
            import anthropic
            return anthropic.Anthropic(api_key=self.config.api_key)
        except Exception as e:
            print(f"[LLMFactory] Anthropic init error: {e}")
            return None
    
    def _init_local(self):
        # Placeholder untuk local LLM (Ollama, vLLM, dll)
        return None
    
    def generate(self, prompt: str, system_prompt: Optional[str] = None) -> str:
        """Universal generate method"""
        client = self.get_client()
        
        if self.config.provider.value == "gemini":
            return self._generate_gemini(client, prompt, system_prompt)
        elif self.config.provider.value == "openai":
            return self._generate_openai(client, prompt, system_prompt)
        elif self.config.provider.value == "anthropic":
            return self._generate_anthropic(client, prompt, system_prompt)
        else:
            return "[Local LLM Placeholder]"
    
    def _generate_gemini(self, client, prompt, system_prompt):
        try:
            if system_prompt:
                response = client.generate_content(
                    [system_prompt, prompt],
                    generation_config={"temperature": self.config.temperature, "max_output_tokens": self.config.max_tokens}
                )
            else:
                response = client.generate_content(prompt)
            return response.text
        except Exception as e:
            return f"[Error: {str(e)}]"
    
    def _generate_openai(self, client, prompt, system_prompt):
        try:
            messages = []
            if system_prompt:
                messages.append({"role": "system", "content": system_prompt})
            messages.append({"role": "user", "content": prompt})
            
            response = client.ChatCompletion.create(
                model=self.config.model,
                messages=messages,
                temperature=self.config.temperature,
                max_tokens=self.config.max_tokens
            )
            return response.choices[0].message.content
        except Exception as e:
            return f"[Error: {str(e)}]"
    
    def _generate_anthropic(self, client, prompt, system_prompt):
        try:
            message = client.messages.create(
                model=self.config.model,
                max_tokens=self.config.max_tokens,
                temperature=self.config.temperature,
                system=system_prompt or "",
                messages=[{"role": "user", "content": prompt}]
            )
            return message.content[0].text
        except Exception as e:
            return f"[Error: {str(e)}]"
