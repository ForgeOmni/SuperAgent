# SuperAgent Installation Guide

> **🌍 Language**: [English](INSTALL.md) | [中文](INSTALL_CN.md)  
> **📖 Documentation**: [README](README.md) | [README 中文](README_CN.md)

## Table of Contents
- [System Requirements](#system-requirements)
- [Installation Steps](#installation-steps)
- [Configuration](#configuration)
- [Multi-Agent Setup](#multi-agent-setup)
- [Verification](#verification)
- [Troubleshooting](#troubleshooting)
- [Upgrade Guide](#upgrade-guide)

## System Requirements

### Minimum Requirements
- **PHP**: 8.1 or higher
- **Laravel**: 10.0 or higher
- **Composer**: 2.0 or higher
- **Memory**: At least 256MB PHP memory limit
- **Disk Space**: At least 100MB available space

### Required PHP Extensions
```bash
# Core extensions
- json        # JSON processing
- mbstring    # Multi-byte string
- openssl     # Encryption
- curl        # HTTP requests
- fileinfo    # File information
```

### Optional PHP Extensions
```bash
# Enhanced features
- redis       # Redis cache support
- pcntl       # Process control (multi-agent collaboration)
- yaml        # YAML configuration files
- zip         # File compression
```

### Environment Check

```bash
# Check PHP version
php -v

# Check installed extensions
php -m

# Check Laravel version  
php artisan --version

# Check Composer version
composer --version
```

## Installation Steps

### 1️⃣ Install via Composer

#### Standard Installation (Recommended)
```bash
composer require forgeomni/superagent
```

#### Install Development Version
```bash
composer require forgeomni/superagent:dev-main
```

#### Install Specific Version
```bash
composer require forgeomni/superagent:^1.0
```

### 2️⃣ Register Service Provider

Laravel 10+ will auto-register. For manual registration, edit `config/app.php`:

```php
'providers' => [
    // Other providers...
    SuperAgent\SuperAgentServiceProvider::class,
],

'aliases' => [
    // Other aliases...
    'SuperAgent' => SuperAgent\Facades\SuperAgent::class,
],
```

### 3️⃣ Publish Resource Files

```bash
# Publish all resources
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"

# Or publish separately
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config"
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="migrations"
```

### 4️⃣ Run Database Migrations

If using memory system and task management features:

```bash
php artisan migrate
```

### 5️⃣ Configure Environment Variables

Edit your `.env` file and add the necessary configuration:

```env
# ========== SuperAgent Base Configuration ==========

# Default AI Provider (anthropic|openai|bedrock|ollama)
SUPERAGENT_PROVIDER=anthropic

# Anthropic Claude Configuration
ANTHROPIC_API_KEY=sk-ant-xxxxxxxxxxxxx
ANTHROPIC_MODEL=claude-4.6-haiku-latest
ANTHROPIC_MAX_TOKENS=4096
ANTHROPIC_TEMPERATURE=0.7

# OpenAI Configuration (optional)
OPENAI_API_KEY=sk-xxxxxxxxxxxxx
OPENAI_MODEL=gpt-5.4
OPENAI_ORG_ID=org-xxxxxxxxxxxxx

# AWS Bedrock Configuration (optional)
AWS_ACCESS_KEY_ID=AKIAXXXXXXXXXXXXX
AWS_SECRET_ACCESS_KEY=xxxxxxxxxxxxx
AWS_DEFAULT_REGION=us-east-1
BEDROCK_MODEL=anthropic.claude-v2

# Local Ollama Models (optional)
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=llama2

# ========== Feature Toggles ==========

# Streaming output
SUPERAGENT_STREAMING=true

# Cache functionality
SUPERAGENT_CACHE_ENABLED=true
SUPERAGENT_CACHE_TTL=3600

# Debug mode
SUPERAGENT_DEBUG=false

# Observability (master switch — all subsystems off when false)
SUPERAGENT_TELEMETRY_ENABLED=false
SUPERAGENT_TELEMETRY_LOGGING=false
SUPERAGENT_TELEMETRY_METRICS=false
SUPERAGENT_TELEMETRY_EVENTS=false
SUPERAGENT_TELEMETRY_COST_TRACKING=false

# Security prompt guardrails
SUPERAGENT_SECURITY_GUARDRAILS=false

# Guardrails DSL (declarative YAML rule engine)
SUPERAGENT_GUARDRAILS_ENABLED=false
# SUPERAGENT_GUARDRAILS_INTEGRATION=permission_engine

# Pipeline DSL (declarative YAML multi-agent workflow engine)
SUPERAGENT_PIPELINES_ENABLED=false

# Cost Autopilot (automatic model downgrade and budget control)
SUPERAGENT_COST_AUTOPILOT_ENABLED=false
# SUPERAGENT_SESSION_BUDGET=0
# SUPERAGENT_MONTHLY_BUDGET=0

# Adaptive Feedback (learn from user corrections)
SUPERAGENT_ADAPTIVE_FEEDBACK_ENABLED=false
# SUPERAGENT_FEEDBACK_THRESHOLD=3
# SUPERAGENT_FEEDBACK_AUTO_PROMOTE=true

# Skill Distillation (auto-distill expensive executions into reusable skills)
SUPERAGENT_SKILL_DISTILLATION_ENABLED=false
# SUPERAGENT_DISTILL_MIN_STEPS=3
# SUPERAGENT_DISTILL_MIN_COST=0.01

# Knowledge Graph (cross-agent shared knowledge)
SUPERAGENT_KNOWLEDGE_GRAPH_ENABLED=false

# Smart Context Window (dynamic thinking/context allocation)
SUPERAGENT_SMART_CONTEXT_ENABLED=false
# SUPERAGENT_SMART_CONTEXT_BUDGET=100000
# SUPERAGENT_SMART_CONTEXT_MIN_THINKING=5000
# SUPERAGENT_SMART_CONTEXT_MAX_THINKING=128000

# Checkpoint & Resume (periodic state snapshots for crash recovery)
SUPERAGENT_CHECKPOINT_ENABLED=false
# SUPERAGENT_CHECKPOINT_INTERVAL=5
# SUPERAGENT_CHECKPOINT_MAX=5

# Experimental features (master switch — all flags on when true)
SUPERAGENT_EXPERIMENTAL=true
# SUPERAGENT_EXP_ULTRATHINK=true
# SUPERAGENT_EXP_TOKEN_BUDGET=true
# SUPERAGENT_EXP_PROMPT_CACHE=true
# SUPERAGENT_EXP_BUILTIN_AGENTS=true
# SUPERAGENT_EXP_VERIFICATION_AGENT=true
# SUPERAGENT_EXP_PLAN_INTERVIEW=true
# SUPERAGENT_EXP_AGENT_TRIGGERS=true
# SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE=true
# SUPERAGENT_EXP_EXTRACT_MEMORIES=true
# SUPERAGENT_EXP_COMPACTION_REMINDERS=true
# SUPERAGENT_EXP_CACHED_MICROCOMPACT=true
# SUPERAGENT_EXP_TEAM_MEMORY=true
# SUPERAGENT_EXP_BASH_CLASSIFIER=true
# SUPERAGENT_EXP_PIPELINES=false
# SUPERAGENT_EXP_COST_AUTOPILOT=false
# SUPERAGENT_EXP_ADAPTIVE_FEEDBACK=false
# SUPERAGENT_EXP_SKILL_DISTILLATION=false
# SUPERAGENT_EXP_CHECKPOINT=false
# SUPERAGENT_EXP_KNOWLEDGE_GRAPH=false
# SUPERAGENT_EXP_SMART_CONTEXT=false

# ========== Permission Configuration ==========

# Permission modes:
# bypass - Skip all permission checks
# acceptEdits - Auto-approve file edits
# plan - All operations need confirmation
# default - Smart judgment
# dontAsk - Auto-deny operations needing confirmation
# auto - AI automatic classification
SUPERAGENT_PERMISSION_MODE=default

# ========== Storage Configuration ==========

SUPERAGENT_STORAGE_DISK=local
SUPERAGENT_STORAGE_PATH=superagent
```

### 6️⃣ Create Necessary Directories

```bash
# Create storage directories
mkdir -p storage/app/superagent/{snapshots,memories,tasks,cache}

# Set permissions
chmod -R 755 storage/app/superagent

# If using web server
chown -R www-data:www-data storage/app/superagent  # Ubuntu/Debian
chown -R nginx:nginx storage/app/superagent        # CentOS/RHEL
```

## Configuration

### Main Configuration File

Edit `config/superagent.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-4.6-haiku-latest'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
            'temperature' => env('ANTHROPIC_TEMPERATURE', 0.7),
            'timeout' => 60,
        ],
        
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5.4'),
            'organization' => env('OPENAI_ORG_ID'),
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Tools Configuration
    |--------------------------------------------------------------------------
    */
    'tools' => [
        // Enabled tools list
        'enabled' => [
            \SuperAgent\Tools\Builtin\FileReadTool::class,
            \SuperAgent\Tools\Builtin\FileWriteTool::class,
            \SuperAgent\Tools\Builtin\FileEditTool::class,
            \SuperAgent\Tools\Builtin\BashTool::class,
            \SuperAgent\Tools\Builtin\WebSearchTool::class,
            \SuperAgent\Tools\Builtin\WebFetchTool::class,
        ],
        
        // Tool permission settings
        'permissions' => [
            'bash' => [
                'commands' => [
                    'allow' => ['ls', 'cat', 'grep', 'find'],
                    'deny' => ['rm -rf', 'sudo', 'chmod 777'],
                ],
            ],
            'file_write' => [
                'paths' => [
                    'deny' => ['.env', 'database.php', '/etc/*'],
                ],
            ],
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Context Management
    |--------------------------------------------------------------------------
    */
    'context' => [
        'max_tokens' => 100000,
        'auto_compact' => true,
        'compact_threshold' => 80000,
        'compact_strategy' => 'smart',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('SUPERAGENT_CACHE_ENABLED', true),
        'driver' => env('CACHE_DRIVER', 'file'),
        'ttl' => env('SUPERAGENT_CACHE_TTL', 3600),
    ],
];
```

### Tool Auto-Discovery

Create custom tool directories:

```bash
# Create directory structure
mkdir -p app/SuperAgent/{Tools,Skills,Plugins,Agents}

# Tools will be automatically discovered and registered
```

### Skill, Agent & MCP Auto-Loading

Skills, agents, and MCP servers can be auto-loaded from Claude Code directories (via `load_claude_code`) and from custom paths. Configure in `config/superagent.php`:

```php
// config/superagent.php
'skills' => [
    'load_claude_code' => false,                // .claude/commands/ and .claude/skills/
    'paths' => [],                              // additional directories
],
'agents' => [
    'load_claude_code' => false,                // .claude/agents/
    'paths' => [],                              // additional directories
],
'mcp' => [
    'load_claude_code' => false,                // .mcp.json and ~/.claude.json
    'paths' => [],                              // additional JSON config files
],
```

All directory paths are scanned recursively. Non-existent paths are silently skipped. Both PHP (`.php`) and Markdown (`.md`) files are supported for skills and agents. PHP files can use any namespace. Markdown files use YAML frontmatter for metadata (name, description, allowed_tools, etc.) and the body as the prompt template — placeholders like `$ARGUMENTS` and `$LANGUAGE` are interpreted by the LLM, not substituted by the program. MCP config files support both Claude Code format (`mcpServers`) and SuperAgent format (`servers`), with `${VAR}` and `${VAR:-default}` environment variable expansion.

### Extended Thinking

Enable extended thinking for deeper reasoning on complex tasks:

```php
use SuperAgent\Thinking\ThinkingConfig;

// Adaptive thinking (model decides when to think)
$agent = new Agent([
    'options' => ['thinking' => ThinkingConfig::adaptive()],
]);

// Fixed budget thinking
$agent = new Agent([
    'options' => ['thinking' => ThinkingConfig::enabled(budgetTokens: 20000)],
]);

// Ultrathink keyword in user messages auto-boosts to max budget
// Just include "ultrathink" in your prompt
```

Set via environment: `MAX_THINKING_TOKENS=20000`

## Multi-Agent Setup

### Auto Mode Configuration (NEW in v0.6.7)

Enable automatic multi-agent orchestration:

```env
# Enable automatic multi-agent detection
SUPERAGENT_AUTO_MODE=true

# Maximum concurrent agents
SUPERAGENT_MAX_CONCURRENT_AGENTS=10

# Agent resource pooling
SUPERAGENT_AGENT_POOL_SIZE=20

# WebSocket monitoring
SUPERAGENT_WEBSOCKET_MONITORING=true
SUPERAGENT_WEBSOCKET_PORT=8080
```

### Basic Multi-Agent Usage

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;

// Create main agent with auto-mode
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],
    'multi_agent' => [
        'auto_mode' => true,
        'max_concurrent' => 10,
    ],
]);

$agent = new Agent($provider, $config);
$agent->enableAutoMode();

// Agent automatically decides single vs multi-agent
$result = $agent->run("Complex multi-step task...");
```

### Manual Agent Team Configuration

```php
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Swarm\ParallelAgentCoordinator;

// Configure agent team
$coordinator = ParallelAgentCoordinator::getInstance();
$coordinator->configure([
    'max_concurrent' => 10,
    'timeout' => 300, // 5 minutes per agent
    'checkpoint_interval' => 60, // Save state every minute
]);

// Create specialized agents
$agentTool = new AgentTool();

// Research agent
$researcher = $agentTool->execute([
    'description' => 'Research',
    'prompt' => 'Research best practices',
    'subagent_type' => 'researcher',
    'run_in_background' => true,
]);

// Code writer agent
$coder = $agentTool->execute([
    'description' => 'Implementation',
    'prompt' => 'Write implementation code',
    'subagent_type' => 'code-writer',
    'run_in_background' => true,
]);

// Monitor progress
$status = $coordinator->getTeamStatus();
foreach ($status['agents'] as $agentId => $info) {
    echo "Agent {$agentId}: {$info['status']} - {$info['progress']}%\n";
}
```

### Agent Mailbox System

Configure persistent agent communication:

```php
// config/superagent.php
'mailbox' => [
    'enabled' => true,
    'storage' => 'redis', // or 'database', 'file'
    'ttl' => 3600, // Message TTL in seconds
    'max_messages' => 1000, // Max messages per agent
],
```

Usage:

```php
use SuperAgent\Tools\Builtin\SendMessageTool;

$messageTool = new SendMessageTool();

// Direct message
$messageTool->execute([
    'to' => 'agent-123',
    'message' => 'Priority update',
    'summary' => 'Update',
]);

// Broadcast
$messageTool->execute([
    'to' => '*',
    'message' => 'Team announcement',
    'summary' => 'Announcement',
]);
```

### WebSocket Monitoring Dashboard

Enable real-time monitoring:

```bash
# Start WebSocket server
php artisan superagent:websocket

# Access dashboard
open http://localhost:8080/superagent/monitor
```

Dashboard features:
- Real-time agent status
- Token usage per agent
- Cost aggregation
- Progress visualization
- Message queue monitoring

### Agent Role Configuration

Define specialized agent roles:

```php
// config/superagent.php
'agent_roles' => [
    'researcher' => [
        'model' => 'claude-4.6-haiku-latest',
        'tools' => ['web_search', 'web_fetch'],
        'max_tokens' => 8192,
    ],
    'code-writer' => [
        'model' => 'claude-4.6-sonnet-latest',
        'tools' => ['file_read', 'file_write', 'file_edit'],
        'max_tokens' => 16384,
    ],
    'reviewer' => [
        'model' => 'claude-4.6-opus-latest',
        'tools' => ['file_read', 'grep'],
        'max_tokens' => 4096,
    ],
],
```

### Checkpoint & Resume for Multi-Agent Workflows

```php
// Enable checkpointing
$coordinator->enableCheckpoints([
    'interval' => 60, // Save every 60 seconds
    'storage' => 'database',
]);

// Resume from checkpoint after failure
$coordinator->resumeFromCheckpoint($checkpointId);
```

### Resource Pooling & Concurrency Control

```php
// Configure agent pool
use SuperAgent\Swarm\AgentPool;

$pool = new AgentPool([
    'max_agents' => 20,
    'max_concurrent' => 10,
    'queue_timeout' => 300,
]);

// Submit tasks to pool
$taskIds = [];
foreach ($tasks as $task) {
    $taskIds[] = $pool->submit($task);
}

// Wait for completion
$results = $pool->waitAll($taskIds);
```

### Multi-Agent Performance Optimization

```env
# Optimize for parallel execution
SUPERAGENT_PARALLEL_CHUNK_SIZE=5
SUPERAGENT_PARALLEL_TIMEOUT=300
SUPERAGENT_PARALLEL_RETRY_COUNT=3

# Memory optimization
SUPERAGENT_AGENT_MEMORY_LIMIT=256M
SUPERAGENT_SHARED_CONTEXT_CACHE=true

# Network optimization
SUPERAGENT_API_CONNECTION_POOL=50
SUPERAGENT_API_KEEPALIVE=true
```

### Coordinator Mode

Enable dual-mode architecture for complex multi-agent orchestration:

```env
# Enable coordinator mode
CLAUDE_CODE_COORDINATOR_MODE=1
```

The coordinator only has Agent/SendMessage/TaskStop tools and delegates all work to isolated worker agents.

### Batch Skill

Use `/batch` to parallelize large-scale changes:

```bash
# In the agent CLI
/batch migrate from react to vue
/batch replace all uses of lodash with native equivalents
```

Requires a git repository. Spawns 5–30 worktree-isolated agents, each creating a PR.

### Remote Agent Tasks

Configure out-of-process agents with cron scheduling:

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiBaseUrl: 'https://api.anthropic.com',
    apiKey: env('ANTHROPIC_API_KEY'),
);

$manager->create(
    name: 'nightly-review',
    prompt: 'Review all PRs merged today',
    cronExpression: '0 2 * * *', // 2 AM UTC daily
    gitRepoUrl: 'https://github.com/org/repo',
);
```

### Telemetry Master Switch

All telemetry subsystems (tracing, logging, metrics, events, cost tracking) are gated by a master switch. When `telemetry.enabled` is `false`, no data is collected regardless of individual subsystem settings:

```php
// config/superagent.php
'telemetry' => [
    'enabled' => env('SUPERAGENT_TELEMETRY_ENABLED', false),
    'logging'       => ['enabled' => env('SUPERAGENT_TELEMETRY_LOGGING', false)],
    'metrics'       => ['enabled' => env('SUPERAGENT_TELEMETRY_METRICS', false)],
    'events'        => ['enabled' => env('SUPERAGENT_TELEMETRY_EVENTS', false)],
    'cost_tracking' => ['enabled' => env('SUPERAGENT_TELEMETRY_COST_TRACKING', false)],
],
```

### Security Prompt Guardrails

When enabled, additional safety instructions are injected into the system prompt to restrict security-related operations. When disabled, only the model's built-in safety training applies:

```php
// config/superagent.php
'security_guardrails' => env('SUPERAGENT_SECURITY_GUARDRAILS', false),
```

### Experimental Feature Flags

22 granular feature flags let you enable or disable experimental capabilities. All default to `true` (enabled) when the master switch is on. Some tools, agents, and behaviors are gated by these flags:

```php
// config/superagent.php
'experimental' => [
    'enabled' => env('SUPERAGENT_EXPERIMENTAL', true),

    'ultrathink' => env('SUPERAGENT_EXP_ULTRATHINK', true),
    'token_budget' => env('SUPERAGENT_EXP_TOKEN_BUDGET', true),
    'prompt_cache_break_detection' => env('SUPERAGENT_EXP_PROMPT_CACHE', true),
    'builtin_agents' => env('SUPERAGENT_EXP_BUILTIN_AGENTS', true),
    'verification_agent' => env('SUPERAGENT_EXP_VERIFICATION_AGENT', true),
    'plan_interview' => env('SUPERAGENT_EXP_PLAN_INTERVIEW', true),
    'agent_triggers' => env('SUPERAGENT_EXP_AGENT_TRIGGERS', true),
    'agent_triggers_remote' => env('SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE', true),
    'extract_memories' => env('SUPERAGENT_EXP_EXTRACT_MEMORIES', true),
    'compaction_reminders' => env('SUPERAGENT_EXP_COMPACTION_REMINDERS', true),
    'cached_microcompact' => env('SUPERAGENT_EXP_CACHED_MICROCOMPACT', true),
    'team_memory' => env('SUPERAGENT_EXP_TEAM_MEMORY', true),
    'bash_classifier' => env('SUPERAGENT_EXP_BASH_CLASSIFIER', true),
    'bridge_mode' => env('SUPERAGENT_EXP_BRIDGE_MODE', false),  // Enhance non-Anthropic models
    'pipelines' => env('SUPERAGENT_EXP_PIPELINES', false),     // Pipeline DSL workflow engine
    'cost_autopilot' => env('SUPERAGENT_EXP_COST_AUTOPILOT', false), // Automatic budget control
    'adaptive_feedback' => env('SUPERAGENT_EXP_ADAPTIVE_FEEDBACK', false), // Learn from corrections
    'skill_distillation' => env('SUPERAGENT_EXP_SKILL_DISTILLATION', false), // Distill skills from traces
    'checkpoint' => env('SUPERAGENT_EXP_CHECKPOINT', false),                 // State snapshots for crash recovery
    'knowledge_graph' => env('SUPERAGENT_EXP_KNOWLEDGE_GRAPH', false),       // Cross-agent knowledge graph
    'smart_context' => env('SUPERAGENT_EXP_SMART_CONTEXT', false),           // Dynamic thinking/context allocation
],
```

**Gated components:**

| Flag | Gated Component |
|------|----------------|
| `builtin_agents` | ExploreAgent, PlanAgent registration |
| `verification_agent` | VerificationAgent registration |
| `agent_triggers` | `schedule_cron` tool |
| `agent_triggers_remote` | `remote_trigger` tool |
| `team_memory` | `team_create`, `team_delete` tools |
| `ultrathink` | Ultrathink keyword boost behavior |
| `token_budget` | Token budget tracking in QueryEngine |
| `prompt_cache_break_detection` | Auto prompt caching in AnthropicProvider |
| `bash_classifier` | Classifier-assisted bash permission decisions |
| `plan_interview` | Plan V2 interview phase workflow |
| `extract_memories` | Session memory extraction defaults |
| `compaction_reminders` | Auto-compact defaults in CompressionConfig |
| `bridge_mode` | Bridge enhancement for non-Anthropic providers |
| `pipelines` | PipelineEngine registration and YAML loading |
| `cost_autopilot` | CostAutopilot registration and budget tracking |
| `adaptive_feedback` | FeedbackManager registration and correction tracking |
| `skill_distillation` | DistillationManager registration and skill generation |
| `checkpoint` | CheckpointManager registration and state snapshots |
| `knowledge_graph` | KnowledgeGraphManager registration and graph tracking |
| `smart_context` | SmartContextManager registration and dynamic allocation |

### Bridge Mode Configuration

When `bridge_mode` is enabled, non-Anthropic providers are automatically enhanced with CC optimization mechanisms. Anthropic/Claude is never wrapped — it natively has these optimizations.

```php
// config/superagent.php
'bridge' => [
    'auto_enhance' => env('SUPERAGENT_BRIDGE_AUTO_ENHANCE', null), // null = use bridge_mode flag
    'provider' => env('SUPERAGENT_BRIDGE_PROVIDER', 'openai'),
    'api_keys' => array_filter(explode(',', env('SUPERAGENT_BRIDGE_API_KEYS', ''))),
    'max_tokens' => (int) env('SUPERAGENT_BRIDGE_MAX_TOKENS', 16384),
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
```

**Priority control** (highest first):
1. Per-instance: `new Agent(['provider' => 'openai', 'bridge_mode' => true])`
2. Config: `SUPERAGENT_BRIDGE_AUTO_ENHANCE=true`
3. Feature flag: `SUPERAGENT_EXP_BRIDGE_MODE=true`
4. Default: off
| `cached_microcompact` | Micro-compact defaults in CompressionConfig |

The `ExperimentalFeatures` class falls back to env vars when running outside a Laravel application (e.g. in unit tests).

### Analytics Sampling

Configure per-event-type sampling rates:

```php
use SuperAgent\Telemetry\EventSampler;

$sampler = new EventSampler([
    'api_query' => ['sample_rate' => 0.1],     // Log 10% of API queries
    'tool_execution' => ['sample_rate' => 0.5], // Log 50% of tool executions
]);

$tracingManager->setEventSampler($sampler);
```

### Prompt Caching

Enable prompt caching for the Anthropic provider to reduce token costs. The `SystemPromptBuilder` uses a cache boundary marker to split the system prompt into a cacheable static prefix and a session-specific dynamic suffix:

```php
use SuperAgent\Prompt\SystemPromptBuilder;

$prompt = SystemPromptBuilder::create()
    ->withTools($toolNames)
    ->withMcpInstructions($mcpManager)
    ->withMemory($memory)
    ->build();

// Pass to Agent with prompt_caching enabled
$agent = new Agent([
    'api_key' => env('ANTHROPIC_API_KEY'),
    'system_prompt' => $prompt,
    'options' => ['prompt_caching' => true],
]);
```

## v0.7.0 Upgrade Notes

v0.7.0 adds 13 performance optimizations (5 token + 8 execution). **All except Batch API are enabled by default. No configuration changes required.**

To disable any optimization, set the corresponding env var to `false`:
```env
SUPERAGENT_OPT_TOOL_COMPACTION=false
SUPERAGENT_OPT_SELECTIVE_TOOLS=false
SUPERAGENT_OPT_MODEL_ROUTING=false
SUPERAGENT_OPT_RESPONSE_PREFILL=false
SUPERAGENT_OPT_CACHE_PINNING=false
```

The fast model for routing defaults to `claude-haiku-4-5-20251001`. Override with `SUPERAGENT_OPT_FAST_MODEL=your-model-id`.

```bash
composer update forgeomni/superagent
```

## v0.6.19 Upgrade Notes

v0.6.19 adds `NdjsonStreamingHandler` for in-process agent execution logging. **No configuration changes required.**

Previously, only child processes (via `agent-runner.php`) emitted NDJSON logs. Now in-process `$agent->prompt()` calls can also write CC-compatible NDJSON to log files via `NdjsonStreamingHandler::create()` or `createWithWriter()`, making them visible in the process monitor.

```bash
composer update forgeomni/superagent
```

## v0.6.18 Upgrade Notes

v0.6.18 upgrades child agent logging from a custom protocol to Claude Code-compatible NDJSON. **No configuration changes required.**

Child processes now emit standard NDJSON events on stderr (`{"type":"assistant",...}`, `{"type":"result",...}`) instead of the `__PROGRESS__:` prefix protocol. The parent's `ProcessBackend` auto-detects both formats, so this is fully backward-compatible.

```bash
composer update forgeomni/superagent
```

## v0.6.17 Upgrade Notes

v0.6.17 adds real-time progress monitoring for child agent processes. **No configuration changes required.**

Previously, when sub-agents ran in separate OS processes via `ProcessBackend`, the process monitor could not display their work progress (tools being used, token counts, etc.). Now child processes emit structured progress events via stderr using the `__PROGRESS__:` protocol, and the parent parses these into `AgentProgressTracker` — making child agent activity visible in `ParallelAgentDisplay` and WebSocket dashboards.

```bash
composer update forgeomni/superagent
```

## v0.6.16 Upgrade Notes

v0.6.16 ensures sub-agent child processes have access to all parent's agent definitions and MCP server configs. **No configuration changes required.**

Previously, child processes relied on Laravel bootstrap to load custom agent definitions from `.claude/agents/` and MCP servers from config. Now the parent serializes these registrations and passes them via stdin JSON — child processes work identically whether or not Laravel is available.

```bash
composer update forgeomni/superagent
```

## v0.6.15 Upgrade Notes

v0.6.15 adds automatic MCP server sharing. **No configuration changes are required.**

When your parent agent connects to a stdio MCP server (e.g. Valhalla), a TCP bridge is started automatically. Child agents spawned via `AgentTool` will connect to the bridge instead of starting their own MCP server processes. This eliminates the overhead of N child processes each spawning an identical Node.js/Python MCP server.

```bash
composer update forgeomni/superagent
```

## v0.6.12 Upgrade Notes

v0.6.12 fixes three issues where sub-agent child processes could not access Laravel services, API credentials, or the full tool set. **No configuration changes are required.**

If you use custom agent definitions in `.claude/agents/`, custom skills in `.claude/commands/`, or MCP servers configured via `config('superagent.mcp')`, these now work correctly in sub-agent processes.

```bash
composer update forgeomni/superagent
```

## v0.6.11 Upgrade Notes

v0.6.11 replaces the default sub-agent execution backend. **No configuration changes are required** — the new behavior is automatic.

**What changed:** `AgentTool` now spawns each sub-agent in a separate OS process via `proc_open()` instead of using PHP Fibers in the same process. This provides true parallelism — 5 concurrent agents complete in ~544ms vs ~2500ms sequential.

**Breaking change for test code only:** If your tests mock `InProcessBackend` or rely on Fiber-based execution, they may need updating. Production code that simply calls `AgentTool` is unaffected.

**Requirement:** `proc_open()` must be available (it is on standard PHP installations). If disabled (e.g. shared hosting with `disable_functions`), `AgentTool` falls back to `InProcessBackend` automatically.

```bash
composer update forgeomni/superagent
```

## v0.6.10 Upgrade Notes

v0.6.10 is a bug-fix release with no configuration changes. If you are using synchronous in-process agents (`run_in_background: false` with the `in-process` backend), this update resolves a critical deadlock where the agent fiber was never started, causing a 5-minute timeout on every call.

**Breaking change for test code only**: The synchronous `AgentTool::execute()` result now returns `'agentId'` (camelCase) and `'status' => 'completed'` instead of the previously unreachable async format. If you have test assertions on these keys, update them accordingly.

```bash
composer update forgeomni/superagent
```

## v0.6.9 Feature Configuration

### Custom Base URL with Path Prefix

Providers now correctly handle `base_url` values that include a path prefix (e.g. API gateways, reverse proxies):

```php
// Anthropic-compatible gateway at a custom path
$agent = new Agent([
    'provider'  => 'anthropic',
    'api_key'   => env('ANTHROPIC_API_KEY'),
    'base_url'  => 'https://gateway.example.com/anthropic', // path prefix preserved
    'model'     => 'claude-sonnet-4-6',
]);

// OpenAI-compatible proxy
$agent = new Agent([
    'provider'  => 'openai',
    'api_key'   => env('OPENAI_API_KEY'),
    'base_url'  => 'https://proxy.example.com/openai',      // path prefix preserved
    'model'     => 'gpt-4o',
]);

// Local Ollama behind a sub-path reverse proxy
$agent = new Agent([
    'provider'  => 'ollama',
    'base_url'  => 'http://localhost:8080/ollama',          // path prefix preserved
    'model'     => 'llama3',
]);
```

> **Note**: In v0.6.8 and earlier, `base_url` values with a path prefix were silently broken for OpenAI, OpenRouter, and Ollama providers — Guzzle's RFC 3986 resolver would strip the path when an absolute request path (e.g. `/v1/chat/completions`) was used. All four providers are now fixed.

## v0.6.8 Feature Configuration

### Incremental Context

```php
use SuperAgent\IncrementalContext\IncrementalContextManager;

$manager = new IncrementalContextManager([
    'auto_compress'       => true,
    'compress_threshold'  => 4000,   // compress when context exceeds N tokens
    'compress_delta'      => true,   // compress deltas before transmission
    'auto_checkpoint'     => true,
    'checkpoint_interval' => 10,     // create checkpoint every N messages
    'max_checkpoints'     => 10,
    'compression_level'   => 'balanced', // minimal | balanced | aggressive
]);

// Initialize with existing messages
$manager->initialize($messages);

// Get only what changed since the last checkpoint
$delta = $manager->getDelta();

// Reconstruct full context from a base + delta
$full = $manager->applyDelta($delta, $baseMessages);

// Restore to a previous checkpoint
$manager->restoreCheckpoint($checkpointId);

// Retrieve a token-budgeted window (recent messages first)
$window = $manager->getSmartWindow(maxTokens: 8000);
```

### Lazy Context Loading

```php
use SuperAgent\LazyContext\LazyContextManager;

$lazy = new LazyContextManager([
    'max_memory' => 50 * 1024 * 1024, // 50 MB cap
    'cache_ttl'  => 600,               // seconds
]);

// Register fragments – content is NOT loaded yet
$lazy->registerContext('system-rules', [
    'type'     => 'system',
    'priority' => 9,
    'tags'     => ['rules', 'permissions'],
    'size'     => 200,   // estimated tokens
    'source'   => '/path/to/rules.json', // or a callable
]);

$lazy->registerContext('codebase-overview', [
    'type'     => 'code',
    'priority' => 6,
    'tags'     => ['php', 'architecture'],
    'size'     => 1500,
    'data'     => $inlineMessages, // inline data, loaded immediately on first access
]);

// Load only what's relevant to the current task
$context = $lazy->getContextForTask('refactor PHP service layer');

// Or stay within a token budget
$window = $lazy->getSmartWindow(maxTokens: 12000, focusArea: 'php');

// Preload high-priority fragments in advance
$lazy->preloadPriority(minPriority: 8);
```

### Tool Lazy Loading

```php
use SuperAgent\Tools\ToolLoader;
use SuperAgent\Tools\LazyToolResolver;

// ToolLoader – register & load on demand
$loader = new ToolLoader(['lazy_load' => true]);

// Load only tools relevant to a task description
$tools = $loader->loadForTask('search and edit PHP files');

// Or use the resolver for call-time loading
$resolver = new LazyToolResolver($loader);
$resolver->predictAndPreload('migrate database schema');

// Pass tools to the agent
$agent = new Agent(['provider' => 'anthropic', 'tools' => $tools]);
```

### Web Search Without API Key

`WebSearchTool` automatically falls back to DuckDuckGo HTML search when `SEARCH_API_KEY` is not set. No configuration needed — the fallback is transparent. For production use, set one of:

```env
# Serper (recommended, ~2 500 free queries/month)
SEARCH_API_KEY=your_serper_key
SEARCH_ENGINE=serper

# Google Custom Search
SEARCH_API_KEY=your_google_key
SEARCH_ENGINE=google

# Bing
SEARCH_API_KEY=your_bing_key
SEARCH_ENGINE=bing
```

## Verification

### 1️⃣ Run Health Check

Create health check script `check-superagent.php`:

```php
<?php

require 'vendor/autoload.php';

$checks = [
    'PHP Version' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Laravel Installation' => class_exists('Illuminate\Foundation\Application'),
    'SuperAgent Installation' => class_exists('SuperAgent\Agent'),
    'JSON Extension' => extension_loaded('json'),
    'CURL Extension' => extension_loaded('curl'),
    'OpenSSL Extension' => extension_loaded('openssl'),
];

echo "SuperAgent Installation Check\n";
echo "============================\n\n";

$allPassed = true;
foreach ($checks as $name => $result) {
    $status = $result ? '✅' : '❌';
    echo "$status $name\n";
    if (!$result) $allPassed = false;
}

if ($allPassed) {
    echo "\n🎉 All checks passed! SuperAgent is ready.\n";
} else {
    echo "\n⚠️ Some checks failed, please resolve the issues above.\n";
    exit(1);
}
```

Run the check:
```bash
php check-superagent.php
```

### 2️⃣ Test Basic Functionality

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// Test basic query
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-4.6-haiku-latest',
    ],
]);

$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

$response = $agent->query("Say 'Installation successful!'");
echo $response->content;
```

### 3️⃣ Test CLI Tools

```bash
# List available tools
php artisan superagent:tools

# Test chat functionality
php artisan superagent:chat

# Execute simple query
php artisan superagent:run --prompt="What is 2+2?"
```

## Troubleshooting

### ❓ Composer Installation Fails

**Error Message**:
```
Your requirements could not be resolved to an installable set of packages
```

**Solution**:
```bash
# Clear cache
composer clear-cache

# Update dependencies
composer update --with-dependencies

# Use domestic mirror (China users)
composer config repo.packagist composer https://mirrors.aliyun.com/composer/
```

### ❓ Service Provider Not Found

**Error Message**:
```
Class 'SuperAgent\SuperAgentServiceProvider' not found
```

**Solution**:
```bash
# Regenerate autoload
composer dump-autoload

# Clear Laravel cache
php artisan optimize:clear
```

### ❓ Invalid API Key

**Error Message**:
```
Invalid API key provided
```

**Solution**:
1. Check API key in `.env` file is correct
2. Ensure no extra spaces or quotes around the key
3. Verify key is activated and not expired
4. Clear config cache: `php artisan config:clear`

### ❓ Memory Exhausted

**Error Message**:
```
Allowed memory size of X bytes exhausted
```

**Solution**:

Edit `php.ini`:
```ini
memory_limit = 512M
```

Or set temporarily in code:
```php
ini_set('memory_limit', '512M');
```

### ❓ Permission Denied

**Error Message**:
```
Permission denied
```

**Solution**:
```bash
# Set correct permissions
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Set owner (adjust based on system)
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

## Upgrade Guide

### From 0.x to 1.0

```bash
# 1. Backup existing data
php artisan backup:run

# 2. Update dependencies
composer update forgeomni/superagent

# 3. Run new migrations
php artisan migrate

# 4. Update configuration files
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config" --force

# 5. Clear all caches
php artisan optimize:clear
```

### Version Compatibility Matrix

| SuperAgent | Laravel | PHP   | Notes |
|------------|---------|-------|-------|
| 0.7.8      | 10.x+   | 8.1+ | Agent Harness mode + enterprise subsystems: persistent tasks & sessions, stream events, REPL loop, auto-compactor, E2E scenarios, API retry middleware, iTerm2 backend, plugin system, observable app state, hook hot-reloading, prompt/agent hooks, multi-channel gateway, backend protocol, OAuth device code flow, permission path rules, coordinator task notifications. 628 new tests |
| 0.7.7      | 10.x+   | 8.1+ | Debuggability hardening: error logging for 27 swallowed exceptions, Agent unit tests (31 tests), docs/REVIEW.md code review framework |
| 0.7.6      | 10.x+   | 8.1+ | 6 innovative subsystems: Agent Replay & Time-Travel Debugging, Conversation Forking, Agent Debate Protocol, Cost Prediction Engine, Natural Language Guardrails, Self-Healing Pipelines |
| 0.7.5      | 10.x+   | 8.1+ | Claude Code tool name compatibility: bidirectional ToolNameResolver, auto-resolve in agent definitions and permission system |
| 0.7.2      | 10.x+   | 8.1+ | Fix .claude/ path resolution: use project root instead of cwd for AgentManager, SkillManager, MCPManager |
| 0.7.1      | 10.x+   | 8.1+ | Fix AgentTool PermissionMode 'bypass' enum mismatch |
| 0.7.0      | 10.x+   | 8.1+ | 13 performance optimizations: token compaction, selective tools, model routing, prefill, cache pinning + parallel tools, streaming dispatch, connection pool, prefetch, adaptive tokens, batch API, zero-copy: tool result compaction, selective tool schema, model routing, response prefill, prompt cache pinning |
| 0.6.19     | 10.x+   | 8.1+ | In-process NDJSON logging via `NdjsonStreamingHandler` for process monitor visibility |
| 0.6.18     | 10.x+   | 8.1+ | Claude Code-compatible NDJSON structured logging replaces `__PROGRESS__:` protocol |
| 0.6.17     | 10.x+   | 8.1+ | Real-time child agent progress monitoring via `__PROGRESS__:` stderr protocol |
| 0.6.16     | 10.x+   | 8.1+ | Parent-to-child agent/MCP registration propagation via stdin serialization |
| 0.6.15     | 10.x+   | 8.1+ | MCP server sharing via TCP bridge — N child agents share 1 MCP server process |
| 0.6.12     | 10.x+   | 8.1+ | Child process Laravel bootstrap, provider config serialization fix, full tool set in sub-agents |
| 0.6.11     | 10.x+   | 8.1+ | True process-level parallel agents (proc_open replaces Fiber), 4.6x speedup |
| 0.6.10     | 10.x+   | 8.1+ | Multi-agent synchronous execution fix (fiber deadlock, backend type mismatch, progress tracker) |
| 0.6.9      | 10.x+   | 8.1+ | Guzzle base URL path fix for OpenAI / OpenRouter / Ollama providers |
| 0.6.8      | 10.x+   | 8.1+ | Incremental Context, Lazy Context & Tool Loading, sub-agent provider inheritance, WebSearch no-key fallback, WebFetch hardening |
| 0.6.7      | 10.x+   | 8.1+ | Multi-Agent Orchestration (parallel execution, auto-mode detection, team management) |
| 0.6.6      | 10.x+   | 8.1+ | Smart Context Window (888 tests) |
| 0.6.5      | 10.x+   | 8.1+ | Skill Distillation, Checkpoint & Resume, Knowledge Graph (865 tests) |
| 0.6.2      | 10.x+   | 8.1+ | Pipeline DSL (with review-fix loops), Cost Autopilot, Adaptive Feedback (776 tests) |
| 0.6.1      | 10.x+   | 8.1+ | Guardrails DSL (644 tests) |
| 0.6.0      | 10.x+   | 8.1+ | Bridge Mode |
| 0.5.7      | 10.x+   | 8.1+ | Telemetry master switch, security guardrails, experimental feature flags (452 tests) |

## Production Deployment

### Performance Optimization

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev
```

### Configure Queues

Create Supervisor configuration `/etc/supervisor/conf.d/superagent.conf`:

```ini
[program:superagent-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --queue=superagent
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/superagent-worker.log
```

### Configure Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/public;
    
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Streaming response support
    location /superagent/stream {
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 3600;
    }
}
```

## Get Help

### 📚 Resources

- 📖 [Official Documentation](https://superagent-docs.example.com)
- 💬 [Community Forum](https://forum.superagent.dev)
- 🐛 [Issue Tracker](https://github.com/yourusername/superagent/issues)
- 📺 [Video Tutorials](https://youtube.com/@superagent)

### 💼 Technical Support

- Community support: [GitHub Discussions](https://github.com/yourusername/superagent/discussions)
- Email support: mliz1984@gmail.com
- Discord server: [Join our community](https://discord.gg/superagent)

### 🔍 Debugging Tips

Enable debug mode:
```env
SUPERAGENT_DEBUG=true
APP_DEBUG=true
```

View logs:
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# SuperAgent specific logs
tail -f storage/logs/superagent.log

# Real-time debugging
php artisan tinker
```

## 📚 Documentation Navigation

### Language Versions
- 🇺🇸 [English Installation Guide](INSTALL.md)
- 🇨🇳 [中文安装手册](INSTALL_CN.md)

### Main Documentation
- 📖 [English README](README.md)
- 📖 [中文 README](README_CN.md)

### Additional Resources
- 🤝 [Contributing Guide](CONTRIBUTING.md)
- 📄 [License](LICENSE)

---

© 2024-2026 SuperAgent. All rights reserved.