<?php

return [

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
        'working_directory' => env('SUPERAGENT_CWD', null),
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
        'auto_enhance' => env('SUPERAGENT_BRIDGE_AUTO_ENHANCE', null),

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
