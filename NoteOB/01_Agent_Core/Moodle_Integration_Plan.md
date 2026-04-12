# AMT-CS1 Agents to Moodle Integration Plan

This document outlines the step-by-step plan for seamlessly integrating the Python-based AI agent logic (`agents/` and `logic_scratch/`) directly into the Moodle PHP environment (`local/orchestrator`).

## The Goal
Currently, Moodle uses its built-in API `\core_ai\manager` to communicate with standard AI models. The goal is to bypass this generic layer and call our custom Python LangGraph engine (`admin/cli/amtcs1_entrypoint.py`), allowing complex agentic features (like Analyzer, Critic, and Memory Updater) to operate natively inside Moodle.

## Integration Architecture: The "Bridge Pattern"

Moodle PHP will act as the orchestrator of data collection, whilst Python will act as the orchestrator of logic.

1. **Moodle (PHP) collects context**: Fetch user history, assignment details, and the student's submission.
2. **State Serialization**: PHP encodes this context into a JSON payload formatted strictly to match the `AgenticState` (defined in `logic_scratch/state.py`).
3. **Execution**: PHP uses `proc_open` to execute `python admin/cli/amtcs1_entrypoint.py --stdin`.
4. **Data Stream**: The JSON payload is passed via standard input (STDIN).
5. **Response parsing**: Python executes the LangGraph flow and prints a final JSON payload to standard output (STDOUT). PHP parses this JSON.
6. **Moodle Persistence**: PHP logs the interaction and updates the student’s memory profile (`local_orch_stud_profile`).

---

## Step-by-Step Implementation Steps

### Phase 1: Establish the Python Execution Bridge
We need a robust mechanism in PHP to execute Python scripts locally and handle I/O securely.

- [ ] **Action:** Create a new PHP class `local_orchestrator\python_runner` (or append to an existing utility class).
- [ ] **Implementation Details:**
  - Create a static method: `public static function run_agentic_flow(array $state_payload): ?array`.
  - Use `proc_open()` to spawn `python $CFG->dirroot/admin/cli/amtcs1_entrypoint.py --stdin`.
  - Encode `$state_payload` using `json_encode()` and write it to STDIN.
  - Read STDOUT and parse the resulting JSON. Handle errors (STDERR outputs or invalid JSON) gracefully.

### Phase 2: Refactor Event Observers (`observer.php`)
Background events like quiz or assignment submissions currently use the `core_ai` wrapper. We must switch them to our Python runner.

- [ ] **Action:** Modify `local/orchestrator/classes/event/observer.php`.
- [ ] **Implementation Details:**
  - Locate `process_orchestrator(...)`.
  - Remove the `$action = new \core_ai\aiactions\generate_text(...)` block.
  - Build the correct `AgenticState` dictionary. We need to map `$student_profile`, `$task_context`, and `$student_submission` to the keys expected by Python (e.g., `student_name`, `course_id`, `kc_targets`, `raw_input`, `assessment_type`).
  - Call the new `python_runner::run_agentic_flow($evidence_array)`.
  - Ensure the resulting DB inserts into `local_orchestrator_log` use the fields returned by Python (such as `agents_called`, `policy_decision`, etc.).

### Phase 3: Refactor Sync Execution (`orchestrator_ajax.php` & `critic.php`)
Direct AJAX calls or internal evaluation pipelines need to use the bridge.

- [ ] **Action:** Modify `public/local/orchestrator_ajax.php`.
- [ ] **Implementation Details:**
  - Rather than concatenating `SKILL.md` strings with incoming evidence, parse the incoming evidence into the standard `AgenticState`.
  - Execute the runner and return the JSON response directly back to the JavaScript frontend.
- [ ] **Action:** Modify `local/orchestrator/classes/critic.php` (if it's used independently from the main graph).
  - Update `evaluate_candidate` to execute via the Python script or fully migrate the Critic logic into the Graph (preferred). Note that `graph_engine.py` already includes `ReflectiveCritic`, so isolated calls may become obsolete.

### Phase 4: State Schema Synchronization
The hardest part of this integration is ensuring PHP provides exactly what Python expects.

- [ ] **Action:** Review and align the structure of `$evidence` built in PHP with `AgenticState` in `logic_scratch/state.py`.
- [ ] **Key Fields to Verify:**
  - `role`: (student or teacher)
  - `mode`: (`submission_review` or `student_chat`)
  - `raw_input` / `normalized_input`: The student's text.
  - `grading_score`, `kc_targets`, `expected_concepts`: Ensure Moodle properly queries and attaches assignment grading criteria before sending it to Python.
  - `memory_updates`: Verify that the format Python returns is exactly what `local_orchestrator\memory::update_profile()` expects.

---

## Maintenance & Debugging Notes
- Moodle's web server (e.g., Apache/Wamp) must have the correct permissions to execute the `python` executable.
- Set the exact path to Python in Moodle's config.php (`$CFG->pathtopython`) if standard `$PATH` resolution fails under WAMP.
- Monitor `local_orchestrator_log` database table during early testing to catch JSON schema mismatches.
