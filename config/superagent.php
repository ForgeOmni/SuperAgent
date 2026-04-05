<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic Multi-Agent Mode Detection
    |--------------------------------------------------------------------------
    |
    | Automatically determine whether to use single or multi-agent mode
    | based on task complexity analysis.
    |
    */
    
    'auto_mode' => [
        'enabled' => env('SUPERAGENT_AUTO_MODE', false),
        'threshold' => [
            'complexity_score' => (float) env('SUPERAGENT_AUTO_MODE_COMPLEXITY', 0.7),
            'min_subtasks' => (int) env('SUPERAGENT_AUTO_MODE_MIN_SUBTASKS', 3),
            'min_tools' => (int) env('SUPERAGENT_AUTO_MODE_MIN_TOOLS', 4),
            'estimated_tokens' => (int) env('SUPERAGENT_AUTO_MODE_MIN_TOKENS', 10000),
        ],
        'weights' => [
            'length' => 0.15,
            'keywords' => 0.25,
            'subtasks' => 0.30,
            'tools' => 0.20,
            'tokens' => 0.10,
        ],
        'multi_agent_config' => [
            'max_agents' => (int) env('SUPERAGENT_AUTO_MODE_MAX_AGENTS', 10),
            'backend' => 'in_process',
            'enable_display' => true,
            'refresh_interval' => 500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    */
    'default_model' => env('SUPERAGENT_MODEL', 'claude-sonnet-4-6-20250627'),

    /*
    |--------------------------------------------------------------------------
    | Model Aliases
    |--------------------------------------------------------------------------
    | Custom shorthand → full model ID mappings. These take precedence over
    | the built-in alias resolution. Built-in aliases automatically resolve
    | shorthands like "opus", "sonnet", "haiku" to the latest known model
    | in that family, so you only need to add entries here for overrides
    | or custom model names.
    |
    | Example: ['my-fast' => 'claude-haiku-4-5-20251001']
    */
    'model_aliases' => [
        // 'my-fast' => 'claude-haiku-4-5-20251001',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => '2023-06-01',
            'max_tokens' => (int) env('SUPERAGENT_MAX_TOKENS', 8192),
            'max_retries' => 3,
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 4096),
            'max_retries' => 3,
            'organization' => env('OPENAI_ORGANIZATION'),
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai'),
            'model' => env('OPENROUTER_MODEL', 'anthropic/claude-3-5-sonnet'),
            'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 4096),
            'max_retries' => 3,
        ],

        'ollama' => [
            'api_key' => env('OLLAMA_API_KEY', 'ollama'),
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3'),
            'max_tokens' => (int) env('OLLAMA_MAX_TOKENS', 4096),
            'max_retries' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Defaults
    |--------------------------------------------------------------------------
    */
    'agent' => [
        'max_turns' => (int) env('SUPERAGENT_MAX_TURNS', 50),
        'max_budget_usd' => (float) env('SUPERAGENT_MAX_BUDGET', 0),
        'working_directory' => env('SUPERAGENT_CWD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    | Five optimization strategies that reduce token consumption, lower cost,
    | and improve response speed. Each can be independently enabled/disabled.
    |
    | All optimizations default to enabled. Set the corresponding env var to
    | false to disable any individual optimization.
    */
    'optimization' => [

        // Compact old tool results (>N turns) to summaries, saving 30-50% input tokens
        'tool_result_compaction' => [
            'enabled' => env('SUPERAGENT_OPT_TOOL_COMPACTION', true),
            'preserve_recent_turns' => (int) env('SUPERAGENT_OPT_COMPACTION_TURNS', 2),
            'max_result_length' => (int) env('SUPERAGENT_OPT_COMPACTION_LENGTH', 200),
        ],

        // Send only relevant tool schemas per turn instead of all 59, saving ~10K tokens
        'selective_tool_schema' => [
            'enabled' => env('SUPERAGENT_OPT_SELECTIVE_TOOLS', true),
            'max_tools' => (int) env('SUPERAGENT_OPT_MAX_TOOLS', 20),
        ],

        // Auto-downgrade to fast model (Haiku) for pure tool-call turns, 40-60% cost saving
        'model_routing' => [
            'enabled' => env('SUPERAGENT_OPT_MODEL_ROUTING', true),
            'fast_model' => env('SUPERAGENT_OPT_FAST_MODEL', 'claude-haiku-4-5-20251001'),
            'min_turns_before_downgrade' => (int) env('SUPERAGENT_OPT_ROUTING_MIN_TURNS', 2),
        ],

        // Prefill assistant response to eliminate preamble tokens
        'response_prefill' => [
            'enabled' => env('SUPERAGENT_OPT_RESPONSE_PREFILL', true),
        ],

        // Auto-insert cache boundary in system prompt for 90% prompt cache hit rate
        'prompt_cache_pinning' => [
            'enabled' => env('SUPERAGENT_OPT_CACHE_PINNING', true),
            'min_static_length' => (int) env('SUPERAGENT_OPT_CACHE_MIN_LENGTH', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Performance
    |--------------------------------------------------------------------------
    | Runtime performance optimizations that speed up tool execution, reduce
    | latency, and improve resource utilization. Each can be independently
    | enabled/disabled.
    */
    'performance' => [

        // Execute read-only tools (Read, Grep, Glob) in parallel using Fibers
        'parallel_tool_execution' => [
            'enabled' => env('SUPERAGENT_PERF_PARALLEL_TOOLS', true),
            'max_parallel' => (int) env('SUPERAGENT_PERF_MAX_PARALLEL', 5),
        ],

        // Start tool execution during SSE streaming before full response completes
        'streaming_tool_dispatch' => [
            'enabled' => env('SUPERAGENT_PERF_STREAMING_DISPATCH', true),
        ],

        // Reuse HTTP connections (TCP keep-alive) for API calls
        'connection_pool' => [
            'enabled' => env('SUPERAGENT_PERF_CONNECTION_POOL', true),
        ],

        // Pre-read related files after Read tool executes (tests, interfaces, configs)
        'speculative_prefetch' => [
            'enabled' => env('SUPERAGENT_PERF_SPECULATIVE_PREFETCH', true),
            'max_cache_entries' => (int) env('SUPERAGENT_PERF_PREFETCH_CACHE', 50),
            'max_file_size' => (int) env('SUPERAGENT_PERF_PREFETCH_MAX_SIZE', 100000),
        ],

        // Stream Bash output with timeout truncation, return last N lines + summary
        'streaming_bash' => [
            'enabled' => env('SUPERAGENT_PERF_STREAMING_BASH', true),
            'max_output_lines' => (int) env('SUPERAGENT_PERF_BASH_MAX_LINES', 500),
            'tail_lines' => (int) env('SUPERAGENT_PERF_BASH_TAIL_LINES', 100),
            'stream_timeout_ms' => (int) env('SUPERAGENT_PERF_BASH_TIMEOUT', 30000),
        ],

        // Dynamically adjust max_tokens based on expected response type
        'adaptive_max_tokens' => [
            'enabled' => env('SUPERAGENT_PERF_ADAPTIVE_TOKENS', true),
            'tool_call_tokens' => (int) env('SUPERAGENT_PERF_TOOL_TOKENS', 2048),
            'reasoning_tokens' => (int) env('SUPERAGENT_PERF_REASON_TOKENS', 8192),
        ],

        // Batch non-realtime sub-agent requests via Anthropic Batch API (50% cost)
        'batch_api' => [
            'enabled' => env('SUPERAGENT_PERF_BATCH_API', false),
            'max_batch_size' => (int) env('SUPERAGENT_PERF_BATCH_SIZE', 100),
        ],

        // Pass PHP objects directly for in-process tools (skip JSON serialization)
        'local_tool_zero_copy' => [
            'enabled' => env('SUPERAGENT_PERF_ZERO_COPY', true),
            'max_cache_size_mb' => (int) env('SUPERAGENT_PERF_ZERO_COPY_MB', 50),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Mode
    |--------------------------------------------------------------------------
    | Supported: "allowAll", "denyAll", "allowList"
    */
    'permission_mode' => env('SUPERAGENT_PERMISSION_MODE', 'allowAll'),

    'allowed_tools' => [
        // e.g. 'bash', 'read', 'write', 'edit', 'glob', 'grep'
    ],

    'denied_tools' => [],

    /*
    |--------------------------------------------------------------------------
    | Telemetry Configuration
    |--------------------------------------------------------------------------
    | Master switch and per-subsystem controls for telemetry.
    | When 'enabled' is false, all telemetry subsystems are disabled
    | regardless of their individual settings — no data is collected or sent.
    */
    'telemetry' => [
        'enabled' => env('SUPERAGENT_TELEMETRY_ENABLED', false),

        'logging' => [
            'enabled' => env('SUPERAGENT_TELEMETRY_LOGGING', false),
        ],
        'metrics' => [
            'enabled' => env('SUPERAGENT_TELEMETRY_METRICS', false),
        ],
        'events' => [
            'enabled' => env('SUPERAGENT_TELEMETRY_EVENTS', false),
        ],
        'cost_tracking' => [
            'enabled' => env('SUPERAGENT_TELEMETRY_COST_TRACKING', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Prompt Guardrails
    |--------------------------------------------------------------------------
    | When enabled, additional safety instructions are injected into the
    | system prompt to restrict security-related operations (e.g. refusing
    | destructive techniques, requiring authorization context for dual-use
    | security tools). When disabled, these prompt-level guardrails are
    | removed — the model's own safety training still applies.
    */
    'security_guardrails' => env('SUPERAGENT_SECURITY_GUARDRAILS', false),

    /*
    |--------------------------------------------------------------------------
    | Guardrails DSL
    |--------------------------------------------------------------------------
    | Declarative rule engine for security, cost, compliance, and rate-limiting
    | policies. Rules are defined in YAML files and evaluated on every tool call.
    |
    | See docs/guardrails.md for the full DSL syntax reference.
    */
    'guardrails' => [
        'enabled' => env('SUPERAGENT_GUARDRAILS_ENABLED', false),

        // YAML files to load (merged in order, later files override same-named groups)
        'files' => [
            // base_path('guardrails.yaml'),
        ],

        // Integration point: 'permission_engine' (recommended) or 'hook'
        'integration' => env('SUPERAGENT_GUARDRAILS_INTEGRATION', 'permission_engine'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Adaptive Feedback
    |--------------------------------------------------------------------------
    | When enabled, the system automatically learns from user corrections
    | (permission denials, edit reverts, explicit feedback). Recurring patterns
    | are promoted to Guardrails rules or Memory entries after reaching the
    | promotion threshold.
    |
    | Manage patterns via: php artisan superagent:feedback {list|show|delete|clear|export|import|promote|stats}
    */
    'adaptive_feedback' => [
        'enabled' => env('SUPERAGENT_ADAPTIVE_FEEDBACK_ENABLED', false),

        // Number of occurrences before a pattern is promoted to a rule/memory
        'promotion_threshold' => (int) env('SUPERAGENT_FEEDBACK_THRESHOLD', 3),

        // Whether to auto-promote or just suggest (false = manual via feedback:promote)
        'auto_promote' => env('SUPERAGENT_FEEDBACK_AUTO_PROMOTE', true),

        // Where to persist correction patterns
        // 'storage_path' => storage_path('superagent/corrections.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Context Window
    |--------------------------------------------------------------------------
    | Dynamically allocates tokens between thinking budget and context window
    | based on task complexity. Complex tasks get more thinking budget with
    | aggressive compaction; simple tasks preserve more conversation history.
    |
    | Priority control:
    |   1. Per-task: new Agent(['context_strategy' => 'deep_thinking'])  ← highest
    |   2. Config: SUPERAGENT_SMART_CONTEXT_ENABLED=true                ← default
    */
    'smart_context' => [
        'enabled' => env('SUPERAGENT_SMART_CONTEXT_ENABLED', false),

        // Total token budget to split between thinking and context
        'total_budget_tokens' => (int) env('SUPERAGENT_SMART_CONTEXT_BUDGET', 100_000),

        // Minimum thinking budget (even for simple tasks)
        'min_thinking_budget' => (int) env('SUPERAGENT_SMART_CONTEXT_MIN_THINKING', 5_000),

        // Maximum thinking budget
        'max_thinking_budget' => (int) env('SUPERAGENT_SMART_CONTEXT_MAX_THINKING', 128_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge Graph
    |--------------------------------------------------------------------------
    | When enabled, tool execution events (file reads, edits, searches) are
    | automatically captured into a shared knowledge graph. Subsequent agents
    | can query the graph to see which files were modified, by whom, and what
    | decisions were made — avoiding redundant codebase exploration.
    */
    'knowledge_graph' => [
        'enabled' => env('SUPERAGENT_KNOWLEDGE_GRAPH_ENABLED', false),

        // Where to persist the graph
        // 'storage_path' => storage_path('superagent/knowledge_graph.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkpoint & Resume
    |--------------------------------------------------------------------------
    | When enabled, agent state is periodically checkpointed to disk during
    | long-running tasks. If the process crashes or is interrupted, the agent
    | can resume from the latest checkpoint instead of starting over.
    |
    | Priority control:
    |   1. Per-task: new Agent(['checkpoint' => true])   ← highest priority
    |   2. Config: SUPERAGENT_CHECKPOINT_ENABLED=true    ← default toggle
    |
    | Manage checkpoints via: php artisan superagent:checkpoint {list|show|delete|clear|prune|stats}
    */
    'checkpoint' => [
        'enabled' => env('SUPERAGENT_CHECKPOINT_ENABLED', false),

        // Checkpoint every N turns (lower = more frequent, higher = less I/O)
        'interval' => (int) env('SUPERAGENT_CHECKPOINT_INTERVAL', 5),

        // Maximum checkpoints to keep per session (older ones auto-pruned)
        'max_per_session' => (int) env('SUPERAGENT_CHECKPOINT_MAX', 5),

        // Where to store checkpoint files (one JSON file per checkpoint)
        // 'storage_path' => storage_path('superagent/checkpoints'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skill Distillation
    |--------------------------------------------------------------------------
    | When enabled, successful agent executions are automatically analyzed
    | and distilled into reusable skill templates. Complex tasks solved by
    | expensive models (Opus) produce step-by-step recipes that cheaper
    | models (Haiku) can follow, dramatically reducing cost for similar
    | future tasks.
    |
    | Manage distilled skills via: php artisan superagent:distill {list|show|delete|clear|export|import|stats}
    */
    'skill_distillation' => [
        'enabled' => env('SUPERAGENT_SKILL_DISTILLATION_ENABLED', false),

        // Minimum tool calls for a trace to be worth distilling
        'min_steps' => (int) env('SUPERAGENT_DISTILL_MIN_STEPS', 3),

        // Minimum cost (USD) for a trace to be worth distilling
        'min_cost_usd' => (float) env('SUPERAGENT_DISTILL_MIN_COST', 0.01),

        // Where to persist distilled skills
        // 'storage_path' => storage_path('superagent/distilled_skills.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Autopilot
    |--------------------------------------------------------------------------
    | Intelligent cost control that automatically downgrades models, compacts
    | context, or halts the agent when budget thresholds are crossed. Set a
    | monthly or session budget and let the autopilot handle the rest.
    |
    | Thresholds are evaluated after each provider call. Actions escalate as
    | budget consumption increases: warn → compact → downgrade → halt.
    |
    | Model tiers define the downgrade path (e.g., Opus → Sonnet → Haiku).
    | When not specified, tiers are auto-detected from the default provider.
    */
    'cost_autopilot' => [
        'enabled' => env('SUPERAGENT_COST_AUTOPILOT_ENABLED', false),

        // Budget limits (set one or both; the more restrictive one applies)
        'session_budget_usd' => (float) env('SUPERAGENT_SESSION_BUDGET', 0),
        'monthly_budget_usd' => (float) env('SUPERAGENT_MONTHLY_BUDGET', 0),

        // Where to persist cross-session spending data
        // 'storage_path' => storage_path('superagent/budget_tracker.json'),

        // Escalation thresholds (evaluated highest-first)
        // 'thresholds' => [
        //     ['at_pct' => 50, 'action' => 'warn',            'message' => 'Budget 50% consumed'],
        //     ['at_pct' => 70, 'action' => 'compact_context',  'message' => 'Compacting context to save tokens'],
        //     ['at_pct' => 80, 'action' => 'downgrade_model',  'message' => 'Downgrading to cheaper model'],
        //     ['at_pct' => 95, 'action' => 'halt',             'message' => 'Budget exhausted — halting agent'],
        // ],

        // Model tier hierarchy for downgrade path (most expensive first)
        // When omitted, auto-detected from default_provider (anthropic/openai)
        // 'tiers' => [
        //     ['name' => 'opus',   'model' => 'claude-opus-4-20250514',   'input_cost' => 15.0, 'output_cost' => 75.0, 'priority' => 30],
        //     ['name' => 'sonnet', 'model' => 'claude-sonnet-4-20250514', 'input_cost' => 3.0,  'output_cost' => 15.0, 'priority' => 20],
        //     ['name' => 'haiku',  'model' => 'claude-haiku-4-5-20251001','input_cost' => 0.80, 'output_cost' => 4.0,  'priority' => 10],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline DSL
    |--------------------------------------------------------------------------
    | Declarative YAML workflow engine for multi-agent pipelines. Pipelines
    | define ordered steps (agent, parallel, conditional, approval, transform)
    | with dependency resolution, failure strategies, and inter-step data flow.
    |
    | See examples/pipeline.yaml for the full DSL syntax reference.
    */
    'pipelines' => [
        'enabled' => env('SUPERAGENT_PIPELINES_ENABLED', false),

        // YAML files to load (merged in order, later files override same-named pipelines)
        'files' => [
            // base_path('pipelines.yaml'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Experimental Features
    |--------------------------------------------------------------------------
    | Feature flags for experimental capabilities. All default to true (enabled).
    | Set SUPERAGENT_EXPERIMENTAL=false to disable all experimental features,
    | or toggle individual features via their env vars.
    |
    | Note: features marked [NOT IMPLEMENTED] are defined for forward
    | compatibility and have no effect yet.
    */
    'experimental' => [
        'enabled' => env('SUPERAGENT_EXPERIMENTAL', true),

        // --- Interaction & UI ---

        // Deep thinking mode: "ultrathink" keyword boosts reasoning budget to max
        'ultrathink' => env('SUPERAGENT_EXP_ULTRATHINK', true),

        // Token budget tracking and usage warnings (dynamic continuation strategy)
        'token_budget' => env('SUPERAGENT_EXP_TOKEN_BUDGET', true),

        // Prompt cache-break detection in compaction/query flow
        'prompt_cache_break_detection' => env('SUPERAGENT_EXP_PROMPT_CACHE', true),

        // --- Agents, Memory & Planning ---

        // Built-in explore/plan agent presets (ExploreAgent, PlanAgent)
        'builtin_agents' => env('SUPERAGENT_EXP_BUILTIN_AGENTS', true),

        // Verification agent for task validation
        'verification_agent' => env('SUPERAGENT_EXP_VERIFICATION_AGENT', true),

        // Plan V2 interview phase (iterative pair-planning workflow)
        'plan_interview' => env('SUPERAGENT_EXP_PLAN_INTERVIEW', true),

        // Local cron/trigger tools for background automation
        'agent_triggers' => env('SUPERAGENT_EXP_AGENT_TRIGGERS', true),

        // Remote trigger tool (API-based remote agent tasks)
        'agent_triggers_remote' => env('SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE', true),

        // Post-query automatic memory extraction
        'extract_memories' => env('SUPERAGENT_EXP_EXTRACT_MEMORIES', true),

        // Smart reminders around context compaction
        'compaction_reminders' => env('SUPERAGENT_EXP_COMPACTION_REMINDERS', true),

        // Cached microcompact state through query flows
        'cached_microcompact' => env('SUPERAGENT_EXP_CACHED_MICROCOMPACT', true),

        // Team-memory files (shared memory with TEAM scope)
        'team_memory' => env('SUPERAGENT_EXP_TEAM_MEMORY', true),

        // --- Tools & Infrastructure ---

        // Classifier-assisted bash permission decisions (BashCommandClassifier)
        'bash_classifier' => env('SUPERAGENT_EXP_BASH_CLASSIFIER', true),

        // Bridge mode: proxy non-Anthropic models through CC optimization pipeline
        'bridge_mode' => env('SUPERAGENT_EXP_BRIDGE_MODE', false),

        // Pipeline DSL: declarative YAML workflow engine for multi-agent pipelines
        'pipelines' => env('SUPERAGENT_EXP_PIPELINES', false),

        // Cost Autopilot: automatic model downgrade, context compaction, and budget halting
        'cost_autopilot' => env('SUPERAGENT_EXP_COST_AUTOPILOT', false),

        // Adaptive Feedback: learn from user corrections and auto-generate rules/memories
        'adaptive_feedback' => env('SUPERAGENT_EXP_ADAPTIVE_FEEDBACK', false),

        // Skill Distillation: auto-distill successful executions into reusable skill templates
        'skill_distillation' => env('SUPERAGENT_EXP_SKILL_DISTILLATION', false),

        // Checkpoint & Resume: periodic state snapshots for crash recovery
        'checkpoint' => env('SUPERAGENT_EXP_CHECKPOINT', false),

        // Knowledge Graph: cross-agent shared knowledge for multi-agent collaboration
        'knowledge_graph' => env('SUPERAGENT_EXP_KNOWLEDGE_GRAPH', false),

        // Smart Context: dynamic token allocation between thinking and context
        'smart_context' => env('SUPERAGENT_EXP_SMART_CONTEXT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridge Configuration
    |--------------------------------------------------------------------------
    | When bridge_mode is enabled, SuperAgent exposes OpenAI-compatible API
    | endpoints that apply CC optimization mechanisms (system prompt enhancement,
    | context compaction, bash security, etc.) to non-Anthropic models.
    |
    | Anthropic/Claude does NOT need this — it natively has these optimizations.
    */
    'bridge' => [
        // Route prefix for bridge endpoints
        'prefix' => env('SUPERAGENT_BRIDGE_PREFIX', ''),

        // API keys for bridge authentication (comma-separated)
        // Empty = no auth required (development only)
        'api_keys' => array_filter(explode(',', env('SUPERAGENT_BRIDGE_API_KEYS', ''))),

        // Auto-enhance non-Anthropic providers when using the SDK directly.
        // When true, Agent(['provider' => 'openai']) will automatically wrap
        // with EnhancedProvider. Can be overridden per-instance:
        //   new Agent(['provider' => 'openai', 'bridge_mode' => false])  // force off
        //   new Agent(['provider' => 'openai', 'bridge_mode' => true])   // force on
        // When null, falls back to the bridge_mode experimental feature flag.
        'auto_enhance' => env('SUPERAGENT_BRIDGE_AUTO_ENHANCE'),

        // Backend provider: 'openai', 'openrouter', 'bedrock', 'ollama'
        // NOT 'anthropic' — Claude already has these optimizations natively
        'provider' => env('SUPERAGENT_BRIDGE_PROVIDER', 'openai'),

        // Default model when none specified in request
        'default_model' => env('SUPERAGENT_BRIDGE_MODEL', 'gpt-4o'),

        // Model name mapping (inbound model → backend model)
        // Models not in this map are passed through unchanged
        'model_map' => [
            // 'gpt-4o' => 'some-other-model',
        ],

        // Max output tokens
        'max_tokens' => (int) env('SUPERAGENT_BRIDGE_MAX_TOKENS', 16384),

        // Enhancer toggles — each can be independently enabled/disabled
        'enhancers' => [
            'system_prompt'      => env('SUPERAGENT_BRIDGE_ENH_SYSTEM_PROMPT', true),
            'context_compaction' => env('SUPERAGENT_BRIDGE_ENH_COMPACTION', true),
            'bash_security'      => env('SUPERAGENT_BRIDGE_ENH_BASH_SECURITY', true),
            'memory_injection'   => env('SUPERAGENT_BRIDGE_ENH_MEMORY', false),
            'tool_schema'        => env('SUPERAGENT_BRIDGE_ENH_TOOL_SCHEMA', true),
            'tool_summary'       => env('SUPERAGENT_BRIDGE_ENH_TOOL_SUMMARY', false),
            'token_budget'       => env('SUPERAGENT_BRIDGE_ENH_TOKEN_BUDGET', false),
            'cost_tracking'      => env('SUPERAGENT_BRIDGE_ENH_COST_TRACKING', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Configuration
    |--------------------------------------------------------------------------
    | load_claude_code: Automatically import MCP servers from Claude Code's
    |   project config (.mcp.json) and user config (~/.claude.json).
    |   Project-level configs take precedence over user-level.
    */
    'mcp' => [
        'load_claude_code' => env('SUPERAGENT_MCP_LOAD_CLAUDE_CODE', false),
        'paths' => [
            // Additional MCP config files (JSON) to load from.
            // Supports both Claude Code format (mcpServers) and SuperAgent format (servers).
            // e.g. '.claude/mcp-custom.json',
            // e.g. '/absolute/path/to/mcp-config.json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Skills Configuration
    |--------------------------------------------------------------------------
    | load_claude_code: Automatically load skills from Claude Code's
    |   .claude/commands/ and .claude/skills/ directories.
    | paths: Additional directories to auto-load skill files from.
    |   All paths are scanned recursively. Supports absolute paths
    |   or paths relative to base_path(). Non-existent paths are
    |   silently skipped.
    */
    'skills' => [
        'load_claude_code' => env('SUPERAGENT_SKILLS_LOAD_CLAUDE_CODE', false),
        'paths' => [
            // app_path('SuperAgent/Skills'),
            // '/absolute/path/to/custom/skills',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents Configuration
    |--------------------------------------------------------------------------
    | load_claude_code: Automatically load agent definitions from Claude
    |   Code's .claude/agents/ directory.
    | paths: Additional directories to auto-load agent definition files
    |   from. All paths are scanned recursively. Supports absolute paths
    |   or paths relative to base_path(). Non-existent paths are
    |   silently skipped.
    */
    'agents' => [
        'load_claude_code' => env('SUPERAGENT_AGENTS_LOAD_CLAUDE_CODE', false),
        'paths' => [
            // app_path('SuperAgent/Agents'),
            // '/absolute/path/to/custom/agents',
        ],
    ],

];
