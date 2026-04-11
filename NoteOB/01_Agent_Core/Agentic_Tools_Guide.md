# 🛠️ AMT-CS1 Agentic Tools Guide

This guide describes the specialized tools available in the `tools/` directory and how they can be leveraged to benefit both students and teachers in the Moodle CS1 environment.

---

## 👨‍🎓 For Students: Personalized Learning Path

### 1. [[Learning_Style_Agent]] (`learning_style_agent.py`)
- **Benefit**: Tailors the AI's explanation based on the student's behavior (Visual, Textual, Auditory, Kinesthetic).
- **How to use**: The agent suggests specific resources (e.g., flowcharts for Visual learners, recordings for Auditory learners) for difficult topics like Loops or Recursion.

### 2. [[Study_Planner]] (`study_planner.py`)
- **Benefit**: Automatically breaks down complex learning objectives into manageable **Micro-Learning Steps**.
- **How to use**: If a student fails an assignment on "Arrays", the planner creates a 5-step plan (Understand basics -> Analyze examples -> Guided practice -> Independent exercise -> Peer teaching).

### 3. [[Peer_Matching]] (`peer_matching.py`)
- **Benefit**: Facilitates collaborative learning.
- **How to use**: Matches students for "Peer Teaching" (pairing a master with a struggler) or "Study Partners" (pairing students at similar levels).

### 4. [[Student_Dashboard]] (`student_dashboard.py`)
- **Benefit**: Real-time progress tracking and "Learning DNA" visualization.
- **How to use**: Students can see their "Weak Concepts", "Improvement Trends", and "Achievements" directly in their Moodle dashboard.

---

## 👩‍🏫 For Teachers: Pedagogical Excellence

### 1. [[Teacher_Refinement]] (`teacher_refinement.py`)
- **Benefit**: Ensures assignments are calibrated to the correct difficulty and level of **Bloom's Taxonomy**.
- **How to use**: When creating a quiz, this tool checks if the questions align with "Apply" or "Analyze" levels and suggests refinements to verbs and complexity.

### 2. [[Quiz_Verifier]] (`quiz_verifier.py`)
- **Benefit**: Automated quality assurance for AI-generated questions.
- **How to use**: Checks for misconceptions, verifies that test cases cover edge cases, and ensures the problem statement is unambiguous.

---

## 🤖 The "Brain": [[Fusion_Agent]] (`fusion_agent.py`)

The **Fusion Agent** is the unifying layer. It "fuses" data from all the above tools to provide a single, coherent response. It ensures that if the Study Planner suggests a topic, the Learning Style agent delivers it in the right format.

---

> [!TIP]
> **Implementation Tip**: To activate a tool, include its specific action in the `amtcs1_entrypoint.py` list of supported actions or integrate it as a node in the LangGraph.

> [!NOTE]
> All student data used by these tools is strictly mapped via the `local_orch_stud_profile` table to ensure privacy and continuity.
