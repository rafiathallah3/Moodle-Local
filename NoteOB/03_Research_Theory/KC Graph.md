# MAS-KCL: Knowledge Component Graph Structure Learning

> [!info] Paper Info **Title:** MAS-KCL: Knowledge Component Graph Structure Learning with Large Language Model-Based Agentic Workflow **Journal:** The Visual Computer (2025) 41:6453–6464 **DOI:** https://doi.org/10.1007/s00371-025-03946-1 **Published:** 28 May 2025 **Authors:** Yuan-Hao Jiang · Kezong Tang · Zi-Wei Chen · Yuang Wei · Tian-Yi Liu · Jiayi Wu

---

## 🗂️ Tags

#ai-education #knowledge-graph #multi-agent-system #LLM #graph-structure-learning #differential-evolution #intelligent-tutoring

---

## 📌 Quick Summary

MAS-KCL is an algorithm that **automatically discovers how knowledge topics (KCs) depend on each other** by analyzing student performance data. It combines:

- **Differential Evolution (DE)** as the core optimization engine
- **Three LLM-powered AI agents** that dynamically guide the search
- A **bidirectional feedback mechanism** to promote good and suppress bad graph edges

The output is a **KC graph** — a directed acyclic graph (DAG) — that teachers can use to pinpoint _why_ a student struggles with a topic.

---

## 🧩 Key Concepts

### What is a Knowledge Component (KC)?

- The **fundamental unit of knowledge** in education
- Examples: addition, multiplication, fractions, etc.
- KCs have **prerequisite relationships** — some must be learned before others

### What is a KC Graph?

- A **Directed Acyclic Graph (DAG)** where:
    - **Nodes** = individual knowledge components
    - **Edges** = directed dependencies (A → B means "learn A before B")
- Represents a subject's complete **learning path structure**

### What is Graph Structure Learning?

- The task of **automatically inferring** which edges exist in the KC graph
- Done from learner response data (quiz scores, interaction logs)
- Harder than it sounds — the search space grows exponentially with the number of KCs

---

## 🔍 Problem Being Solved

> [!warning] The Core Problem Traditional KC graphs are either **manually designed** (expert-dependent, slow, costly) or built with **simple data-driven methods** (poor scalability, lack of interpretability). Neither scales well for real-world education systems.

**Limitations of prior approaches:**

- Bayesian Networks → parameter explosion with many KCs
- GNNs → lack interpretability, limited to static graphs
- RL-based methods → sparse feedback, complex reward design
- Expert-defined structures → costly, not data-driven

---

## 🏗️ System Architecture

### The 4-Phase Agentic Workflow

```
Phase 1 → Phase 2 → Phase 3 → Phase 4
Data      Optimize   Evaluate   Educational
Process   KC Graph   Feedback   Application
```

|Phase|Description|
|---|---|
|**Phase 1: Data Processing**|Collect real-world KC data from learners|
|**Phase 2: Optimizing KC Graph**|Iteratively build and refine candidate graphs via DE + agents|
|**Phase 3: Evaluation & Feedback**|Score graphs; agents adjust generation probabilities|
|**Phase 4: Educational Application**|Compare learner KC graph with subject KC graph; identify weak KCs|

---

## 🤖 The Three AI Agents

### 🎮 Game Agent

- Controls the **Ambient Pressure (AP)** parameter
- AP = proportion of elite individuals kept in next iteration
- **Higher AP** → more exploitation of known good solutions
- **Lower AP** → more exploration of new structures
- Reads loss change trend → outputs JSON decision → updates AP before next iteration

### 👍 Positive Feedback Agent (PFA)

- Identifies edges **associated with decreased loss** (good edges)
- **Promotes** those edges across the population
- Multiplies edge vectors by the **Positive Factor (PF)**
- Effect: increases the probability that good connections appear in future candidates

### 👎 Negative Feedback Agent (NFA)

- Identifies edges **associated with increased loss** (bad edges)
- **Suppresses** those edges across the population
- Multiplies edge vectors by the **Negative Factor (NF)**
- Effect: decreases the probability that bad connections appear in future candidates
- ⭐ Found to be the **most important agent** in ablation studies

> [!tip] Agent Communication All three agents communicate via **structured JSON outputs** through LLM API calls at the end of every iteration. This makes their decisions transparent and interpretable.

---

## ⚙️ Algorithm Deep Dive

### Differential Evolution (DE) Basics

- Population-based optimization technique
- Each **individual** = one candidate KC graph (binary vector: 1 = edge exists, 0 = no edge)
- Each iteration: generate offspring → score → keep better → discard worse

### Multi-Sub-Population Strategy

```
Total Population
├── Superior Sub-population  (AP × N)     → kept for next round
├── Exploratory Sub-population ((1-AP) × N) → refined by feedback agents
└── Elimination Sub-population (N)        → discarded
```

### Bidirectional Feedback Mechanism

1. Sort exploratory sub-population → split into PPFO (high-fitness) and PNFO (remaining)
2. Count new edges added compared to current population → `count_ones`
3. Apply **Positive Factor (PF)** to edges from PPFO → promote good edges
4. Apply **Negative Factor (NF)** to edges from PNFO → suppress bad edges
5. Modify exploratory individuals based on updated probabilities
6. Merge modified exploratory + superior → pass to next generation

### Pseudocode Summary (Algorithm 1)

```
Initialize random population
FE ← 0
WHILE FE < maxFE:
    Generate offspring via DE
    Sort offspring by loss
    Split into: Superior, Exploratory, Elimination
    Apply bidirectional feedback to Exploratory
    Merge Exploratory + Superior → next generation
    Re-rank all; eliminate worst
    AP ← Game Agent decision
    PF ← Positive Feedback Agent decision
    NF ← Negative Feedback Agent decision
    FE ← FE + N
RETURN best individual
```

### Complexity Analysis

|Type|Complexity|
|---|---|
|**Time**|O(n² - n + a)|
|**Space**|O(n² - n + cs)|

Where n = number of KCs. Growth is **quadratic** — manageable for moderate-sized KC sets.

---

## 📊 Datasets

### Synthetic Datasets (5)

|Dataset|Source|Purpose|
|---|---|---|
|LPR-GD1 to LPR-GD5|NeurIPS 2022 CausalML Challenge|Preliminary validation, rapid testing|

### Real-World Datasets (4)

|Dataset|Source|Scale (D)|
|---|---|---|
|LPR-RWD|Microsoft Research & Eedi|6670|
|LPR-RWD1|Microsoft Research & Eedi|1225|
|LPR-RWD2|Microsoft Research & Eedi|1225|
|MOOCCubeX-Math|XuetangX MOOC Platform|210|

> [!note] About MOOCCubeX-Math Extracted from 331,202 mathematics-related JSON records. Relatively sparse, making it harder for all algorithms to perform well.

---

## 📈 Results

### vs. Baseline Algorithms (Real-World Datasets)

|Algorithm|MOOCCubeX-Math|LPR-RWD|LPR-RWD1|LPR-RWD2|
|---|---|---|---|---|
|MSEA|7.37e-1|4.84e-1|4.13e-1|4.43e-1|
|GEO|6.46e-1|3.44e-1|2.72e-1|3.34e-1|
|EESHHO|2.57e-1|3.43e-1|2.71e-1|3.21e-1|
|AGE-MOEA-II|7.28e-1|4.81e-1|4.11e-1|4.42e-1|
|**MAS-KCL**|**2.51e-1**|**3.31e-1**|**2.23e-1**|**2.65e-1**|

✅ MAS-KCL achieves **best results on all datasets**

### vs. Best Baseline on Generated Datasets

|Dataset|MAS-KCL|Baseline|Δloss|
|---|---|---|---|
|LPR-GD1|26.99|32.81|↓ 5.82%|
|LPR-GD2|26.13|31.00|↓ 4.87%|
|LPR-GD3|27.30|35.01|↓ 7.71%|
|LPR-GD4|29.19|33.87|↓ 4.68%|
|LPR-GD5|28.85|33.30|↓ 4.45%|
|**Mean**|**27.69**|**33.20**|**↓ 5.51%**|

### LLM Comparison (Table 4)

|LLM|Mean Loss|SD|Notes|
|---|---|---|---|
|GPT-3.5|25.63|5.94|Best for small datasets|
|GPT-4.0|26.75|4.57|Best for large datasets|
|LLaMA-70B|29.46|3.43|Most stable (lowest SD)|
|Claude 3.7 Sonnet|34.50|9.95|Worst performance here|

> [!important] LLM Recommendation
> 
> - Small datasets → use **GPT-3.5**
> - Large datasets → use **GPT-4.0**
> - No GPT access → use **LLaMA-70B** (stable, decent performance)

---

## 🔬 Ablation Study

> What happens when you remove each component?

|Removed|Mean Loss|Δloss vs Full MAS-KCL|
|---|---|---|
|Game Agent (-GA)|2.96e-1|↑ 2.30%|
|Positive Feedback Agent (-PFA)|3.01e-1|↑ 2.80%|
|Negative Feedback Agent (-NFA)|3.03e-1|↑ 3.03%|
|Entire MAS (-MAS)|3.79e-1|↑ 10.63%|
|**Full MAS-KCL**|**2.73e-1**|—|

> [!important] Key Insight
> 
> - Every agent contributes meaningfully
> - **NFA is the most critical** individual agent
> - Removing the **entire MAS causes a 10.63% degradation** — the whole is much greater than the sum of its parts

---

## 📉 Convergence Analysis

- 30 independent runs per dataset
- Loss distributions are **tightly clustered** → high stability
- Median loss ranking: LPR-RWD > LPR-RWD2 > LPR-RWD1
- Dense distribution despite high dimensionality of LPR-RWD confirms **strong convergence**

---

## 🎓 Educational Application

### How Teachers Use the Output

```
Student takes quizzes
        ↓
MAS-KCL builds subject KC graph
        ↓
Compare learner's performance pattern with KC graph
        ↓
Identify weak KCs (root cause analysis)
        ↓
Teacher designs targeted instructional interventions
```

### Practical Example

> If a student struggles with **multiplication**, the KC graph may reveal the root cause is actually a weak understanding of **addition** — allowing the teacher to address the real problem, not just the symptom.

---

## 🔗 Connections to Related Work

|Area|Key Methods|MAS-KCL's Advantage|
|---|---|---|
|Bayesian Networks|Expert-defined priors|Data-driven, no expert needed|
|Dynamic BNs|Causal KC relations|Avoids parameter explosion|
|GNNs|Structural pattern learning|Adds interpretability + causal grounding|
|RL-based|Sequential graph construction|No complex reward design needed|
|E-PRISM|Interpretable parameters|More flexible, LLM-guided|

---

## ⚡ Strengths & Limitations

### ✅ Strengths

- Outperforms all baselines on all 9 datasets
- Interpretable — agents explain their decisions in natural language
- Flexible — works with different LLM backends
- Scalable — quadratic complexity, manageable for moderate KC sets
- Practical — directly applicable to real tutoring systems

### ⚠️ Limitations

- Requires **LLM API access** (cost + latency per iteration)
- Performance depends on **LLM quality** (GPT > LLaMA > Claude in this task)
- Primarily validated on **mathematics** datasets
- **Code not publicly released** in the paper
- Quadratic complexity may struggle with very large KC sets

---

## 💡 Moodle Integration Possibility

> [!tip] Feasibility Assessment **Partially feasible** with significant development effort.

### Possible Architecture

```
Moodle Gradebook/Quiz Data
        ↓ (Moodle REST API)
Data Preprocessing Script (Python)
        ↓
MAS-KCL Engine (Python)
        ↓ (LLM API calls)
GPT-4 / GPT-3.5
        ↓
KC Graph Output
        ↓
Teacher Dashboard / Moodle Competency Map
```

### What You'd Need

- [ ] Implement DE algorithm + 3 agents from Algorithm 1 pseudocode
- [ ] Set up LLM API access (OpenAI GPT recommended)
- [ ] Map Moodle quiz data → KC format
- [ ] Build visualization layer for the KC graph
- [ ] Optionally connect output back to Moodle Learning Plans

---

## 📚 Key References

- [[Liu et al. 2024]] — Prior work on learning path identification that MAS-KCL builds upon
- [[Piech et al. 2015]] — Deep Knowledge Tracing (foundational KC modeling)
- [[Käser et al. 2017]] — Dynamic Bayesian Networks for student modeling
- [[Allègre et al. 2023]] — E-PRISM: interpretable prerequisite relationships
- NeurIPS 2022 CausalML Challenge datasets → https://codalab.lisn.upsaclay.fr/competitions/5626

---

## 📝 Personal Notes

> [!question] Open Questions
> 
> - Can MAS-KCL generalize to non-math subjects (e.g., language, science)?
> - How does it perform with smaller student cohorts?
> - Is the KC graph stable across different student populations?
> - Could a fine-tuned open-source LLM replace GPT and reduce cost?

> [!todo] Next Steps
> 
> - [ ] Contact authors for code: tangkezong@jci.edu.cn / ziwei.cs@foxmail.com
> - [ ] Access NeurIPS CausalML datasets for local testing
> - [ ] Prototype DE algorithm in Python
> - [ ] Explore Moodle Web Services API for data extraction
