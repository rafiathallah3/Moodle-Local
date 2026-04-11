
"""
Student Dashboard - Analytics dan Progress Tracking untuk Siswa
"""
from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta

class StudentDashboard:
    """
    Dashboard untuk siswa melihat:
    - Progress belajar
    - Statistik submission
    - Weak concepts
    - Study plan status
    - Achievements
    """
    
    def __init__(self, registry):
        self.registry = registry
    
    def get_dashboard_data(self, user_id: str, course_id: str) -> Dict[str, Any]:
        """
        Get semua data untuk student dashboard
        
        Output komprehensif untuk ditampilkan di UI
        """
        student = self.registry.get_student_model(user_id, course_id)
        course = self.registry.get_course_config(course_id)
        
        return {
            "student_info": self._get_student_info(student),
            "progress_overview": self._get_progress_overview(student),
            "submission_stats": self._get_submission_stats(student),
            "weak_concepts": self._get_weak_concepts(student),
            "recent_activity": self._get_recent_activity(student),
            "achievements": self._get_achievements(student),
            "study_plan_status": self._get_study_plan_status(student),
            "learning_style": self._get_learning_style(student),
            "recommendations": self._get_personalized_recommendations(student, course)
        }
    
    def _get_student_info(self, student) -> Dict[str, Any]:
        """Info dasar siswa"""
        return {
            "user_id": student.user_id,
            "course_id": student.course_id,
            "joined_date": student.preferences.get("joined_date", "Unknown"),
            "last_active": student.last_active,
            "total_sessions": student.preferences.get("session_count", 0)
        }
    
    def _get_progress_overview(self, student) -> Dict[str, Any]:
        """Overview progress belajar"""
        mastery = student.mastery
        
        if not mastery:
            return {
                "overall_mastery": 0.0,
                "concepts_mastered": 0,
                "total_concepts": 0,
                "status": "Just Started"
            }
        
        avg_mastery = sum(mastery.values()) / len(mastery)
        mastered = sum(1 for score in mastery.values() if score >= 0.7)
        
        status = "Beginner" if avg_mastery < 0.4 else                  "Intermediate" if avg_mastery < 0.7 else "Advanced"
        
        return {
            "overall_mastery": round(avg_mastery, 2),
            "concepts_mastered": mastered,
            "total_concepts": len(mastery),
            "status": status,
            "mastery_breakdown": mastery
        }
    
    def _get_submission_stats(self, student) -> Dict[str, Any]:
        """Statistik submission"""
        history = student.preferences.get("submission_history", [])
        
        if not history:
            return {
                "total_submissions": 0,
                "average_score": 0,
                "improvement_trend": "No data"
            }
        
        scores = [h.get("score", 0) for h in history]
        avg_score = sum(scores) / len(scores)
        
        # Hitung trend
        if len(scores) >= 3:
            recent_avg = sum(scores[-3:]) / 3
            older_avg = sum(scores[:3]) / 3 if len(scores) >= 6 else scores[0]
            trend = "Improving" if recent_avg > older_avg else                     "Declining" if recent_avg < older_avg else "Stable"
        else:
            trend = "Not enough data"
        
        return {
            "total_submissions": len(history),
            "average_score": round(avg_score, 1),
            "best_score": max(scores),
            "latest_score": scores[-1],
            "improvement_trend": trend
        }
    
    def _get_weak_concepts(self, student) -> List[Dict[str, Any]]:
        """Daftar konsep yang perlu diperbaiki"""
        weak = []
        for kc, score in student.mastery.items():
            if score < 0.6:
                weak.append({
                    "concept": kc,
                    "mastery": round(score, 2),
                    "priority": "High" if score < 0.4 else "Medium",
                    "suggested_action": f"Review {kc} fundamentals"
                })
        
        # Urutkan berdasarkan mastery (terendah dulu)
        weak.sort(key=lambda x: x["mastery"])
        return weak[:5]  # Top 5 weak concepts
    
    def _get_recent_activity(self, student) -> List[Dict[str, Any]]:
        """Aktivitas terbaru"""
        history = student.preferences.get("session_history", [])
        
        # Ambil 5 aktivitas terakhir
        recent = history[-5:] if history else []
        
        formatted = []
        for activity in recent:
            formatted.append({
                "timestamp": activity.get("timestamp"),
                "action": activity.get("action", "Unknown"),
                "score": activity.get("score"),
                "description": self._format_activity_description(activity)
            })
        
        return list(reversed(formatted))  # Terbaru di atas
    
    def _format_activity_description(self, activity: Dict) -> str:
        """Format deskripsi aktivitas untuk UI"""
        action = activity.get("action", "")
        score = activity.get("score")
        
        if "submission" in action:
            return f"Submitted code with score {score}" if score else "Submitted code"
        elif "practice" in action:
            return "Practiced with AI tutor"
        elif "study_plan" in action:
            return "Updated study plan"
        else:
            return "Learning activity"
    
    def _get_achievements(self, student) -> List[Dict[str, Any]]:
        """Achievements yang sudah diraih"""
        achievements = []
        history = student.preferences.get("submission_history", [])
        scores = [h.get("score", 0) for h in history]
        
        # Achievement: First Submission
        if history:
            achievements.append({
                "name": "First Steps",
                "description": "Made your first submission",
                "icon": "",
                "date_earned": history[0].get("timestamp")
            })
        
        # Achievement: Perfect Score
        if any(score == 100 for score in scores):
            achievements.append({
                "name": "Perfect Score",
                "description": "Achieved 100/100 on a submission",
                "icon": "",
                "date_earned": "Recent"
            })
        
        # Achievement: Improvement
        if len(scores) >= 3 and scores[-1] > scores[0]:
            achievements.append({
                "name": "Getting Better",
                "description": "Improved your score over time",
                "icon": "",
                "date_earned": "Recent"
            })
        
        # Achievement: Consistent
        if len(history) >= 5:
            achievements.append({
                "name": "Consistent Learner",
                "description": "Completed 5+ learning sessions",
                "icon": "",
                "date_earned": "Recent"
            })
        
        return achievements
    
    def _get_study_plan_status(self, student) -> Dict[str, Any]:
        """Status study plan aktif"""
        plan = student.preferences.get("active_study_plan", None)
        
        if not plan:
            return {
                "has_active_plan": False,
                "message": "No active study plan. Create one to track your progress!"
            }
        
        tasks = plan.get("tasks", [])
        completed = sum(1 for t in tasks if t.get("completed", False))
        
        return {
            "has_active_plan": True,
            "plan_objectives": plan.get("objectives", []),
            "total_tasks": len(tasks),
            "completed_tasks": completed,
            "progress_percentage": round(completed / len(tasks) * 100, 1) if tasks else 0,
            "estimated_time_remaining": plan.get("total_minutes", 0)
        }
    
    def _get_learning_style(self, student) -> Dict[str, Any]:
        """Info learning style siswa"""
        style = student.preferences.get("learning_style", {})
        
        if not style:
            return {
                "detected": False,
                "message": "Complete more activities to detect your learning style"
            }
        
        return {
            "detected": True,
            "primary_style": style.get("primary", "unknown"),
            "confidence": style.get("confidence", 0),
            "description": self._get_learning_style_description(style.get("primary"))
        }
    
    def _get_learning_style_description(self, style: str) -> str:
        """Deskripsi learning style"""
        descriptions = {
            "visual": "You learn best with diagrams, charts, and visualizations",
            "textual": "You learn best by reading and writing",
            "auditory": "You learn best by listening and discussing",
            "kinesthetic": "You learn best by doing and practicing"
        }
        return descriptions.get(style, "Learning style not determined")
    
    def _get_personalized_recommendations(self, student, course) -> List[str]:
        """Rekomendasi personal untuk siswa"""
        recommendations = []
        mastery = student.mastery
        
        # Rekomendasi berdasarkan weak concepts
        weak = [kc for kc, score in mastery.items() if score < 0.6]
        if weak:
            recommendations.append(f"Focus on: {', '.join(weak[:2])}")
        
        # Rekomendasi berdasarkan activity
        history = student.preferences.get("session_history", [])
        if len(history) < 3:
            recommendations.append("Try to practice daily for better retention")
        
        # Rekomendasi berdasarkan progress
        avg_mastery = sum(mastery.values()) / len(mastery) if mastery else 0
        if avg_mastery < 0.4:
            recommendations.append("Review the basics before moving to advanced topics")
        elif avg_mastery > 0.8:
            recommendations.append("Great progress! Try some challenge problems")
        
        return recommendations
    
    def update_activity(self, user_id: str, course_id: str, 
                       action: str, score: Optional[int] = None):
        """Update aktivitas terbaru ke student model"""
        student = self.registry.get_student_model(user_id, course_id)
        
        if "session_history" not in student.preferences:
            student.preferences["session_history"] = []
        
        student.preferences["session_history"].append({
            "timestamp": datetime.now().isoformat(),
            "action": action,
            "score": score
        })
        
        # Update submission history jika ada score
        if score is not None:
            if "submission_history" not in student.preferences:
                student.preferences["submission_history"] = []
            
            student.preferences["submission_history"].append({
                "timestamp": datetime.now().isoformat(),
                "score": score
            })
        
        student.last_active = datetime.now().isoformat()
        self.registry.update_student_model(user_id, course_id, student)
    
    def generate_progress_report(self, user_id: str, course_id: str) -> str:
        """Generate laporan progress dalam format teks"""
        data = self.get_dashboard_data(user_id, course_id)
        
        report = []
        report.append("=" * 50)
        report.append("STUDENT PROGRESS REPORT")
        report.append("=" * 50)
        report.append(f"Student: {data['student_info']['user_id']}")
        report.append(f"Course: {data['student_info']['course_id']}")
        report.append(f"Status: {data['progress_overview']['status']}")
        report.append(f"Overall Mastery: {data['progress_overview']['overall_mastery']*100:.0f}%")
        report.append("")
        report.append("SUBMISSION STATISTICS:")
        stats = data['submission_stats']
        report.append(f"  Total Submissions: {stats['total_submissions']}")
        report.append(f"  Average Score: {stats['average_score']}")
        report.append(f"  Trend: {stats['improvement_trend']}")
        report.append("")
        report.append("WEAK CONCEPTS (Focus Areas):")
        for concept in data['weak_concepts']:
            report.append(f"  - {concept['concept']}: {concept['mastery']*100:.0f}% ({concept['priority']} priority)")
        report.append("")
        report.append("RECOMMENDATIONS:")
        for rec in data['recommendations']:
            report.append(f"  * {rec}")
        report.append("=" * 50)
        
        return "\n".join(report)
