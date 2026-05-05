# SuperAgent

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.9.8-purple)](https://github.com/forgeomni/superagent)

> **🌍 Language**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)
> **📖 Docs**: [Installation](INSTALL.md) · [安装](INSTALL_CN.md) · [Installation FR](INSTALL_FR.md) · [Advanced usage](docs/ADVANCED_USAGE.md) · [API docs](docs/)

An AI agent SDK for PHP — run the full agentic loop (LLM turn → tool call → tool result → next turn) in-process, with thirteen providers, real-time streaming, multi-agent orchestration, and a machine-readable wire protocol. Usable as a standalone CLI or as a Laravel library.

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
- [Cross-provider handoff](#cross-provider-handoff)
- [DeepSeek V4](#deepseek-v4)
- [Goal mode (codex `/goal` parity)](#goal-mode-codex-goal-parity-v098)
- [Operational guardrails](#operational-guardrails-v098)
- [Companion tools (jcode-inspired)](#companion-tools-jcode-inspired)
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

Thirteen registry-backed providers, with region-aware base URLs and multiple auth modes per provider. All implement the same `LLMProvider` contract, so swapping one for another is one line.

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
| `deepseek` | DeepSeek V4 | API key; upstreams `deepseek` / `beta` / `cn` / `nvidia_nim` / `fireworks` / `novita` / `openrouter` / `sglang` *(since v0.9.6, multi-upstream v0.9.8)* |
| `bedrock` | AWS Bedrock | AWS SigV4 |
| `ollama` | Local Ollama daemon | No auth — localhost:11434 by default |
| `lmstudio` | Local LM Studio server | Placeholder auth — localhost:1234 by default *(since v0.9.1)* |

Auth options, by priority:

1. **API key from environment** — `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `KIMI_API_KEY`, `QWEN_API_KEY`, `GLM_API_KEY`, `MINIMAX_API_KEY`, `DEEPSEEK_API_KEY`, `OPENROUTER_API_KEY`, `GEMINI_API_KEY`.
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

## Cross-provider handoff

`Agent::switchProvider($name, $config, $policy)` swaps the active provider mid-conversation. The message history is preserved and re-encoded into the new provider's wire format on the next request — so a tool history that ran against Claude can continue under Kimi without losing parallel tool calls or `tool_use_id` correlation.

```php
use SuperAgent\Conversation\HandoffPolicy;

$agent = new Agent(['provider' => 'anthropic', 'api_key' => $key, 'model' => 'claude-opus-4-7']);
$agent->run('analyse this codebase');

// Hand off to a cheaper / faster model for the next phase:
$agent->switchProvider('kimi', ['api_key' => $kimiKey, 'model' => 'kimi-k2-6'])
      ->run('write the unit tests');

// Token-window check after switching — different tokenizers count
// the same history differently (Anthropic vs GPT-4 drift 20–30%):
$status = $agent->lastHandoffTokenStatus();
if ($status !== null && ! $status['fits']) {
    // Trigger your existing IncrementalContext compression before the next call.
}
```

### Handoff policy

```php
HandoffPolicy::default()      // keep tool history, drop signed thinking, append handoff marker
HandoffPolicy::preserveAll()  // keep everything — useful when swap is temporary and you'll come back
HandoffPolicy::freshStart()   // collapse history to (latest user turn) — fresh shot at a stuck conversation
```

Provider-only artifacts the new wire shape can't carry (Anthropic signed `thinking`, Kimi `prompt_cache_key`, Responses-API encrypted `reasoning`, Gemini `cachedContent` refs) get parked under `AssistantMessage::$metadata['provider_artifacts'][$providerKey]` — `HandoffPolicy::preserveAll()` keeps them around so a later swap back to the originating family can re-stitch them; `default()` keeps them stashed but invisible to the new provider.

### Atomic swap

`switchProvider()` constructs the new provider before mutating any state. If construction fails (missing `api_key`, unknown region, network probe rejection) the agent stays on the old provider with its history untouched.

### Six wire-format families share one Transcoder

All conversion goes through `Conversation\Transcoder`, which dispatches by `WireFamily` enum: `Anthropic` (also `bedrock`'s `anthropic.*` invocations), `OpenAIChat` (OpenAI/Kimi/GLM/MiniMax/Qwen/OpenRouter/LMStudio), `OpenAIResponses`, `Gemini` (the only family that correlates tool calls by name+order, no ids), `DashScope`, `Ollama`. Useful directly for offline transcoding:

```php
use SuperAgent\Conversation\Transcoder;
use SuperAgent\Conversation\WireFamily;

$wire = (new Transcoder())->encode($messages, WireFamily::Gemini);
```

*Since v0.9.5*

---

## DeepSeek V4

DeepSeek V4 (released 2026-04-24) ships two MoE models — `deepseek-v4-pro` (1.6T total / 49B active) and `deepseek-v4-flash` (284B / 13B active) — with **1M context** as the default and a single-model **thinking / non-thinking toggle**. The same backend exposes both an OpenAI-wire and an Anthropic-wire endpoint, so the SDK supports two routes:

```php
// OpenAI-wire: native DeepSeekProvider
$agent = new Agent([
    'provider' => 'deepseek',
    'api_key'  => getenv('DEEPSEEK_API_KEY'),
    'model'    => 'deepseek-v4-pro',           // or 'deepseek-v4-flash'
]);

// Anthropic-wire: reuse AnthropicProvider with a custom base_url
$agent = new Agent([
    'provider' => 'anthropic',
    'api_key'  => getenv('DEEPSEEK_API_KEY'),
    'base_url' => 'https://api.deepseek.com/anthropic',
    'model'    => 'deepseek-v4-pro',
]);
```

**Reasoning channel.** V4-thinking, R1, Kimi-thinking, Qwen-reasoning and any future OpenAI-compat reasoner stream their internal monologue on `delta.reasoning_content`. The shared `ChatCompletionsProvider` SSE parser now surfaces it as a separate `ContentBlock::thinking()` block prepended to the assistant turn — callers render or hide it deliberately rather than mixing it into the user-facing answer.

```php
$result = $agent->run('hard reasoning prompt', ['thinking' => true]);

foreach ($result->message()->content as $block) {
    if ($block->type === 'thinking') {
        // model's reasoning chain
    } elseif ($block->type === 'text') {
        // user-facing answer
    }
}
```

**Deprecation lane.** `deepseek-chat` and `deepseek-reasoner` retire **2026-07-24**. The catalog flags both with `deprecated_until` and `replaced_by` fields; `ModelResolver` emits a one-shot warning per process recommending `deepseek-v4-flash` / `deepseek-v4-pro` respectively. Set `SUPERAGENT_SUPPRESS_DEPRECATION=1` to silence.

**Cache-aware billing.** OpenAI-compat backends report `prompt_tokens` as gross (cache hits + misses). The parser now subtracts the cached portion before populating `Usage::inputTokens`, so the cache discount lands correctly — `CostCalculator` charges 10% of input price for read hits instead of effectively 110%. Affects every OpenAI-compat backend with caching (DeepSeek, Kimi, OpenAI itself).

**Beta endpoint.** Set `region: 'beta'` to route to `https://api.deepseek.com/beta` for FIM / prefix completion access on the same auth — see [`completeFim()`](#fim-prefix-completion-v098) for the dedicated helper.

*Since v0.9.6*

### Reasoning-effort dial *(v0.9.8)*

Three-tier dial across DeepSeek native + every relay:

```php
// Cheapest: thinking off entirely.
$agent->run('translate this paragraph', options: ['reasoning_effort' => 'off']);

// Standard thinking budget (V4-Pro tier default).
$agent->run('design a queue with at-least-once semantics', options: ['reasoning_effort' => 'high']);

// Deepest CoT — V4-Pro "think harder". Slower, more expensive.
$agent->run('audit this migration for race conditions', options: ['reasoning_effort' => 'max']);
```

Each upstream gets the body shape it expects: top-level
`reasoning_effort` + `thinking: {type: enabled}` for DeepSeek native /
OpenRouter / Novita / Fireworks / SGLang; nested
`chat_template_kwargs.{thinking, reasoning_effort}` for NVIDIA NIM.
Unknown values silently no-op rather than poisoning the request.

### Multi-upstream routing *(v0.9.8)*

Same V4 weights, six relay paths. One `upstream` config key picks the
host:

```php
$agent = new Agent([
    'provider' => 'deepseek',
    'upstream' => 'fireworks',          // or nvidia_nim / novita / openrouter / sglang
    'options'  => ['model' => 'deepseek-v4-pro'],
]);

// Self-hosted SGLang requires explicit base_url:
$agent = new Agent([
    'provider' => 'deepseek',
    'upstream' => 'sglang',
    'base_url' => 'http://my-sglang:30000/v1',
]);
```

`region` is preserved as an alias of `upstream` for backward
compatibility — existing `region: 'default' | 'cn' | 'beta'` callers
are byte-compatible.

### V4 Interleaved-Thinking replay *(v0.9.8)*

V4 thinking mode rejects assistant messages that carry `tool_calls`
without `reasoning_content`. The provider now:

1. Re-emits each `AssistantMessage`'s `thinking` blocks as wire
   `reasoning_content` automatically (no caller change).
2. Runs a final-pass sanitizer that forces a `(reasoning omitted)`
   placeholder on any assistant+tool_calls that slipped through —
   bullet-proofs sessions restored from disk pre-0.9.8 and sub-agents
   that hand-build messages.

Disable with `reasoning_effort: 'off'` (sanitizer skips when thinking
is explicitly disabled).

### FIM (prefix completion) *(v0.9.8)*

```php
$agent = new Agent([
    'provider' => 'deepseek',
    'region'   => 'beta',
]);

$completed = $agent->provider()->completeFim(
    prefix: "function fibonacci(\$n) {\n    ",
    suffix: "\n}\n",
    options: ['max_tokens' => 64],
);
```

Hits `https://api.deepseek.com/beta/v1/completions`. Throws when the
provider isn't on the beta region rather than silently routing
elsewhere.

### `/model auto` heuristic *(v0.9.8)*

```php
use SuperAgent\Routing\AutoModelStrategy;

$strategy = new AutoModelStrategy();
$model    = $strategy->select($messages, $systemPrompt, $options);
// → 'deepseek-v4-pro' or 'deepseek-v4-flash'

$agent = new Agent([
    'provider' => 'deepseek',
    'options'  => ['model' => $model, 'reasoning_effort' => 'high'],
]);
```

Pro escalation when: prompt ≥ 32K tokens, ≥ 3 trailing tool turns,
explicit `reasoning_effort=max`, or system-prompt keywords
(`review / audit / design / architect / plan / debug a complex /
analyze the codebase / find the root cause`). Flash otherwise.

### Cache-aware compaction *(v0.9.8)*

```php
use SuperAgent\Context\Strategies\CacheAwareCompressor;
use SuperAgent\Context\Strategies\ConversationCompressor;

$compactor = new CacheAwareCompressor(
    delegate:       new ConversationCompressor($estimator, $config, $provider),
    tokenEstimator: $estimator,
    config:         $config,
    pinHead:        4,        // first 4 messages stay byte-stable
    pinSystem:      true,     // also pin the system message
);
```

Wraps any `CompressionStrategy`. Result shape:
`[head_pinned, summary_boundary, summary, tail_preserved]` with the
cached prefix at byte 0. Idempotent across rounds — feeding a
compacted result back through the wrapper preserves the same prefix
bytes, so DeepSeek's auto prefix cache keeps hitting on every
`/compact`.

---

## Goal mode (codex `/goal` parity) *(v0.9.8)*

Three model-callable tools, four-state lifecycle, two prompt
templates. Goals are thread-scoped; each thread has at most one
non-terminal goal at a time. The model can ONLY transition `active →
complete`; pause / resume / budget changes flow from user / system.

```php
use SuperAgent\Goals\GoalManager;
use SuperAgent\Goals\InMemoryGoalStore;
use SuperAgent\Tools\Builtin\CreateGoalTool;
use SuperAgent\Tools\Builtin\GetGoalTool;
use SuperAgent\Tools\Builtin\UpdateGoalTool;

$threadId = 'session-42';
$goals    = new GoalManager(new InMemoryGoalStore());

$agent->registerTool(new CreateGoalTool($goals, $threadId));
$agent->registerTool(new GetGoalTool($goals, $threadId));
$agent->registerTool(new UpdateGoalTool($goals, $threadId));

// On each turn, account tokens and inject continuation when idle:
$agent->onTurnEnd(function ($usage) use ($goals, $threadId) {
    $goal = $goals->getActive($threadId);
    if ($goal === null) return;
    $updated = $goals->recordUsage($goal->id, $usage->inputTokens + $usage->outputTokens);
    if ($updated->status === GoalStatus::BudgetLimited) {
        $agent->injectSystemMessage($goals->renderBudgetLimitPrompt($updated));
    } elseif ($updated->status === GoalStatus::Active) {
        $agent->injectSystemMessage($goals->renderContinuationPrompt($updated));
    }
});
```

**Persistence.** `InMemoryGoalStore` ships with the SDK; SuperAICore
provides `EloquentGoalStore` (table `ai_goals`) so a goal survives
process restarts.

**Untrusted-input wrapping.** Both prompt templates wrap the user
objective in `<untrusted_objective>` via `Security\UntrustedInput::tag()`
so a crafted goal can't smuggle higher-priority instructions into the
system role:

```php
use SuperAgent\Security\UntrustedInput;

$wrapped = UntrustedInput::wrap($userInput, kind: 'note');
// → "The text below is user-provided data..." + "<untrusted_note>...</untrusted_note>"
```

Recommended at every site that injects user-supplied text into a
system-role message — goals, skills, memory imports.

---

## Operational guardrails *(v0.9.8)*

### Sub-agent depth cap

Cap on recursive `agent` tool calls. Mirrors codex's `agents.max_depth`.

```php
use SuperAgent\Swarm\AgentDepthGuard;

// Set the cap (default 5; env: SUPERAGENT_MAX_AGENT_DEPTH).
AgentDepthGuard::setMax(8);

// In the spawn site, before launching the child:
AgentDepthGuard::check();                       // throws AgentDepthExceededException at cap
$childEnv = AgentDepthGuard::forChild();        // pass to proc_open / Symfony\Process
```

Depth tracked through the `SUPERAGENT_AGENT_DEPTH` env so it survives
process spawning.

### Token-bucket rate limiter

DeepSeek-TUI shape (8 RPS sustained, 16-burst):

```php
use SuperAgent\Providers\Transport\TokenBucket;

$bucket = new TokenBucket(ratePerSecond: 8.0, burst: 16);

$bucket->consume();          // blocks until capacity
if (! $bucket->tryConsume()) { /* skip / queue */ }
```

In-process fidelity. Cross-process limits are a host concern (Redis-
backed Guzzle middleware).

### Ephemeral conversation fork (`/side` semantics)

```php
use SuperAgent\Conversation\Fork;

$fork = Fork::from($parentMessages);
$fork->extend(new UserMessage('try the alternative approach'),
              $sideAssistantReply);

// Either discard or promote selected side messages back into parent:
$parentNext = $fork->discard();          // throw the side away
$parentNext = $fork->promote(2);         // bring back side message #2 only
$parentNext = $fork->promoteAll();       // bring everything back
```

### Ad-hoc memory injection

```php
use SuperAgent\Memory\AdHocMemoryProvider;

$adhoc = new AdHocMemoryProvider();
$adhoc->push('CI is currently red on main', ttlSeconds: 1800, untrusted: true);
$adhoc->push('You MUST output JSON', ttlSeconds: 0, untrusted: false);  // sticky + trusted

$memoryManager->setExternalProvider($adhoc);
// Next turn sees both entries via onTurnStart(); ad-hoc is push-only —
// search() returns []. Compose alongside BuiltinMemoryProvider, not in place of.
```



---

## Companion tools (jcode-inspired)

Five additive primitives borrowed from [jcode](https://github.com/1jehuang/jcode). Each is opt-in and degrades to no-op when its host wiring is absent.

### `agent_grep` — token-aware grep with enclosing-symbol injection

A sibling of the byte-for-byte ripgrep `grep` tool. Same flags, plus per-match enclosing-symbol metadata (PHP / JS / TS / Python / Go) and per-session seen-chunk truncation so the model doesn't re-read the same hunk three turns in a row.

```php
$agent->loadTools(['grep', 'agent_grep']);   // both registered, pick per call

// Default: regex-based extractor (dependency-free, ~95% accuracy)
$agent->run('find every caller of MyClass::handle and show me which method contains it');
```

Symbol extraction is pluggable via the `Tools\Builtin\Symbols\SymbolExtractor` SPI:

```php
use SuperAgent\Tools\Builtin\AgentGrepTool;
use SuperAgent\Tools\Builtin\Symbols\CompositeSymbolExtractor;
use SuperAgent\Tools\Builtin\Symbols\TreeSitterSymbolExtractor;
use SuperAgent\Tools\Builtin\Symbols\RegexSymbolExtractor;

$agent->registerTool(new AgentGrepTool(symbolExtractor: new CompositeSymbolExtractor([
    new TreeSitterSymbolExtractor(),     // shells out to `tree-sitter` CLI; ~15 grammars
    new RegexSymbolExtractor(),          // pure-PHP fallback; always works
])));
```

Tree-sitter is auto-discovered on `$PATH` (override via `SUPERAGENT_TREE_SITTER_BIN` or constructor arg). Missing binary / unsupported grammar / failed invocation degrades to "I don't support this" — never throws.

### `FileLedger` — cross-agent edit notification for swarms

Agent A edits a file that agent B has read; B gets a `FileShiftedEvent` in its mailbox. Lazy-attached to `WorktreeManager::fileLedger()`, opt-in by tools that record reads/writes; default emitter is no-op so existing swarms are byte-compatible.

```php
$ledger = $worktreeManager->fileLedger();
$ledger->setEmitter(function (FileShiftedEvent $event, string $toAgent) {
    // event = {path, byAgent, at, summary, shaBefore, shaAfter}
    $mailbox->push($toAgent, $event);
});

$ledger->recordRead($agentB, '/abs/file.php');
$ledger->recordWrite($agentA, '/abs/file.php', shaBefore: '...', shaAfter: '...', summary: 'fixed null guard');
// → emitter fires with toAgent=$agentB
```

### `AmbientWorker` — background memory hygiene with cost split

Long-lived low-priority worker that runs memory dedup + staleness scans on a tick. Tick budget enforced internally so a pass never blocks for more than a few seconds. Token cost is tagged `usage_source: 'ambient'` via the supplied callback so dashboards split user-facing vs background spend.

```php
$worker = new AmbientWorker(
    memoryProvider: $memProvider,
    usageReporter:  fn(Usage $u) => $costMeter->record($u, source: 'ambient'),
    passBudgetSeconds: 3,
);

while ($host->running()) {
    $worker->tick();          // call from cron, swoole, react, or plain `while sleep`
    sleep(60);
}
```

### Native browser bridge (Firefox / Chromium)

WebExtension Native Messaging — 4-byte length-prefixed JSON framing — lets an agent drive a real browser without Selenium / Playwright. Single launcher per tool instance; tight capability surface (no tab management, cookies, or extension APIs).

```php
$agent->registerTool(new FirefoxBridgeTool());

$agent->run('open https://example.com, take a screenshot, click the "Sign in" link, screenshot again');
```

Launcher path comes from `SUPERAGENT_BROWSER_BRIDGE_PATH` (or constructor `launcherArgv`). The companion `Tools\Browser\FirefoxBridge::class` docblock contains the full WebExtension + Native Messaging manifest walkthrough.

### Pluggable embeddings — `Memory\Embeddings\*`

`EmbeddingProvider` interface (batch shape, `dimensions()`, `fingerprint()`). Three reference implementations:

| Class | Path of least resistance for |
|---|---|
| `OllamaEmbeddingProvider` | Devs already running Ollama locally — talks to `/api/embeddings`, default `nomic-embed-text` (768 dims) |
| `OnnxEmbeddingProvider` | In-process inference — needs `ext-onnxruntime` or `ankane/onnxruntime` + a model file |
| `NullEmbeddingProvider` | Tests / dev — returns `[]`; downstream falls back to keyword scoring |
| `CallableEmbeddingProvider` | Adapts existing `fn(array): array` or legacy `fn(string): array<float>` closures |

Hooks straight into the upgraded `SemanticSkillRouter`:

```php
use SuperAgent\Skills\SemanticSkillRouter;
use SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider;

$router = new SemanticSkillRouter(
    embedder: new OllamaEmbeddingProvider(),    // or any EmbeddingProvider
    topK: 5,
);
// Falls back to keyword overlap when no embedder; vector cache keyed by skill content hash.
```

### `superagent resume` — cross-harness session pickup

Pick up a Claude Code or Codex CLI session in SuperAgent without losing the thread.

```bash
superagent resume list  --from claude
superagent resume show  --from claude --session 8e2c-...
superagent resume load  --from claude --session 8e2c-... \
  | superagent chat --provider kimi --resume-stdin
```

`--from` accepts `claude` / `claude-code` / `cc` / `codex`. Behind the scenes: `Conversation\HarnessImporter` interface + per-harness importers (`ClaudeCodeImporter` reads `~/.claude/projects/<hash>/<uuid>.jsonl`; `CodexImporter` reads `~/.codex/sessions/**/*.jsonl`), feeding internal `Message[]` into the existing `Conversation\Transcoder` so the transcript flips wire family transparently.

*Since v0.9.7*

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
