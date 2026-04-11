
"""
FastAPI Server untuk Moodle Integration
"""
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Dict, Any, Optional

app = FastAPI(title="Agentic Multimodal AI Tutor API", version="3.0")

# Request/Response Models
class TutorRequest(BaseModel):
    user_id: str
    course_id: str
    evidence: Dict[str, Any]
    trigger: str = "on_submit"
    metadata: Optional[Dict] = {}

class OptimizationRequest(BaseModel):
    course_id: str
    run_nightly: bool = False

# Global graph instance (akan diinisialisasi di main)
graph = None

@app.post("/api/v1/tutor")
async def tutor_endpoint(req: TutorRequest):
    """Main endpoint untuk student/teacher requests"""
    if graph is None:
        raise HTTPException(status_code=500, detail="Graph not initialized")
    
    from logic_scratch.moodle_adapter import MoodleAdapter
    
    adapter = MoodleAdapter()
    state = adapter.evidence_to_state(
        evidence=req.evidence,
        user_id=req.user_id,
        course_id=req.course_id
    )
    
    result = graph.invoke(state)
    return adapter.state_to_response(result)

@app.post("/api/v1/admin/optimize")
async def optimize_endpoint(req: OptimizationRequest):
    """Admin endpoint untuk trigger MAS-KCL optimization"""
    from mas_kcl.llm_agents import BatchProcessor
    
    processor = BatchProcessor()
    result = processor.run_optimization(req.course_id, student_data=[])
    
    return result

@app.get("/api/v1/courses")
async def list_courses():
    """List available courses"""
    from logic_scratch.registry import registry
    return {
        "courses": [
            {"id": cid, "name": registry.get_course_config(cid).course_name}
            for cid in registry.list_courses()
        ]
    }

@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "version": "3.0",
        "features": ["multimodal", "cff", "mas-kcl", "dynamic-courses"]
    }
