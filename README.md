# SuperAgent

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.9.2-purple)](https://github.com/forgeomni/superagent)

> **🌍 Language**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)
> **📖 Docs**: [Installation](INSTALL.md) · [安装](INSTALL_CN.md) · [Installation FR](INSTALL_FR.md) · [Advanced usage](docs/ADVANCED_USAGE.md) · [API docs](docs/)

An AI agent SDK for PHP — run the full agentic loop (LLM turn → tool call → tool result → next turn) in-process, with twelve providers, real-time streaming, multi-agent orchestration, and a machine-readable wire protocol. Usable as a standalone CLI or as a Laravel library.

```bash
superagent "fix the login bug in src/Auth/"
```

```php
$agent = new SuperAgent\Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);

$result = $agent->run('Summarise docs/ADVANCED_USAGE.md in one paragraph');
echo $result->text();
```

---

## Table of Contents

- [Quick Start](#quick-start)
- [Providers & Authentication](#providers--authentication)
- [OpenAI Responses API](#openai-responses-api)
- [Agent Loop](#agent-loop)
- [Tools & Multi-Agent](#tools--multi-agent)
- [Agent Definitions](#agent-definitions-yaml--markdown)
- [Skills](#skills)
- [MCP Integration](#mcp-integration)
- [Wire Protocol](#wire-protocol)
- [Retry, Errors & Observability](#retry-errors--observability)
- [Guardrails & Checkpoints](#guardrails--checkpoints)
- [Standalone CLI](#standalone-cli)
- [Laravel Integration](#laravel-integration)
- [Configuration reference](#configuration-reference)

Every feature section ends with a *Since* line pointing at the release that introduced it. Full release notes live in [CHANGELOG.md](CHANGELOG.md).

---

## Quick Start

Install:

```bash
# As a standalone CLI:
composer global require forgeomni/superagent

# Or as a Laravel dependency:
composer require forgeomni/superagent
```

See [INSTALL.md](INSTALL.md) for the full matrix (system requirements, auth setup, IDE bridges, CI integration).

Smallest possible agent run:

```php
$agent = new SuperAgent\Agent(['provider' => 'anthropic']);
$result = $agent->run('what day is it?');
echo $result->text();
```

Smallest agent run with tools:

```php
$agent = (new SuperAgent\Agent(['provider' => 'openai']))
    ->loadTools(['read', 'write', 'bash']);

$result = $agent->run('inspect composer.json and tell me what PHP version this project targets');
echo $result->text();
```

One-shot via CLI:

```bash
export ANTHROPIC_API_KEY=sk-...
superagent "inspect composer.json and tell me what PHP version this project targets"
```

---

## Providers & Authentication

Twelve registry-backed providers, with region-aware base URLs and multiple auth modes per provider. All implement the same `LLMProvider` contract, so swapping one for another is one line.

| Registry key | Provider | Notes |
|---|---|---|
| `anthropic` | Anthropic | API key or stored Claude Code OAuth |
| `openai` | OpenAI Chat Completions (`/v1/chat/completions`) | API key, `OPENAI_ORGANIZATION` / `OPENAI_PROJECT` |
| `openai-responses` | OpenAI Responses API (`/v1/responses`) | [Dedicated section below](#openai-responses-api) |
| `openrouter` | OpenRouter | API key |
| `gemini` | Google Gemini | API key |
| `kimi` | Moonshot Kimi | API key; regions `intl` / `cn` / `code` (OAuth) |
| `qwen` | Alibaba Qwen (OpenAI-compat default) | API key; regions `intl` / `us` / `cn` / `hk` / `code` (OAuth + PKCE) |
| `qwen-native` | Alibaba Qwen (DashScope-native body) | Kept for `parameters.thinking_budget` callers |
| `glm` | BigModel GLM | API key; regions `intl` / `cn` |
| `minimax` | MiniMax | API key; regions `intl` / `cn` |
| `bedrock` | AWS Bedrock | AWS SigV4 |
| `ollama` | Local Ollama daemon | No auth — localhost:11434 by default |
| `lmstudio` | Local LM Studio server | Placeholder auth — localhost:1234 by default *(since v0.9.1)* |

Auth options, by priority:

1. **API key from environment** — `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `KIMI_API_KEY`, `QWEN_API_KEY`, `GLM_API_KEY`, `MINIMAX_API_KEY`, `OPENROUTER_API_KEY`, `GEMINI_API_KEY`.
2. **Stored OAuth credentials** at `~/.superagent/credentials/<name>.json`. Device-code flow — run `superagent auth login <name>`:
   - `claude-code` — reuses an existing Claude Code login
   - `codex` — reuses a Codex CLI login
   - `gemini` — reuses a Gemini CLI login
   - `kimi-code` — RFC 8628 device flow against `auth.kimi.com` *(since v0.9.0)*
   - `qwen-code` — device flow with PKCE S256 + per-account `resource_url` *(since v0.9.0)*
3. **Explicit config** — `api_key` / `access_token` / `account_id` on the agent options.

OAuth refresh is serialised across processes via `CredentialStore::withLock()` — parallel queue workers sharing one credential file don't race on refresh *(since v0.9.0)*.

### Declarative headers

```php
new Agent([
    'provider'         => 'openai',
    'env_http_headers' => [
        'OpenAI-Project'      => 'OPENAI_PROJECT',      // sent only when env set + non-empty
        'OpenAI-Organization' => 'OPENAI_ORGANIZATION',
    ],
    'http_headers' => [
        'x-app' => 'my-host-app',                       // static header
    ],
]);
```

*Since v0.9.1*

### Model catalog

Every provider ships with model-id + pricing metadata bundled in `resources/models.json`. Refresh to the vendor's live `/models` endpoint at any time:

```bash
superagent models refresh              # every provider with env creds
superagent models refresh openai       # one provider
superagent models list                 # show merged catalog
superagent models status               # catalog source + age
```

*Since v0.9.0*

---

## OpenAI Responses API

Dedicated provider at `provider: 'openai-responses'`. Hits `/v1/responses` with the full modern OpenAI shape.

**Why use it over `openai`:**

| Feature | Responses | Chat Completions |
|---|---|---|
| `previous_response_id` continuation | ✅ — server holds state; new turn skips resending context | ❌ — must re-send `messages[]` every turn |
| `reasoning.effort` (`minimal / low / medium / high / xhigh`) | ✅ native | ❌ requires model-id hacks for o-series |
| `reasoning.summary` | ✅ native | ❌ |
| `prompt_cache_key` (server-side cache pinning) | ✅ native | ❌ |
| `text.verbosity` (`low / medium / high`) | ✅ native | ❌ |
| `service_tier` (`priority / default / flex / scale`) | ✅ native | ❌ |
| Classified error types | ✅ via `response.failed` event codes | Pattern-matched on HTTP body |

```php
$agent = new Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);

$result = $agent->run('analyse this codebase and propose refactors', [
    'reasoning'        => ['effort' => 'high', 'summary' => 'auto'],
    'verbosity'        => 'low',
    'prompt_cache_key' => 'session:42',
    'service_tier'     => 'priority',
    'store'            => true,           // required to use previous_response_id next turn
]);

// Continue the conversation without resending history:
$provider = $agent->getProvider();
$nextAgent = new Agent([
    'provider' => 'openai-responses',
    'options'  => ['previous_response_id' => $provider->lastResponseId()],
]);
$nextResult = $nextAgent->run('now go one level deeper on the auth layer');
```

### ChatGPT subscription routing

Pass `access_token` (or set `auth_mode: 'oauth'`) to auto-route through `chatgpt.com/backend-api/codex` — so Plus / Pro / Business subscribers bill against their subscription instead of getting rejected at `api.openai.com`.

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $token,
    'account_id'   => $accountId,   // adds chatgpt-account-id header
]);
```

### Azure OpenAI

Six base-URL markers auto-flip the provider into Azure mode. `api-version` query string is added (default `2025-04-01-preview`, overridable); `api-key` header is set alongside `Authorization`.

```php
new Agent([
    'provider'          => 'openai-responses',
    'base_url'          => 'https://my-resource.openai.azure.com/openai/deployments/gpt-5',
    'api_key'           => $azureKey,
    'azure_api_version' => '2024-12-01-preview',   // optional override
]);
```

### Trace-context passthrough

Inject W3C `traceparent` into `client_metadata` so OpenAI-side logs correlate with your distributed trace:

```php
$tc = SuperAgent\Support\TraceContext::fresh();              // mint fresh
// OR: SuperAgent\Support\TraceContext::parse($headerValue); // from incoming HTTP header

$agent->run($prompt, ['trace_context' => $tc]);
// OR: $agent->run($prompt, ['traceparent' => '00-0af7-...', 'tracestate' => 'v=1']);
```

*Since v0.9.1*

---

## Agent Loop

`Agent::run($prompt, $options)` drives the full turn loop until the model stops emitting `tool_use` blocks. Each turn's cost, usage, and messages flow into `AgentResult`.

```php
$result = $agent->run('...', [
    'model'             => 'claude-sonnet-4-5-20250929',  // per-call override
    'max_tokens'        => 8192,
    'temperature'       => 0.3,
    'response_format'   => ['type' => 'json_schema', 'json_schema' => [...]],
    'idempotency_key'   => 'job-42:turn-7',               // since v0.9.1
    'system_prompt'     => 'You are a precise analyst.',
]);

echo $result->text();
$result->turns();          // turn count
$result->totalUsage();     // Usage{inputTokens, outputTokens, cache*}
$result->totalCostUsd;     // float, across all turns
$result->idempotencyKey;   // passthrough for usage-log dedup (since v0.9.1)
```

### Budget + turn caps

```php
$agent = (new Agent(['provider' => 'openai']))
    ->withMaxTurns(50)
    ->withMaxBudget(5.00);            // USD — hard cap; aborts mid-loop if breached
```

### Streaming

```php
foreach ($agent->stream('...') as $assistantMessage) {
    echo $assistantMessage->text();
}
```

For machine-readable event streams (JSON / NDJSON for IDE / CI consumers) see the [Wire Protocol](#wire-protocol) section.

### Auto-mode (task detection)

```php
new Agent([
    'provider'  => 'anthropic',
    'auto_mode' => true,               // delegates to TaskAnalyzer to pick model + tools
]);
```

### Idempotency

```php
$result = $agent->run($prompt, ['idempotency_key' => $queueJobId . ':' . $turnNumber]);
// $result->idempotencyKey is truncated to 80 chars; surfaces on the AgentResult
// so hosts that write ai_usage_logs can dedupe on it.
```

*Since v0.9.1*

---

## Tools & Multi-Agent

Tools are subclasses of `SuperAgent\Tools\Tool`. Built-in tools — read / write / edit / bash / glob / grep / search / fetch — auto-load unless the caller opts out. Custom tools register via `$agent->registerTool(new MyTool())`.

```php
$agent = (new Agent(['provider' => 'anthropic']))
    ->loadTools(['read', 'write', 'bash'])
    ->registerTool(new MyDomainTool());

$result = $agent->run('apply the refactor plan in ./plan.md');
```

### Multi-agent orchestration (`AgentTool`)

Dispatch sub-agents in parallel by emitting multiple `agent` tool_use blocks in one assistant message:

```php
$agent->registerTool(new AgentTool());

$result = $agent->run(<<<PROMPT
Run these three investigations in parallel:
1. Read CHANGELOG.md and summarise the last three releases
2. Read composer.json and list all runtime dependencies
3. Grep for TODO comments in src/
Collate the three reports.
PROMPT);
```

Each sub-agent runs in its own PHP process (via `ProcessBackend`); blocking I/O in one child doesn't block siblings. When `proc_open` is disabled, fibers take over.

#### Productivity evidence

Every `AgentTool` result carries hard evidence of what the child actually did — not just `success: true`:

```php
[
    'status'              => 'completed',          // or 'completed_empty' / 'async_launched'
    'filesWritten'        => ['/abs/path/a.md'],   // deduped absolute paths
    'toolCallsByName'     => ['Read' => 3, 'Write' => 1],
    'totalToolUseCount'   => 4,                    // observed, not self-reported turn count
    'productivityWarning' => null,                 // or advisory string (CJK-localised — since v0.9.1)
    'outputWarnings'      => [],                   // since v0.9.1 — filesystem audit findings
]
```

`completed_empty` — zero tool calls observed. Re-dispatch or pick a stronger model.
`completed` + non-empty `productivityWarning` — the child invoked tools but wrote no files (often fine for advisory consults; check the text).

*Productivity instrumentation since v0.8.9. CJK localisation + filesystem audit since v0.9.1.*

#### Output-directory audit + guard injection

Pass `output_subdir` to opt into both (a) a CJK-aware guard-block prepended to the child's prompt and (b) a post-exit filesystem scan:

```php
$agent->run('...', [
    'output_subdir' => '/abs/path/to/reports/analyst-1',
]);
// Audit catches:
//   - non-whitelisted extensions (defaults to .md / .csv / .png)
//   - consolidator-reserved filenames (summary.md / 摘要.md / mindmap.md / ...)
//   - sibling-role sub-dirs (ceo / cfo / cto / marketing / ... or kebab-case role slugs)
// Configurable via AgentOutputAuditor constructor. Never modifies disk.
```

*Since v0.9.1*

### Provider-native tools

Any main brain can call these as regular tools — no provider switch needed.

**Moonshot server-hosted builtins** (execute server-side; results inlined in the assistant reply):

| Tool | Attributes | Since |
|---|---|---|
| `KimiMoonshotWebSearchTool` (`$web_search`) | network | v0.9.0 |
| `KimiMoonshotWebFetchTool` (`$web_fetch`) | network | v0.9.1 |
| `KimiMoonshotCodeInterpreterTool` (`$code_interpreter`) | network, cost, sensitive | v0.9.1 |

**Other provider-native tool families:**
- Kimi — `KimiFileExtractTool`, `KimiBatchTool`, `KimiSwarmTool`, `KimiMediaUploadTool`
- Qwen — `QwenLongFileTool` + `dashscope_cache_control` feature
- GLM — `glm_web_search`, `glm_web_reader`, `glm_ocr`, `glm_asr`
- MiniMax — `minimax_tts`, `minimax_music`, `minimax_video`, `minimax_image`

---

## Agent Definitions (YAML / Markdown)

Auto-loaded from `~/.superagent/agents/` (user scope) and `<project>/.superagent/agents/` (project scope). Three formats: `.yaml`, `.yml`, `.md`. Cross-format `extend:` inheritance.

```yaml
# ~/.superagent/agents/reviewer.yaml
name: reviewer
description: Code reviewer with strict style enforcement
extend: base-coder              # can be .yaml / .yml / .md
system_prompt: |
  You review PRs with a focus on correctness and hidden state.
allowed_tools: [read, grep, glob]
disallowed_tools: [write, edit, bash]
model: claude-sonnet-4-5-20250929
```

```markdown
<!-- ~/.superagent/agents/analyst.md -->
---
name: analyst
extend: reviewer
model: gpt-5
---
Your job is to surface architectural risks. Write findings as Markdown.
```

Tool-list fields (`allowed_tools`, `disallowed_tools`, `exclude_tools`) accumulate through `extend:` chains. Cycle depth-limited.

*Since v0.9.0*

---

## Skills

Markdown-based capabilities you can register globally and pull into any agent run:

```bash
superagent skills install ./my-skill.md
superagent skills list
superagent skills show review
superagent skills remove review
superagent skills path        # show install directory
```

Skill markdown supports frontmatter with `name`, `description`, `allowed_tools`, `system_prompt`. Skill runs inherit the caller's provider.

---

## MCP Integration

### Server registration

```bash
superagent mcp list
superagent mcp add sqlite stdio uvx --arg mcp-server-sqlite
superagent mcp add brave stdio npx --arg @brave/mcp --env BRAVE_API_KEY=...
superagent mcp remove sqlite
superagent mcp status
superagent mcp path
```

Config persists atomically at `~/.superagent/mcp.json`.

### OAuth-gated MCP servers

```bash
superagent mcp auth <name>          # run RFC 8628 device flow
superagent mcp reset-auth <name>    # clear stored token
superagent mcp test <name>          # probe availability (stdio `command -v` or HTTP reachability)
```

Servers declaring an `oauth: {client_id, device_endpoint, token_endpoint}` block in their config use this flow. *Since v0.9.0*.

### Declarative catalog + non-destructive sync

Drop a catalog at `.mcp-servers/catalog.json` (or `.mcp-catalog.json`) in your project root:

```json
{
  "mcpServers": {
    "sqlite": {"command": "uvx", "args": ["mcp-server-sqlite"]},
    "brave":  {"command": "npx", "args": ["@brave/mcp"], "env": {"BRAVE_API_KEY": "k"}}
  },
  "domains": {
    "baseline": ["sqlite"],
    "all":      ["sqlite", "brave"]
  }
}
```

Sync to a project `.mcp.json`:

```bash
superagent mcp sync                         # full catalog
superagent mcp sync --domain=baseline       # only the "baseline" domain
superagent mcp sync --servers=sqlite,brave  # explicit subset
superagent mcp sync --dry-run               # preview, no disk writes
```

Non-destructive contract — byte-equal disk hash → `unchanged`; a user-edited file is kept as `user-edited`; first-time writes or our-last-hash matches become `written`. A manifest at `<project>/.superagent/mcp-manifest.json` tracks sha256 of every file we've written so stale entries clean up automatically.

*Since v0.9.1*

---

## Wire Protocol

v1 — line-delimited JSON (NDJSON), one event per line, self-describing via `wire_version` + `type` top-level fields. Foundation for IDE bridges, CI integrations, structured logs.

```bash
superagent --output json-stream "summarise src/"
# Emits events like:
# {"wire_version":1,"type":"turn.begin","turn_number":1}
# {"wire_version":1,"type":"text.delta","delta":"I'll start by..."}
# {"wire_version":1,"type":"tool.call","name":"read","input":{"path":"src/"}}
# {"wire_version":1,"type":"turn.end","turn_number":1,"usage":{...}}
```

### Transport (since v0.9.1)

Choose where the stream goes via a DSN:

| DSN | Meaning |
|---|---|
| `stdout` (default) / `stderr` | Standard streams |
| `file:///path/to/log.ndjson` | Append-mode file write |
| `tcp://host:port` | Connect to a listening TCP peer |
| `unix:///path/to/sock` | Connect to a listening unix socket |
| `listen://tcp/host:port` | Listen on TCP, accept one client |
| `listen://unix//path/to/sock` | Listen on unix socket, accept one client |

Programmatic use:

```php
$factory = new SuperAgent\CLI\AgentFactory();
[$emitter, $transport] = $factory->makeWireEmitterForDsn('listen://unix//tmp/agent.sock');

// IDE plugin attaches, then:
$agent->run($prompt, ['wire_emitter' => $emitter]);

$transport->close();
```

Non-blocking peer socket means a dropped IDE doesn't stall the agent loop.

*Wire Protocol v1 since v0.9.0. Socket / TCP / file transport since v0.9.1.*

---

## Retry, Errors & Observability

### Layered retry

```php
new Agent([
    'provider'               => 'openai',
    'request_max_retries'    => 4,       // HTTP connect / 4xx / 5xx (default 3)
    'stream_max_retries'     => 5,       // reserved for mid-stream resume (Responses API)
    'stream_idle_timeout_ms' => 60_000,  // cURL low-speed cutoff on SSE (default 300 000)
]);
```

Jittered exponential backoff (0.9–1.1× multiplier) prevents thundering-herd retries from parallel workers. `Retry-After` header honoured exactly (no jitter — the server knows best).

*Since v0.9.1*

### Classified errors

Six subclasses of `ProviderException` emitted by `OpenAIErrorClassifier` against the response body's `error.code` / `error.type` / HTTP status:

```php
try {
    $agent->run($prompt);
} catch (\SuperAgent\Exceptions\Provider\ContextWindowExceededException $e) {
    // prompt was too long; compact history or swap models
} catch (\SuperAgent\Exceptions\Provider\QuotaExceededException $e) {
    // monthly cap hit; notify operator
} catch (\SuperAgent\Exceptions\Provider\UsageNotIncludedException $e) {
    // ChatGPT plan doesn't include this model; upgrade or switch to API key
} catch (\SuperAgent\Exceptions\Provider\CyberPolicyException $e) {
    // policy rejection — don't retry
} catch (\SuperAgent\Exceptions\Provider\ServerOverloadedException $e) {
    // retryable with backoff; check $e->retryAfterSeconds
} catch (\SuperAgent\Exceptions\Provider\InvalidPromptException $e) {
    // malformed body — inspect and fix
} catch (\SuperAgent\Exceptions\ProviderException $e) {
    // catch-all base; every subclass above extends this
}
```

All subclasses extend `ProviderException`, so pre-existing `catch (ProviderException)` sites keep working unchanged.

*Since v0.9.1*

### Health dashboard

```bash
superagent health                # 5s cURL probe of every configured provider
superagent health --all          # include providers with no env key (useful for "what did I forget to set?")
superagent health --json         # machine-readable table; exits non-zero on any failure
```

Wraps `ProviderRegistry::healthCheck()` — distinguishes auth rejection (401/403) from network timeout from "no API key" so an operator can fix the right thing without guessing.

*Since v0.9.1*

### SSE parser hardening (since v0.9.0)

- **Per-index tool-call assembly** — one streamed call split across N chunks now produces one tool-use block, not N fragments.
- **`finish_reason: error_finish` detection** — DashScope-compat throttles raise `StreamContentError` (retryable, HTTP 429) instead of silently polluting the message body.
- **Truncated tool-call JSON repair** — one-shot attempt to close unbalanced braces before falling back to an empty arg dict.
- **Dual-shape cached-token reads** — `usage.prompt_tokens_details.cached_tokens` (current OpenAI shape) AND `usage.cached_tokens` (legacy) both populate `Usage::cacheReadInputTokens`.

---

## Guardrails & Checkpoints

### Loop detection (since v0.9.0)

Five detectors observe the streaming event bus; first trigger is sticky:

| Detector | Signal |
|---|---|
| `TOOL_LOOP` | Same tool + same normalised args 5× in a row |
| `STAGNATION` | Same tool name 8× regardless of args |
| `FILE_READ_LOOP` | ≥ 8 of last 15 tool calls are read-like, with cold-start exemption |
| `CONTENT_LOOP` | Same 50-char rolling window appears 10× in streamed text |
| `THOUGHT_LOOP` | Same thinking-channel text appears 3× |

```php
new Agent([
    'provider'        => 'openai',
    'loop_detection'  => true,           // defaults
    // OR per-detector overrides:
    // 'loop_detection' => ['TOOL_LOOP' => 10, 'STAGNATION' => 15],
]);
```

Violations fan out as `loop_detected` wire events — the agent keeps running, the host decides whether to intervene.

### Checkpoints + shadow-git (since v0.9.0)

Every turn snapshots the agent state (messages, cost, usage). Attach a `GitShadowStore` and file-level snapshots land alongside in a separate bare git repo at `~/.superagent/history/<project-hash>/shadow.git` — never touches the user's own `.git`.

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\GitShadowStore;

$mgr = new CheckpointManager(shadowStore: new GitShadowStore('/path/to/project'));
$mgr->createCheckpoint($agentState, label: 'after-refactor');

// Later:
$checkpoints = $mgr->list();
$mgr->restore($checkpoints[0]->id);
$mgr->restoreFiles($checkpoints[0]);   // plays back the shadow commit
```

Restore reverts tracked files and leaves untracked files in place for safety. The project's own `.gitignore` is respected (the shadow's worktree IS the project dir).

### Permission modes

```php
new Agent([
    'provider'        => 'anthropic',
    'permission_mode' => 'ask',     // or 'default' / 'plan' / 'bypassPermissions'
]);
```

`ask` prompts the caller's `PermissionCallbackInterface` before any write-class tool. Wrap it in `WireProjectingPermissionCallback` to surface the request as a wire event for IDE prompts.

---

## Standalone CLI

```bash
superagent                                  # interactive REPL
superagent "fix the login bug"              # one-shot
superagent init                             # initialize ~/.superagent/
superagent auth login <provider>            # import OAuth login
superagent auth status                      # show stored credentials
superagent models list / update / refresh / status / reset
superagent mcp list / add / remove / sync / auth / reset-auth / test / status / path
superagent skills install / list / show / remove / path
superagent swarm <prompt>                   # plan + execute a swarm
superagent health [--all] [--json] [--providers=a,b,c]   # provider reachability
```

**Options:**

```
  -m, --model <model>                  Model name
  -p, --provider <provider>            Provider key (openai, anthropic, openai-responses, ...)
      --max-turns <n>                  Maximum agent turns (default 50)
  -s, --system-prompt <prompt>         Custom system prompt
      --project <path>                 Project working directory
      --json                           Output results as JSON
      --output json-stream             Emit NDJSON wire events
      --verbose-thinking               Show full thinking stream
      --no-thinking                    Hide thinking
      --plain                          Disable ANSI colours
      --no-rich                        Legacy minimal renderer
  -V, --version                        Show version
  -h, --help                           Show help
```

**Interactive commands** (inside the REPL):

```
  /help                    available commands
  /model <name>            switch model
  /cost                    show cost tracking
  /compact                 force context compaction
  /session save|load|list|delete
  /clear                   clear conversation
  /quit                    exit
```

*Standalone CLI since v0.8.6.*

---

## Laravel Integration

The service provider auto-registers when you `composer require forgeomni/superagent`:

```php
// config/superagent.php
return [
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    'providers' => [
        'anthropic'         => ['api_key' => env('ANTHROPIC_API_KEY')],
        'openai'            => ['api_key' => env('OPENAI_API_KEY')],
        'openai-responses'  => ['api_key' => env('OPENAI_API_KEY'), 'model' => 'gpt-5'],
        // ...
    ],
    'agent' => [
        'max_turns'      => 50,
        'max_budget_usd' => 5.00,
    ],
];
```

```php
use SuperAgent\Facades\SuperAgent;

$result = SuperAgent::agent(['provider' => 'openai'])
    ->run('summarise this week\'s commits');
```

Artisan commands mirror the CLI:

```bash
php artisan superagent:chat "fix the bug"
php artisan superagent:mcp sync
php artisan superagent:models refresh
php artisan superagent:health --json
```

See `docs/LARAVEL.md` for queue integration, job dispatching, and the `ai_usage_logs` schema.

---

## Host Integrations

Frameworks that embed SuperAgent — typically multi-tenant platforms that store encrypted provider credentials in a database row and spin up an agent per request — use `ProviderRegistry::createForHost()` instead of `create()`. The host passes a normalised shape and the SDK dispatches to the right constructor via per-provider adapters.

```php
use SuperAgent\Providers\ProviderRegistry;

// One call, every provider — no `match ($type)` on the host side.
$agent = ProviderRegistry::createForHost($sdkKey, [
    'api_key'     => $aiProvider->decrypted_api_key,
    'base_url'    => $aiProvider->base_url,
    'model'       => $resolvedModel,
    'max_tokens'  => $extra['max_tokens']  ?? null,
    'region'      => $extra['region']      ?? null,
    'credentials' => $extra,                // opaque blob; adapter picks what it needs
    'extra'       => $extra,                // provider-specific passthrough (organization, reasoning, verbosity, ...)
]);
```

Every ChatCompletions-style provider (Anthropic, OpenAI, OpenAI-Responses, OpenRouter, Ollama, LM Studio, Gemini, Kimi, Qwen, Qwen-native, GLM, MiniMax) uses the default pass-through adapter. Bedrock ships a built-in adapter that splits `credentials.aws_access_key_id` / `aws_secret_access_key` / `aws_region` into the AWS SDK's shape.

Plugins or hosts that need to customise an adapter register their own:

```php
ProviderRegistry::registerHostConfigAdapter('my-custom-provider', function (array $host): array {
    return [
        'api_key' => $host['credentials']['my_custom_token'] ?? null,
        'model'   => $host['model'] ?? 'default-model',
        // ... arbitrary transform
    ];
});
```

New SDK provider keys in future releases register their own adapter (or ride the default one), so the host-side factory code never needs to grow a new `match` arm per release.

*Since v0.9.2*

---

## Configuration reference

Every option accepted by the `Agent` constructor, grouped. Defaults in parentheses.

**Provider selection**

| Key | Accepts |
|---|---|
| `provider` | Registry key or an `LLMProvider` instance |
| `model` | Model id — overrides provider default |
| `base_url` | URL — overrides provider default; also triggers auto-detection (Azure) |
| `region` | `intl` / `cn` / `us` / `hk` / `code` (provider-specific) |
| `api_key` | Provider API key |
| `access_token` + `account_id` | OAuth (OpenAI ChatGPT / Anthropic Claude Code) |
| `auth_mode` | `'api_key'` (default) or `'oauth'` |
| `organization` | OpenAI org id (adds `OpenAI-Organization` header) |

**Agent loop**

| Key | Default |
|---|---|
| `max_turns` | `50` |
| `max_budget_usd` | `0.0` (no cap) |
| `system_prompt` | `null` |
| `auto_mode` | `false` |
| `allowed_tools` / `denied_tools` | `null` / `[]` |
| `permission_mode` | `'default'` |
| `options` | `[]` (per-call defaults forwarded to provider) |

**Per-call options** (`$agent->run($prompt, $options)`)

| Key | Since | Notes |
|---|---|---|
| `model` / `max_tokens` / `temperature` / `tool_choice` / `response_format` | v0.1.0 | Standard Chat Completions knobs |
| `features` | v0.8.8 | `thinking` / `prompt_cache_key` / `dashscope_cache_control` / ... routed via `FeatureDispatcher` |
| `extra_body` | v0.9.0 | Power-user escape hatch — deep-merged into the request body |
| `loop_detection` | v0.9.0 | `true` (defaults), `false`, or threshold overrides |
| `idempotency_key` | v0.9.1 | Passthrough to `AgentResult::$idempotencyKey` |
| `reasoning` | v0.9.1 | Responses API — `{effort, summary}` |
| `verbosity` | v0.9.1 | Responses API — `low` / `medium` / `high` |
| `prompt_cache_key` | v0.9.0 | Cache key for Kimi + OpenAI Responses |
| `previous_response_id` | v0.9.1 | Responses API continuation |
| `store` / `include` / `service_tier` / `parallel_tool_calls` | v0.9.1 | Responses API |
| `client_metadata` | v0.9.1 | Responses API opaque key-value map |
| `trace_context` / `traceparent` / `tracestate` | v0.9.1 | W3C Trace Context injection |
| `output_subdir` | v0.9.1 | `AgentTool` guard-block + post-exit audit |

**Retry + transport** (provider-level)

| Key | Default | Since |
|---|---|---|
| `max_retries` | `3` | v0.1.0 (legacy single knob) |
| `request_max_retries` | `3` (inherits `max_retries`) | v0.9.1 |
| `stream_max_retries` | `5` | v0.9.1 |
| `stream_idle_timeout_ms` | `300_000` | v0.9.1 |
| `env_http_headers` | `[]` | v0.9.1 |
| `http_headers` | `[]` | v0.9.1 |
| `experimental_ws_transport` | `false` | v0.9.1 (scaffold) |
| `azure_api_version` | `'2025-04-01-preview'` | v0.9.1 (Azure only) |

---

## Links

- [CHANGELOG](CHANGELOG.md) — full per-release notes
- [INSTALL](INSTALL.md) — install + first-run setup
- [Advanced usage](docs/ADVANCED_USAGE.md) — patterns, sample agents, debugging
- [Native providers](docs/NATIVE_PROVIDERS.md) — region maps + capability matrix
- [Wire protocol](docs/WIRE_PROTOCOL.md) — v1 spec
- [Features matrix](docs/FEATURES_MATRIX.md) — which provider supports which feature

## License

MIT — see [LICENSE](LICENSE).
