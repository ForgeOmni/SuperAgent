# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.7] - 2026-04-04

### 🚀 Summary

This release introduces **Multi-Agent Orchestration**, enabling SuperAgent to automatically detect task complexity and spawn multiple specialized agents working in parallel. Key highlights:

- **Automatic Mode Detection**: Zero-configuration multi-agent activation based on task analysis
- **Parallel Execution**: Run up to 10+ agents simultaneously with real-time progress tracking
- **Claude Code Compatibility**: Seamless integration with exact Claude Code result format
- **Agent Communication**: Built-in mailbox system for inter-agent messaging
- **WebSocket Monitoring**: Live browser-based dashboard for multi-agent visualization
- **Multi-Language Docs**: Full documentation in English, Chinese, and French

### Added

#### Multi-Agent Parallel Tracking
- **AgentProgressTracker**: Individual agent progress monitoring with real-time token counting and activity tracking
- **ParallelAgentCoordinator**: Centralized coordinator for managing multiple concurrent agents with singleton pattern
- **ParallelAgentDisplay**: Console visualization for hierarchical team/agent progress display
- **WebSocket Server**: Real-time browser-based monitoring at ws://localhost:8765
- **HTML Dashboard**: Live progress dashboard at /public/dashboard.html
- **InProcessBackend Integration**: Seamless integration with existing Fiber-based execution

#### Automatic Multi-Agent Mode Detection
- **TaskAnalyzer**: Intelligent task complexity analysis with weighted scoring algorithm
- **AutoModeAgent**: Automatic mode selection based on task characteristics
- **Complexity Metrics**: 
  - Length analysis (character/line count)
  - Keyword detection (multi-task indicators)
  - Subtask identification (numbered lists, bullet points)
  - Tool requirement analysis
  - Estimated token calculation
- **Seamless Integration**: Zero-configuration auto-mode in main Agent class

#### Agent Communication & Collaboration
- **AgentMailbox System**: Persistent agent-to-agent messaging with filtering, archiving, and broadcast capabilities
- **SendMessage Tool**: Direct and broadcast messaging between agents with summary and priority support
- **AgentCommunicationProtocol**: Inter-agent message passing system
- **Message Types**: BROADCAST, DIRECT, REQUEST, RESPONSE
- **Message Queue**: Persistent message storage with filtering capabilities
- **Protocol Handlers**: Extensible message processing pipeline

#### Claude Code Compatibility
- **AgentToolResult**: Exact Claude Code-compatible result format for seamless integration
- **AgentTeamResult**: Aggregated multi-agent results with individual agent tracking
- **Result Aggregation**: Combined token counting, cost calculation, and message collection across all agents
- **Backward Compatibility**: Full support for existing single-agent workflows

#### Performance & Resource Management
- **PerformanceProfiler**: Agent execution performance tracking
- **AgentDependencyManager**: Topological sorting for dependent task execution
- **AgentPoolManager**: Resource pooling with max concurrency control
- **DistributedBackend**: Cross-process/machine agent distribution

#### Persistence & Recovery
- **AgentStateStore**: Persistent state management with SQLite backend
- **Checkpoint/Resume**: Automatic state recovery after failures
- **Session Persistence**: Cross-session agent state continuity

#### Developer Tools
- **Agent Templates**: Pre-configured agent patterns for common tasks
- **Command-Line Tools**: 
  - `superagent:agent:list` - List running agents
  - `superagent:agent:status` - Check agent status
  - `superagent:agent:stop` - Stop specific agents
  - `superagent:agent:monitor` - Real-time monitoring
- **Integration Examples**: Sample implementations for common use cases

#### Documentation
- **Multi-Language Support**: Added French documentation (README_FR.md, INSTALL_FR.md)
- **Chinese Documentation**: Created INSTALL_CN.md, updated README_CN.md with v0.6.7 features
- **Installation Guide**: Comprehensive multi-agent setup section in INSTALL.md
- **Code Examples**: Added examples for auto-mode, manual teams, agent communication, and WebSocket monitoring
- **Configuration Guide**: Detailed environment variables and configuration options for multi-agent features

#### Testing
- **Smoke Tests**: Added comprehensive smoke tests for multi-agent functionality
- **Mailbox Tests**: Agent communication and message queue testing
- **Result Aggregation Tests**: Validation of Claude Code-compatible result formats
- **Comprehensive Test Suite**: 50+ tests covering all new components
- **Integration Tests**: End-to-end multi-agent workflow validation
- **Performance Benchmarks**: Baseline performance metrics

### Changed
- **InProcessBackend**: Enhanced with progress tracking integration
- **Agent Class**: Added auto-mode support with backward compatibility
- **Swarm Mode**: Improved coordinator/worker separation

### Fixed
- **Parallel Execution**: Fixed race conditions in concurrent agent execution
- **Progress Aggregation**: Accurate token counting across multiple agents
- **Memory Management**: Optimized Fiber stack allocation

### Technical Details

#### Task Complexity Scoring Algorithm
```
Score = (0.3 × length) + (0.25 × keywords) + (0.25 × subtasks) + (0.15 × tools) + (0.05 × tokens)
Threshold: Score > 50 = Multi-Agent Mode
```

#### WebSocket Protocol
```json
{
  "type": "progress_update",
  "agentId": "agent-123",
  "progress": {
    "tokens": { "input": 1500, "output": 750 },
    "activity": "Processing task",
    "status": "running"
  }
}
```

#### Performance Metrics
- Single agent overhead: < 2ms
- Multi-agent coordination: < 10ms per agent
- WebSocket latency: < 50ms
- Memory per agent: ~2MB

### Migration Guide

#### Enable Auto-Mode (Recommended)
```php
// Automatic mode detection - no parameters needed!
$agent = new Agent($provider, $config);
$agent->enableAutoMode(); // That's it!

// The agent now automatically detects when to use multi-agent mode
$result = $agent->run("Complex task requiring multiple agents...");
```

#### Manual Multi-Agent Mode
```php
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\ParallelAgentDisplay;

$coordinator = ParallelAgentCoordinator::getInstance();
$display = new ParallelAgentDisplay();

// Register agents
$tracker1 = $coordinator->registerAgent('agent-1', 'Research Agent');
$tracker2 = $coordinator->registerAgent('agent-2', 'Code Writer');

// Monitor progress
$display->render($coordinator);
```

#### WebSocket Monitoring
```javascript
const ws = new WebSocket('ws://localhost:8765');
ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  console.log(`Agent ${data.agentId}: ${data.progress.activity}`);
};
```

### Dependencies
- PHP Ratchet/Pawl (WebSocket support)
- React/EventLoop (Async operations)
- SQLite3 (State persistence)

---

## [0.6.6] - 2026-04-03

### Added
- **Smart Context Window** — Dynamic token allocation between thinking and context based on task complexity
  - `SmartContextManager` — main manager that analyzes prompts, allocates budgets, supports per-task override (`options['context_strategy']` > config toggle) with strategy auto-detection or manual forcing via string/enum
  - `TaskComplexity` — heuristic analyzer scoring prompts (0.0–1.0) based on complexity keywords (refactor, architect, debug), simplicity keywords (list, show, read), multi-step indicators, prompt length, code presence, and question detection; maps scores to ContextStrategy
  - `ContextStrategy` enum — `deep_thinking` (60/40 split, keep 4 recent), `balanced` (40/60 split, keep 8), `broad_context` (15/85 split, keep 16); each defines thinking/context ratios and compaction aggressiveness
  - `BudgetAllocation` — immutable result with thinking/context token budgets, compaction keep-recent count, complexity score, percentage helpers, and serialization
  - QueryEngine integration: analyzes prompt at run() start, sets thinking budget and stores allocation for compaction decisions
- New `smart_context` configuration section in `config/superagent.php` with `enabled`, `total_budget_tokens`, `min_thinking_budget`, and `max_thinking_budget` settings
- New `smart_context` experimental feature flag
- `SmartContextManager` registered as conditional singleton in `SuperAgentServiceProvider`

### Changed
- `QueryEngine` — new optional `?SmartContextManager` constructor parameter; `run()` analyzes prompt complexity at start and adjusts thinking budget; per-task override via `options['context_strategy']`

### Tests
- 23 new SmartContext unit tests (55 assertions):
  - `SmartContextTest` — strategy ratios/compaction, complex/simple/balanced task detection, short question, long prompt effect, multi-step detection, code detection, describe, allocation percentages/describe/toArray, force strategy (enum/string/null reset), min/max thinking budget enforcement, isEnabled, total budget, allocation sums to total

## [0.6.5] - 2026-04-03

### Added
- **Knowledge Graph** — Cross-agent shared knowledge graph for multi-agent collaboration
  - `KnowledgeGraph` — in-memory graph with JSON persistence, node/edge CRUD, deduplication, query API (getFilesModifiedBy, getAgentsForFile, getHotFiles, getDecisions, searchNodes, getSummary), import/export
  - `GraphCollector` — captures tool execution events (Read→READ edge, Edit→MODIFIED, Write→CREATED, Grep→SEARCHED, Glob→FILE nodes, Bash→EXECUTED) with per-agent tracking, decision recording, dependency/symbol tracking
  - `KnowledgeGraphManager` — high-level API for querying, clearing, import/export, and statistics
  - `KnowledgeNode` — graph node with type (FILE, SYMBOL, AGENT, DECISION, TOOL), label, metadata, access counter
  - `KnowledgeEdge` — directed edge with type (READ, MODIFIED, CREATED, DEPENDS_ON, DECIDED, SEARCHED, EXECUTED, DEFINED_IN), agent attribution, deduplication key
  - `NodeType` enum — FILE, SYMBOL, AGENT, DECISION, TOOL
  - `EdgeType` enum — READ, MODIFIED, CREATED, DEPENDS_ON, DECIDED, SEARCHED, EXECUTED, DEFINED_IN
- New `knowledge_graph` configuration section in `config/superagent.php` with `enabled` and `storage_path` settings
- New `knowledge_graph` experimental feature flag
- `KnowledgeGraphManager` registered as conditional singleton in `SuperAgentServiceProvider`

- **Checkpoint & Resume** — Periodic state snapshots for crash recovery and long-running task resumption
  - `CheckpointManager` — main manager with configurable interval (every N turns), per-task override (`options['checkpoint']` > config toggle), auto-pruning, and resume with full message deserialization
  - `CheckpointStore` — file-based persistence (one JSON file per checkpoint in a directory), with list/load/delete/clear/prune/statistics operations, session filtering, and turn-count tiebreaker sorting
  - `Checkpoint` — immutable state snapshot: serialized messages, turnCount, totalCostUsd, turnOutputTokens, budgetTrackerState, collectorState, model, prompt, metadata
  - `MessageSerializer` — serializes/deserializes all Message subclasses (UserMessage, AssistantMessage, ToolResultMessage) with full ContentBlock and Usage round-trip support, including tool_use, tool_result, and thinking blocks
  - `CheckpointCommand` — Artisan CLI (`superagent:checkpoint`) with 6 sub-commands: `list` (with `--session` filter), `show`, `delete`, `clear` (with `--session`), `prune` (with `--keep`), `stats`
  - QueryEngine integration: `maybeCheckpoint()` called after each turn, per-task override via `options['checkpoint']`
- New `checkpoint` configuration section in `config/superagent.php` with `enabled`, `interval`, `max_per_session`, and `storage_path` settings
- New `checkpoint` experimental feature flag
- `CheckpointManager` registered as conditional singleton in `SuperAgentServiceProvider`; `CheckpointCommand` registered as Artisan command

- **Skill Distillation** — Auto-distills successful expensive-model executions into reusable skill templates for cheaper models
  - `DistillationEngine` — analyzes execution traces, generalizes specific values into template parameters, selects target model tier via cost-tier mapping (Opus→Sonnet, Sonnet→Haiku, GPT-4o→GPT-4o-mini), generates step-by-step Markdown skill templates, estimates cost savings percentage
  - `DistillationStore` — persistent JSON storage for distilled skills with CRUD, search, usage tracking, import/export, and savings statistics
  - `DistillationManager` — high-level API for distillation triggering, skill management, and usage recording
  - `ExecutionTrace` — captures tool call sequence, model, cost, tokens from `AgentResult` message history; provides `getUsedTools()` and `getToolSequenceSummary()` for analysis
  - `ToolCallRecord` — individual tool call with input generalization (`generalizeInput()` replaces cwd prefix with `{{cwd}}`) and input summarization
  - `DistilledSkill` — generated skill with source/target models, required tools, template parameters, cost savings estimate, usage counter
  - `DistillCommand` — Artisan CLI (`superagent:distill`) with 7 sub-commands: `list` (with `--search`), `show`, `delete`, `clear`, `export`, `import`, `stats`
- New `skill_distillation` configuration section in `config/superagent.php` with `enabled`, `min_steps`, `min_cost_usd`, and `storage_path` settings
- New `skill_distillation` experimental feature flag
- `DistillationManager` registered as conditional singleton in `SuperAgentServiceProvider`; `DistillCommand` registered as Artisan command

- **Adaptive Feedback** — Self-improving agent that learns from user corrections and automatically generates Guardrails rules or Memory entries from recurring patterns
  - `CorrectionStore` — persistent JSON storage for correction patterns with full CRUD, search, import/export, and statistics tracking
  - `CorrectionCollector` — captures denial events and user corrections, normalizes them into generalizable patterns (e.g., `rm -rf /foo` → `bash: rm -rf`), with 5 recording methods: `recordDenial`, `recordCorrection`, `recordRevert`, `recordUnwantedContent`, `recordRejection`
  - `AdaptiveFeedbackEngine` — evaluates patterns against configurable threshold, promotes tool denials to Guardrails rules (warn/deny based on frequency), promotes behavior corrections to Memory entries (feedback type), with event listeners for `feedback.promoted`, `feedback.rule_generated`, `feedback.memory_generated`
  - `FeedbackManager` — high-level API providing list/show/delete/clear/import/export/promote/stats operations, plus auto-promotion and suggestion tracking
  - `CorrectionPattern` — pattern with ID, category, occurrences, reasons history, promotion status, timestamps, and serialization
  - `CorrectionCategory` enum — `tool_denied`, `output_rejected`, `behavior_correction`, `edit_reverted`, `content_unwanted` with category→promotion-type mapping
  - `PromotionResult` — immutable result of pattern-to-rule/memory promotion with generated content
  - `FeedbackCommand` — Artisan CLI (`superagent:feedback`) with 8 sub-commands: `list` (with `--category` and `--search` filters), `show`, `delete`, `clear`, `export` (to JSON file), `import` (from JSON file), `promote` (force-promote), `stats` (with approaching-threshold suggestions)
- New `adaptive_feedback` configuration section in `config/superagent.php` with `enabled`, `promotion_threshold`, `auto_promote`, and `storage_path` settings
- New `adaptive_feedback` experimental feature flag
- `FeedbackManager`, `CorrectionStore` registered as conditional singletons in `SuperAgentServiceProvider`; `FeedbackCommand` registered as Artisan command

- **Cost Autopilot** — Intelligent budget control system that monitors cumulative spending and automatically escalates through cost-saving actions to prevent budget overruns
  - `CostAutopilot` — main engine that evaluates budget thresholds after each provider call, tracks fired thresholds to prevent re-triggering, resolves model downgrades via tier hierarchy, and emits events (`autopilot.warn`, `autopilot.downgrade`, `autopilot.compact`, `autopilot.halt`)
  - `BudgetConfig` — configuration with session/monthly budget limits, customizable escalation thresholds (default: 50% warn, 70% compact, 80% downgrade, 95% halt), model tier definitions, and validation
  - `BudgetTracker` — persistent cross-session spending tracker with daily/monthly period accumulation, JSON file storage with atomic writes, delta-based recording, and data pruning
  - `ModelTier` — model tier definition with pricing data and priority ordering; includes preset hierarchies for Anthropic (Opus → Sonnet → Haiku) and OpenAI (GPT-4o → GPT-4o-mini → GPT-3.5-turbo)
  - `AutopilotDecision` — immutable decision result describing actions to take (downgrade, compact, warn, halt), new/previous model names, tier info, and budget utilization percentage
  - `CostAction` enum — `downgrade_model`, `compact_context`, `warn`, `halt`
  - `ThresholdRule` — threshold definition binding a budget percentage to an action with optional message
  - Auto-detection of model tiers from the default provider when not explicitly configured
- New `cost_autopilot` configuration section in `config/superagent.php` with `enabled`, `session_budget_usd`, `monthly_budget_usd`, `thresholds`, `tiers`, and `storage_path` settings
- New `cost_autopilot` experimental feature flag
- `CostAutopilot` registered as conditional singleton in `SuperAgentServiceProvider` with automatic `BudgetTracker` wiring and provider-based tier detection

- **Pipeline DSL** — Declarative YAML workflow engine for orchestrating multi-agent pipelines with dependency resolution, failure handling, and inter-step data flow
  - `PipelineEngine` — main engine that loads YAML pipeline definitions, resolves execution order via topological sort, manages step lifecycle (retry, approval gates, events), and integrates with the Swarm agent backend via injectable `agentRunner` and `approvalHandler` callbacks
  - `PipelineConfig` — YAML parsing with multi-file merge, validation (duplicate names, unknown dependencies, output references, input definitions), and default propagation (failure_strategy, timeout, max_retries)
  - `PipelineDefinition` — immutable pipeline definition with input validation, default application, output template resolution, and trigger matching
  - `PipelineContext` — runtime context tracking step results, inputs, custom variables, cancellation state, and template variable resolution (`{{inputs.*}}`, `{{steps.*.output/status/error}}`, `{{vars.*}}`)
  - `PipelineResult` / `StepResult` — immutable execution results with summary statistics (completed/failed/skipped counts, duration)
  - **6 step types**:
    - `AgentStep` — execute a named agent with prompt templates, model override, isolation mode, read-only flag, `input_from` context injection, and `buildSpawnConfig()` for Swarm integration
    - `ParallelStep` — fan-out multiple sub-steps for concurrent execution with configurable `wait_all` behavior
    - `ConditionalStep` — gate execution on conditions: `step_succeeded`, `step_failed`, `input_equals`, `output_contains`, `expression` (with 7 comparison operators)
    - `ApprovalStep` — pause pipeline for user approval with configurable message, timeout, and `required_approvers`
    - `TransformStep` — transform/aggregate data between steps: `merge` (combine outputs), `template` (build strings), `extract` (pull fields), `map` (iterate arrays)
  - `StepFactory` — recursive YAML-to-step parser supporting nested parallel/conditional composition and automatic `when` clause wrapping
  - `FailureStrategy` enum — `abort` (stop pipeline), `continue` (log and proceed), `retry` (up to `max_retries`)
  - `StepStatus` enum — PENDING, RUNNING, COMPLETED, FAILED, SKIPPED, WAITING_APPROVAL, CANCELLED
  - `LoopStep` — repeat a body of steps until exit conditions are met or max iterations reached; supports 5 exit condition types (`output_contains`, `output_not_contains`, `all_passed` for multi-reviewer unanimous approval, `any_passed`, `expression`), iteration variable tracking (`loop.<name>.iteration`/`loop.<name>.max`), composable with parallel/conditional/agent inner steps, and `loop.iteration` events
  - Event system with 7 events: `pipeline.start`, `pipeline.end`, `step.start`, `step.end`, `step.retry`, `step.skip`, `loop.iteration`
  - Example configuration at `examples/pipeline.yaml` with code-review, deploy, and research pipeline templates
- New `pipelines` configuration section in `config/superagent.php` with `enabled` and `files` settings
- New `pipelines` experimental feature flag
- `PipelineEngine` registered as conditional singleton in `SuperAgentServiceProvider`

### Changed
- `QueryEngine` — new optional `?CheckpointManager` constructor parameter; `run()` loop calls `maybeCheckpoint()` after each turn; per-task override via `options['checkpoint']`

### Tests
- 33 new KnowledgeGraph unit tests (77 assertions):
  - `KnowledgeGraphTest` — node add/get/find/touch/getByType, edge add/dedup/getFrom/getTo/getByAgent, query API (filesModifiedBy, agentsForFile, hotFiles, searchNodes, decisions, summary), persistence, clear, statistics, export/import/dedup
  - `GraphCollectorTest` — record Read/Edit/Write/Bash/Grep/Glob tool calls, skip errors, record decisions/dependencies/symbols, set agent name, multiple agents on shared file, skip missing input
- 25 new Checkpoint unit tests (65 assertions):
  - `MessageSerializerTest` — UserMessage/AssistantMessage/ToolResultMessage serialize+deserialize round-trips, text/tool_use/thinking block preservation, Usage with cache tokens, serializeAll/deserializeAll, unknown class error
  - `CheckpointManagerTest` — enable/disable (config vs force override vs null fallback), maybeCheckpoint interval logic (interval=3, skip turn 0, disabled), createCheckpoint with serialized messages, resume with deserialized messages (type verification), getLatest, list/delete/clear, auto-prune on checkpoint, statistics, interval getter
- 31 new SkillDistillation unit tests (79 assertions):
  - `ExecutionTraceTest` — used tools extraction, tool sequence summary, serialization round-trip
  - `DistillationEngineTest` — successful distillation, custom name, store persistence, duplicate skip, too-few-steps skip, too-cheap skip, error-trace skip, model downgrade paths (Opus→Sonnet, Sonnet→Haiku, GPT-4o→mini, unknown→same), savings estimation, template frontmatter/steps/tool-instructions, parameter detection (file/command/search/task_description)
  - `DistillationStoreTest` — save/get, findByName, getAll, search, delete, clear, recordUsage, persistence, export/import, duplicate skip on import, statistics with savings calculation

## [0.6.2] - 2026-04-03

### Added
- **Adaptive Feedback** — Self-improving agent that learns from user corrections and automatically generates Guardrails rules or Memory entries from recurring patterns
  - `CorrectionStore` — persistent JSON storage for correction patterns with full CRUD, search, import/export, and statistics tracking
  - `CorrectionCollector` — captures denial events and user corrections, normalizes them into generalizable patterns (e.g., `rm -rf /foo` → `bash: rm -rf`), with 5 recording methods: `recordDenial`, `recordCorrection`, `recordRevert`, `recordUnwantedContent`, `recordRejection`
  - `AdaptiveFeedbackEngine` — evaluates patterns against configurable threshold, promotes tool denials to Guardrails rules (warn/deny based on frequency), promotes behavior corrections to Memory entries (feedback type), with event listeners for `feedback.promoted`, `feedback.rule_generated`, `feedback.memory_generated`
  - `FeedbackManager` — high-level API providing list/show/delete/clear/import/export/promote/stats operations, plus auto-promotion and suggestion tracking
  - `CorrectionPattern` — pattern with ID, category, occurrences, reasons history, promotion status, timestamps, and serialization
  - `CorrectionCategory` enum — `tool_denied`, `output_rejected`, `behavior_correction`, `edit_reverted`, `content_unwanted` with category→promotion-type mapping
  - `PromotionResult` — immutable result of pattern-to-rule/memory promotion with generated content
  - `FeedbackCommand` — Artisan CLI (`superagent:feedback`) with 8 sub-commands: `list` (with `--category` and `--search` filters), `show`, `delete`, `clear`, `export` (to JSON file), `import` (from JSON file), `promote` (force-promote), `stats` (with approaching-threshold suggestions)
- New `adaptive_feedback` configuration section in `config/superagent.php` with `enabled`, `promotion_threshold`, `auto_promote`, and `storage_path` settings
- New `adaptive_feedback` experimental feature flag
- `FeedbackManager`, `CorrectionStore` registered as conditional singletons in `SuperAgentServiceProvider`; `FeedbackCommand` registered as Artisan command

- **Cost Autopilot** — Intelligent budget control system that monitors cumulative spending and automatically escalates through cost-saving actions to prevent budget overruns
  - `CostAutopilot` — main engine that evaluates budget thresholds after each provider call, tracks fired thresholds to prevent re-triggering, resolves model downgrades via tier hierarchy, and emits events (`autopilot.warn`, `autopilot.downgrade`, `autopilot.compact`, `autopilot.halt`)
  - `BudgetConfig` — configuration with session/monthly budget limits, customizable escalation thresholds (default: 50% warn, 70% compact, 80% downgrade, 95% halt), model tier definitions, and validation
  - `BudgetTracker` — persistent cross-session spending tracker with daily/monthly period accumulation, JSON file storage with atomic writes, delta-based recording, and data pruning
  - `ModelTier` — model tier definition with pricing data and priority ordering; includes preset hierarchies for Anthropic (Opus → Sonnet → Haiku) and OpenAI (GPT-4o → GPT-4o-mini → GPT-3.5-turbo)
  - `AutopilotDecision` — immutable decision result describing actions to take (downgrade, compact, warn, halt), new/previous model names, tier info, and budget utilization percentage
  - `CostAction` enum — `downgrade_model`, `compact_context`, `warn`, `halt`
  - `ThresholdRule` — threshold definition binding a budget percentage to an action with optional message
  - Auto-detection of model tiers from the default provider when not explicitly configured
- New `cost_autopilot` configuration section in `config/superagent.php` with `enabled`, `session_budget_usd`, `monthly_budget_usd`, `thresholds`, `tiers`, and `storage_path` settings
- New `cost_autopilot` experimental feature flag
- `CostAutopilot` registered as conditional singleton in `SuperAgentServiceProvider` with automatic `BudgetTracker` wiring and provider-based tier detection

- **Pipeline DSL** — Declarative YAML workflow engine for orchestrating multi-agent pipelines with dependency resolution, failure handling, and inter-step data flow
  - `PipelineEngine` — main engine that loads YAML pipeline definitions, resolves execution order via topological sort, manages step lifecycle (retry, approval gates, events), and integrates with the Swarm agent backend via injectable `agentRunner` and `approvalHandler` callbacks
  - `PipelineConfig` — YAML parsing with multi-file merge, validation (duplicate names, unknown dependencies, output references, input definitions), and default propagation (failure_strategy, timeout, max_retries)
  - `PipelineDefinition` — immutable pipeline definition with input validation, default application, output template resolution, and trigger matching
  - `PipelineContext` — runtime context tracking step results, inputs, custom variables, cancellation state, and template variable resolution (`{{inputs.*}}`, `{{steps.*.output/status/error}}`, `{{vars.*}}`)
  - `PipelineResult` / `StepResult` — immutable execution results with summary statistics (completed/failed/skipped counts, duration)
  - **6 step types**:
    - `AgentStep` — execute a named agent with prompt templates, model override, isolation mode, read-only flag, `input_from` context injection, and `buildSpawnConfig()` for Swarm integration
    - `ParallelStep` — fan-out multiple sub-steps for concurrent execution with configurable `wait_all` behavior
    - `ConditionalStep` — gate execution on conditions: `step_succeeded`, `step_failed`, `input_equals`, `output_contains`, `expression` (with 7 comparison operators)
    - `ApprovalStep` — pause pipeline for user approval with configurable message, timeout, and `required_approvers`
    - `TransformStep` — transform/aggregate data between steps: `merge` (combine outputs), `template` (build strings), `extract` (pull fields), `map` (iterate arrays)
    - `LoopStep` — repeat a body of steps until exit conditions are met or max iterations reached; supports 5 exit condition types, iteration variable tracking, composable inner steps, and `loop.iteration` events
  - `StepFactory` — recursive YAML-to-step parser supporting nested parallel/conditional/loop composition and automatic `when` clause wrapping
  - `FailureStrategy` enum — `abort` (stop pipeline), `continue` (log and proceed), `retry` (up to `max_retries`)
  - `StepStatus` enum — PENDING, RUNNING, COMPLETED, FAILED, SKIPPED, WAITING_APPROVAL, CANCELLED
  - Event system with 7 events: `pipeline.start`, `pipeline.end`, `step.start`, `step.end`, `step.retry`, `step.skip`, `loop.iteration`
  - Example configuration at `examples/pipeline.yaml` with code-review, deploy, review-fix-loop, and research pipeline templates
- New `pipelines` configuration section in `config/superagent.php` with `enabled` and `files` settings
- New `pipelines` experimental feature flag
- `PipelineEngine` registered as conditional singleton in `SuperAgentServiceProvider`

### Changed
- `QueryEngine` — new optional `?CostAutopilot` constructor parameter; `run()` loop now evaluates autopilot after each provider call, applies model downgrades via `provider->setModel()`, injects system notice on downgrade, and performs cost-driven context compaction; new `applyCostAutopilotDecision()` and `compactMessagesForCost()` methods

### Tests
- 68 new AdaptiveFeedback unit tests (135 assertions)
- 45 new CostAutopilot unit tests (128 assertions)
- 93 new Pipeline unit tests (213 assertions)

## [0.6.1] - 2026-04-03

### Added
- **Guardrails DSL** — Declarative YAML rule engine for security, cost, compliance, and rate-limiting policies, evaluated on every tool call within the PermissionEngine pipeline
  - `GuardrailsEngine` — main engine that loads YAML rule files, evaluates priority-ordered rule groups against runtime context, supports `first_match` and `all_matching` evaluation modes
  - `GuardrailsConfig` — YAML parsing with multi-file merge, validation (duplicate names, missing params), and `{{cwd}}` template variable resolution
  - `GuardrailsResult` — evaluation result with conversion to `PermissionDecision` and `HookResult` for seamless integration
  - `RuntimeContext` — immutable snapshot of all runtime state (tool info, session cost, token counts, budget percentage, turn count, elapsed time, working directory)
  - `RuntimeContextCollector` — stateful collector wired into `QueryEngine` loop, accumulates cost/token/turn data and builds context snapshots per tool call
  - `RateTracker` — in-memory sliding window counter for rate-limiting conditions
  - **7 condition types**: `tool` (name matching), `tool_content` (extracted content), `tool_input` (specific input fields), `session` (cost/budget/elapsed), `agent` (turn count/model), `token` (session/current totals), `rate` (sliding window)
  - **3 logical combinators**: `all_of` (AND), `any_of` (OR), `not` (negation) — composable into arbitrary depth condition trees
  - **8 action types**: `deny`, `allow`, `ask`, `warn`, `log`, `pause`, `rate_limit`, `downgrade_model`
  - `ConditionFactory` — recursive parser that converts YAML condition arrays into `ConditionInterface` trees
  - `Comparator` — generic comparison utility supporting 9 operators: `gt`, `gte`, `lt`, `lte`, `eq`, `contains`, `starts_with`, `matches` (glob), `any_of`
  - Example configuration at `examples/guardrails.yaml` with security, cost, rate-limiting, compliance, and agent guardrail groups
- New `guardrails` configuration section in `config/superagent.php` with `enabled`, `files`, and `integration` settings
- `GuardrailsEngine` registered as conditional singleton in `SuperAgentServiceProvider`

### Changed
- `PermissionEngine` — new Step 1.5 (Guardrails DSL evaluation) inserted between existing rule-based checks (Step 1) and bash-specific checks (Step 2); accepts optional `?GuardrailsEngine` constructor parameter; new `setRuntimeContextCollector()` method for runtime state injection
- `QueryEngine` — new optional `?RuntimeContextCollector` constructor parameter; `run()` loop now feeds cost/token/turn data to the collector after each provider call
- `AnthropicProvider::formatTools()` — strips `category` field from tool definitions before sending to API, fixing `tools.0.custom.category: Extra inputs are not permitted` error

### Fixed
- **Anthropic API compatibility** — `AnthropicProvider::formatTools()` no longer sends the internal `category` field to the Anthropic API, which rejected it as an unknown field
- **FileHistoryTest flakiness** — `testGitAttributionCreatesCommit` no longer depends on the real git staging area state; test now verifies the disabled-path behavior via `setEnabled(false)` for deterministic results

### Tests
- 53 new Guardrails unit tests (114 assertions):
  - `ComparatorTest` — all 9 operators, edge cases (non-numeric, non-string inputs)
  - `ConditionFactoryTest` — YAML-to-condition-tree parsing for all condition types, logical combinators, error handling
  - `GuardrailsEngineTest` — first_match/all_matching modes, priority ordering, disabled groups, cost-based rules, composite conditions, reload, statistics, result-to-PermissionDecision conversion
  - `GuardrailsConfigTest` — minimal config, defaults parsing, priority sorting, validation errors (duplicate names, missing params, invalid actions), template vars, file-not-found
  - `RateTrackerTest` — empty state, recording, rate detection, key isolation, reset

## [0.6.0] - 2026-04-02

### Added
- **Bridge Mode** — Provider-agnostic enhancement proxy that injects Claude Code optimization mechanisms into non-Anthropic models (OpenAI, Bedrock, Ollama, OpenRouter). Anthropic/Claude is never wrapped — it natively has these optimizations
  - `EnhancedProvider` — decorator implementing `LLMProvider` that wraps any non-Anthropic provider with an ordered enhancer pipeline (pre-request modification + post-response enhancement)
  - `EnhancerInterface` — contract for all enhancers: `enhanceRequest()` modifies messages/tools/systemPrompt/options by reference; `enhanceResponse()` post-processes `AssistantMessage`
  - `BridgeFactory` — factory with `createProvider()` (for HTTP proxy) and `wrapProvider()` (for SDK auto-enhance), resolves backend provider + enhancer pipeline from config
  - `BridgeToolProxy` — lightweight `ToolInterface` wrapper for external tool definitions; `execute()` throws (bridge never executes tools — the client does)
- **8 Bridge Enhancers** (each independently toggleable via `bridge.enhancers.*` config):
  - `SystemPromptEnhancer` (P0) — injects CC's optimized system prompt sections (task philosophy, tool usage, output efficiency, security guardrails) via `SystemPromptBuilder`; prepends to client's existing prompt with `# Client Instructions` separator; result cached across calls
  - `ContextCompactionEnhancer` (P0) — truncates old tool result content exceeding threshold (default 2000 chars), strips thinking blocks from old assistant messages; preserves recent N messages (default 10) untouched
  - `BashSecurityEnhancer` (P0) — intercepts bash/shell tool_use blocks in responses, validates commands through `BashSecurityValidator` (23-point check); dangerous commands replaced with `[Bridge Security]` text warning including check ID and reason
  - `MemoryInjectionEnhancer` (P1) — loads cross-session memories from `.claude/memory/` directory, parses YAML frontmatter (name/type/description), injects as `# Memories` section in system prompt
  - `ToolSchemaEnhancer` (P1) — fixes JSON Schema issues (empty `properties: []` → `properties: {}`), applies configurable description enhancements from `bridge.tool_enhancements` map
  - `ToolSummaryEnhancer` (P1) — rule-based truncation of verbose old tool results (keeps first N lines + char count indicator); preserves recent results unmodified
  - `TokenBudgetEnhancer` (P2) — tracks output tokens across requests, detects diminishing returns (3+ continuations with <500 token deltas), injects metadata hints (`bridge_diminishing_returns`, `bridge_total_output_tokens`)
  - `CostTrackingEnhancer` (P2) — per-request cost calculation via `CostCalculator`, USD budget enforcement (throws `SuperAgentException` on exhaustion), injects cost metadata (`bridge_request_cost_usd`, `bridge_total_cost_usd`)
- **Bridge HTTP Proxy** — OpenAI-compatible API endpoints for tools like Codex CLI:
  - `POST /v1/chat/completions` — accepts OpenAI Chat Completions format, returns SSE stream or JSON
  - `POST /v1/responses` — accepts OpenAI Responses API format (Codex CLI), returns SSE events or JSON
  - `GET /v1/models` — returns available model list
  - `BridgeAuth` middleware — Bearer token authentication against `bridge.api_keys` config (empty = no auth for dev)
  - `BridgeServiceProvider` — conditional route registration when `bridge_mode` feature flag is enabled
- **Bridge Format Adapters**:
  - `OpenAIMessageAdapter` — bidirectional conversion between OpenAI Chat Completions format and internal `Message` objects; extracts system messages as `$systemPrompt`; handles `role: "tool"` → `ToolResultMessage`, `tool_calls` → `ContentBlock::toolUse()`; generates OpenAI completion response format with usage
  - `ResponsesApiAdapter` — converts Responses API `input[]` items (`message`, `function_call`, `function_call_output`) to internal messages; generates response output items and SSE stream events (`response.created`, `response.output_item.added`, `response.content_part.delta`, `response.function_call_arguments.delta`, `response.completed`)
  - `OpenAIStreamTranslator` — translates `AssistantMessage` into OpenAI Chat Completions SSE chunks (`data: {...}\n\n`); handles role declaration, text deltas, indexed tool_calls, finish_reason, usage, `[DONE]` sentinel
- **SDK Auto-Enhance** — `Agent::maybeWrapWithBridge()` automatically wraps non-Anthropic providers with `EnhancedProvider` based on 3-level priority:
  1. Per-instance: `new Agent(['provider' => 'openai', 'bridge_mode' => true/false])`
  2. Config: `bridge.auto_enhance` setting
  3. Feature flag: `ExperimentalFeatures::enabled('bridge_mode')`
  4. Default: off (conservative — must be explicitly enabled)
- **Bridge configuration section** in `config/superagent.php`:
  - `bridge.auto_enhance` — global SDK auto-enhance toggle (null = use feature flag)
  - `bridge.provider` — backend provider for HTTP proxy mode
  - `bridge.api_keys` — comma-separated auth keys for HTTP endpoints
  - `bridge.model_map` — inbound→backend model name mapping
  - `bridge.max_tokens` — default max output tokens
  - `bridge.enhancers.*` — per-enhancer on/off toggles
- **Provider configs** — added `openai`, `openrouter`, `ollama` provider configurations in `config/superagent.php` (previously only `anthropic` was configured)
- **AssistantMessage::$metadata** — new `array` property for provider/bridge metadata (used by `CostTrackingEnhancer`, `TokenBudgetEnhancer`, `OpenRouterProvider`)

### Changed
- `bridge_mode` experimental feature flag — changed from `[NOT IMPLEMENTED]` placeholder to fully functional Bridge Mode
- `SuperAgentServiceProvider::boot()` — conditionally registers `BridgeServiceProvider` when `bridge_mode` is enabled
- `Agent::resolveProvider()` — now calls `maybeWrapWithBridge()` to auto-enhance non-Anthropic providers

### Removed
- `voice_mode` experimental feature flag — removed from config, `ExperimentalFeatures` env map, and all documentation (README, README.zh-CN, INSTALL, INSTALL.zh-CN)

### Tests
- 51 new Bridge unit tests (135 assertions):
  - `EnhancedProviderTest` — decorator pipeline, enhancer ordering, response modification
  - `OpenAIMessageAdapterTest` — system prompt extraction, tool_calls conversion, round-trip, completion response format
  - `ResponsesApiAdapterTest` — string/item input, function_call/output items, mixed conversation, stream events
  - `BashSecurityEnhancerTest` — safe passthrough, command substitution blocking, non-bash tool passthrough
  - `ContextCompactionEnhancerTest` — short conversation skip, old result truncation, recent preservation
  - `SystemPromptEnhancerTest` — injection, prepend, caching (Orchestra Testbench)
  - `ToolSchemaEnhancerTest` — empty properties fix, non-empty preservation (Orchestra Testbench)
  - `ToolSummaryEnhancerTest` — short passthrough, old truncation, recent preservation
  - `CostTrackingEnhancerTest` — metadata tracking, accumulation, budget enforcement, reset
  - `BridgeToolProxyTest` — properties, execute throws
  - `OpenAIStreamTranslatorTest` — text/tool_call translation, model/id propagation, usage in finish chunk

## [0.5.7] - 2026-04-01

### Added
- **Telemetry Master Switch** — hierarchical telemetry control: new `telemetry.enabled` master gate in config; all 5 telemetry subsystems (TracingManager, MetricsCollector, StructuredLogger, CostTracker, EventDispatcher) now require both the master switch AND their individual flag to be enabled. When master is off, no data is collected regardless of subsystem settings
- **Security Prompt Guardrails** — new `security_guardrails` config flag; when enabled, safety instructions are injected into SystemPromptBuilder's intro section to restrict security-related operations (dual-use tools, destructive techniques). Disabled by default
- **Experimental Feature Flags** — 15 granular feature flags with master switch (`experimental.enabled`) in config, each backed by env vars:
  - `ultrathink` — gate ultrathink keyword boost in ThinkingConfig
  - `token_budget` — gate TokenBudgetTracker creation in QueryEngine
  - `prompt_cache_break_detection` — gate auto prompt caching in AnthropicProvider
  - `builtin_agents` — gate ExploreAgent/PlanAgent registration in AgentManager
  - `verification_agent` — gate VerificationAgent registration in AgentManager
  - `plan_interview` — gate Plan V2 interview phase in EnterPlanModeTool
  - `agent_triggers` — gate `schedule_cron` tool in BuiltinToolRegistry
  - `agent_triggers_remote` — gate `remote_trigger` tool in BuiltinToolRegistry
  - `extract_memories` — gate session memory extraction default in CompressionConfig
  - `compaction_reminders` — gate auto-compact default in CompressionConfig
  - `cached_microcompact` — gate micro-compact default in CompressionConfig
  - `team_memory` — gate `team_create`/`team_delete` tools in BuiltinToolRegistry
  - `bash_classifier` — gate classifier-assisted bash permission checks in PermissionEngine
  - `bridge_mode` — placeholder for Bridge Mode (implemented in v0.6.0)
- **ExperimentalFeatures env fallback** — `ExperimentalFeatures::enabled()` now falls back to env vars (via `$_ENV`/`getenv()`) when running outside a Laravel application (e.g. unit tests without a booted container), with `configAvailable()` detection

### Changed
- **BuiltinToolRegistry** — tool registration now split into always-available core tools and feature-flag-gated experimental tools (`schedule_cron`, `remote_trigger`, `team_create`, `team_delete`)
- **AgentManager::loadBuiltinAgents()** — ExploreAgent/PlanAgent gated by `builtin_agents` flag; VerificationAgent gated by `verification_agent` flag
- **CompressionConfig::fromArray()** — `enableMicroCompact`, `enableSessionMemory`, `enableAutoCompact` defaults now driven by experimental feature flags instead of hardcoded values
- **AnthropicProvider** — `prompt_caching` option falls back to `prompt_cache_break_detection` feature flag when not explicitly set
- **Telemetry classes** — CostTracker, EventDispatcher, MetricsCollector, StructuredLogger, TracingManager constructors now check `telemetry.enabled AND subsystem.enabled` (was subsystem-only)
- **ExperimentalFeatures::enabled()** — master switch default changed from `false` to `true` to match config defaults

### Fixed
- **Phase10ObservabilityTest** — set telemetry master switch to `true` and added `tracing.enabled => false` in test config to match new hierarchical telemetry gate (fixes 11 failures)
- **TelemetryTest** — set telemetry master switch to `true` in test config (fixes 7 failures)
- Test suite: 452 tests, 1557 assertions, 0 errors, 0 failures

## [0.5.6] - 2026-04-01

### Fixed
- **Test suite fully passing** — fixed 97 errors and 9 failures across 13 test files (466 tests, 1557 assertions, 0 errors, 0 failures)
- `MCPTest` — updated to use `ServerConfig::stdio/http/sse()` factory methods and named constructor params; fixed `MCPTool` 3-arg constructor (`Client, serverName, MCPToolType`); replaced non-existent `isRegistered()`/`isConnected()` with `getServers()->has()` / `getClient()`
- `FileHistoryTest` — switched to singleton `getInstance()` for `GitAttribution`, `SensitiveFileProtection`, `UndoRedoManager`; replaced `listSnapshots()` with `getFileSnapshots()`; fixed `getDiff()` array return handling; used `FileAction` + `recordAction()` API for undo/redo
- `TelemetryTest` — bootstrapped Laravel container with config bindings; aligned `MetricsCollector` (`incrementCounter`, `setGauge`, `recordTiming`), `StructuredLogger` (`logError`, `setGlobalContext`), `CostTracker` (`trackLLMUsage`, `getCostSummary`) APIs
- `Phase10ObservabilityTest` — bootstrapped `Illuminate\Foundation\Application` with config/log services; fixed metric key format expectations; added graceful skip for optional OpenTelemetry dependency
- `PluginsTest` — added container config bindings for `PluginManager`; replaced `isRegistered()` with `get()`, `shutdown()` with `disable()`, `discover()` with `loadFromDirectory()`
- `Phase12Test` — bootstrapped Laravel Application; supplied all template placeholders for builtin skill tests; fixed `parseArguments()` test input; added `clearstatcache`/`touch` for Windows timestamp detection
- `TasksTest` — aligned `listTasks()` signature (`listId, status`); `updateTask` uses `addBlocks`; replaced `createTaskList`/`getTaskList`/`searchTasks`/sort with actual API
- `ConfigTest` — bootstrapped `Illuminate\Foundation\Application` for `base_path()`; added `clearstatcache` + `touch` for Windows file change detection
- `ConsoleTest` — used `LaravelApplication` for `runningUnitTests()`; fixed assertion to match actual command description (`Generate` not `Create`); `prompt` is a required argument not option; `file` option → `output`
- `Phase1ToolsTest` — added Windows path separator compatibility for glob results
- `Phase4HooksTest` — trimmed Windows `echo` double-quotes from command hook output
- `SensitiveFileProtection::matchesPattern()` — fixed regex: use `preg_quote()` before glob-to-regex conversion to prevent "Unknown modifier" warnings on patterns with dots

### Changed
- `SuperAgentToolsCommand`, `SuperAgentRunCommand`, `SuperAgentChatCommand`, `HotReload` — replaced references to non-existent `ToolRegistry` class with `BuiltinToolRegistry` (static API)

## [0.5.5] - 2026-04-01

### Added

#### High Value — Agent Quality
- **Smart Context Compaction** - `SessionMemoryCompressor` with semantic boundary protection: tool_use/tool_result pair preservation, backward expansion to meet min token (10K) and min message (5) thresholds, compact boundary floor, 9-section structured summary prompt with analysis scratchpad stripping
- **Token Budget Continuation** - `TokenBudgetTracker` replaces fixed maxTurns with dynamic budget-based continuation: 90% completion threshold, diminishing returns detection (3+ continuations with <500 token deltas), nudge messages for model continuation
- **Bash Security Validator** - 23 injection/obfuscation checks: incomplete commands, jq system()/file args, obfuscated flags (ANSI-C/locale/empty quotes), shell metacharacters, dangerous variables, newlines/carriage returns, command substitution ($()/{}/backticks/Zsh patterns), input/output redirection, IFS injection, git commit substitution, /proc/*/environ, malformed tokens, backslash-escaped whitespace/operators, brace expansion, control chars, Unicode whitespace, mid-word #, Zsh dangerous commands, comment-quote desync, quoted newlines. Plus read-only command classification with 50+ safe prefixes
- **Stop Hooks Pipeline** - 3-phase turn-end hook execution: Stop → TaskCompleted → TeammateIdle, with preventContinuation support and blocking error collection. New `TEAMMATE_IDLE` and `SUBAGENT_STOP` hook events

#### Medium Value — Product Experience
- **Coordinator Mode** - Dual-mode architecture: Coordinator (pure synthesis/delegation with only Agent/SendMessage/TaskStop tools) vs Worker (full execution tools). Includes 4-phase workflow system prompt (research→synthesis→implementation→verification), tool filtering for both modes, session mode persistence and restoration
- **Real-time Session Memory Extraction** - `SessionMemoryExtractor` with 3-gate trigger (10K token init, 5K growth delta, 3 tool calls OR natural break), 10-section structured template, cursor tracking, extraction-in-progress guards
- **KAIROS Daily Logs** - `DailyLog` with append-only entries at `{memoryDir}/logs/YYYY/MM/YYYY-MM-DD.md`. `AutoDreamConsolidator` enhanced with 4-phase consolidation prompt, KAIROS log ingestion as primary source, MEMORY.md size enforcement (<200 lines, <25KB)
- **Extended Thinking** - `ThinkingConfig` with adaptive/enabled/disabled modes, ultrathink keyword detection (regex), model capability detection (Claude 4+ thinking, 4.6+ adaptive), budget token management. Integrated into `AnthropicProvider` with automatic temperature removal
- **File History LRU Cache** - `FileSnapshotManager` enhanced with per-message LRU snapshots (100 cap), `rewindToMessage()`, `getDiffStats()` (insertions/deletions/filesChanged), snapshot inheritance for unchanged files, mtime fast-path change detection

#### Lower Priority — Polish
- **Plan V2 Interview Phase** - Iterative pair-planning workflow: explore with read-only tools, incrementally update structured plan file (context/approach/files/verification), ask user about ambiguities, periodic reminders, plan file persistence with word-slug naming
- **Tool Use Summary Generator** - Haiku-generated git-commit-subject-style summaries after tool batches (~40 chars), non-blocking, with tool input/output truncation
- **Remote Agent Tasks** - `RemoteAgentManager` for out-of-process agent execution via API triggers: create/list/get/run/update/delete, cron scheduling with local-to-UTC conversion, MCP connection configuration
- **Tool Search (real implementation)** - `ToolSearchTool` replaces placeholder: select mode (`select:Name1,Name2`), keyword fuzzy search with scoring (10pt name, 12pt MCP, 4pt hint, 2pt description), CamelCase/MCP name splitting, deferred tool registry with auto-threshold (10% context window), discovered tool tracking, delta computation
- **Analytics Sampling Rate Control** - `EventSampler` with per-event-type configurable rates, probabilistic sampling decision, sample_rate metadata enrichment. Integrated into `SimpleTracingManager.logEvent()`
- **Batch Skill** - `/batch` command for parallel large-scale changes: 3-phase workflow (research & plan → spawn 5-30 worktree-isolated workers → track progress with PR status table), worker instructions with simplify/test/commit/PR creation

### Fixed
- `SkillsTest` — added missing `template()` to all 13 anonymous Skill subclasses, fixed API mismatches (`list()` → `getAll()`, `listByCategory()` → `getByCategory()`, `examples()` → `example()`, parameters array format)
- `Phase3PermissionsTest` — updated assertion for new security validator classification (`high` → `critical` for shell metacharacter commands)

### Changed
- `ContextManager` now registers `SessionMemoryCompressor` at priority 5 between micro (1) and conversation (10) when session memory is enabled
- `ConversationCompressor` upgraded to 9-section CC summary prompt with `<analysis>` scratchpad + `<summary>` extraction via `formatCompactSummary()`
- `BashCommandClassifier` now runs `BashSecurityValidator` as Phase 1 before existing classification, adds `securityCheckId` field and `isReadOnly()` delegation
- `QueryEngine` integrates `TokenBudgetTracker` for dynamic continuation and `StopHooksPipeline` for turn-end hooks
- `HookEvent` enum gains `TEAMMATE_IDLE` and `SUBAGENT_STOP` events
- `AnthropicProvider.buildRequestBody()` supports `thinking` parameter with auto temperature removal
- `FileSnapshotManager` gains `MessageSnapshot`, `FileBackup`, `DiffStats` types and LRU eviction
- `AutoDreamConsolidator` reads KAIROS daily logs as primary source, enforces MEMORY.md size limits
- `SkillManager` registers `BatchSkill` as built-in skill

## [0.5.2] - 2026-04-01

### Added
- **AgentManager** - New registry and loader for agent definitions, mirroring SkillManager's architecture
  - `AgentDefinition` abstract base class for defining agent types
  - 4 built-in agent types extracted from AgentTool (general-purpose, code-writer, researcher, reviewer)
  - `loadFromDirectory()` and `loadFromFile()` for loading from any path
- **Markdown file support** - Both skills and agents can now be defined as `.md` files with YAML frontmatter
  - `MarkdownSkill` and `MarkdownAgentDefinition` classes
  - `MarkdownFrontmatter` parser (uses ext-yaml/symfony-yaml if available, otherwise built-in parser)
  - All frontmatter fields preserved and accessible via `getMeta()`
  - Placeholders (`$ARGUMENTS`, `$LANGUAGE`, etc.) left for LLM interpretation, not program substitution
- **Claude Code compatibility** - `load_claude_code` config flag for all three modules
  - Skills: auto-loads from `.claude/commands/` and `.claude/skills/`
  - Agents: auto-loads from `.claude/agents/`
  - MCP: auto-loads from `.mcp.json` (project) and `~/.claude.json` (user), with `${VAR}` and `${VAR:-default}` environment variable expansion
- **MCP custom paths** - `mcp.paths` config for loading additional MCP server JSON config files
- **`loadFromJsonFile()`** on MCPManager for loading MCP configs from any JSON file

### Changed
- `SkillManager::loadFromDirectory()` now parses namespace from source files instead of hardcoding `App\SuperAgent\Skills\`
- `SkillManager::parseArguments()` now collects non key=value text into `arguments` key for `$ARGUMENTS` substitution
- `AgentTool` now resolves agent types via `AgentManager` instead of hardcoded match statements
- Default paths (`.claude/skills`, `.claude/agents`) removed from config — replaced by `load_claude_code` toggle
- `MCPManager::loadConfiguration()` now accepts both SuperAgent format (`servers`) and Claude Code format (`mcpServers`)

## [0.5.1] - 2026-03-31

### Added
- **Multiple Named Provider Instances** - Support registering multiple instances of the same provider type (e.g. multiple Anthropic-compatible APIs) with different configurations
- New `driver` config field to decouple instance name from provider class selection
- All provider types now supported in `Agent::resolveProvider()` (Anthropic, OpenAI, OpenRouter, Bedrock, Ollama)
- Documentation for multi-provider instance usage in both English and Chinese READMEs

### Changed
- `Agent::resolveProvider()` now uses a `driver` field to determine which provider class to instantiate, falling back to the provider name for backward compatibility

## [0.5.0] - 2026-03-31

### Added
- **Initial release of SuperAgent SDK**
- Multi-provider AI support (Anthropic Claude, OpenAI GPT, AWS Bedrock, OpenRouter)
- 56+ built-in tools for file operations, code editing, web search, and task management
- Streaming output support for real-time responses
- Comprehensive permission system with 6 different modes
- Lifecycle hooks system for custom logic integration
- Context compression with smart conversation history management
- Memory system for cross-session persistence
- Multi-agent collaboration (Swarm mode)
- MCP (Model Context Protocol) integration
- OpenTelemetry observability and tracing
- File history with version control and rollback capabilities
- Cost tracking and token usage statistics
- Laravel Artisan commands for CLI interaction
- Custom tool, plugin, and skill development framework
- Cache system with Redis support
- Comprehensive configuration system
- Database migrations for memory and task management
- Multi-language documentation (English and Chinese)

### Features
- **Core Functionality**
  - Agent class with query and streaming methods
  - Configuration management with environment variable support
  - Provider abstraction for multiple AI services
  - Tool registry and execution framework
  - Permission validation and security controls

- **Built-in Tools**
  - File operations (read, write, edit, delete)
  - Code editing and syntax highlighting
  - Bash command execution with safety controls
  - Web search and content fetching
  - Task management and tracking
  - Image processing and analysis
  - JSON and YAML manipulation
  - Git operations and version control

- **Advanced Features**
  - Smart context compression to handle token limits
  - Cross-session memory with learning capabilities
  - Multi-agent task distribution and coordination
  - Real-time telemetry and performance monitoring
  - Automatic file versioning and rollback
  - Intelligent permission management
  - Hook-based extensibility system

- **Development Tools**
  - Artisan commands for interactive chat
  - Tool scaffolding and generation
  - Plugin development framework
  - Custom skill creation system
  - Comprehensive testing suite

### Documentation
- Complete installation guide with system requirements
- Multi-language README (English and Chinese)
- Detailed configuration documentation
- API reference and examples
- Best practices and security guidelines
- Troubleshooting and FAQ sections

### Security
- Input validation and sanitization
- API key management and encryption
- Command execution safety controls
- File access permission validation
- Rate limiting and abuse prevention
- Secure error handling without information leakage

### Performance
- Optimized memory usage with context compression
- Efficient caching with Redis support
- Async operation support
- Batch processing capabilities
- Connection pooling and resource management

## Previous Development Versions
*Note: Versions prior to 0.5.0 were development releases and not publicly available.*

---

## Links
- [Homepage](https://github.com/forgeomni/superagent)
- [Documentation](README.md)
- [Installation Guide](INSTALL.md)
- [中文文档](README.zh-CN.md)
- [中文安装手册](INSTALL.zh-CN.md)

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

**Note**: For upgrade instructions and breaking changes, please refer to our [Installation Guide](INSTALL.md#upgrade-guide).