# SuperAgent - Enterprise Multi-Agent Orchestration SDK for Laravel 🚀

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.7.1-purple)](https://github.com/xiyanyang/superagent)

> **🌍 Language**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)  
> **📖 Documentation**: [Installation Guide](INSTALL.md) | [安装手册](INSTALL_CN.md) | [Guide d'Installation](INSTALL_FR.md) | [Advanced Usage](docs/ADVANCED_USAGE.md) | [API Docs](docs/)

SuperAgent is a powerful enterprise-grade Laravel AI Agent SDK that enables Claude-level capabilities with multi-agent orchestration, real-time monitoring, and distributed scaling. Build and deploy AI agent teams that work in parallel with automatic task detection and intelligent resource management.

## 🚀 Features

### Core Features
- **Multi-Provider Support** - Anthropic (Claude 4.6), OpenAI (GPT-5.4), Bedrock, OpenRouter and more
- **59+ Built-in Tools** - File operations, code editing, web search, task management, tool search and more (core tools always available; experimental tools gated by feature flags)
- **Streaming Output** - Real-time responses for better user experience
- **Cost Tracking** - Accurate token usage and cost statistics

### Advanced Features
- **Permission System** - 6 permission modes with intelligent security control
- **Bash Security Validator** - 23 injection/obfuscation checks (command substitution, IFS injection, Unicode whitespace, Zsh attacks, obfuscated flags, parser differentials) with read-only command classification
- **Lifecycle Hooks** - Hook into tool execution pipeline with permission decisions (allow/deny/ask), input modification, and stop hooks pipeline (Stop → TaskCompleted → TeammateIdle)
- **Smart Context Compaction** - Session memory compressor with semantic boundary protection (tool_use/tool_result pair preservation, min token/message expansion, 9-section structured summary), micro compressor, and conversation compressor with analysis scratchpad stripping
- **Token Budget Continuation** - Dynamic budget-based agent loop control (90% completion threshold, diminishing returns detection) replacing fixed maxTurns
- **Memory System** - Cross-session persistence with real-time session memory extraction (3-gate trigger: 10K init, 5K growth, 3 tool calls), KAIROS append-only daily logs, and auto-dream nightly consolidation into MEMORY.md
- **Extended Thinking** - Adaptive/enabled/disabled modes, ultrathink keyword trigger, model capability detection (Claude 4+), budget token management
- **Coordinator Mode** - Dual-mode architecture: Coordinator (pure synthesis/delegation with Agent/SendMessage/TaskStop) vs Worker (full execution tools), with 4-phase workflow and session mode persistence

### 🆕 Multi-Agent Orchestration (v0.6.7)
- **Parallel Agent Execution** - Run multiple agents simultaneously with real-time progress tracking for each agent
- **Claude Code-Compatible Results** - Returns results in exact Claude Code format for seamless integration
- **Automatic Task Detection** - Analyzes task complexity and automatically decides single vs multi-agent mode
- **Agent Team Management** - Coordinate teams with leader/member relationships and role-based execution
- **Inter-Agent Communication** - SendMessage tool for agent-to-agent messaging and coordination
- **Persistent Mailbox System** - Reliable message queues with filtering, archiving, and broadcast
- **Progress Aggregation** - Real-time token counting, activity tracking, and cost aggregation across all agents
- **WebSocket Monitoring** - Live browser-based dashboard for monitoring parallel agent execution
- **Resource Pooling** - Intelligent agent pooling with concurrency limits and dependency management
- **Checkpoint & Resume** - Automatic state recovery for long-running multi-agent workflows
- **Batch Skill** - `/batch` command for parallel large-scale changes across 5–30 isolated worktree agents, each opening a PR
- **MCP Protocol** - Integration with Model Context Protocol ecosystem, with server instruction injection into system prompt
- **Prompt Cache Optimization** - Dynamic system prompt assembly with static/dynamic boundary for prompt caching
- **Telemetry Master Switch** - Hierarchical telemetry control: master `telemetry.enabled` gate plus per-subsystem toggles (logging, metrics, events, cost_tracking) — when master is off, no data is collected regardless of individual settings
- **Security Prompt Guardrails** - Optional safety instructions injected into the system prompt to restrict security-related operations; configurable via `security_guardrails` flag
- **Guardrails DSL** - Declarative YAML rule engine for security, cost, compliance, and rate-limiting policies. Supports composable conditions (`all_of`/`any_of`/`not`), 7 condition types (tool, tool_content, tool_input, session, agent, token, rate), 8 action types (deny, allow, ask, warn, log, pause, rate_limit, downgrade_model), priority-ordered rule groups, and integration with the PermissionEngine pipeline
- **Bridge Mode** - Provider-agnostic enhancement proxy that injects CC optimization mechanisms (system prompt enhancement, context compaction, bash security, memory injection, tool schema optimization, cost tracking) into non-Anthropic models (OpenAI, Bedrock, Ollama, OpenRouter). Supports both HTTP proxy mode (for Codex CLI etc.) and SDK auto-enhance mode with 3-level priority control (`bridge_mode` param > config `auto_enhance` > feature flag)
- **Pipeline DSL** - Declarative YAML workflow engine for multi-agent pipelines. Supports 6 step types (agent, parallel, conditional, approval, transform, loop), dependency-based topological execution order, 3 failure strategies (abort, continue, retry), inter-step data flow via `{{steps.name.output}}` templates, `input_from` context injection, approval gates with pluggable handlers, review-fix loops with multi-model parallel review and configurable exit conditions (`output_contains`, `all_passed`, `any_passed`, `expression`), and event listeners for observability
- **Cost Autopilot** - Intelligent budget control that monitors cumulative spending and automatically escalates through cost-saving actions: warn → compact context → downgrade model (Opus → Sonnet → Haiku) → halt. Supports session and monthly budgets, persistent cross-session spending tracker, configurable threshold percentages, auto-detected model tier hierarchies per provider, and event listeners for cost observability
- **Adaptive Feedback** - Learns from user corrections (permission denials, edit reverts, behavior feedback) and automatically promotes recurring patterns to Guardrails rules or Memory entries. Configurable promotion threshold, 5 correction categories, persistent JSON storage, full management CLI (`superagent:feedback list|show|delete|clear|export|import|promote|stats`), import/export for sharing across projects
- **Skill Distillation** - Automatically distills successful expensive-model executions (Opus) into reusable step-by-step skill templates that cheaper models (Haiku) can follow. Captures tool call sequences, generalizes file paths into parameters, selects optimal target model tier, estimates cost savings, and tracks usage. Full management CLI (`superagent:distill list|show|delete|clear|export|import|stats`)
- **Checkpoint & Resume** - Periodically snapshots agent state (messages, turn count, cost, tokens) to disk during long-running tasks. Resume from any checkpoint after crashes or interruptions. Per-task override (`options['checkpoint'] = true`) takes priority over config toggle. Auto-prunes old checkpoints. Full management CLI (`superagent:checkpoint list|show|delete|clear|prune|stats`)
- **Knowledge Graph** - Cross-agent shared knowledge graph that automatically tracks which files were read/modified/created by which agents, records search patterns, bash commands, decisions, symbol definitions, and file dependencies. Subsequent agents query the graph instead of re-exploring the codebase. Provides hot-file ranking, per-agent file lists, per-file agent lists, and a token-efficient summary for system prompt injection
- **Smart Context Window** - Dynamically allocates tokens between thinking budget and context window based on task complexity analysis. Complex reasoning tasks (refactor, architect, debug) get 60% thinking + 40% context with aggressive compaction; simple tasks (list, read, show) get 15% thinking + 85% context preserving full history. Per-task override (`options['context_strategy']`) takes priority over config
- **Experimental Feature Flags** - 22 granular feature flags (with master switch) to gate experimental capabilities: ultrathink, token budget, prompt cache detection, builtin agents, verification agent, plan interview, agent triggers (local/remote), memory extraction, compaction reminders, cached microcompact, team memory, bash classifier, bridge mode, pipelines, cost autopilot, adaptive feedback
- **Observability** - OpenTelemetry integration with complete tracing and per-event-type analytics sampling rate control
- **File History** - LRU cache (100 message-level snapshots) with per-message rewind, diff stats (insertions/deletions/filesChanged), and snapshot inheritance
- **Tool Use Summaries** - Haiku-generated git-commit-subject-style summaries after tool batches
- **Tool Search & Deferred Loading** - Fuzzy keyword search with scoring, select mode, auto-threshold deferred loading (10% context window)
- **Remote Agent Tasks** - Out-of-process agent execution via API triggers with cron scheduling
- **Plan V2 Interview Phase** - Iterative pair-planning with structured plan files, periodic reminders, and user approval before execution
- **Claude Code Compatibility** - Auto-load skills, agents, and MCP configs from Claude Code directories

### 🆕 v0.7.1 — Execution Performance Suite (8 strategies)
- **Parallel Tool Execution** — Execute read-only tools (Read, Grep, Glob, WebSearch) in parallel using PHP Fibers. Multiple tool_use blocks in one turn run concurrently: time = max(t1,t2,t3) instead of sum. Config: `performance.parallel_tool_execution`
- **Streaming Tool Dispatch** — Start tool execution as soon as tool_use block is fully received during SSE streaming, before the complete LLM response arrives. Config: `performance.streaming_tool_dispatch`
- **HTTP Connection Pooling** — Reuse TCP/TLS connections across API calls with cURL keep-alive, TCP_NODELAY, and shared multi handler. Eliminates repeated handshake latency. Config: `performance.connection_pool`
- **Speculative Prefetch** — After Read tool executes, predict and pre-read related files (tests, interfaces, configs in same directory). Subsequent reads hit memory cache. Config: `performance.speculative_prefetch`
- **Streaming Bash Executor** — Stream Bash output with timeout truncation. Long output returns last N lines + summary header instead of waiting for full completion. Config: `performance.streaming_bash`
- **Adaptive max_tokens** — Dynamically set max_tokens per turn: 2048 for pure tool-call turns, 8192 for reasoning. Reduces reserved capacity waste. Config: `performance.adaptive_max_tokens`
- **Batch API Support** — Queue non-realtime sub-agent requests for Anthropic Message Batches API (50% cost reduction). Config: `performance.batch_api`
- **Local Tool Zero-Copy** — File content cache between Read/Edit/Write tools. Read result cached in memory, Edit/Write invalidates cache. Eliminates redundant disk I/O. Config: `performance.local_tool_zero_copy`

### 🆕 v0.7.0 — Performance Optimization Suite (5 strategies, all configurable)
- **Tool Result Compaction** — Automatically compacts old tool results (beyond recent N turns) into concise summaries, reducing input tokens by 30-50%. Preserves error results and recent context intact. Config: `optimization.tool_result_compaction` (`enabled`, `preserve_recent_turns`, `max_result_length`)
- **Selective Tool Schema** — Dynamically selects relevant tool subset per turn based on task phase (explore/edit/plan), saving ~10K tokens by omitting unused tool schemas. Always includes recently-used tools. Config: `optimization.selective_tool_schema` (`enabled`, `max_tools`)
- **Per-Turn Model Routing** — Auto-downgrades to fast model (configurable, default Haiku) for pure tool-call turns, upgrades back for reasoning. Detects consecutive tool-only turns and routes accordingly. 40-60% cost reduction. Config: `optimization.model_routing` (`enabled`, `fast_model`, `min_turns_before_downgrade`)
- **Response Prefill** — Uses Anthropic's assistant prefill to guide output format after extended tool-call sequences, encouraging summarization over more tool calls. Conservative strategy: only prefills after 3+ consecutive tool turns. Config: `optimization.response_prefill` (`enabled`)
- **Prompt Cache Pinning** — Auto-inserts cache boundary marker in system prompts that lack one, splitting static (tool descriptions, role) from dynamic (memory, context) sections. Achieves ~90% prompt cache hit rate. Config: `optimization.prompt_cache_pinning` (`enabled`, `min_static_length`)
- **All optimizations default to enabled** and can be individually disabled via env vars (`SUPERAGENT_OPT_TOOL_COMPACTION`, `SUPERAGENT_OPT_SELECTIVE_TOOLS`, `SUPERAGENT_OPT_MODEL_ROUTING`, `SUPERAGENT_OPT_RESPONSE_PREFILL`, `SUPERAGENT_OPT_CACHE_PINNING`)
- **No hardcoded model IDs** — Fast model for routing is fully configurable via `SUPERAGENT_OPT_FAST_MODEL`; cheap model detection uses heuristic name matching instead of hardcoded lists

### 🆕 v0.6.19 — In-Process NDJSON Logging for Process Monitor
- **`NdjsonStreamingHandler`** (`src/Logging/NdjsonStreamingHandler.php`) — Factory class for creating a `StreamingHandler` that writes CC-compatible NDJSON to any log file or stream. One-liner integration for in-process agent execution (`$agent->prompt()` calls that don't go through `agent-runner.php`/`ProcessBackend`)
- **`create(logTarget, agentId)`** — Returns a `StreamingHandler` with `onToolUse`, `onToolResult`, and `onTurn` callbacks wired to `NdjsonWriter`. Accepts file path (auto-creates directories) or writable stream resource
- **`createWithWriter(logTarget, agentId)`** — Returns `{handler, writer}` pair so callers can also emit `writeResult()`/`writeError()` after execution completes. The writer and handler share the same NDJSON stream
- **Process Monitor Compatible** — Log files contain the same NDJSON format as child process stderr, enabling `parseStreamJsonIfNeeded()` to display tool activity (🔧 Read, Edit, Grep, etc.), token counts, and execution status for in-process agents

### 🆕 v0.6.18 — Claude Code-Compatible NDJSON Structured Logging
- **`NdjsonWriter`** (`src/Logging/NdjsonWriter.php`) — New class that writes Claude Code-compatible NDJSON (Newline Delimited JSON) events to any writable stream. Supports 5 event methods: `writeAssistant()` (LLM turn with text/tool_use content blocks + per-turn usage), `writeToolUse()` (single tool call), `writeToolResult()` (tool execution result as `type:user` with `parent_tool_use_id`), `writeResult()` (success with usage/cost/duration), `writeError()` (error with subtype). Escapes U+2028/U+2029 line separators matching CC's `ndjsonSafeStringify`
- **NDJSON Replaces `__PROGRESS__:` Protocol** — `agent-runner.php` now uses `NdjsonWriter` on stderr instead of the custom `__PROGRESS__:` prefix. Events are standard NDJSON lines parseable by CC's bridge/sessionRunner `extractActivities()`. Each assistant event includes per-turn `usage` (inputTokens, outputTokens, cacheReadInputTokens, cacheCreationInputTokens) for real-time token tracking
- **ProcessBackend NDJSON Parsing** — `ProcessBackend::poll()` upgraded to detect NDJSON lines (JSON objects starting with `{`) alongside legacy `__PROGRESS__:` lines. Non-JSON stderr lines (e.g. `[agent-runner]` log messages) continue forwarding to the PSR-3 logger
- **AgentTool CC Format Support** — `applyProgressEvents()` now handles both CC NDJSON format (`assistant` → extract tool_use blocks + usage, `user` → tool_result, `result` → final usage) and legacy format, enabling seamless process monitor integration

### 🆕 v0.6.17 — Real-Time Child Agent Progress Monitoring
- **Structured Progress Events** — Child agent processes now emit structured JSON progress events on stderr using the `__PROGRESS__:` protocol. Events include `tool_use` (tool name, input), `tool_result` (success/error, result size), and `turn` (token usage per LLM turn)
- **StreamingHandler in Child Processes** — `agent-runner.php` creates a `StreamingHandler` with `onToolUse`, `onToolResult`, and `onTurn` callbacks that serialize execution events to the parent. Changed from `Agent::run()` to `Agent::prompt()` to pass the handler
- **ProcessBackend Event Parsing** — `ProcessBackend::poll()` now detects `__PROGRESS__:` prefixed lines in stderr, parses them as JSON, and queues them per agent. New `consumeProgressEvents(agentId)` method returns and clears queued events. Regular log lines are still forwarded to the logger as before
- **AgentTool Coordinator Integration** — `waitForProcessCompletion()` registers child agents with `ParallelAgentCoordinator` and feeds progress events into `AgentProgressTracker` on each poll cycle. The tracker updates tool use count, current activity description (e.g. "Editing /src/Agent.php"), token counts, and recent activity list in real time
- **Process Monitor Visibility** — `ParallelAgentDisplay` now shows live child agent progress (current tool, token count, tool use count) without any display code changes — the existing UI reads from the coordinator's trackers which are now populated for process-based agents

### 🆕 v0.6.16 — Parent-to-Child Registration Propagation
- **Agent Definition Propagation** — Parent process serializes all registered agent definitions (builtin + custom from `.claude/agents/`) via `AgentManager::exportDefinitions()` and passes them in the stdin JSON. Child processes import them via `importDefinitions()` before creating their Agent — no Laravel bootstrap or filesystem access required
- **MCP Server Config Propagation** — Parent serializes all registered MCP server configs (`ServerConfig::toArray()`) and passes them to children. Child processes register them via `MCPManager::registerServer()`, making MCP tools available without re-reading config files or `.mcp.json`
- **Verified** — Child process receives 9 agent types (7 builtin + 2 custom with full system prompts), 2 MCP servers (stdio + http), 6 built-in skills, and 58 tools

### 🆕 v0.6.15 — MCP Server Sharing via TCP Bridge
- **MCP TCP Bridge** (`MCPBridge`) — When the parent process connects to a stdio MCP server, a lightweight TCP proxy is automatically started on a random port (`127.0.0.1`). Child processes discover the bridge via a registry file and connect via `HttpTransport` instead of spawning their own MCP server. N child agents share 1 MCP server process
- **MCPManager Auto-Detection** — `createTransport()` checks for parent bridges before creating `StdioTransport`. If found, uses `HttpTransport` to `localhost:{port}` transparently
- **ProcessBackend Bridge Polling** — `poll()` now also calls `MCPBridge::poll()` to service incoming TCP requests from child processes

### 🆕 v0.6.12 — Child Process Laravel Bootstrap & Provider Fix
- **Laravel Bootstrap in Child Processes** — `agent-runner.php` now performs full Laravel bootstrap (`$app->make(Kernel)->bootstrap()`) when a `base_path` is provided. This gives child processes access to `config()`, `AgentManager`, `SkillManager`, `MCPManager`, `.claude/agents/` directories, and all service providers — identical to the parent process
- **Provider Config Serialization Fix** — When `Agent` was constructed with a `LLMProvider` object (not a string), the object was JSON-serialized as `{}`, leaving child processes without API credentials. `injectProviderConfigIntoAgentTools()` now replaces provider objects with `$provider->name()`, pulls `api_key` from Laravel config if not in constructor args, and always sets provider name and model from the resolved provider
- **Full Tool Set in Child Processes** — `ProcessBackend` now sets `load_tools='all'` (58 tools) by default instead of the 5-tool default set. Child agents have access to `agent`, `skill`, `mcp`, `web_search`, and all other tools

### 🆕 v0.6.11 — True Process-Level Parallel Agents
- **Process-Based Sub-Agents** — `AgentTool` now defaults to `ProcessBackend` (`proc_open`) instead of `InProcessBackend` (Fiber). Each sub-agent runs in its own OS process with its own Guzzle connection, achieving true parallelism. PHP Fibers are cooperative — blocking I/O (HTTP calls, bash commands) inside a fiber blocks the entire process, making the old approach sequential in practice
- **Rewritten `bin/agent-runner.php`** — One-shot runner: reads JSON config from stdin, creates a real `SuperAgent\Agent` with full LLM provider and tools, executes the prompt, writes JSON result to stdout. No interactive message loop
- **`ProcessBackend` Overhaul** — `spawn()` writes config via stdin then closes it; `poll()` non-blocking drains stdout/stderr; `waitAll()` blocks until all tracked agents finish. Verified: 5 agents each sleeping 500ms complete in 544ms total (4.6x speedup vs sequential)
- **InProcessBackend Fallback** — Fiber-based backend is kept as fallback when `proc_open` is unavailable (e.g. restricted hosting)

### 🆕 v0.6.10 — Multi-Agent Synchronous Execution Fix
- **Synchronous Agent Deadlock Fix** — `InProcessBackend::spawn()` now always prepares the execution fiber regardless of `runInBackground`. Previously, synchronous mode never created the fiber, causing `waitForSynchronousCompletion()` to poll forever (5-minute timeout deadlock)
- **Backend Type Mismatch Fix** — `AgentTool::$activeTasks` now stores the actual backend instance alongside the `BackendType` enum. The synchronous wait loop was calling `->getStatus()` and `instanceof InProcessBackend` on the enum value, which always returned wrong results
- **Fiber Lifecycle Fix** — `ParallelAgentCoordinator::processAllFibers()` now handles unstarted fibers (`!$fiber->isStarted()` → `start()`), enabling the synchronous caller to drive fiber execution. Fixed missing `$status` property on `AgentProgressTracker` and null usage type errors in stub agents

### 🆕 v0.6.9 — Guzzle Base URL Path Fix
- **Multi-Provider Base URL Fix** — `OpenAIProvider`, `OpenRouterProvider`, and `OllamaProvider` now correctly append a trailing slash to `base_uri` and use relative request paths. Previously, any custom `base_url` with a path prefix (e.g. `https://gateway.example.com/openai`) would have its path silently stripped by Guzzle's RFC 3986 resolver when an absolute path like `/v1/chat/completions` was used. All four providers (`AnthropicProvider` was fixed in v0.6.8) now follow the correct pattern

### 🆕 v0.6.8 — Incremental Context & Tool Lazy Loading
- **Incremental Context** (`IncrementalContextManager`) — Delta-based context synchronization: only the diff (added/modified/removed messages) is transmitted instead of the full history. Automatic checkpoints, one-step restore, configurable auto-compress on token thresholds, and a `getSmartWindow(maxTokens)` API for token-budgeted context retrieval
- **Lazy Context Loading** (`LazyContextManager`) — Register context fragments with metadata (type, priority, tags, size) without loading their content. Fragments are fetched on demand when a task requests them, scored by keyword/tag relevance. TTL cache, LRU eviction, `preloadPriority()`, `loadByTags()`, and `getSmartWindow(maxTokens, focusArea)` for fine-grained memory management
- **Tool Lazy Loading** (`ToolLoader` / `LazyToolResolver`) — Register tool classes without instantiating them; tools are loaded the moment the model calls them. `predictAndPreload(task)` pre-warms tools based on task keywords. `loadForTask(task)` returns the minimal tool set. Unload unused tools to free memory between tasks
- **Sub-Agent Provider Inheritance** — `AgentTool` now receives the parent agent's provider config (API key, model, base URL) and injects it into every spawned sub-agent via `AgentSpawnConfig::$providerConfig`. Sub-agents created by `InProcessBackend` are real `SuperAgent\Agent` instances with a live LLM connection instead of the no-op stub
- **WebSearch No-Key Fallback** — `WebSearchTool` no longer hard-errors when `SEARCH_API_KEY` is unset. It falls back to DuckDuckGo HTML search via `WebFetchTool`, which uses cURL (preferred) or `file_get_contents` with a browser-grade User-Agent
- **WebFetch Hardening** — `WebFetchTool` now prefers cURL over `file_get_contents`; checks HTTP status codes (4xx/5xx → error instead of silent body return); provides a clear error when both cURL and `allow_url_fopen` are unavailable

## 📦 Installation

### System Requirements
- PHP >= 8.1
- Laravel >= 10.0
- Composer >= 2.0

### Install via Composer

```bash
composer require forgeomni/superagent
```

### Publish Configuration

```bash
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"
```

### Configure Environment Variables

Add to your `.env` file:

```env
# Anthropic
ANTHROPIC_API_KEY=your_anthropic_api_key

# OpenAI (optional)
OPENAI_API_KEY=your_openai_api_key

# AWS Bedrock (optional)
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_DEFAULT_REGION=us-east-1

# OpenRouter (optional)
OPENROUTER_API_KEY=your_openrouter_api_key
```

📋 **Quick Links**: [Installation Guide](INSTALL.md) | [中文安装手册](INSTALL_CN.md) | [中文版本](README_CN.md)

## 🎯 Quick Start

### Basic Usage

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// Create configuration
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-4.6-haiku-latest',
    ],
    'streaming' => true,
]);

// Initialize Agent
$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

// Execute query
$response = $agent->query("Analyze performance issues in this code");
echo $response->content;
```

### Streaming Response

```php
// Enable streaming output
$stream = $agent->stream("Write a quicksort algorithm");

foreach ($stream as $chunk) {
    if (isset($chunk['content'])) {
        echo $chunk['content'];  // Real-time output
    }
}
```

### Using Tools

```php
use SuperAgent\Tools\Builtin\FileReadTool;
use SuperAgent\Tools\Builtin\FileWriteTool;
use SuperAgent\Tools\Builtin\BashTool;

// Register tools
$agent->registerTool(new FileReadTool());
$agent->registerTool(new FileWriteTool());
$agent->registerTool(new BashTool());

// Agent will automatically use tools to complete tasks
$response = $agent->query("Read config.php file, analyze configuration and provide optimization suggestions");
```

### 🤖 Multi-Agent Orchestration (NEW in v0.6.7)

#### Automatic Multi-Agent Mode
```php
use SuperAgent\Agent;

// Enable auto-mode - no configuration needed!
$agent = new Agent($provider, $config);
$agent->enableAutoMode();

// Agent automatically detects when to use multiple agents
$result = $agent->run("
1. Research best practices for API design
2. Write a REST API with authentication
3. Create comprehensive tests
4. Document the API endpoints
");

// Result contains aggregated outputs from all agents
echo $result->text();
echo "Total cost: $" . $result->totalCostUsd();
```

#### Manual Agent Team Creation
```php
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Swarm\ParallelAgentCoordinator;

// Create agent tool
$agentTool = new AgentTool();

// Spawn multiple specialized agents
$researcher = $agentTool->execute([
    'description' => 'Research task',
    'prompt' => 'Research best practices for REST API design',
    'subagent_type' => 'researcher',
    'run_in_background' => true,
]);

$coder = $agentTool->execute([
    'description' => 'Code implementation',
    'prompt' => 'Implement a REST API with JWT authentication',
    'subagent_type' => 'code-writer',
    'run_in_background' => true,
]);

// Monitor progress
$coordinator = ParallelAgentCoordinator::getInstance();
$teamResult = $coordinator->collectTeamResults();

// Get individual agent results
foreach ($teamResult->getResultsByAgent() as $agentName => $result) {
    echo "Agent: $agentName\n";
    echo $result->text() . "\n";
}
```

#### Inter-Agent Communication
```php
use SuperAgent\Tools\Builtin\SendMessageTool;

$messageTool = new SendMessageTool();

// Send direct message to specific agent
$messageTool->execute([
    'to' => 'researcher-agent',
    'message' => 'Please prioritize security best practices',
    'summary' => 'Priority update',
]);

// Broadcast to all agents
$messageTool->execute([
    'to' => '*',
    'message' => 'Team update: Focus on performance optimization',
    'summary' => 'Team announcement',
]);
```

### Multiple Provider Instances

You can register multiple Anthropic-compatible APIs (or any provider) with different configurations, and select which one to use per Agent:

```php
// config/superagent.php
'default_provider' => 'anthropic',
'providers' => [
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-sonnet-4-20250514',
    ],
    'my-proxy' => [
        'driver' => 'anthropic',           // Reuse AnthropicProvider class
        'api_key' => env('MY_PROXY_KEY'),
        'base_url' => 'https://proxy.example.com',
        'model' => 'claude-sonnet-4-20250514',
    ],
    'another-api' => [
        'driver' => 'anthropic',
        'api_key' => env('ANOTHER_API_KEY'),
        'base_url' => 'https://another.example.com',
        'model' => 'claude-4.6-haiku-latest',
    ],
],
```

Then specify which provider to use when creating an Agent:

```php
use SuperAgent\Agent;

$agent1 = new Agent(['provider' => 'anthropic']);     // Official Anthropic API
$agent2 = new Agent(['provider' => 'my-proxy']);       // Proxy API
$agent3 = new Agent(['provider' => 'another-api']);    // Another compatible API
```

The `driver` field determines which provider class to instantiate, while the config key (e.g. `my-proxy`) serves as the instance name for selection. If `driver` is omitted, the config key itself is used as the driver name, maintaining backward compatibility.

**Supported driver types:**

| Driver | Provider Class | Description |
|--------|---------------|-------------|
| `anthropic` | `AnthropicProvider` | Anthropic Claude API and compatible endpoints |
| `openai` | `OpenAIProvider` | OpenAI API and compatible endpoints (e.g. DeepSeek, Azure OpenAI) |
| `openrouter` | `OpenRouterProvider` | OpenRouter multi-model gateway |
| `bedrock` | `BedrockProvider` | AWS Bedrock managed AI service |
| `ollama` | `OllamaProvider` | Ollama local model runtime |

## 🛠 Advanced Features

### Permission Management

```php
use SuperAgent\Permissions\PermissionMode;

// Set permission mode
$config->permissions->mode = PermissionMode::AcceptEdits; // Auto-approve file edits

// Custom permission callback
$config->permissions->callback = function($tool, $params) {
    // Deny delete operations
    if ($tool === 'bash' && str_contains($params['command'], 'rm')) {
        return false;
    }
    return true;
};
```

### Hook System

```php
use SuperAgent\Hooks\HookRegistry;

$hooks = HookRegistry::getInstance();

// Register pre-tool-use hook
$hooks->register('pre_tool_use', function($data) {
    logger()->info('Tool usage', $data);
    return $data;
});

// Register post-query hook
$hooks->register('on_query_complete', function($response) {
    // Save to database
    DB::table('agent_logs')->insert([
        'response' => $response->content,
        'timestamp' => now(),
    ]);
});
```

### Context Compression

```php
// Configure auto-compression
$config->context->autoCompact = true;
$config->context->compactThreshold = 3000; // Token threshold
$config->context->compactStrategy = 'smart'; // Compression strategy

// Manually trigger compression
$agent->compactContext();
```

### Task Management

```php
use SuperAgent\Tasks\TaskManager;

$taskManager = TaskManager::getInstance();

// Create task
$task = $taskManager->createTask([
    'subject' => 'Optimize database queries',
    'description' => 'Analyze and optimize slow queries in the system',
    'status' => 'pending',
    'metadata' => ['priority' => 'high'],
]);

// Update task progress
$taskManager->updateTask($task->id, [
    'status' => 'in_progress',
    'metadata' => ['progress' => 50],
]);
```

### MCP Integration

```php
use SuperAgent\MCP\MCPManager;
use SuperAgent\MCP\Types\ServerConfig;

$mcpManager = MCPManager::getInstance();

// Register MCP server (use static factory methods)
$config = ServerConfig::stdio(
    name: 'github-mcp',
    command: 'npx',
    args: ['-y', '@modelcontextprotocol/server-github'],
    env: ['GITHUB_TOKEN' => env('GITHUB_TOKEN')]
);

$mcpManager->registerServer($config);
$mcpManager->connect('github-mcp');

// MCP tools will be automatically registered with Agent
```

### Observability

```php
use SuperAgent\Telemetry\SimpleTracingManager;
use SuperAgent\Telemetry\MetricsCollector;

// Enable tracing (via SimpleTracingManager)
$tracer = SimpleTracingManager::getInstance();
$spanId = $tracer->startSpan('agent.query', 'api');

// Record metrics
$metrics = MetricsCollector::getInstance();
$metrics->incrementCounter('api.requests');
$metrics->recordHistogram('response.time', 150.5);
$metrics->recordTiming('query.duration', 320.0);
```

### Telemetry Master Switch

All telemetry subsystems are gated by a master switch. When `telemetry.enabled` is `false`, no telemetry data is collected regardless of individual subsystem settings:

```env
# Master switch — must be true for any telemetry to function
SUPERAGENT_TELEMETRY_ENABLED=false

# Individual subsystem toggles (only effective when master is ON)
SUPERAGENT_TELEMETRY_LOGGING=false
SUPERAGENT_TELEMETRY_METRICS=false
SUPERAGENT_TELEMETRY_EVENTS=false
SUPERAGENT_TELEMETRY_COST_TRACKING=false
```

### Security Prompt Guardrails

When enabled, additional safety instructions are injected into the system prompt to restrict security-related operations (e.g. refusing destructive techniques, requiring authorization context for dual-use security tools):

```env
SUPERAGENT_SECURITY_GUARDRAILS=false
```

### Guardrails DSL

Declarative YAML rule engine for security, cost, compliance, and rate-limiting policies. Rules are evaluated on every tool call within the PermissionEngine pipeline.

```env
SUPERAGENT_GUARDRAILS_ENABLED=true
```

```yaml
# guardrails.yaml
version: "1.0"

groups:
  security:
    priority: 100
    rules:
      - name: block_sensitive_paths
        conditions:
          any_of:
            - tool_content: { contains: ".git/" }
            - tool_content: { contains: ".env" }
            - tool_content: { contains: ".ssh/" }
        action: deny
        message: "Access to sensitive path blocked"

      - name: block_destructive_bash
        conditions:
          all_of:
            - tool: { name: Bash }
            - tool_input: { field: command, matches: "rm -rf *" }
        action: deny
        message: "Destructive command blocked"

  cost:
    priority: 90
    rules:
      - name: session_cost_limit
        conditions:
          session: { cost_usd: { gt: 5.00 } }
        action: deny
        message: "Session cost exceeded $5.00"

      - name: auto_downgrade
        conditions:
          session: { budget_pct: { gt: 80 } }
        action: downgrade_model
        params: { target_model: "claude-haiku-4-5-20251001" }
```

Configure in `config/superagent.php`:

```php
'guardrails' => [
    'enabled' => env('SUPERAGENT_GUARDRAILS_ENABLED', false),
    'files' => [
        base_path('guardrails.yaml'),
    ],
    'integration' => 'permission_engine',
],
```

**Supported conditions**: `tool`, `tool_content`, `tool_input`, `session`, `agent`, `token`, `rate`, with `all_of`/`any_of`/`not` combinators.

**Supported actions**: `deny`, `allow`, `ask`, `warn`, `log`, `pause`, `rate_limit`, `downgrade_model`.

See `examples/guardrails.yaml` for a complete reference.

### Experimental Feature Flags

Granular feature flags allow you to enable or disable experimental capabilities independently. All default to `true` (enabled) when the master switch is on:

```env
# Master switch — set to false to disable all experimental features
SUPERAGENT_EXPERIMENTAL=true

# Individual feature toggles
SUPERAGENT_EXP_ULTRATHINK=true           # "ultrathink" keyword boosts reasoning budget
SUPERAGENT_EXP_TOKEN_BUDGET=true          # Token budget tracking and usage warnings
SUPERAGENT_EXP_PROMPT_CACHE=true          # Prompt cache-break detection
SUPERAGENT_EXP_BUILTIN_AGENTS=true        # Explore/Plan agent presets
SUPERAGENT_EXP_VERIFICATION_AGENT=true    # Verification agent for task validation
SUPERAGENT_EXP_PLAN_INTERVIEW=true        # Plan V2 interview phase workflow
SUPERAGENT_EXP_AGENT_TRIGGERS=true        # Local cron/trigger tools
SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE=true # Remote trigger tool (API-based)
SUPERAGENT_EXP_EXTRACT_MEMORIES=true      # Post-query memory extraction
SUPERAGENT_EXP_COMPACTION_REMINDERS=true  # Smart reminders around context compaction
SUPERAGENT_EXP_CACHED_MICROCOMPACT=true   # Cached microcompact state
SUPERAGENT_EXP_TEAM_MEMORY=true           # Team-memory files (shared memory)
SUPERAGENT_EXP_BASH_CLASSIFIER=true       # Classifier-assisted bash permissions
SUPERAGENT_EXP_BRIDGE_MODE=false          # Bridge mode: enhance non-Anthropic models with CC optimizations
SUPERAGENT_EXP_PIPELINES=false            # Pipeline DSL: declarative YAML multi-agent workflow engine
SUPERAGENT_EXP_COST_AUTOPILOT=false       # Cost Autopilot: automatic model downgrade, context compaction, budget control
SUPERAGENT_EXP_ADAPTIVE_FEEDBACK=false    # Adaptive Feedback: auto-learn from user corrections, generate rules/memories
```

The `ExperimentalFeatures` class also falls back to env vars when running outside a Laravel application (e.g. in unit tests), so feature flags work consistently across all environments.

### Bridge Mode (Enhance Non-Anthropic Models)

Bridge mode injects Claude Code's optimization mechanisms into non-Anthropic models (OpenAI, Bedrock, Ollama, OpenRouter). Anthropic/Claude does NOT need this — it natively has these optimizations.

**SDK auto-enhance mode** — automatically wraps non-Anthropic providers:

```php
use SuperAgent\Agent;

// Enable per-instance
$agent = new Agent(['provider' => 'openai', 'bridge_mode' => true]);

// Force disable even when config is on
$agent = new Agent(['provider' => 'openai', 'bridge_mode' => false]);

// Use config default (bridge.auto_enhance or bridge_mode feature flag)
$agent = new Agent(['provider' => 'openai']);

// Anthropic is never wrapped regardless of settings
$agent = new Agent(['provider' => 'anthropic', 'bridge_mode' => true]); // still raw
```

**HTTP proxy mode** — expose OpenAI-compatible endpoints for tools like Codex CLI:

```env
SUPERAGENT_EXP_BRIDGE_MODE=true
SUPERAGENT_BRIDGE_PROVIDER=openai
```

```bash
# Codex CLI connects to SuperAgent Bridge
export OPENAI_BASE_URL=http://localhost:8000/v1
codex "fix the login bug"
```

Endpoints: `POST /v1/chat/completions`, `POST /v1/responses`, `GET /v1/models`

**Available enhancers** (each independently toggleable):

| Enhancer | Config Key | Default | Effect |
|----------|-----------|---------|--------|
| System Prompt | `system_prompt` | on | Inject CC task/tool/style instructions |
| Context Compaction | `context_compaction` | on | Truncate old tool results, strip thinking blocks |
| Bash Security | `bash_security` | on | 23-point security validation on shell commands |
| Memory Injection | `memory_injection` | off | Inject cross-session memories into system prompt |
| Tool Schema | `tool_schema` | on | Fix JSON Schema issues, enhance descriptions |
| Tool Summary | `tool_summary` | off | Compress verbose old tool results |
| Token Budget | `token_budget` | off | Track token usage, detect diminishing returns |
| Cost Tracking | `cost_tracking` | on | Per-request cost calculation, budget enforcement |

```env
SUPERAGENT_BRIDGE_ENH_SYSTEM_PROMPT=true
SUPERAGENT_BRIDGE_ENH_COMPACTION=true
SUPERAGENT_BRIDGE_ENH_BASH_SECURITY=true
SUPERAGENT_BRIDGE_ENH_MEMORY=false
SUPERAGENT_BRIDGE_ENH_COST_TRACKING=true
```

### Pipeline DSL

Declarative YAML workflow engine for orchestrating multi-agent pipelines:

```yaml
# pipelines.yaml
version: "1.0"
pipelines:
  code-review:
    description: "Multi-agent code review pipeline"
    inputs:
      - name: files
        required: true
    steps:
      - name: security-scan
        agent: researcher
        prompt: "Scan {{inputs.files}} for vulnerabilities"
        on_failure: abort

      - name: parallel-checks
        parallel:
          - name: style-check
            agent: code-writer
            prompt: "Check code style"
          - name: test-coverage
            agent: verification
            prompt: "Analyze test coverage"

      - name: approval-gate
        approval:
          message: "All checks passed. Proceed with review?"

      - name: final-review
        agent: reviewer
        prompt: "Synthesize review findings"
        input_from:
          security: "{{steps.security-scan.output}}"
        depends_on: [parallel-checks]
    outputs:
      report: "{{steps.final-review.output}}"
```

```php
'pipelines' => [
    'enabled' => env('SUPERAGENT_PIPELINES_ENABLED', false),
    'files' => [base_path('pipelines.yaml')],
],
```

**Review-fix loop** — iterative multi-model review with automatic exit:

```yaml
- name: review-fix-loop
  loop:
    max_iterations: 5
    exit_when:
      all_passed:
        - { step: claude-review, contains: "LGTM" }
        - { step: gpt-review, contains: "LGTM" }
    steps:
      - name: reviews
        parallel:
          - name: claude-review
            agent: reviewer
            model: claude-sonnet-4-20250514
            prompt: "Review for bugs. Say LGTM if clean."
          - name: gpt-review
            agent: reviewer
            model: gpt-5.4
            prompt: "Review for security. Say LGTM if clean."
      - name: fix
        agent: code-writer
        prompt: "Fix all issues found"
        input_from:
          claude: "{{steps.claude-review.output}}"
          gpt: "{{steps.gpt-review.output}}"
```

Loop variables: `{{vars.loop.<name>.iteration}}`, `{{vars.loop.<name>.max}}`

Exit conditions: `output_contains`, `output_not_contains`, `all_passed`, `any_passed`, `expression`

See `examples/pipeline.yaml` for a complete reference.

### Cost Autopilot

Intelligent budget control that automatically escalates cost-saving actions:

```
Budget usage →  50%  → ⚠️  Warn (log warning)
                70%  → 📦  Compact (reduce context)
                80%  → ⬇️  Downgrade (Opus → Sonnet → Haiku)
                95%  → 🛑  Halt (stop agent)
```

```php
'cost_autopilot' => [
    'enabled' => env('SUPERAGENT_COST_AUTOPILOT_ENABLED', false),
    'session_budget_usd' => 5.00,    // Per-session budget
    'monthly_budget_usd' => 100.00,  // Monthly budget
    // Custom thresholds (optional, has sensible defaults)
    // 'thresholds' => [
    //     ['at_pct' => 50, 'action' => 'warn'],
    //     ['at_pct' => 80, 'action' => 'downgrade_model'],
    //     ['at_pct' => 95, 'action' => 'halt'],
    // ],
],
```

Model tiers are auto-detected from the default provider (Anthropic: Claude 4.6 Opus → Claude 4.6 Sonnet → Claude 4.6 Haiku, OpenAI: GPT-5.4 → GPT-5 → GPT-4 Turbo) or can be manually configured.

### Adaptive Feedback

Learns from user corrections and automatically promotes recurring patterns to rules or memories:

```
User denials → CorrectionCollector extracts pattern → Reaches threshold (default 3x)
                    ↓                                         ↓
           tool_denied / edit_reverted             behavior / content corrections
                    ↓                                         ↓
           Guardrails Rule (warn/deny)             Memory Entry (feedback type)
```

```php
'adaptive_feedback' => [
    'enabled' => env('SUPERAGENT_ADAPTIVE_FEEDBACK_ENABLED', false),
    'promotion_threshold' => 3,   // Occurrences before promotion
    'auto_promote' => true,       // Auto-promote (false = manual via feedback:promote)
],
```

Management commands:

```bash
php artisan superagent:feedback list [--category=tool_denied] [--search=keyword]
php artisan superagent:feedback show <id>
php artisan superagent:feedback delete <id>
php artisan superagent:feedback clear
php artisan superagent:feedback export [--output=path.json]
php artisan superagent:feedback import <path.json>
php artisan superagent:feedback promote <id>
php artisan superagent:feedback stats
```

## 🔧 CLI Commands

### Interactive Chat

```bash
php artisan superagent:chat
```

### Execute Single Query

```bash
php artisan superagent:run --prompt="Optimize this code" --file=app/Models/User.php
```

### List Available Tools

```bash
php artisan superagent:tools
```

### Create Custom Tool

```bash
php artisan superagent:make-tool MyCustomTool
```

### Manage Adaptive Feedback

```bash
php artisan superagent:feedback stats
```

## 🎨 Custom Extensions

### Create Custom Tool

```php
namespace App\SuperAgent\Tools;

use SuperAgent\Tools\BaseTool;
use SuperAgent\Tools\ToolResult;

class CustomTool extends BaseTool
{
    public function name(): string
    {
        return 'custom_tool';
    }
    
    public function description(): string
    {
        return 'Custom tool description';
    }
    
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'input' => ['type' => 'string', 'description' => 'Input parameter'],
            ],
            'required' => ['input'],
        ];
    }
    
    public function execute(array $params): ToolResult
    {
        // Implement tool logic
        $result = $this->processInput($params['input']);
        
        return new ToolResult(
            success: true,
            data: ['result' => $result]
        );
    }
}
```

### Create Plugin

```php
namespace App\SuperAgent\Plugins;

use SuperAgent\Plugins\BasePlugin;

class MyPlugin extends BasePlugin
{
    public function name(): string
    {
        return 'my-plugin';
    }
    
    public function boot(): void
    {
        // Plugin boot logic
        $this->registerTool(new MyCustomTool());
        $this->registerHook('pre_query', [$this, 'preQueryHandler']);
    }
    
    public function preQueryHandler($query)
    {
        // Pre-query processing
        return $query;
    }
}
```

### Create Skill

```php
namespace App\SuperAgent\Skills;

use SuperAgent\Skills\Skill;

class CodeReviewSkill extends Skill
{
    public function name(): string
    {
        return 'code_review';
    }
    
    public function description(): string
    {
        return 'Perform code review';
    }
    
    public function template(): string
    {
        return <<<PROMPT
Please review the following code:
- Check for potential bugs
- Evaluate code quality  
- Provide improvement suggestions

Code:
{code}

Provide detailed improvement recommendations.
PROMPT;
    }
    
    public function execute(array $args = []): string
    {
        $prompt = str_replace('{code}', $args['code'], $this->template());
        return $this->agent->query($prompt)->content;
    }
}
```

### Create Agent Definition

Both PHP classes and Markdown files are supported.

**Markdown format** (recommended — place in `.claude/agents/`):

```markdown
---
name: ai-advisor
description: "AI Strategy Advisor"
model: inherit
allowed_tools:
  - read_file
  - web_search
---

# AI Strategy Agent

You are an AI strategy advisor. Evaluate AI/ML scenarios with a pragmatic approach.

## Input

$ARGUMENTS

## Language

Output in $LANGUAGE. If unspecified, default to English.
```

Placeholders like `$ARGUMENTS` and `$LANGUAGE` are interpreted by the LLM from the user's input context, not substituted by the program. All frontmatter fields are preserved and accessible via `getMeta()`.

**PHP format:**

```php
namespace App\SuperAgent\Agents;

use SuperAgent\Agent\AgentDefinition;

class TranslatorAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'translator';
    }

    public function description(): string
    {
        return 'Translation specialist for multilingual content';
    }

    public function systemPrompt(): ?string
    {
        return 'You are a translation specialist. Translate content accurately while preserving tone and context.';
    }

    public function allowedTools(): ?array
    {
        return ['read_file', 'write_file', 'edit_file'];
    }

    public function category(): string
    {
        return 'content';
    }
}
```

### Auto-Loading Skills, Agents & MCP

SuperAgent can auto-load skills, agents, and MCP servers from Claude Code's standard directories via the `load_claude_code` flag, and from any additional paths you configure. Both `.php` and `.md` files are supported for skills and agents. All directory paths are scanned recursively. Non-existent paths are silently skipped.

```php
// config/superagent.php
'skills' => [
    'load_claude_code' => false,                // load from .claude/commands/ and .claude/skills/
    'paths' => [
        // app_path('SuperAgent/Skills'),
        // '/absolute/path/to/shared/skills',
    ],
],
'agents' => [
    'load_claude_code' => false,                // load from .claude/agents/
    'paths' => [
        // app_path('SuperAgent/Agents'),
    ],
],
'mcp' => [
    'load_claude_code' => false,                // load from .mcp.json and ~/.claude.json
    'paths' => [
        // 'custom/mcp-servers.json',            // additional MCP config files (JSON)
    ],
],
```

You can also load manually at runtime:

```php
use SuperAgent\Skills\SkillManager;
use SuperAgent\Agent\AgentManager;
use SuperAgent\MCP\MCPManager;

// Load from any directory (recursive)
SkillManager::getInstance()->loadFromDirectory('/any/path', recursive: true);
AgentManager::getInstance()->loadFromDirectory('/any/path', recursive: true);

// Load a single file (PHP or Markdown)
SkillManager::getInstance()->loadFromFile('/path/to/biznet.md');
AgentManager::getInstance()->loadFromFile('/path/to/ai-advisor.md');

// Load MCP servers from Claude Code configs or custom JSON files
MCPManager::getInstance()->loadFromClaudeCode();
MCPManager::getInstance()->loadFromJsonFile('/path/to/mcp-servers.json');
```

PHP files can use any namespace — the loader parses `namespace` and `class` from the source. Markdown files use YAML frontmatter for metadata and the body as the prompt template. MCP config files support both Claude Code format (`mcpServers`) and SuperAgent format (`servers`), with `${VAR}` and `${VAR:-default}` environment variable expansion.

### Fork Semantics

Fork an agent that inherits the parent's full conversation context and system prompt. Fork children share the prompt cache prefix for token efficiency.

```php
use SuperAgent\Agent\ForkContext;

// Create a fork context from the current agent's state
$fork = new ForkContext(
    parentMessages: $agent->getMessages(),
    parentSystemPrompt: $currentSystemPrompt,
    parentToolNames: ['bash', 'read_file', 'edit_file'],
);

// Fork context is passed to AgentSpawnConfig
$config = new AgentSpawnConfig(
    name: 'research-fork',
    prompt: 'Investigate the auth module',
    forkContext: $fork,
);
// $config->isFork() === true
```

Fork children are prevented from recursively forking. They follow a structured output format (Scope/Result/Key files/Issues) and execute directly without delegation.

### Dynamic System Prompt

The system prompt is built from modular sections with a static/dynamic split optimized for prompt caching:

```php
use SuperAgent\Prompt\SystemPromptBuilder;

$prompt = SystemPromptBuilder::create()
    ->withTools(['bash', 'read_file', 'edit_file', 'agent'])
    ->withMcpInstructions($mcpManager)    // inject MCP server usage instructions
    ->withMemory($memoryContent)           // inject cross-session memory
    ->withLanguage('zh-CN')                // set response language
    ->withEnvironment([                    // inject runtime info
        'Platform' => 'darwin',
        'PHP Version' => PHP_VERSION,
    ])
    ->withCustomSection('project', $projectRules)
    ->build();
```

**Section layout:**
- Static prefix (cacheable): identity, system rules, task philosophy, actions, tool usage, tone, output efficiency
- Cache boundary marker (`__SYSTEM_PROMPT_DYNAMIC_BOUNDARY__`)
- Dynamic suffix (session-specific): MCP instructions, memory, environment, language, custom sections

When prompt caching is enabled, the Anthropic provider splits the system prompt at the boundary marker and applies `cache_control` to the static prefix, so it stays cached across turns while the dynamic suffix can change freely.

### MCP Instruction Injection

Connected MCP servers can provide instructions on how to use their tools. These instructions are captured during the MCP initialize handshake and automatically injected into the system prompt via `SystemPromptBuilder::withMcpInstructions()`.

```php
$mcpManager = MCPManager::getInstance();
$mcpManager->connect('github-mcp');

// Server instructions (if provided) are now available:
$instructions = $mcpManager->getConnectedInstructions();
// ['github-mcp' => 'Use search_repos to find repositories...']
```

## 📊 Performance Optimization

### Cache Strategy

```php
// config/superagent.php
'cache' => [
    'enabled' => true,
    'driver' => 'redis',  // Use Redis for better performance
    'ttl' => 3600,        // Cache time (seconds)
    'prefix' => 'superagent_',
],
```

### Batch Processing

```php
// Batch process tasks
$tasks = [
    "Analyze code quality",
    "Generate unit tests",
    "Write documentation",
];

$results = $agent->batch($tasks, [
    'concurrency' => 3,  // Concurrency level
    'timeout' => 30,     // Timeout in seconds
]);
```

## 🔐 Security Best Practices

1. **API Key Management**
   - Never hardcode API keys in code
   - Use environment variables or key management services
   - Regularly rotate API keys

2. **Permission Control**
   - Use strict permission modes in production
   - Audit all tool calls
   - Limit access to sensitive operations

3. **Input Validation**
   - Validate and sanitize user input
   - Use parameterized queries to prevent injection
   - Implement rate limiting

4. **Error Handling**
   - Don't expose sensitive error information to users
   - Log detailed errors for debugging
   - Implement graceful degradation

## 📈 Monitoring and Logging

### Configure Logging

```php
// config/superagent.php
'logging' => [
    'enabled' => true,
    'channel' => 'superagent',
    'level' => 'info',
    'separate_files' => true,  // Separate log files
],
```

### Custom Log Handling

```php
use SuperAgent\Telemetry\StructuredLogger;

$logger = StructuredLogger::getInstance();

// Set global context
$logger->setGlobalContext([
    'user_id' => auth()->id(),
]);
$logger->setSessionId('session-123');

// Log LLM requests
$logger->logLLMRequest(
    model: 'claude-4.6-haiku-latest',
    inputTokens: 500,
    outputTokens: 200,
    duration: $duration,
    metadata: ['query_type' => 'analysis']
);

// Log errors
$logger->logError('API timeout', new \RuntimeException('Connection timed out'), [
    'provider' => 'anthropic',
]);
```

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

MIT License - See [LICENSE](LICENSE) file for details

## 🙋 Support

- 📖 [Documentation](https://superagent-docs.example.com)
- 💬 [Discussions](https://github.com/yourusername/superagent/discussions)
- 🐛 [Issue Tracker](https://github.com/yourusername/superagent/issues)
- 📧 Email: mliz1984@gmail.com

## 🗺 Roadmap


### Coming Soon
- ✨ More model support (Gemini, Mistral)
- 🎯 Visual debugging tools
- 🔄 Automatic task orchestration
- 📊 Performance analytics dashboard
- 🌐 Multi-language support

## 📚 Documentation Navigation

### Language Versions
- 🇺🇸 [English README](README.md)
- 🇨🇳 [中文 README](README_CN.md)

### Installation Guides
- 📖 [English Installation Guide](INSTALL.md)
- 📖 [中文安装手册](INSTALL_CN.md)

### Additional Resources
- 🤝 [Contributing Guide](CONTRIBUTING.md)
- 📄 [License](LICENSE)

---

<p align="center">
  Made with ❤️ by the SuperAgent Team
</p>