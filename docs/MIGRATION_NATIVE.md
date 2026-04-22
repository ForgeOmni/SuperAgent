# Migration — from OpenAI-compat mode to native Kimi/Qwen/GLM/MiniMax

> For users who were calling Moonshot / Alibaba / Z.AI / MiniMax as OpenAI
> with a custom `base_url`. v0.8.8 ships real native providers — this guide
> shows the minimum diff to switch and what you unlock when you do.

## TL;DR

```diff
- $kimi = ProviderRegistry::create('openai', [
-     'api_key'  => getenv('KIMI_API_KEY'),
-     'base_url' => 'https://api.moonshot.ai',
-     'model'    => 'kimi-k2-6',
- ]);
+ $kimi = ProviderRegistry::create('kimi');
```

That's the whole migration. Everything else is bonus.

## What the one-line switch gets you

| Benefit | OpenAI-compat (before) | Native provider (after) |
|---|---|---|
| Region switching | Manual `base_url` override per region | `createWithRegion('kimi', 'cn')` or `KIMI_REGION=cn` env |
| Error tag | Errors show `provider: 'openai'` | Errors show `provider: 'kimi'` (readable logs) |
| `CredentialPool` safety | Can leak cn keys to intl endpoints | Region-tagged; mismatched keys filtered out |
| Native features (`thinking`, `swarm`, `agent_teams`, …) | Inaccessible | Via `$options['features']` / `SupportsSwarm` / FeatureAdapter |
| Specialty tools (GLM Web Search, Kimi File-Extract, MiniMax TTS, …) | Inaccessible | Importable as regular `Tool` classes |
| Model catalog entries (pricing, max_context, regions) | Missing for the vendor | Shipped in `resources/models.json` |

## Provider-by-provider migration

### Kimi (Moonshot)

```diff
- 'api_key'  => getenv('KIMI_API_KEY'),
- 'base_url' => 'https://api.moonshot.ai',
  // 'provider' => 'openai'
+ 'provider' => 'kimi',
+ 'region'   => 'intl',   // or 'cn'
```

**Env var**: `KIMI_API_KEY` (was whatever you'd pointed `OPENAI_API_KEY` at).
Legacy alias `MOONSHOT_API_KEY` also recognised.

**Model defaults**: `kimi-k2-6` (previously you had to hard-code).

**New capabilities you can use**:
- `kimi_file_extract` / `kimi_batch` / `kimi_swarm` tools.
- `SupportsSwarm` — `$kimi->submitSwarm(...)` returns a `JobHandle`.

### Qwen (DashScope)

```diff
- 'api_key'  => getenv('DASHSCOPE_API_KEY'),
- 'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
  // 'provider' => 'openai'
+ 'provider' => 'qwen',
+ 'region'   => 'intl',   // or 'us' / 'cn' / 'hk'
```

**Body shape change** — the native provider uses DashScope's
`text-generation/generation` endpoint (not chat-completions). Callers who
only invoke `chat()` don't need to do anything; the translation is
internal. But if you were hand-building requests against the compat
endpoint, that code needs to move into `QwenProvider::buildRequestBody()`
or you need to pass through `$options` with the new shape
(`enable_thinking`, `enable_code_interpreter`, etc.).

**Env var**: `QWEN_API_KEY` (legacy `DASHSCOPE_API_KEY` still works).

**New capabilities**:
- Four regions instead of one.
- `enable_thinking` + `thinking_budget` pass-through.
- `enable_code_interpreter`.
- `qwen_long_file` tool for the 10M-token file-reference mode.
- Full `SupportsThinking` integration with `ThinkingAdapter`.

### GLM (Z.AI / BigModel)

```diff
- 'api_key'  => getenv('ZAI_API_KEY'),
- 'base_url' => 'https://api.z.ai/api/paas/v4',
  // 'provider' => 'openai'
+ 'provider' => 'glm',
+ 'region'   => 'intl',   // or 'cn' (BigModel)
```

**Env var**: `GLM_API_KEY` (aliases `ZAI_API_KEY` / `ZHIPU_API_KEY`).

**New capabilities**:
- `glm_web_search` / `glm_web_reader` / `glm_ocr` / `glm_asr` — usable by any main brain, not just GLM itself.
- `$options['thinking'] = true` triggers `thinking: {type: enabled}`.

### MiniMax

```diff
- 'api_key'  => getenv('MINIMAX_API_KEY'),
- 'base_url' => 'https://api.minimax.io',
  // 'provider' => 'openai'
+ 'provider' => 'minimax',
+ 'region'   => 'intl',   // or 'cn' (api.minimaxi.com)
+ 'group_id' => getenv('MINIMAX_GROUP_ID'),  // optional, sets X-GroupId header
```

**Path change**: native provider hits `/v1/text/chatcompletion_v2` (was
compat-mode default).

**New capabilities**:
- `minimax_tts` / `minimax_music` / `minimax_video` / `minimax_image` as tools.
- `agent_teams` feature activates M2.7's native multi-agent mode.

## CredentialPool — tag your keys

If you pool multiple keys per vendor, add a `region` tag so the pool
doesn't serve a cn key to an intl endpoint (or vice versa):

```diff
  $pool = CredentialPool::fromConfig([
      'kimi' => [
          'strategy' => 'round_robin',
          'keys' => [
-             'sk-kimi-1',
-             'sk-kimi-2',
+             ['key' => 'sk-kimi-intl', 'region' => 'intl'],
+             ['key' => 'sk-kimi-cn',   'region' => 'cn'],
          ],
      ],
  ]);
```

Region-less strings stay accepted and are treated as "universal", so
this migration is optional — do it the moment you add a second-region
key.

## Feature spec — `$options['features']`

Instead of provider-specific magic strings, the native path exposes a
uniform feature channel:

```diff
  $provider->chat($messages, $tools, $systemPrompt, [
-     // hand-crafted per-provider
-     'enable_thinking' => true,
-     'thinking_budget' => 4000,
+     'features' => [
+         'thinking' => ['budget' => 4000],
+     ],
  ]);
```

Same feature map works for Anthropic (`thinking`), Qwen (`parameters.enable_thinking`),
GLM (`thinking: {type: enabled}`) — `FeatureDispatcher` translates.

## What stays the same

- **Every pre-0.8.8 public method** keeps its signature. `catch (ProviderException)` still catches new errors (the new `FeatureNotSupportedException` extends it).
- **`resources/models.json` v1** keeps loading unchanged. Schema v2 is purely additive.
- **`OpenAIProvider`** still exists, still points at `api.openai.com`, still honours OAuth / Organization. If you were using `openai` for actual OpenAI, nothing changes.
- **`BashSecurityValidator`** untouched. The 23 checks and their test suite are locked down; new `ToolSecurityValidator` delegates to it for Bash tools.

## Compatibility guarantees

The `tests/Compat/` suite in the repo pins:
- v1 `models.json` field-to-value mapping is byte-exact.
- Each pre-0.8.8 provider's default base-URL host.
- `ProviderRegistry::getCapabilities()` shape for the six shipped providers.

Anything that breaks those tests is by definition a breaking change and
goes through an explicit deprecation path — not a silent bump.

## When to keep compat-mode

Keep pointing `openai` at a vendor's `base_url` only if:
- You specifically don't want native features (simpler mental model).
- You're wrapping a vendor that doesn't have a dedicated provider in 0.8.8 (unlikely; all major Chinese providers now do).
- You're prototyping and haven't decided which provider to commit to.

For anything production-ish, switch to the native providers — region
safety and error attribution alone usually justify the one-line change.

---

**Version:** v0.8.8 · **Last updated:** 2026-04-21
