
"""
MAS-KCL: Differential Evolution untuk KC Graph Optimization
Dari paper: "MAS-KCL: knowledge component graph structure learning"
Algoritma: DE/rand/1/bin classic (Storn & Price, 1997)
"""
import random
import copy
from typing import List, Dict, Tuple, Callable
from dataclasses import dataclass

@dataclass
class DEParams:
    population_size: int = 50
    mutation_factor: float = 0.5  # F
    crossover_rate: float = 0.7   # CR
    max_generations: int = 100
    ambient_pressure: float = 0.4  # AP dari paper

@dataclass
class KCGraphCandidate:
    """Chromosome: difficulty values untuk setiap KC"""
    chromosome: Dict[str, float]  # {kc_name: difficulty_score}
    fitness: float = 0.0
    is_valid: bool = True
    
    def copy(self):
        return KCGraphCandidate(
            chromosome=copy.deepcopy(self.chromosome),
            fitness=self.fitness,
            is_valid=self.is_valid
        )

class DifferentialEvolution:
    """DE/rand/1/bin untuk optimize KC graph difficulty"""
    
    def __init__(self, params: DEParams = None):
        self.params = params or DEParams()
        self.population: List[KCGraphCandidate] = []
        self.fitness_history: List[float] = []
    
    def optimize(self, 
                 kc_names: List[str], 
                 fitness_func: Callable[[Dict[str, float]], float],
                 initial_difficulty: Dict[str, float] = None) -> KCGraphCandidate:
        """
        Optimize difficulty values untuk KC graph
        
        Args:
            kc_names: List of knowledge component names
            fitness_func: Function yang menerima chromosome dan return fitness (higher=better)
            initial_difficulty: Initial values (optional)
        """
        # Initialize population
        self._initialize_population(kc_names, initial_difficulty)
        
        # Evaluate initial population
        for ind in self.population:
            ind.fitness = fitness_func(ind.chromosome)
        
        best = max(self.population, key=lambda x: x.fitness)
        
        # Evolution loop
        for generation in range(self.params.max_generations):
            new_population = []
            
            for i, target in enumerate(self.population):
                # DE/rand/1/bin mutation
                mutant = self._mutate(i)
                
                # Binomial crossover
                trial = self._crossover(target, mutant)
                
                # Evaluate trial
                trial.fitness = fitness_func(trial.chromosome)
                
                # Selection (greedy)
                if trial.fitness >= target.fitness:
                    new_population.append(trial)
                else:
                    new_population.append(target)
            
            self.population = new_population
            current_best = max(self.population, key=lambda x: x.fitness)
            
            if current_best.fitness > best.fitness:
                best = current_best.copy()
            
            self.fitness_history.append(best.fitness)
            
            if generation % 20 == 0:
                print(f"[DE] Gen {generation}: Best Fitness = {best.fitness:.4f}")
        
        return best
    
    def _initialize_population(self, kc_names: List[str], initial: Dict[str, float] = None):
        """Initialize dengan random values 0-1 atau dari initial"""
        self.population = []
        
        for _ in range(self.params.population_size):
            chromosome = {}
            for kc in kc_names:
                if initial and kc in initial:
                    # Perturbasi dari initial
                    base = initial[kc]
                    chromosome[kc] = max(0.0, min(1.0, base + random.gauss(0, 0.1)))
                else:
                    chromosome[kc] = random.random()
            
            self.population.append(KCGraphCandidate(chromosome=chromosome))
    
    def _mutate(self, target_idx: int) -> KCGraphCandidate:
        """DE/rand/1 mutation: v = x_r1 + F * (x_r2 - x_r3)"""
        # Pilih 3 random indices yang berbeda dari target
        candidates = list(range(self.params.population_size))
        candidates.remove(target_idx)
        r1, r2, r3 = random.sample(candidates, 3)
        
        x_r1 = self.population[r1].chromosome
        x_r2 = self.population[r2].chromosome
        x_r3 = self.population[r3].chromosome
        
        mutant_chromosome = {}
        for kc in x_r1.keys():
            # v = x_r1 + F * (x_r2 - x_r3)
            value = x_r1[kc] + self.params.mutation_factor * (x_r2[kc] - x_r3[kc])
            mutant_chromosome[kc] = max(0.0, min(1.0, value))  # Clamp 0-1
        
        return KCGraphCandidate(chromosome=mutant_chromosome)
    
    def _crossover(self, target: KCGraphCandidate, mutant: KCGraphCandidate) -> KCGraphCandidate:
        """Binomial crossover"""
        trial_chromosome = {}
        kcs = list(target.chromosome.keys())
        
        # Ensure at least one from mutant (j DE constraint)
        j_rand = random.randint(0, len(kcs) - 1)
        
        for i, kc in enumerate(kcs):
            if random.random() < self.params.crossover_rate or i == j_rand:
                trial_chromosome[kc] = mutant.chromosome[kc]
            else:
                trial_chromosome[kc] = target.chromosome[kc]
        
        return KCGraphCandidate(chromosome=trial_chromosome)

# ==================== BIDIRECTIONAL FEEDBACK (dari paper) ====================

class BidirectionalFeedback:
    """Positive & Negative Feedback Agents untuk adjust probabilities"""
    
    def __init__(self):
        self.positive_factor = 0.6  # PF
        self.negative_factor = 0.4  # NF
    
    def apply_feedback(self, 
                       population: List[KCGraphCandidate],
                       best_chromosome: Dict[str, float]) -> List[KCGraphCandidate]:
        """
        Adjust population berdasarkan comparison dengan best individual
        Positive feedback: promote edges yang ada di best
        Negative feedback: suppress edges yang tidak ada di best
        """
        new_pop = []
        
        for ind in population:
            new_chrom = {}
            for kc, value in ind.chromosome.items():
                best_val = best_chromosome.get(kc, 0.5)
                
                # Jika value mendekati best, promote (positive feedback)
                # Jika value jauh dari best, suppress (negative feedback)
                diff = abs(value - best_val)
                
                if diff < 0.2:  # Close to best (good)
                    # Positive feedback: amplify
                    adjustment = (best_val - value) * self.positive_factor
                else:  # Far from best (bad)
                    # Negative feedback: dampen
                    adjustment = (best_val - value) * self.negative_factor
                
                new_chrom[kc] = max(0.0, min(1.0, value + adjustment))
            
            new_ind = KCGraphCandidate(chromosome=new_chrom, fitness=ind.fitness)
            new_pop.append(new_ind)
        
        return new_pop
