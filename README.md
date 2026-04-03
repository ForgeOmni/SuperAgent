# SuperAgent - Multi-Provider AI Agent SDK for Laravel

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

> **🌍 Language**: [English](README.md) | [中文](README.zh-CN.md)  
> **📖 Documentation**: [Installation Guide](INSTALL.md) | [安装手册](INSTALL.zh-CN.md)

SuperAgent is a powerful Laravel AI Agent SDK that provides multi-provider support, comprehensive tooling, advanced permissions, and observability features.

## 🚀 Features

### Core Features
- **Multi-Provider Support** - Anthropic, OpenAI, Bedrock, OpenRouter and more
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
- **Multi-Agent Collaboration** - Swarm mode with specialized agents (Explore, Plan, Verification, Code-Writer, Researcher, Reviewer, Coordinator) and fork semantics for context-sharing sub-agents
- **Batch Skill** - `/batch` command for parallel large-scale changes across 5–30 isolated worktree agents, each opening a PR
- **MCP Protocol** - Integration with Model Context Protocol ecosystem, with server instruction injection into system prompt
- **Prompt Cache Optimization** - Dynamic system prompt assembly with static/dynamic boundary for prompt caching
- **Telemetry Master Switch** - Hierarchical telemetry control: master `telemetry.enabled` gate plus per-subsystem toggles (logging, metrics, events, cost_tracking) — when master is off, no data is collected regardless of individual settings
- **Security Prompt Guardrails** - Optional safety instructions injected into the system prompt to restrict security-related operations; configurable via `security_guardrails` flag
- **Guardrails DSL** - Declarative YAML rule engine for security, cost, compliance, and rate-limiting policies. Supports composable conditions (`all_of`/`any_of`/`not`), 7 condition types (tool, tool_content, tool_input, session, agent, token, rate), 8 action types (deny, allow, ask, warn, log, pause, rate_limit, downgrade_model), priority-ordered rule groups, and integration with the PermissionEngine pipeline
- **Bridge Mode** - Provider-agnostic enhancement proxy that injects CC optimization mechanisms (system prompt enhancement, context compaction, bash security, memory injection, tool schema optimization, cost tracking) into non-Anthropic models (OpenAI, Bedrock, Ollama, OpenRouter). Supports both HTTP proxy mode (for Codex CLI etc.) and SDK auto-enhance mode with 3-level priority control (`bridge_mode` param > config `auto_enhance` > feature flag)
- **Experimental Feature Flags** - 15 granular feature flags (with master switch) to gate experimental capabilities: ultrathink, token budget, prompt cache detection, builtin agents, verification agent, plan interview, agent triggers (local/remote), memory extraction, compaction reminders, cached microcompact, team memory, bash classifier, bridge mode
- **Observability** - OpenTelemetry integration with complete tracing and per-event-type analytics sampling rate control
- **File History** - LRU cache (100 message-level snapshots) with per-message rewind, diff stats (insertions/deletions/filesChanged), and snapshot inheritance
- **Tool Use Summaries** - Haiku-generated git-commit-subject-style summaries after tool batches
- **Tool Search & Deferred Loading** - Fuzzy keyword search with scoring, select mode, auto-threshold deferred loading (10% context window)
- **Remote Agent Tasks** - Out-of-process agent execution via API triggers with cron scheduling
- **Plan V2 Interview Phase** - Iterative pair-planning with structured plan files, periodic reminders, and user approval before execution
- **Claude Code Compatibility** - Auto-load skills, agents, and MCP configs from Claude Code directories

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

📋 **Quick Links**: [Installation Guide](INSTALL.md) | [中文安装手册](INSTALL.zh-CN.md) | [中文版本](README.zh-CN.md)

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
        'model' => 'claude-3-haiku-20240307',
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
        'model' => 'claude-3-haiku-20240307',
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
    model: 'claude-3-haiku-20240307',
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
- 🇨🇳 [中文 README](README.zh-CN.md)

### Installation Guides
- 📖 [English Installation Guide](INSTALL.md)
- 📖 [中文安装手册](INSTALL.zh-CN.md)

### Additional Resources
- 🤝 [Contributing Guide](CONTRIBUTING.md)
- 📄 [License](LICENSE)

---

<p align="center">
  Made with ❤️ by the SuperAgent Team
</p>