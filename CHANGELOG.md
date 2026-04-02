# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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