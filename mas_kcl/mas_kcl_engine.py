import random
from typing import List, Tuple, Dict, Any
from logic_scratch.utils import load_json, save_json, now_iso

def all_possible_edges(kcs: List[str]) -> List[Tuple[str,str]]:
    edges = []
    for i in range(len(kcs)):
        for j in range(i+1, len(kcs)):
            edges.append((kcs[i], kcs[j]))
    return edges

def decode_edges(edge_list: List[Tuple[str,str]], vec: List[int]) -> List[Tuple[str,str]]:
    return [e for bit, e in zip(vec, edge_list) if bit == 1]

def fitness(vec: List[int], edge_list: List[Tuple[str,str]], events: List[Dict[str,Any]]) -> float:
    """
    Simple MAS-KCL-lite fitness using current mastery snapshot from students.json.
    (Next improvement: store mastery_snapshot in events, but not required for smoothing patch.)
    """
    students = load_json("students.json")
    score = 0.0
    total = 0.0

    for ev in events:
        kc_targets = ev.get("kc_targets", [])
        succ = int(ev.get("success", 0))
        sid = ev.get("student_id","")
        mastery = (students.get(sid, {}).get("mastery_by_kc") or {})

        for bit, (a,b) in zip(vec, edge_list):
            if bit == 0:
                continue
            if b not in kc_targets:
                continue
            total += 1.0
            ma = float(mastery.get(a, 0.5))
            if succ == 0 and ma < 0.55:
                score += 1.0
            if succ == 1 and ma >= 0.65:
                score += 0.4

    if total == 0:
        return 0.0
    return score / total

def de_binary_offspring(pop: List[List[int]], F: float = 0.5, CR: float = 0.6) -> List[List[int]]:
    """
    Differential Evolution style for binary vectors.
    """
    N = len(pop)
    D = len(pop[0])
    offspring = []
    for i in range(N):
        idxs = [j for j in range(N) if j != i]
        r1, r2, r3 = random.sample(idxs, 3)
        x = pop[i]
        a, b, c = pop[r1], pop[r2], pop[r3]
        mutant = [a[d] ^ (b[d] ^ c[d]) if random.random() < F else a[d] for d in range(D)]
        trial = [mutant[d] if random.random() < CR else x[d] for d in range(D)]
        offspring.append(trial)
    return offspring

def mas_kcl_run_for_course(
    course_id: str,
    min_events: int = 10,
    pop_size: int = 24,
    iters: int = 25,
    ap: float = 0.4,
    pf: float = 0.6,
    nf: float = 0.4,
    seed: int = 7
):
    """
    MAS-KCL-lite with:
    - population + DE offspring
    - superior/exploratory split by AP
    - bidirectional feedback using PF/NF
    - deterministic AP adjustment by stagnation
    - confidence smoothing (Laplace)
    """
    random.seed(seed)

    courses = load_json("courses.json")
    kc_graph_db = load_json("kc_graph_db.json")
    events_all = load_json("learning_events.json")
    events = [e for e in events_all if e.get("course_id") == course_id]

    if len(events) < min_events:
        return {"ok": False, "reason": f"Not enough events ({len(events)}/{min_events})", "course_id": course_id}

    kc_set = (courses.get(course_id, {}) or {}).get("kc_set", [])
    if len(kc_set) < 2:
        return {"ok": False, "reason": "KC set too small", "course_id": course_id}

    edge_list = all_possible_edges(kc_set)
    D = len(edge_list)

    pop = [[1 if random.random() < 0.2 else 0 for _ in range(D)] for _ in range(pop_size)]
    best_vec = None
    best_fit = -1e9
    hist = []
    stagnation = 0

    for it in range(iters):
        fits = [fitness(v, edge_list, events) for v in pop]
        ranked = sorted(list(zip(pop, fits)), key=lambda x: x[1], reverse=True)

        cur_best = ranked[0][1]
        if cur_best > best_fit + 1e-9:
            best_fit = cur_best
            best_vec = ranked[0][0][:]
            stagnation = 0
        else:
            stagnation += 1

        sup_n = max(1, int(ap * pop_size))
        superior = [x[0] for x in ranked[:sup_n]]
        exploratory = [x[0] for x in ranked[sup_n:]]

        # DE offspring
        off = de_binary_offspring(pop, F=0.5, CR=0.6)
        off_fits = [fitness(v, edge_list, events) for v in off]
        off_ranked = sorted(list(zip(off, off_fits)), key=lambda x: x[1], reverse=True)

        pop = [x[0] for x in ranked[:pop_size//2]] + [x[0] for x in off_ranked[:pop_size - pop_size//2]]

        # bidirectional feedback based on superior edge frequency
        edge_counts = [0]*D
        for v in superior:
            for i,b in enumerate(v):
                edge_counts[i] += b

        new_expl = []
        for v in exploratory:
            nv = v[:]
            for i in range(D):
                freq = edge_counts[i] / max(1, len(superior))
                p_on = freq * pf
                p_off = (1 - freq) * nf
                r = random.random()
                if r < p_on:
                    nv[i] = 1
                elif r < (p_on + p_off):
                    nv[i] = 0
            new_expl.append(nv)

        pop = (superior + new_expl)[:pop_size]

        # deterministic "game agent" AP adjust
        if stagnation >= 3:
            ap = max(0.2, ap - 0.05)
        else:
            ap = min(0.6, ap + 0.01)

        hist.append({"iter": it, "best_fit": round(best_fit, 4), "ap": round(ap, 2)})

    best_edges = decode_edges(edge_list, best_vec)

    # Confidence smoothing via Laplace: (count+1)/(n+2)
    fits = [fitness(v, edge_list, events) for v in pop]
    ranked = sorted(list(zip(pop, fits)), key=lambda x: x[1], reverse=True)
    sup_n = max(1, int(ap * pop_size))
    superior = [x[0] for x in ranked[:sup_n]]

    edge_counts = [0]*D
    for v in superior:
        for i,b in enumerate(v):
            edge_counts[i] += b

    conf = {}
    n = max(1, len(superior))
    for i, (a,b) in enumerate(edge_list):
        if best_vec[i] == 1:
            # Laplace smoothing
            conf[f"{a}->{b}"] = round((edge_counts[i] + 1) / (n + 2), 2)

    kc_graph_db[course_id] = {
        "edges": [list(e) for e in best_edges],
        "confidence": conf,
        "last_updated": now_iso()
    }
    save_json("kc_graph_db.json", kc_graph_db)

    return {"ok": True, "course_id": course_id, "best_fit": round(best_fit, 4), "edges": best_edges, "history": hist}

def mas_kcl_trigger(course_id: str, mode: str = "threshold", threshold_n: int = 30):
    events = load_json("learning_events.json")
    n = sum(1 for e in events if e.get("course_id")==course_id)
    if mode == "threshold" and n < threshold_n:
        return {"ok": False, "reason": f"Below threshold {n}/{threshold_n}", "course_id": course_id}
    return mas_kcl_run_for_course(course_id)
