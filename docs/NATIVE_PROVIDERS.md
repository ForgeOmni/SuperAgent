# Native providers — Kimi / Qwen / GLM / MiniMax

> Since v0.8.8, Moonshot Kimi, Alibaba Qwen, Z.AI GLM, and MiniMax are
> first-class **native providers** — each has its own class, region
> configuration, and native-feature entry points. You no longer need to
> point `OpenAIProvider` at their `base_url` as a workaround.
>
> **Language:** [English](NATIVE_PROVIDERS.md) · [中文](NATIVE_PROVIDERS_CN.md) · [Français](NATIVE_PROVIDERS_FR.md)

---

## 1. Quick start

### 1.1 Set credentials

```bash
# All four default to the international endpoint (intl region)
export KIMI_API_KEY=sk-moonshot-xxx
export QWEN_API_KEY=sk-dashscope-xxx
export GLM_API_KEY=your-zai-key
export MINIMAX_API_KEY=your-minimax-key
export MINIMAX_GROUP_ID=your-group-id   # optional
```

Env-var aliases (auto-recognised):

| Primary variable | Alias |
|---|---|
| `KIMI_API_KEY` | `MOONSHOT_API_KEY` |
| `QWEN_API_KEY` | `DASHSCOPE_API_KEY` |
| `GLM_API_KEY` | `ZAI_API_KEY` / `ZHIPU_API_KEY` |
| `MINIMAX_API_KEY` | — |

### 1.2 Pick a region (CN keys require CN endpoints)

```bash
export KIMI_REGION=cn          # intl | cn
export QWEN_REGION=cn          # intl | us | cn | hk
export GLM_REGION=cn           # intl | cn
export MINIMAX_REGION=cn       # intl | cn
```

**Important**: All four vendors **host-bind their API keys**. An intl
key can't call a CN endpoint, and vice versa. `CredentialPool` filters
keys by `region` to prevent misrouting.

### 1.3 Use it

```bash
# CLI
superagent chat -p kimi "Write a Python fibonacci function"

# PHP
use SuperAgent\Providers\ProviderRegistry;

$provider = ProviderRegistry::createFromEnv('kimi');
// or with explicit region:
$provider = ProviderRegistry::createWithRegion('qwen', 'us', ['api_key' => '...']);
```

---

## 2. Default models + region map

| Provider | Default model | Supported regions → endpoint |
|---|---|---|
| **kimi** | `kimi-k2-6` | `intl` → api.moonshot.ai<br>`cn` → api.moonshot.cn |
| **qwen** | `qwen3.6-max-preview` | `intl` → dashscope-intl.aliyuncs.com (Singapore)<br>`us` → dashscope-us.aliyuncs.com (Virginia)<br>`cn` → dashscope.aliyuncs.com (Beijing)<br>`hk` → cn-hongkong.dashscope.aliyuncs.com |
| **glm** | `glm-4.6` | `intl` → api.z.ai/api/paas/v4<br>`cn` → open.bigmodel.cn/api/paas/v4 |
| **minimax** | `MiniMax-M2.7` | `intl` → api.minimax.io<br>`cn` → api.minimaxi.com |

Full model list: `superagent models list` or `resources/models.json`.

---

## 3. Per-provider native capabilities

### 3.1 Kimi K2.6

| Capability | How to use |
|---|---|
| Thinking (request-level) | `$options['features']['thinking']` — sets `reasoning_effort` (low/medium/high, bucketed from the advisory `budget` token count) + `thinking: {type: "enabled"}` on the same model. No model swap. |
| File extraction (PDF/PPT/Word → text) | `kimi_file_extract` tool |
| Batch processing (JSONL) | `kimi_batch` tool — pass `wait=false` for fire-and-forget |
| **Agent Swarm** (300 sub-agents / 4000 steps) | `kimi_swarm` tool — any main brain can call it; REST schema is **provisional** (Moonshot hasn't published the spec yet) |
| Context caching | Server-side automatic (no client-side markup) |

### 3.2 Qwen3.6-Max-Preview

> **Default path:** OpenAI-compatible endpoint `<region>/compatible-mode/v1/chat/completions` — the same endpoint Alibaba's own qwen-code CLI uses exclusively. The legacy DashScope `text-generation/generation` body shape is still available via `provider: qwen-native` (see below).

| Capability | How to use |
|---|---|
| **Thinking** (request-level) | `$options['features']['thinking']` — emits `enable_thinking: true` at the body root. **No `thinking_budget`** on the OpenAI-compat endpoint; the budget arg is accepted for interface compatibility but ignored (warning logged when `SUPERAGENT_DEBUG=1`). For budget control, opt into `provider: qwen-native`. |
| **Qwen-Long** (10M tokens via file reference) | `qwen_long_file` tool returns `fileid://xxx`; paste into a system message to give Qwen-Long file access. **Only supported on `cn` region.** Works under both `qwen` and `qwen-native` — the tool resolves the upload endpoint from the provider's host. |
| Multimodal (VL / Omni) | Use `qwen3-vl-plus` / `qwen3-omni` model ids |
| OCR | Use `qwen-vl-ocr` model |

**Legacy `qwen-native` provider.** When you need DashScope's native body shape (`input.messages` + `parameters.*` including `thinking_budget` and `enable_code_interpreter`), construct the legacy class:

```php
$qwen = ProviderRegistry::create('qwen-native', ['api_key' => $key, 'region' => 'intl']);
// equivalent to: new \SuperAgent\Providers\QwenNativeProvider([...])
```

Both providers report `name() === 'qwen'` so observability / cost-attribution stays consistent.

### 3.3 GLM (Z.AI / BigModel)

GLM's distinguishing trait: **tools are exposed as standalone REST
endpoints**, so your main LLM doesn't need to be GLM to use them.

| Capability | How to use |
|---|---|
| **Thinking** (`thinking: {type: enabled}`) | `$options['thinking'] = true` |
| **Web Search** | `glm_web_search` tool |
| **Web Reader** (URL → clean markdown) | `glm_web_reader` tool |
| **OCR / Layout parsing** | `glm_ocr` tool |
| **ASR** (speech-to-text, GLM-ASR-2512) | `glm_asr` tool |
| Strongest open-weight agentic model | Use `glm-5` (744B / 40B active) — #1 on MCP-Atlas, τ²-Bench, BrowseComp |

### 3.4 MiniMax M2.7

M2.7 is a **self-evolving agent model** with multi-agent collaboration
trained into the weights (not via prompt engineering).

| Capability | How to use |
|---|---|
| **Agent Teams** (native multi-agent, role boundaries + adversarial reasoning + protocol adherence) | `$options['features']['agent_teams']` with `roles` and `objective` |
| **Skills** (2000+ token skills, 97% adherence rate) | `SkillManager` + `SkillInjector` (MiniMax bridge) |
| **Dynamic tool search** (model finds its own tools) | Just attach all relevant tools |
| TTS (sync short text, ≤10K chars) | `minimax_tts` tool |
| Music generation (music-2.6) | `minimax_music` tool |
| Video (Hailuo-2.3, T2V + I2V) | `minimax_video` tool (async, `wait=false` for task_id) |
| Image (image-01) | `minimax_image` tool |

---

## 4. Mixed invocation — all vendors + other providers

This is v0.8.8's core design. **The main brain doesn't matter; every
vendor's specialty is callable from it.**

### 4.1 Example: Claude as main brain + GLM web search + MiniMax TTS

```php
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Providers\Glm\GlmWebSearchTool;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxTtsTool;

$claude  = ProviderRegistry::createFromEnv('anthropic');
$glmProv = ProviderRegistry::createFromEnv('glm');
$mmProv  = ProviderRegistry::createFromEnv('minimax');

$tools = [
    new GlmWebSearchTool($glmProv),
    new MiniMaxTtsTool($mmProv),
];

$response = $claude->chat($messages, $tools);
```

See the complete example: `examples/mixed_agent.php`.

### 4.2 Why it works

- SuperAgent's `Tool` contract is provider-agnostic — the main LLM only sees `name` / `description` / `inputSchema` / `execute()`
- Each vendor tool (`GlmWebSearchTool` etc.) **reuses** its provider's Guzzle client (bearer / base URL / region already configured)
- When the main brain invokes a tool, the actual HTTP call hits the matching vendor and the result flows back
- MCP tools (from any MCP server) and Skills (system-prompt injection) use the same `Tool` contract

---

## 5. Features (`features` field)

`$options['features']` is the unified cross-provider entry point that
`FeatureDispatcher` translates at request time:

```php
$provider->chat($messages, $tools, $system, [
    'features' => [
        'thinking' => ['budget' => 4000, 'required' => false],
        'agent_teams' => [
            'objective' => 'Produce a 10-page market report',
            'roles' => [
                ['name' => 'researcher', 'description' => 'Gather sources'],
                ['name' => 'writer',     'description' => 'Draft output'],
                ['name' => 'critic',     'description' => 'Challenge claims'],
            ],
        ],
    ],
]);
```

**Degradation strategy:**
- `required: false` (default) → unsupported providers fall back (CoT prompt / scaffold injection)
- `required: true` → unsupported providers raise `FeatureNotSupportedException` (extends `ProviderException`)
- `enabled: false` → hard no-op

See `docs/FEATURES_MATRIX.md` for the per-provider × per-feature grid.

### 5.1 `extra_body` escape hatch (power users)

Every provider that extends `ChatCompletionsProvider` (OpenAI, OpenRouter,
Kimi, GLM, MiniMax) accepts an `$options['extra_body']` array that is
**deep-merged at the top level of the outgoing request body after every
other transform runs** (`customizeRequestBody`, `FeatureDispatcher`).
It's the PHP equivalent of OpenAI's Python SDK `extra_body=` convention
and exists for the case where a vendor ships a new request field before
we've shipped a capability adapter for it:

```php
$provider->chat($messages, $tools, $system, [
    // Kimi: enable session-level prompt cache without a feature adapter
    'extra_body' => ['prompt_cache_key' => $sessionId],
]);

// Override an adapter's choice: FeatureDispatcher picked "medium" —
// we want "high"
$provider->chat($messages, $tools, $system, [
    'features'   => ['thinking' => ['budget' => 4000]],
    'extra_body' => ['reasoning_effort' => 'high'],
]);
```

Merge semantics: scalar fields overwrite; associative sub-objects
deep-merge (leaf-wins); indexed lists replace wholesale.

---

## 6. MCP (unified tool integration across providers)

```bash
# User-level config
superagent mcp add filesystem stdio npx --arg -y --arg @modelcontextprotocol/server-filesystem --arg /tmp
superagent mcp add search http https://mcp.example.com/search --header "Authorization: Bearer x"
superagent mcp list
superagent mcp remove filesystem
superagent mcp path   # prints ~/.superagent/mcp.json
```

**Configure once, works with every main brain** (all four new providers + the six pre-existing ones). MCP tools auto-wrap as SuperAgent `Tool`s and flow through the standard `formatTools()` path.

---

## 7. Skills

SuperAgent packages instructions / styles / rules into markdown files,
loaded from:
- `~/.superagent/skills/` (user-level)
- `<project>/.superagent/skills/` (project-level)

```bash
superagent skills install my-skill.md
superagent skills list
superagent skills show my-skill
superagent skills remove my-skill
```

**Skill file format** (frontmatter + body):
```markdown
---
name: code-review
description: Review PR against project conventions
category: engineering
---
Review the diff. Focus on: ...
```

`SkillInjector` merges the skill body into `$options['system_prompt']`
(with an idempotent `## Skill: <name>` header). When Kimi / MiniMax
publish their native Skills APIs, registering a bridge switches those
providers to the native upload path without any caller changes.

---

## 8. Security layer

Every tool declares `attributes()`, and `ToolSecurityValidator` decides:

| attribute | Meaning | Default behaviour |
|---|---|---|
| `network` | Reaches the public internet | denied when `SUPERAGENT_OFFLINE=1` |
| `cost` | Metered by vendor | routed through `CostLimiter` (per-call / per-tool-daily / global-daily) |
| `sensitive` | Uploads user data | default `ask` (configurable to `allow` / `deny`) |
| (Bash tools) | — | delegated to existing `BashSecurityValidator` (23 checks, unchanged) |

**Example configuration:**
```php
use SuperAgent\Security\ToolSecurityValidator;

$validator = new ToolSecurityValidator([
    'sensitive_default' => 'ask',
    'cost' => [
        'global_daily_usd' => 10.00,
        'per_tool_daily_usd' => ['minimax_video' => 5.00],
        'ask_threshold_usd' => 0.50,
    ],
]);

$decision = $validator->validate($tool, $input, /*estimated_cost=*/0.20);
// $decision->verdict ∈ {'allow', 'ask', 'deny'}
```

Ledger path: `~/.superagent/cost_ledger.json` (UTC auto-rollover).

---

## 9. Agent Team / Swarm orchestration

Three strategies, picked automatically by `SwarmRouter` or forced manually:

```bash
# Plan (no execution yet)
superagent swarm "Analyse this repo and generate a deck" --max-sub-agents 100
# → native_swarm (Kimi)

superagent swarm "Write a market analysis" --role researcher:gather --role writer:draft
# → agent_teams (MiniMax M2.7)

superagent swarm "Simple parallel task"
# → local_swarm (existing src/Swarm/)

superagent swarm ... --json   # emit plan as JSON
```

Execution wiring ships in the next minor; today the CLI only **plans**,
so you can take the plan's `strategy` + `provider` and dispatch
manually.

---

## 10. FAQ

**Q: I was pointing `OpenAIProvider` at Kimi's `base_url` — do I need to migrate?**
A: Recommended. Switching to `KimiProvider` unlocks region routing, native specialty features (Swarm etc.), and cleaner error tags. See `docs/MIGRATION_NATIVE.md`.

**Q: I don't use any of these four — am I affected?**
A: No. They're all opt-in. Without the env vars, `discover()` doesn't surface them and `superagent models list` shows only what you've configured.

**Q: Is Kimi Agent Swarm actually usable today?**
A: **SuperAgent's side is architecturally complete** (`SupportsSwarm` interface + `KimiSwarmTool` + `SwarmRouter`). But Moonshot's Swarm REST spec **isn't yet publicly documented**. We implement against the most plausible structure and flag it `provisional`; when the official spec ships, it's a 30-line fix to align.

**Q: Why does Qwen have 4 regions but others only 2?**
A: Qwen (DashScope) actually provides four geographies: Singapore / Virginia / Beijing / Hong Kong. The other three vendors currently advertise only international + mainland.

**Q: Did MCP / Skills change?**
A: Existing MCP (`MCPManager`, 1200+ lines) and Skills (`SkillManager` + builtins) are **untouched**. This release only standardised `~/.superagent/mcp.json` and `~/.superagent/skills/` as first-class directories and added CLI commands.

---

**Version:** v0.8.8 · **Updated:** 2026-04-21 · **Design doc:** `design/NATIVE_PROVIDERS_CN.md`
