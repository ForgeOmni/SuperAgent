# Feature Matrix — SuperAgent v0.8.8

> Where each registered provider stands on each capability. Use this as the
> cheat sheet when picking a provider for a task, or when writing code that
> needs a specific native feature.

**Legend**

- ✅ native — the provider implements the capability directly (best quality, lowest latency)
- ⚠️ fallback — SuperAgent approximates via system-prompt injection or local emulation (works, but quality can degrade)
- ➖ not supported
- 🧩 tool — exposed as a standalone SuperAgent Tool any main brain can call

## Core chat

| Provider     | Streaming | Tool calling | Vision | Region switch | Max context |
|--------------|:---------:|:------------:|:------:|:-------------:|:-----------:|
| anthropic    |     ✅     |      ✅       |   ✅    |       ➖       |  200 K       |
| openai       |     ✅     |      ✅       |   ✅    |       ➖       |  128 K       |
| openrouter   |     ✅     |      ✅       |   ✅    |       ➖       |  varies      |
| bedrock      |     ✅     |     model     |  model  |       ➖       |  model       |
| ollama       |     ✅     |      ➖       |  model  |       ➖       |  model       |
| gemini       |     ✅     |      ✅       |   ✅    |       ➖       |  1.05 M      |
| **kimi**     |     ✅     |      ✅       |   ✅    |   intl / cn   |  256 K       |
| **qwen**     |     ✅     |      ✅       |   ✅    | intl / us / cn / hk | 260 K  |
| **glm**      |     ✅     |      ✅       |   ✅    |   intl / cn   |  200 K       |
| **minimax**  |     ✅     |      ✅       |   ✅    |   intl / cn   |  204 K       |

## Feature-channel capabilities

> These are activated via `$options['features']['<name>']` and routed by
> `FeatureDispatcher` to each provider's native support or to a fallback
> adapter. Bold providers implement natively; others go through the
> fallback path.

| Feature            | Native providers       | Fallback                                   |
|--------------------|:-----------------------|:-------------------------------------------|

| `thinking`         | anthropic, qwen, glm, kimi | CoT system-prompt injection (every other) |
| `agent_teams`      | minimax                | System-prompt scaffold (every other)       |
| `context_cache`    | anthropic              | Transparent skip                           |
| `file_extract`     | kimi (via tool)        | Tool wrapper; no automatic fallback        |
| `long_context_file`| qwen (via tool)        | Tool wrapper; no automatic fallback        |
| `web_search`       | glm (via tool)         | MCP web-search server                      |
| `code_interpreter` | qwen-native            | ➖ (not emulated; default `qwen` provider's OpenAI-compat endpoint doesn't surface it) |
| `prompt_cache_key` | kimi                   | Silent skip (perf optimization, not correctness) |

Required vs preferred — pass `required: true` in the feature spec to
hard-fail (`FeatureNotSupportedException`) when neither native support
nor a usable fallback is available. Default is `required: false`
(graceful degradation).

## Specialty-as-Tool (callable from any main brain)

> Each tool declares `attributes()` — `network` / `cost` / `sensitive` —
> that `ToolSecurityValidator` honours.

| Tool                       | Provider  | Attributes                     | Async? |
|----------------------------|-----------|--------------------------------|:------:|
| `glm_web_search`           | glm       | network, cost                  | sync   |
| `glm_web_reader`           | glm       | network, cost                  | sync   |
| `glm_ocr`                  | glm       | network, cost                  | sync   |
| `glm_asr`                  | glm       | network, cost                  | sync   |
| `kimi_file_extract`        | kimi      | network, cost, sensitive       | sync   |
| `kimi_batch`               | kimi      | network, cost, sensitive       | async (sync-wait) |
| `kimi_swarm`               | kimi      | network, cost                  | async (sync-wait) |
| `qwen_long_file`           | qwen      | network, cost, sensitive       | sync   |
| `minimax_tts`              | minimax   | network, cost                  | sync   |
| `minimax_music`            | minimax   | network, cost                  | async (sync-wait) |
| `minimax_video`            | minimax   | network, cost                  | async (sync-wait) |
| `minimax_image`            | minimax   | network, cost                  | sync   |

## MCP & Skills (provider-agnostic)

| Surface        | Works across every provider? | Notes                                                             |
|----------------|:----------------------------:|-------------------------------------------------------------------|
| MCP tools      | ✅                            | `MCPTool` implements the `Tool` contract; every provider's `formatTools()` handles it identically (locked by `CrossProviderToolFormatTest`). |
| Skills         | ✅                            | `SkillInjector` merges into `$options['system_prompt']` for every provider. Bridge hooks ready for Kimi/MiniMax native uploads once vendor specs publish. |
| Security gate  | ✅                            | `ToolSecurityValidator` delegates bash to `BashSecurityValidator` and applies network / cost / sensitive policy to everything else. |

## What's deliberately not in 0.8.8

- **Kimi Claw Groups** — external-agent mount into Kimi swarm. Research preview; REST + permission model not public.
- **Kimi / MiniMax native Skills upload REST** — bridge hooks are in place (`SkillInjector::registerBridge`) so this drops in without caller changes once the vendor specs publish.
- **MCP OAuth 2.0 flow** — `McpAuthTool` scaffold exists; full device-code / authorization-code flow left for when a user-initiated MCP server requires it.
- **`superagent swarm` execution path** — planning is shipped; actual dispatch to the three strategies wires up in the next minor once all three targets have stable contracts.
- **Voice cloning / voice design (MiniMax)** — wrappable with the same pattern as `MiniMaxTtsTool`; deferred until real user demand surfaces.

## Version

This matrix tracks **SuperAgent v0.8.8**. Keep it fresh — if you add a
capability or a provider gains native support for a feature, update the
corresponding row in the same PR.
