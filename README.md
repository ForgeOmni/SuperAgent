# SuperAgent - Enterprise-Grade Laravel Multi-Agent Orchestration SDK 🚀

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.8.6-purple)](https://github.com/forgeomni/superagent)

> **🌍 Language**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)  
> **📖 Docs**: [Installation Guide](INSTALL.md) | [安装手册](INSTALL_CN.md) | [Guide d'Installation](INSTALL_FR.md) | [Advanced Usage](docs/ADVANCED_USAGE.md) | [API Docs](docs/)

SuperAgent is a powerful enterprise-grade Laravel AI Agent SDK that delivers Claude-level capabilities with multi-agent orchestration, real-time monitoring, and distributed scaling. Build and deploy teams of AI agents that work in parallel, with automatic task detection and intelligent resource management.

## ✨ Core Features

### 🆕 v0.8.6 — SuperAgent CLI: `superagent` command (standalone + Laravel, OAuth login, Claude-Code-style REPL)
SuperAgent is no longer Laravel-only. The **`superagent`** binary (`bin/superagent` / `bin/superagent.bat`) ships a full Claude-Code-style REPL, one-shot task runner, session management, and OAuth-based authentication. The CLI auto-detects Laravel projects and uses the host `config()` / container when present; otherwise it bootstraps a minimal standalone container that still unlocks everything in this SDK — Memory Palace, sub-agents, Guardrails, AutoCompaction, TaskRouter, MCP tools, skills — without writing a line of PHP.

- **Interactive REPL** (`src/CLI/Commands/ChatCommand.php`, `src/Harness/HarnessLoop.php`) — streaming Claude-Code-style rendering with live text deltas, thinking previews, tool-call cards, cost counters. Slash commands: `/help`, `/status`, `/tasks`, `/compact`, `/continue`, `/session list|save|load|delete`, `/clear`, `/model`, `/cost`, `/quit`
- **One-shot mode** — `superagent "fix the login bug"` runs a single task and exits. `--json` emits machine-readable output `{content, cost, turns, usage}` for scripting / CI
- **First-run setup** — `superagent init` walks through provider choice (Anthropic / OpenAI / Ollama / OpenRouter), API-key capture from env or secret prompt, default model, and writes `~/.superagent/config.php` (mode `0600`)
- **OAuth login by importing your Claude Code / Codex tokens** — `superagent auth login claude-code` reads `~/.claude/.credentials.json`; `superagent auth login codex` reads `~/.codex/auth.json`. No second sign-in, no API-key copy-paste. Tokens stored atomically in `~/.superagent/credentials/{anthropic,openai}.json` (mode `0600`), auto-refreshed 60s before expiry
- **OAuth-aware providers** (`src/Providers/AnthropicProvider.php`, `OpenAIProvider.php`) — Bearer mode with `anthropic-beta: oauth-2025-04-20`; auto-prepends the required `"You are Claude Code, Anthropic's official CLI for Claude."` system block; rewrites legacy model ids (`claude-3*`) to `claude-opus-4-5` since OAuth tokens only authorize current-gen models. OpenAI side adds `chatgpt-account-id` header for Codex ChatGPT-subscription traffic
- **Interactive `/model` picker** (`src/Harness/CommandRouter.php`) — provider-aware numbered catalog (Opus/Sonnet/Haiku 4.5, GPT-5 family, OpenRouter, Ollama), active model starred. `/model 2` by number, `/model <id>` by id
- **Rich rendering** (`src/Console/Output/RealTimeCliRenderer.php`) — `--verbose-thinking` / `--no-thinking` / `--plain` / `--no-rich` flags. Plain mode strips ANSI for pipes & CI logs
- **Container glue** — `Foundation\Application::bootstrap()` binds our `ConfigRepository` to the Laravel `Container::getInstance()` singleton in standalone mode, so the 14 `config()`-driven Optimization / Performance classes work silently outside Laravel
- **Windows-friendly** — `CredentialStore` falls back to `USERPROFILE` when `HOME` is empty; batch launcher shim at `bin/superagent.bat`

**Typical first session:**
```bash
composer global require forgeomni/superagent     # or clone + composer install
superagent auth login claude-code                 # reuse Claude Code OAuth login
superagent "explain this codebase"                # one-shot, no API key needed

superagent                                        # interactive REPL
> /model                                          # list available models
> /model 1                                        # switch to Opus 4.5
> /session save my-session                        # persist state
> /quit
```

**Without a local Claude Code install:**
```bash
export ANTHROPIC_API_KEY=sk-ant-...
superagent init              # interactive setup → ~/.superagent/config.php
superagent "review this PR"
```

### 🆕 v0.8.5 — Memory Palace: MemPalace-Inspired Hierarchical Memory (enabled by default, 6 new tests)
- **Memory Palace** (`src/Memory/Palace/`) — Hierarchical memory module inspired by MemPalace (LongMemEval 96.6%). Wings (people / projects / agents) → Halls (5 memory-type corridors) → Rooms (topics) → Drawers (raw verbatim content). Auto-Tunnels bridge the same Room across different Wings. Plugs into the existing `MemoryProviderManager` as an external provider — **does not replace** the builtin `MEMORY.md` flow
- **Wing + Room Filtering** (`PalaceRetriever`) — Structured metadata drives retrieval; MemPalace benchmarks attribute +34% R@10 to this pattern. Hybrid scoring: keyword overlap + optional cosine similarity + recency decay + access-count boost. Generator-based drawer iteration streams without loading everything into memory
- **4-Layer Memory Stack** (`Layers/MemoryLayer`, `LayerManager`) — L0 Identity (~50 tok) and L1 Critical Facts (~120 tok) always loaded; L2 Room Recall and L3 Deep Search on demand. `wakeUp()` emits a ~600–900 tok session-bootstrap payload
- **Temporal Knowledge Graph** — `KnowledgeEdge` gains `validFrom` / `validUntil`; `KnowledgeGraph` gains `addTriple()`, `invalidate()`, `queryEntity($entity, asOf:)`, `timeline()`. New `NodeType::ENTITY` + `EdgeType::RELATES_TO`. Fully backward compatible (fields default empty)
- **Agent Diaries** (`Diary/AgentDiary.php`) — Each agent gets a dedicated AGENT-type Wing with a `hall_events/diary` Room. Every specialist agent (reviewer, architect, ops…) keeps its own short-entry history separate from shared memory
- **Fact Checker** (`FactChecker.php`) — KG-backed contradiction detection with 3 severities (`attribution_conflict`, `stale`, `unsupported`). **No LLM call** — pure graph traversal
- **Near-Duplicate Detection** (`MemoryDeduplicator.php`) — Content-hash exact match + 5-gram Jaccard shingle overlap (default threshold 0.85), room-scoped by default because context matters
- **Wake-Up CLI** (`php artisan superagent:wake-up [--wing=] [--search=]`) — Loads L0+L1 plus optional wing-scoped drawer search. Designed to bootstrap external AI sessions without full-memory loads
- **Opt-in Vector Scoring** — `palace.vector.enabled` + an `embed_fn` callable. Without it, the retriever runs fully offline on keyword + recency + access-count
- **Enabled by Default** — `palace.enabled=true`. Storage layout: `{memory}/palace/wings/{slug}/halls/{hall}/rooms/{room}/drawers/*.md`. Full suite: **1851 tests, 5234 assertions, 0 failures**

### 🆕 v0.8.2 — Multi-Agent Collaboration Pipeline, Smart Routing & Parallel Execution (10 improvements, 48 new tests)
- **Collaboration Pipeline** (`src/Coordinator/`) — Phased multi-agent orchestration with topological dependency DAG, 4 failure strategies (fail-fast, continue, retry, fallback), conditional phase execution, and 8-event lifecycle listeners. Agents within a phase execute in true parallel via ProcessBackend (OS processes) or InProcessBackend (Fibers)
- **Smart Task Router** (`src/Coordinator/TaskRouter.php`) — Automatic task→model routing: research/chat → Tier 3 (Haiku, low-cost), code/debug/analysis → Tier 2 (Sonnet, balanced), synthesis/coordination → Tier 1 (Opus, powerful). Complexity overrides: very-complex code generation promotes to Tier 1, simple analysis demotes to Tier 3. `withAutoRouting()` on pipeline or phase. Explicit `withAgentProvider()` always takes priority
- **Phase Context Injection** (`src/Coordinator/PhaseContextInjector.php`) — Cross-phase context sharing: phase-N agents automatically receive summaries from phases 1..N-1 via `<prior-phase-results>` in the system prompt. Token-budgeted per-phase (2K) and total (8K) with smart truncation. Saves tokens by preventing re-discovery
- **Provider Patterns** — 3 collaboration modes: `sameProvider` (shared credentials + CredentialPool rotation), `crossProvider` (mix Anthropic/OpenAI/Ollama in one pipeline), `withFallbackChain` (ordered provider failover)
- **Agent Retry Policy** — Per-agent exponential/linear/fixed backoff + jitter. Error classification (auth/rate-limit/server/network). Credential rotation on 429, provider switch on persistent failure. Factory presets: `default()`, `aggressive()`, `none()`, `crossProvider()`
- **ProcessBackend Retry** — Agents that failed in parallel batch execution now retry individually with full credential rotation and provider fallback. Shared `retryFailedAgents()` for Process and Fiber paths
- **stream_select Polling** — ProcessBackend uses `stream_select()` event-driven I/O on Linux/macOS (was: 50ms busy-loop). Windows auto-falls back to usleep polling
- **AgentMailbox Buffering** — Writes buffered in memory, flushed every 10 messages. Eliminates O(n²) disk I/O for bulk messaging
- **3 Bug Fixes** — SQLite session `loadLatest()` ordering (rowid tiebreaker), WebSearch fallback assertion (accepts DuckDuckGo success), undefined `$agentConfig` in retry catch block
- **48 New Tests** — `TaskRouterTest` (26), `PhaseContextInjectorTest` (12), `CollaborationPipelineTest` (+10). Full suite: **1945 tests, 5729 assertions, 0 failures**

### 🆕 v0.8.1 — Middleware Pipeline, Typed Errors & Tool Caching (6 improvements, 32 new tests)
- **Middleware Pipeline** (`src/Middleware/`) — Composable onion-model middleware chain. `MiddlewareInterface` with priority-based ordering. 5 built-in middleware: `RateLimitMiddleware` (token-bucket), `RetryMiddleware` (exponential backoff + jitter), `CostTrackingMiddleware` (budget enforcement), `LoggingMiddleware` (structured logging), `GuardrailMiddleware` (input/output validators). Config: `middleware`
- **Structured Output** (`src/Providers/ResponseFormat.php`) — `ResponseFormat` value object for forcing JSON output from LLMs. Supports `text()`, `json()`, `jsonSchema()` modes with provider-specific conversion (`toAnthropicFormat()`, `toOpenAIFormat()`)
- **Per-Tool Result Cache** (`src/Tools/ToolResultCache.php`) — In-memory TTL cache for read-only tool results. Order-independent input hashing, targeted invalidation by tool name or file path, LRU eviction, error exclusion. Config: `optimization.tool_cache`
- **Enhanced Exception Hierarchy** — `SuperAgentException` gains `context` array, `isRetryable()`, `toArray()`. `ProviderException` adds `retryable`, `retryAfterSeconds`, `fromHttpStatus()` factory. `ToolException` adds `toolInput`. New: `BudgetExceededException`, `ContextOverflowException`, `ValidationException`
- **Proactive Context Compression** — `ContextCompressor::compressIfNeeded()` auto-checks token budget and compresses only when exceeded. New `estimateTokenCount()`, `getCompressionStats()` for per-message-add integration
- **Plugin Middleware & Provider Extension** — `PluginInterface` now supports `middleware()` and `providers()` methods. Plugins can register middleware into the pipeline and custom LLM provider drivers. `PluginManager::collectMiddleware()`, `registerMiddleware()`, `registerProviders()`

### 🆕 v0.8.0 — Hermes-Agent Inspired Architecture Upgrade (19 improvements, 74 new tests)
- **SQLite Session Storage + FTS5 Search** (`src/Session/SqliteSessionStorage.php`) — SQLite WAL mode backend with FTS5 full-text search across all session messages. Random-jitter retry on lock contention, passive WAL checkpointing, optional SQLCipher at-rest encryption. `SessionManager::search()` for cross-session search. Dual-write with file fallback
- **Unified Context Compression** (`src/Optimization/ContextCompression/ContextCompressor.php`) — 4-phase hierarchical compression: prune old tool results → protect head/tail → LLM summarize middle → iterative summary updates. Token-budget tail protection (default 8K), structured 5-section summary template
- **Prompt Injection Detection** (`src/Guardrails/PromptInjectionDetector.php`) — Pattern-based detection for 7 threat categories (instruction override, system-prompt extraction, data exfiltration, role confusion, invisible Unicode, hidden HTML, encoding evasion). 4 severity levels, file scanning, invisible-char sanitization. Auto-integrated into `SystemPromptBuilder::withContextFiles()` — high/critical threats excluded, medium threats sanitized
- **Credential Pool** (`src/Providers/CredentialPool.php`) — Same-provider multi-credential failover with 4 rotation strategies. Per-credential status tracking and automatic cooldown. Auto-integrated into `ProviderRegistry::create()` for transparent key rotation
- **Query Complexity Router** (`src/Optimization/QueryComplexityRouter.php`) — Content-based model routing: detects code, URLs, complexity keywords, multi-step instructions. Simple queries auto-route to a fast model
- **Path-Level Write Conflict Detection** — `ParallelToolExecutor::classify()` upgraded: write tools targeting different paths can now run in parallel; overlapping paths forced sequential. Destructive bash command detection
- **Memory Provider Interface** (`src/Memory/Contracts/MemoryProviderInterface.php`) — Pluggable memory provider with 10 lifecycle hooks. Two implementations: `VectorMemoryProvider` (cosine similarity with embeddings) and `EpisodicMemoryProvider` (temporal episodes + recency scoring). Error-isolated
- **SecurityCheckChain** (`src/Permissions/SecurityCheckChain.php`) — Composable security-check chain wrapping the 23-check BashSecurityValidator. `SecurityCheck` interface + `LegacyValidatorCheck` adapter. Supports `add()`, `insertAt()`, `disableById()` for custom policies
- **Skill Progressive Disclosure** (`src/Tools/Builtin/SkillCatalogTool.php`) — Two-phase skill loading: Phase 1 (metadata only) → Phase 2 (full instructions on demand)
- **Safe Stream Writer** (`src/Output/SafeStreamWriter.php`) — Broken-pipe protection for daemon/container scenarios
- **Architecture Hardening** — FileSnapshotManager batched I/O (default batch=5), AutoDreamConsolidator memory bounds (gather 500 / consolidate 1000), ReplayStore JSON-schema validation, PromptHook $ARGUMENTS sanitization
- **Architecture Diagram** (`docs/ARCHITECTURE.md`) — Mermaid dependency graph with 80+ nodes, data-flow sequence diagram, subsystem counts
- **18 Test Fixes** — Fixed all pre-existing test failures. Full suite: **1687 tests, 4713 assertions, 0 failures**

### 🆕 v0.7.9 — Dependency Injection & Architecture Hardening (63 new unit tests)
- **Singleton → Constructor Injection** — 19 singleton classes (`AgentManager`, `TaskManager`, `MCPManager`, `ParallelAgentCoordinator`, `EventDispatcher`, `CostTracker`, and more) now have public constructors; `getInstance()` marked `@deprecated`. 25 call-sites updated to accept injected dependencies and keep backward-compatible fallbacks. Enables correct test isolation and process-safe Swarm execution
- **ToolStateManager** (`src/Tools/ToolStateManager.php`) — Centralised injectable state container replacing `private static` properties scattered across 14 built-in tool classes (`EnterPlanModeTool`, `ToolSearchTool`, `MonitorTool`, `REPLTool`, `SkillTool`, `WorkflowTool`, `BriefTool`, `ConfigTool`, `SnipTool`, `TodoWriteTool`, `AskUserQuestionTool`, `TerminalCaptureTool`, `VerifyPlanExecutionTool`). Bucket-based state with auto-increment IDs, collection helpers and per-tool reset. Shared instance injected in Swarm mode for cross-process correctness
- **SessionManager Decomposition** — Extracted `SessionStorage` (atomic file I/O, directory scan, path resolution) and `SessionPruner` (by-time + by-count cleanup) from the 631-line `SessionManager`. The manager now delegates and becomes a pure orchestration layer
- **Process Parallelism Limit** — `ParallelToolExecutor::executeProcessParallel()` now respects `$maxParallel` (default 5), chunking batches instead of spawning unbounded concurrent OS processes
- **v0.7.6 Feature Unit Tests** — 63 new unit tests across 4 dedicated test classes: `ForkTest` (20: branch lifecycle, session management, scoring strategies, result ranking), `DebateTest` (12: config fluent API, round data, result aggregation), `CostPredictionTest` (18: type/complexity detection, token estimation, budget check, model comparison), `ReplayTest` (13: event types, recorder capture, step count, snapshot interval)

### 🆕 v0.7.8 — Agent Harness Pattern + Enterprise Subsystems (20 subsystems, 628 tests)
- **Persistent Task Manager** (`src/Tasks/PersistentTaskManager.php`) — File-based task persistence: JSON index + per-task output logs. Streaming `appendOutput()` / `readOutput()`, non-blocking `watchProcess()` + `pollProcesses()`, auto-mark orphaned running tasks as failed on restart, age-based cleanup. Config: `persistence.tasks`
- **Session Manager** (`src/Session/SessionManager.php`) — Save/load/list/delete conversation snapshots to `~/.superagent/sessions/`. `loadLatest()` with CWD filtering for project-scoped resume, auto summary extraction, session-ID path sanitisation, count + age cleanup. Config: `persistence.sessions`
- **Unified StreamEvent Architecture** (`src/Harness/`) — 9 unified event types (`TextDelta`, `ThinkingDelta`, `TurnComplete`, `ToolStarted`, `ToolCompleted`, `Compaction`, `Status`, `Error`, `AgentComplete`). `StreamEventEmitter` multi-listener dispatch + `toStreamingHandler()` bridge — QueryEngine integrates without change
- **Harness REPL Loop** (`src/Harness/HarnessLoop.php`) — Interactive Agent loop with a `CommandRouter` (10 built-in commands: `/help`, `/status`, `/tasks`, `/compact`, `/continue`, `/session`, `/clear`, `/model`, `/cost`, `/quit`). Busy lock, `continue_pending()` resume, auto-save sessions, custom command registration
- **Auto Compactor** (`src/Harness/AutoCompactor.php`) — Two-tier compaction: micro (truncate old tool results, no LLM call) → full (LLM summary). Failure circuit breaker, `CompactionEvent` notifications. `maybeCompact()` called at the start of each loop turn
- **E2E Scenario Testing Framework** (`src/Harness/Scenario.php`, `ScenarioRunner.php`) — Structured scenario definitions + fluent builder, temp workspace management, transparent tool-call tracing, three-dimensional validation (required tools + expected text + custom closures), tag filtering, pass/fail/error summary
- **QueryEngine `continue_pending()`** — `hasPendingContinuation()` + `continuePending()` resumes an interrupted tool loop without a new user message. Internal loop extracted as shared `runLoop()`
- **Worktree Manager** (`src/Swarm/WorktreeManager.php`) — Standalone git-worktree lifecycle: creation symlinks large dirs (node_modules, vendor, .venv), metadata persistence, resume and cleanup. Extracted from ProcessBackend for reuse
- **Tmux Backend** (`src/Swarm/Backends/TmuxBackend.php`) — Visual multi-agent debugging: each agent runs in a tmux pane. Auto-detected (`$TMUX` + `which tmux`), graceful degradation when unavailable. New `BackendType::TMUX`
- **Parameters over Config** — All new subsystems' `fromConfig()` accepts `array $overrides`, priority: `$overrides` > config file > defaults. Can force-enable disabled-by-config features at the call site
- **API Retry Middleware** (`src/Providers/RetryMiddleware.php`) — Exponential backoff + jitter, `Retry-After` header support, error classification (auth / rate_limit / transient / unrecoverable), configurable max retries, retry log. `wrap()` static factory
- **iTerm2 Backend** (`src/Swarm/Backends/ITermBackend.php`) — AppleScript-based pane debugging, auto-detected (`$ITERM_SESSION_ID`), graceful shutdown + force-kill. `BackendType::ITERM2`
- **Plugin System** (`src/Plugins/`) — `PluginManifest` (parses `plugin.json`), `LoadedPlugin` (resolves skills/hooks/MCP), `PluginLoader` (discovers from `~/.superagent/plugins/` and `.superagent/plugins/`, enable/disable, install/uninstall, cross-plugin collection)
- **Observable App State** (`src/State/`) — `AppState` immutable value object with `with()` partial updates. `AppStateStore` observable store, `subscribe()` returns cancel-subscription callback
- **Hook Hot-Reload** (`src/Hooks/HookReloader.php`) — Monitors config-file mtime, reloads `HookRegistry` on change. Supports JSON and PHP config. `forceReload()`, `hasChanged()`, `fromDefaults()` factory
- **Prompt & Agent Hook** (`src/Hooks/PromptHook.php`, `AgentHook.php`) — LLM-based validation: sends a prompt with `$ARGUMENTS` injection, expects `{"ok": true/false, "reason": "..."}`. `AgentHook` extends with context + 60s timeout. Both support `blockOnFailure` and matcher patterns
- **Multi-Channel Gateway** (`src/Channels/`) — `ChannelInterface`, `BaseChannel` (ACL control), `MessageBus` (SplQueue-based inbound/outbound), `ChannelManager` (register / dispatch), `WebhookChannel` (HTTP webhook), `InboundMessage`/`OutboundMessage` value objects
- **Backend Protocol** (`src/Harness/BackendProtocol.php`, `FrontendRequest.php`) — JSON-lines protocol (`SAJSON:` prefix) for front/back communication. 8 event emitters, `readRequest()`, `createStreamBridge()`. `FrontendRequest` typed request parsing
- **OAuth Device-Code Flow** (`src/Auth/`) — RFC 8628 implementation with auto browser open. `CredentialStore` file-based credential storage (atomic write + 0600 permissions). `TokenResponse` / `DeviceCodeResponse` immutable DTOs
- **Permission Path Rules** (`src/Permissions/`) — `PathRule` glob-based allow/deny rules, `CommandDenyPattern` fnmatch patterns, `PathRuleEvaluator` chained evaluation (deny wins). `fromConfig()` factory
- **Coordinator Task Notifications** (`src/Coordinator/TaskNotification.php`) — Structured XML notifications for sub-agent completion. `toXml()` / `toText()` / `fromXml()` / `fromResult()`. XML round-trip fidelity
- **Enhanced Auto Compactor** — Dynamic threshold (`contextWindow - 20K - 13K`), token-estimate padding (`raw * 4/3`), `contextWindowForModel()` mapping, `setContextWindow()` override
- **Enhanced Parallel Tool Execution** — New `executeProcessParallel()` using `proc_open` for true OS-level parallelism, `getStrategy()` returns `process`/`fiber`/`sequential`, config `performance.process_parallel_execution.enabled`
- **Session Project Isolation** — Sessions stored under project-scoped sub-directories: `sessions/{basename}-{sha1[:12]}/`. Backward-compatible with flat layout

### 🆕 v0.7.7 — Debuggability & Quality Hardening
- **Swallowed Exception Logging Fix** — Added `error_log('[SuperAgent] ...')` to 27 silent catch blocks across 24 files (Performance, Optimization, ProcessBackend, MCPManager, etc.). Exceptions that were previously invisible in production can now be traced via the `[SuperAgent]` log prefix
- **Agent Core Unit Tests** (`tests/Unit/AgentTest.php`) — 31 tests, 44 assertions, covering constructor, provider routing, fluent API chaining, tool management, bridge mode, auto mode, provider-config injection into sub-agents
- **Code Review Framework** (`docs/REVIEW.md`) — Periodic architecture-assessment template with scale metrics, strengths/issues analysis, test-coverage gaps, prioritised action items, and version-over-version scoring (current: 7.6/10)

### 🆕 v0.7.6 — Innovative Agent Capability Suite (6 new subsystems)
- **Agent Replay Time-Travel Debugging** (`src/Replay/`) — Records the full execution trace (LLM calls, tool calls, agent spawn, message passing) and replays step by step. `ReplayPlayer` supports forward/backward navigation, agent-state inspection at any step, search, forked re-execution from any step, formatted timeline with cumulative cost. Persisted in NDJSON via `ReplayStore`, with age-based cleanup. Config: `replay.enabled`, `replay.snapshot_interval`
- **Conversation Fork** (`src/Fork/`) — Fork a conversation at any point, explore N paths in parallel, auto-select the best result. `ForkManager` creates a `ForkSession` with multiple `ForkBranch`es, executed in true parallel via `proc_open` (`ForkExecutor`), using built-in scoring strategies (`ForkScorer::costEfficiency`, `brevity`, `completeness`, `composite`). Config: `fork.enabled`, `fork.max_branches`
- **Agent Debate Protocol** (`src/Debate/`) — Three structured multi-agent collaboration modes: **Debate** (proposer → critic → judge with rebuttal rounds), **Red Team** (builder → attacker → reviewer, configurable attack vectors), **Ensemble** (N agents solve independently → merge best elements). Fluent config `DebateConfig::create()->withRounds(3)->withMaxBudget(5.0)`, per-agent model selection, per-round cost tracking. Config: `debate.enabled`, `debate.default_rounds`
- **Cost Prediction Engine** (`src/CostPrediction/`) — Estimate task cost before execution, 3 strategies: history-weighted average (confidence up to 95%), type-average hybrid, heuristic (token estimate × model pricing). `TaskAnalyzer` detects task type (code-gen, refactor, debug, test, analysis, chat) and complexity via keywords. `CostPredictor::compareModels()` instant multi-model cost comparison. Config: `cost_prediction.enabled`
- **Natural-Language Guardrails** (`src/Guardrails/NaturalLanguage/`) — Define guardrail rules in natural language, zero-cost compilation (no LLM call). Pattern-based `RuleParser` supports 6 rule types: tool restriction ("do not modify files in database/migrations"), cost rule ("pause if cost exceeds $5"), rate limit ("at most 10 bash calls per minute"), file restriction ("don't touch .env files"), warning rules, and content rules. Fluent API: `NLGuardrailFacade::create()->rule('...')->compile()`. Supports confidence scoring and YAML export. Config: `nl_guardrails.enabled`, `nl_guardrails.rules`
- **Self-Healing Pipeline** (`src/Pipeline/SelfHealing/`) — New failure strategy `self_heal` for pipeline steps: diagnose failure → plan repair → apply mutation → retry. `DiagnosticAgent` classifies 8 error categories via rules + LLM (timeout, rate-limit, model-limit, resource-exhaustion, etc.). `StepMutator` supports 6 mutations (modify prompt, change model, adjust timeout, add context, simplify task, split step). Config: `self_healing.enabled`, `self_healing.max_heal_attempts`

### 🆕 v0.7.5 — Claude Code Tool Name Compatibility
- **`ToolNameResolver`** (`src/Tools/ToolNameResolver.php`) — Bidirectional mapping between Claude Code PascalCase tool names (`Read`, `Write`, `Edit`, `Bash`, `Glob`, `Grep`, `Agent`, `WebSearch`, etc.) and SuperAgent snake_case tool names (`read_file`, `write_file`, `edit_file`, `bash`, `glob`, `grep`, `agent`, `web_search`, etc.). 40+ tool mappings, including legacy CC names (`Task` → `agent`)
- **Agent Definition Auto-Resolution** — `MarkdownAgentDefinition::allowedTools()` and `disallowedTools()` auto-resolve CC tool names via `ToolNameResolver::resolveAll()`. Definitions in `.claude/agents/` may use either format: `allowed_tools: [Read, Grep, Glob]` or `allowed_tools: [read_file, grep, glob]` both work
- **Permission System Compatibility** — `QueryEngine::isToolAllowed()` checks both original and resolved names, so permission lists in CC or SA format work correctly
- **Backward Compatible** — Existing SuperAgent tool names continue to work; the resolver is additive and non-breaking

### 🆕 v0.7.0 — Performance Optimisation Suite (13 strategies, all configurable)
- **Tool Result Compression** — Automatically compresses old tool results (beyond the last N turns) into concise summaries, reducing 30–50% of input tokens. Error results and recent context untouched. Config: `optimization.tool_result_compaction` (`enabled`, `preserve_recent_turns`, `max_result_length`)
- **Selective Tool Schema** — Dynamically selects a relevant subset of tools based on task stage (explore/edit/plan), omitting unused tool schemas to save ~10K tokens. Always includes recently used tools. Config: `optimization.selective_tool_schema` (`enabled`, `max_tools`)
- **Per-Turn Model Routing** — Pure tool-call turns auto-downgrade to a fast model (configurable, default Haiku) and upgrade again for reasoning turns. Detects consecutive tool-call turns and routes accordingly, reducing 40–60% cost. Config: `optimization.model_routing` (`enabled`, `fast_model`, `min_turns_before_downgrade`)
- **Response Prefill** — Uses Anthropic assistant prefill to steer output format after long tool-call sequences, encouraging summaries over more tool calls. Conservative: prefills only after 3+ consecutive tool-call turns. Config: `optimization.response_prefill` (`enabled`)
- **Prompt Cache Pinning** — Auto-inserts cache boundary markers into system prompts missing them, separating static parts (tool definitions, role) from dynamic parts (memory, context) to achieve ~90% prompt cache hit rate. Config: `optimization.prompt_cache_pinning` (`enabled`, `min_static_length`)
- **All Optimisations Enabled by Default**, individually disableable via env vars (`SUPERAGENT_OPT_TOOL_COMPACTION`, `SUPERAGENT_OPT_SELECTIVE_TOOLS`, `SUPERAGENT_OPT_MODEL_ROUTING`, `SUPERAGENT_OPT_RESPONSE_PREFILL`, `SUPERAGENT_OPT_CACHE_PINNING`)
- **No Hardcoded Model IDs** — The fast model used by routing is configurable via `SUPERAGENT_OPT_FAST_MODEL`; low-price model detection uses heuristic name-matching, not a hardcoded list
- **Parallel Tool Execution** — PHP Fibers parallelise read-only tools; runtime = max, not sum. Config: `performance.parallel_tool_execution`
- **Streaming Tool Dispatch** — Kicks off execution as soon as a tool_use block is received in the SSE stream. Config: `performance.streaming_tool_dispatch`
- **HTTP Connection Pool** — cURL keep-alive to reuse connections. Config: `performance.connection_pool`
- **Speculative Prefetch** — After a Read, prefetches related files into an in-memory cache. Config: `performance.speculative_prefetch`
- **Streaming Bash Execution** — Timeout truncation + tail summary. Config: `performance.streaming_bash`
- **Adaptive max_tokens** — 2048 for tool calls, 8192 for reasoning. Config: `performance.adaptive_max_tokens`
- **Batch API** — Anthropic Batches API (50% discount). Config: `performance.batch_api`
- **Local Tool Zero-Copy** — Caches file content between Read/Edit/Write. Config: `performance.local_tool_zero_copy`

### 🆕 v0.6.19 — In-Process NDJSON Logging for Process Monitoring
- **`NdjsonStreamingHandler`** (`src/Logging/NdjsonStreamingHandler.php`) — Factory that creates a `StreamingHandler` that writes CC-compatible NDJSON to a log file in one line. For in-process agent execution (scenarios that call `$agent->prompt()` directly without going through `agent-runner.php` / `ProcessBackend`)
- **`create(logTarget, agentId)`** — Returns a `StreamingHandler` with `onToolUse`, `onToolResult`, `onTurn` callbacks that auto-write to an `NdjsonWriter`. Accepts a file path (auto-creates the directory) or a writable stream resource
- **`createWithWriter(logTarget, agentId)`** — Returns `{handler, writer}` so the caller can emit `writeResult()` / `writeError()` after execution. Writer and handler share the same NDJSON stream
- **Process Monitor Compatibility** — Log files are byte-identical to sub-process stderr format; `parseStreamJsonIfNeeded()` parses them directly and displays tool-call activity (🔧 Read, Edit, Grep, etc.), token counts and execution status

### 🆕 v0.6.18 — Claude Code Compatible NDJSON Structured Logging
- **`NdjsonWriter`** (`src/Logging/NdjsonWriter.php`) — New Claude Code compatible NDJSON (newline-delimited JSON) event writer. Supports 5 event methods: `writeAssistant()` (LLM response with text/tool_use content blocks + per-turn usage), `writeToolUse()` (single tool call), `writeToolResult()` (tool execution result, `type:user` + `parent_tool_use_id`), `writeResult()` (success result with usage/cost/duration), `writeError()` (error with subtype). Escapes U+2028/U+2029 line separators, matching CC's `ndjsonSafeStringify`
- **NDJSON Replaces `__PROGRESS__:` Protocol** — `agent-runner.php` now writes standard NDJSON to stderr via `NdjsonWriter`, replacing the custom `__PROGRESS__:` prefix. Events are parseable directly by CC's bridge / sessionRunner `extractActivities()`. Each assistant event includes per-turn `usage` (inputTokens, outputTokens, cacheReadInputTokens, cacheCreationInputTokens) for real-time token tracking
- **ProcessBackend NDJSON Parsing** — `ProcessBackend::poll()` upgraded to detect NDJSON lines (JSON objects starting with `{`), remaining compatible with the old `__PROGRESS__:` format. Non-JSON stderr lines (e.g. `[agent-runner]` logs) continue to forward to the PSR-3 logger
- **AgentTool CC Format Support** — `applyProgressEvents()` now handles both CC NDJSON format (`assistant` → extract tool_use blocks + usage, `user` → tool_result, `result` → final usage) and the legacy format, enabling seamless process-monitor integration

### 🆕 v0.6.17 — Sub-Agent Process Real-Time Progress Monitoring
- **Structured Progress Events** — Sub-agent processes now send structured JSON progress events over stderr via the `__PROGRESS__:` protocol. Events include `tool_use` (tool name, input args), `tool_result` (success/failure, result size) and `turn` (per-LLM-call token usage)
- **Sub-Process StreamingHandler** — `agent-runner.php` creates a `StreamingHandler` with `onToolUse`, `onToolResult`, `onTurn` callbacks that serialize execution events back to the parent process. Switched from `Agent::run()` to `Agent::prompt()` to pass the handler through
- **ProcessBackend Event Parsing** — `ProcessBackend::poll()` now recognises lines in stderr prefixed with `__PROGRESS__:`, parses them as JSON and queues them per agent. New `consumeProgressEvents(agentId)` method returns and flushes queued events. Regular log lines still forward to the logger as usual
- **AgentTool Coordinator Integration** — `waitForProcessCompletion()` registers the sub-agent with `ParallelAgentCoordinator` and, on every poll, injects progress events into `AgentProgressTracker`. The tracker updates tool-use counts, current activity description (e.g. "Editing /src/Agent.php"), token counts and recent-activity list in real time
- **Process Monitor Visibility** — `ParallelAgentDisplay` can now show sub-agent real-time progress (current tool, token count, tool-use count) with no display-code changes — the existing UI reads from the coordinator's tracker, which is now populated for process-level agents

### 🆕 v0.6.16 — Parent-Process Registered Data Passthrough to Sub-Processes
- **Agent Definition Passthrough** — The parent process serialises all registered agent definitions (built-in + `.claude/agents/` custom) via `AgentManager::exportDefinitions()` and passes them to the sub-process over stdin JSON. The sub-process imports via `importDefinitions()` — no Laravel bootstrap or filesystem access required
- **MCP Server Config Passthrough** — The parent process serialises all registered MCP server configs (`ServerConfig::toArray()`) to the sub-process, which registers them via `MCPManager::registerServer()` without re-reading config files or `.mcp.json`
- **Verified** — Sub-process receives 9 agent types (7 built-in + 2 custom with full system prompt), 2 MCP servers (stdio + http), 6 built-in skills, 58 tools

### 🆕 v0.6.15 — MCP Server TCP Bridge Sharing
- **MCP TCP Bridge** (`MCPBridge`) — After the parent process connects to a stdio MCP server, it auto-starts a lightweight TCP proxy on a random port. Sub-processes discover the bridge through a registration file and connect via `HttpTransport` instead of each launching their own MCP server. N sub-agents share 1 MCP server process
- **MCPManager Auto-Detection** — `createTransport()` checks for the parent-process bridge before creating a `StdioTransport`; if found, transparently uses `HttpTransport`
- **ProcessBackend Bridge Polling** — `poll()` additionally calls `MCPBridge::poll()` to handle sub-process TCP requests

### 🆕 v0.6.12 — Sub-Process Laravel Bootstrap & Provider Fix
- **Sub-Process Laravel Bootstrap** — `agent-runner.php` now runs a full Laravel bootstrap (`$app->make(Kernel)->bootstrap()`) when given a `base_path`. Sub-processes can access `config()`, `AgentManager`, `SkillManager`, `MCPManager`, the `.claude/agents/` directory, and all service providers — exactly like the parent
- **Provider Config Serialisation Fix** — When `Agent` was constructed with an `LLMProvider` object (not a string), the object was serialised to `{}` in JSON and sub-processes couldn't access API credentials. `injectProviderConfigIntoAgentTools()` now replaces the object with `$provider->name()` as a string, backfills `api_key` from Laravel config, and always sets provider name and model
- **Full Tool Set for Sub-Processes** — `ProcessBackend` now defaults to `load_tools='all'` (58 tools); sub-agents can access agent, skill, mcp, web_search and all other tools

### 🆕 v0.6.11 — True Process-Level Parallel Sub-Agents
- **Process-Based Sub-Agents** — `AgentTool` now defaults to `ProcessBackend` (`proc_open`) instead of `InProcessBackend` (Fibers). Each sub-agent runs in an isolated OS process with its own Guzzle connection, giving true parallelism. PHP Fibers are cooperative — blocking I/O in a Fiber (HTTP calls, bash commands) blocks the whole process, which made the old approach effectively serial
- **Rewrote `bin/agent-runner.php`** — One-shot runner: reads JSON config from stdin, creates a `SuperAgent\Agent` with full LLM Provider and tools, executes the prompt, writes the JSON result to stdout
- **`ProcessBackend` Refactor** — `spawn()` passes config over stdin and closes it; `poll()` non-blocking reads of stdout/stderr; `waitAll()` waits for every tracked agent to complete. Measured: 5 agents each sleeping 500ms complete in 544ms total (4.6× speedup)
- **InProcessBackend Fallback** — The Fiber backend is kept as a fallback when `proc_open` is unavailable

### 🆕 v0.6.10 — Multi-Agent Synchronous Execution Fix
- **Synchronous Agent Deadlock Fix** — `InProcessBackend::spawn()` now creates the execution Fiber regardless of `runInBackground`. Previously, synchronous mode never created a Fiber, so `waitForSynchronousCompletion()` polled forever (5-minute timeout deadlock)
- **Backend Type Mismatch Fix** — `AgentTool::$activeTasks` now additionally stores the actual backend instance alongside the `BackendType` enum. The synchronous wait loop previously called `->getStatus()` and `instanceof InProcessBackend` on the enum value and always got a wrong answer
- **Fiber Lifecycle Fix** — `ParallelAgentCoordinator::processAllFibers()` now handles unstarted Fibers (`!$fiber->isStarted()` → `start()`). Fixed the missing `$status` property on `AgentProgressTracker` and a null-usage type error in stub agents

### 🆕 v0.6.9 — Guzzle Base URL Path Fix
- **Multi-Provider Base URL Fix** — `OpenAIProvider`, `OpenRouterProvider` and `OllamaProvider` now correctly append a trailing slash to `base_uri` and use relative request paths. Previously, any custom `base_url` with a path prefix (e.g. `https://gateway.example.com/openai`) would silently lose that prefix because Guzzle's RFC 3986 parser drops the base path when an absolute path (e.g. `/v1/chat/completions`) is used. Four providers (`AnthropicProvider` was already fixed in v0.6.8) now follow the correct pattern

### 🆕 v0.6.8 — Incremental Context & Tool Lazy Loading
- **Incremental Context** (`IncrementalContextManager`) — Delta-based context sync: only transmits diffs (added/modified/removed messages) instead of the full history. Auto-checkpointing, one-step revert, configurable token-threshold auto-compaction, and `getSmartWindow(maxTokens)` API for token-budget-bounded context retrieval
- **Lazy Context** (`LazyContextManager`) — Register context fragments (with type, priority, tags, size metadata) without loading content eagerly. Fragments fetched on demand when the task requests them, selected by keyword/tag relevance scoring. Supports TTL cache, LRU eviction, `preloadPriority()`, `loadByTags()` and `getSmartWindow(maxTokens, focusArea)` for fine-grained memory management
- **Tool Lazy Loading** (`ToolLoader` / `LazyToolResolver`) — Register tool classes without instantiating them; tools are loaded only when the model calls them. `predictAndPreload(task)` preheats tools based on task keywords. `loadForTask(task)` returns the minimal tool set. Idle tools can be unloaded between tasks to free memory
- **Sub-Agent Provider Inheritance** — `AgentTool` now receives the parent agent's provider config (API key, model, base URL) and injects it into each spawned sub-agent via `AgentSpawnConfig::$providerConfig`. Sub-agents created by `InProcessBackend` are real `SuperAgent\Agent` instances with real LLM connections, not no-op stubs
- **WebSearch Key-less Fallback** — `WebSearchTool` no longer errors when `SEARCH_API_KEY` is absent; it auto-falls back to DuckDuckGo HTML search via `WebFetchTool` (uses cURL or `file_get_contents`, browser-grade User-Agent)
- **WebFetch Hardening** — `WebFetchTool` now prefers cURL, checks HTTP status codes (4xx/5xx → error instead of silently returning the error page), and gives an explicit error when neither cURL nor `allow_url_fopen` is available

### 🆕 Multi-Agent Orchestration (v0.6.7)
- **Parallel Agent Execution** — Run multiple agents simultaneously with real-time progress tracking per agent
- **Claude-Code-Compatible Results** — Returns results in the exact Claude Code format for seamless integration
- **Automatic Task Detection** — Analyses task complexity and auto-selects single-agent or multi-agent mode
- **Agent Team Management** — Coordinate teams with leader/member relationships and role-based execution
- **Inter-Agent Communication** — `SendMessage` tool for inter-agent messaging and coordination
- **Persistent Mailbox System** — Reliable message queue with filtering, archiving and broadcast
- **Progress Aggregation** — Real-time token counting, activity tracking and cost aggregation across all agents
- **WebSocket Monitoring** — Browser-based real-time dashboard for parallel agent execution
- **Resource Pooling** — Agent pooling with concurrency limits and dependency management
- **Checkpoint & Resume** — Automatic state recovery for long-running multi-agent workflows

### 🎯 Auto-Mode Detection
- **Intelligent Task Analysis** — Automatically decides whether multi-agent collaboration is needed
- **Complexity Assessment** — Picks execution mode based on task complexity
- **Resource Optimisation** — Single agent for simple tasks, multi-agent parallel for complex ones

### 📊 Enterprise Features
- **Real-Time WebSocket Monitoring** — Browser-side live dashboard
- **Performance Profiling** — Comprehensive metrics and bottleneck analysis
- **Dependency Management** — Complex workflow orchestration with topological sorting
- **Distributed Scaling** — Run agents across multiple machines/processes
- **Persistent Storage** — Auto-save progress, supports crash recovery
- **Agent Pooling** — Pre-warmed agent pools for instant task assignment
- **Template System** — 10+ pre-built templates for common tasks

### 🔧 Powerful Toolset
- **59+ Built-in Tools** — File ops, code editing, web search, task management, and more
- **Security Validator** — 23 injection / obfuscation checks, command classification
- **Smart Context Compression** — Session-memory compression with semantic-boundary protection
- **Token Budget Control** — Dynamic budget management, smart cost control

### 🌍 Multi-Provider Support
- **Claude (Anthropic)** — Latest Claude 4.7 Opus / Sonnet, 4.5 Haiku (and all 4.6 / 4.5 / 4 variants)
- **OpenAI** — GPT-5 / GPT-5-mini / GPT-5-nano, GPT-4o, o4-mini, and legacy models
- **Google Gemini (native)** — `GeminiProvider` hits the Generative Language API directly with streaming SSE, function calling, and full MCP / Skills / sub-agent support. `superagent -p gemini -m gemini-2.5-flash "…"`
- **AWS Bedrock** — Claude via AWS, with the latest models
- **Ollama** — Local models including Llama 3, Mistral, and others
- **OpenRouter** — Unified API to 100+ models

### 🔄 Dynamic model catalog (v0.8.7)
- **`ModelCatalog`** — one JSON (`resources/models.json`) drives `CostCalculator` pricing, `ModelResolver` aliases, and the `/model` picker. Override at `~/.superagent/models.json` or fetch from `SUPERAGENT_MODELS_URL`
- **CLI**: `superagent models list | update | status | reset` to refresh model lists + pricing without a package release
- **Opt-in auto-refresh** with `SUPERAGENT_MODELS_AUTO_UPDATE=1` (7-day staleness check at CLI startup)

## 📦 Installation

### System Requirements
- PHP >= 8.1
- Composer
- Laravel >= 10.0 (optional — standalone use supported)

### Install via Composer

```bash
composer require forgeomni/superagent
```

### Laravel Project Installation

1. **Install the package:**
```bash
composer require forgeomni/superagent
```

2. **Publish the config file:**
```bash
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"
```

3. **Configure `.env`:**
```env
# Primary provider
SUPERAGENT_PROVIDER=anthropic
ANTHROPIC_API_KEY=your-api-key

# Optional providers
OPENAI_API_KEY=your-openai-key
GEMINI_API_KEY=your-gemini-key   # or GOOGLE_API_KEY
AWS_BEDROCK_REGION=us-east-1

# Model catalog auto-update (opt-in)
SUPERAGENT_MODELS_URL=https://your-cdn/models.json
SUPERAGENT_MODELS_AUTO_UPDATE=1

# Multi-agent features
SUPERAGENT_WEBSOCKET_ENABLED=true
SUPERAGENT_WEBSOCKET_PORT=8080
SUPERAGENT_STORAGE_PATH=storage/superagent

# Auto-mode detection
SUPERAGENT_AUTO_MODE=true
```

### Standalone Installation (no Laravel)

```bash
# Install the package
composer require forgeomni/superagent

# Create a config file
cp vendor/forgeomni/superagent/config/superagent.php config/superagent.php
```

```php
// Initialise in your app
use SuperAgent\SuperAgent;

$config = require 'config/superagent.php';
SuperAgent::initialize($config);
```

## 🚀 Quick Start

### Basic Agent

```php
use SuperAgent\Agent;

$agent = new Agent([
    'provider' => 'anthropic',
    'model' => 'claude-4.6-opus-latest',
]);

$result = $agent->run("Analyse this codebase and propose improvements");
echo $result->message->content;
```

### Auto Multi-Agent Mode

```php
use SuperAgent\Agent;

// Enable auto-detection
$agent = new Agent([
    'auto_mode' => true,  // auto-decide single vs multi-agent
]);

// Simple task — auto single-agent
$result = $agent->run("What is 2+2?");

// Complex task — auto multi-agent team
$result = $agent->run("
    Analyse the code quality of this project,
    find all security vulnerabilities,
    produce a detailed remediation plan,
    and generate test cases for every issue
");

// The system automatically analyses the task and decides:
// ✅ 4 sub-tasks detected
// ✅ Multiple tool types needed (code analysis, security scanning, documentation, test creation)
// ✅ Estimated tokens > 10,000
// → Auto-enables multi-agent mode
```

### Multi-Agent Team Orchestration

```php
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Console\Output\ParallelAgentDisplay;

// Create a team
$team = new TeamContext('research_team', 'team_leader');

// Set up the backend
$backend = new InProcessBackend();
$backend->setTeamContext($team);

// Spawn multiple agents
$agents = [
    $backend->spawn(new AgentSpawnConfig(
        name: 'Data Collector',
        prompt: 'Collect sales data from the database',
        teamName: 'research_team'
    )),
    $backend->spawn(new AgentSpawnConfig(
        name: 'Data Analyst',
        prompt: 'Analyse sales trends and anomalies',
        teamName: 'research_team'
    )),
    $backend->spawn(new AgentSpawnConfig(
        name: 'Report Writer',
        prompt: 'Write a report based on the analysis',
        teamName: 'research_team'
    ))
];

// Real-time progress monitoring
$display = new ParallelAgentDisplay($output);
$display->displayWithRefresh(500); // refresh every 500ms
```

### Inter-Agent Communication

```php
use SuperAgent\Tools\Builtin\SendMessageTool;

$messageTool = new SendMessageTool();

// Send a direct message to a specific agent
$messageTool->execute([
    'to' => 'researcher-agent',
    'message' => 'Please prioritise security best practices',
    'summary' => 'Priority update',
]);

// Broadcast to all agents
$messageTool->execute([
    'to' => '*',
    'message' => 'Team update: focus on performance optimisation',
    'summary' => 'Team announcement',
]);
```

### WebSocket Real-Time Monitoring

```bash
# Start the WebSocket server
php artisan superagent:websocket

# Open the monitoring dashboard
open http://localhost:8080/superagent/monitor
```

Dashboard features:
- 🔴 Real-time agent status indicators
- 📊 Per-agent token usage
- 💰 Cost aggregation and budget tracking
- 📈 Progress visualisation with ETA
- 📬 Message queue monitoring

### Using Agent Templates

```php
use SuperAgent\Swarm\Templates\AgentTemplateManager;

$templates = AgentTemplateManager::getInstance();

// Use a pre-built template — code reviewer
$config = $templates->createSpawnConfig('code_reviewer', [
    'repository' => '/path/to/repo',
    'focus_areas' => 'security, performance',
    'standards' => 'PSR-12, security best practices'
]);

$agent = $backend->spawn($config);

// Available template categories:
// - Data processing: data_processor, etl_pipeline
// - Code analysis: code_reviewer, security_scanner
// - Research: web_researcher, documentation_writer
// - Test generation: test_generator, performance_tester
// - Automation: ci_cd_agent, deployment_agent
```

### Dependency Management

```php
use SuperAgent\Swarm\Dependency\AgentDependencyManager;

$depManager = new AgentDependencyManager();

// Define an execution chain
$depManager->registerChain([
    'Data Extraction',
    'Data Cleaning',
    'Data Analysis',
    'Report Generation'
]);

// Define parallel tasks
$depManager->registerParallel([
    'Unit Tests',
    'Integration Tests',
    'Performance Tests'
]);

// Execute following dependencies automatically
$depManager->processWaitingAgents($backend);
```

## 📊 Real-Time Monitoring Dashboard

### Start the WebSocket Server

```bash
# Start the WebSocket server
php artisan superagent:websocket

# In another terminal, start the dashboard
php artisan superagent:dashboard

# Open http://localhost:8080/dashboard
```

### Dashboard Features
- 🔄 Real-time agent status updates
- 📈 Token usage and cost tracking
- 🎯 Task progress visualisation
- 📊 Performance metric charts
- 🌳 Team hierarchy view

## 🎯 Auto-Mode Detection Mechanism

SuperAgent judges task complexity along the following dimensions:

### Detection Dimensions
1. **Task Complexity Analysis**
   - Sub-task count detection
   - Task description length
   - Keyword pattern matching

2. **Tool Requirement Assessment**
   - Predicts the kinds of tools needed
   - Estimates tool-call frequency

3. **Token Estimation**
   - Input/output token prediction
   - Context window requirement

4. **Parallelism Opportunity Identification**
   - Parallelisable sub-tasks
   - Inter-task dependencies

### Trigger Thresholds
```php
// Configure auto-mode thresholds
'auto_mode' => [
    'enabled' => true,
    'threshold' => [
        'complexity_score' => 0.7,   // complexity score threshold
        'min_subtasks' => 3,         // minimum sub-tasks
        'min_tools' => 4,            // minimum tool types
        'estimated_tokens' => 10000, // estimated tokens
    ],
],
```

## 🛠️ Advanced Configuration

### Performance Optimisation

```php
// config/superagent.php
'performance' => [
    'pool' => [
        'enabled' => true,
        'min_idle_agents' => 2,       // minimum idle agents
        'max_idle_agents' => 10,      // maximum idle agents
        'max_agent_lifetime' => 3600, // max agent lifetime (seconds)
    ],
    'cache' => [
        'prompt_cache' => true,       // enable prompt caching
        'result_cache' => true,       // enable result caching
        'ttl' => 3600,                // cache TTL
    ],
],
```

### Security Settings

```php
'security' => [
    'bash_validator' => true,         // bash command validation
    'permission_mode' => 'standard',  // permission mode
    'max_file_size' => 10485760,      // max file size (10MB)
    'allowed_directories' => [        // allowed access directories
        base_path(),
        storage_path(),
    ],
],
```

## 📚 Full Documentation

- [Getting Started Guide](docs/getting-started.md)
- [Multi-Agent Orchestration](docs/PARALLEL_AGENT_TRACKING.md)
- [Advanced Features](docs/PARALLEL_AGENT_ENHANCEMENTS.md)
- [API Reference](docs/api-reference.md)
- [Examples](examples/)

## 🧪 Testing

```bash
# Run all tests
composer test

# Run unit tests
composer test:unit

# Run integration tests
composer test:integration

# Run multi-agent tests
php vendor/bin/phpunit tests/Unit/ParallelAgentTrackingTest.php
```

## 📈 Performance Benchmarks

| Metric | 1 Agent | 10 Agents | 100 Agents | 1000 Agents |
|--------|---------|-----------|------------|-------------|
| Memory overhead | 2 MB | 15 MB | 120 MB | 1.1 GB |
| Tracking latency | <1ms | <2ms | <10ms | <50ms |
| WebSocket broadcast | N/A | 5ms | 20ms | 100ms |
| Storage writes | 1ms | 5ms | 50ms | 500ms |

## 🤝 Contributing

Contributions welcome! See the [Contributing Guide](CONTRIBUTING.md) for details.

### Development Roadmap
- [ ] Support for more LLM providers
- [ ] GraphQL API support
- [ ] Kubernetes-native deployment
- [ ] Agent marketplace
- [ ] Visual workflow editor

## 📄 License

SuperAgent is open-source software licensed under the [MIT License](LICENSE).

## 🙏 Acknowledgements

- Inspired by the Claude Code architecture
- Thanks to the Laravel and PHP communities
- Special thanks to Anthropic for the Claude API

## 🔗 Related Links

- [GitHub Repository](https://github.com/forgeomni/superagent)
- [Discord Community](https://discord.gg/superagent)
- [Example Projects](https://github.com/forgeomni/superagent-examples)

---
