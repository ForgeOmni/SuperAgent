# 特性矩阵 — SuperAgent v0.8.8

> 每个已注册 provider 的能力一览。选 provider 时的速查表，也是写代码需要特定 native 特性时的参考。
>
> **语言:** [English](FEATURES_MATRIX.md) · [中文](FEATURES_MATRIX_CN.md) · [Français](FEATURES_MATRIX_FR.md)

**图例**

- ✅ 原生 — provider 直接实现（质量最高、延迟最低）
- ⚠️ 降级 — SuperAgent 通过 system prompt 注入或本地模拟近似实现（能用，但质量可能下降）
- ➖ 不支持
- 🧩 工具 — 作为独立 SuperAgent Tool 暴露，任何主脑可调

## 核心 chat

| Provider | 流式 | 工具调用 | Vision | 区域切换 | 最大上下文 |
|---|:---:|:---:|:---:|:---:|:---:|
| anthropic | ✅ | ✅ | ✅ | ➖ | 200K |
| openai | ✅ | ✅ | ✅ | ➖ | 128K |
| openrouter | ✅ | ✅ | ✅ | ➖ | 视模型 |
| bedrock | ✅ | 视模型 | 视模型 | ➖ | 视模型 |
| ollama | ✅ | ➖ | 视模型 | ➖ | 视模型 |
| gemini | ✅ | ✅ | ✅ | ➖ | 1.05M |
| **kimi** | ✅ | ✅ | ✅ | intl / cn | 256K |
| **qwen** | ✅ | ✅ | ✅ | intl / us / cn / hk | 260K |
| **glm** | ✅ | ✅ | ✅ | intl / cn | 200K |
| **minimax** | ✅ | ✅ | ✅ | intl / cn | 204K |

## Feature 通道能力

> 通过 `$options['features']['<name>']` 激活，由 `FeatureDispatcher` 路由到各 provider 的原生实现或降级 adapter。粗体 provider 为原生，其他走 fallback。

| Feature | 原生 provider | 降级路径 |
|---|:---|:---|
| `thinking` | anthropic, qwen, glm, kimi | CoT system prompt 注入（其他） |
| `agent_teams` | minimax | System prompt scaffold（其他） |
| `code_interpreter` | qwen-native | 提示模型优先用本地 sandbox tool（默认 `qwen` 走 OpenAI-兼容端，不支持此字段） |
| `context_cache` | anthropic | 透明跳过 |
| `file_extract` | kimi（工具） | 工具包装；无自动降级 |
| `long_context_file` | qwen（工具） | 工具包装；无自动降级 |
| `web_search` | glm（工具） | MCP web-search server |
| `prompt_cache_key` | kimi | 静默跳过（会话级缓存是性能优化，不是正确性保证） |

**required vs preferred**：spec 中 `required: true` 无原生且无降级时硬失败（`FeatureNotSupportedException`）；默认 `required: false` 优雅降级。

## Specialty-as-Tool（任何主脑可调）

> 每个工具声明 `attributes()`（`network` / `cost` / `sensitive`），由 `ToolSecurityValidator` 尊重执行。

| 工具 | Provider | 属性 | 异步? |
|---|---|---|:---:|
| `glm_web_search` | glm | network, cost | sync |
| `glm_web_reader` | glm | network, cost | sync |
| `glm_ocr` | glm | network, cost | sync |
| `glm_asr` | glm | network, cost | sync |
| `kimi_file_extract` | kimi | network, cost, sensitive | sync |
| `kimi_batch` | kimi | network, cost, sensitive | async (sync-wait) |
| `kimi_swarm` | kimi | network, cost | async (sync-wait) |
| `qwen_long_file` | qwen | network, cost, sensitive | sync |
| `minimax_tts` | minimax | network, cost | sync |
| `minimax_music` | minimax | network, cost | async (sync-wait) |
| `minimax_video` | minimax | network, cost | async (sync-wait) |
| `minimax_image` | minimax | network, cost | sync |

## MCP 和 Skills（跨 provider 无差异）

| 能力面 | 所有 provider 都能用? | 备注 |
|---|:---:|---|
| MCP tools | ✅ | `MCPTool` 实现标准 `Tool` 合约；每家 `formatTools()` 完全相同处理（`CrossProviderToolFormatTest` 锁定） |
| Skills | ✅ | `SkillInjector` 走 `$options['system_prompt']` 合并；bridge 钩子已就位，Kimi/MiniMax 原生上传 API 公开后即可切换 |
| 安全闸门 | ✅ | `ToolSecurityValidator` 委托 Bash 给 `BashSecurityValidator`，其他走 network / cost / sensitive 策略 |

## v0.8.8 明确未做的

- **Kimi Claw Groups** —— 外部 agent 挂载到 Kimi swarm。research preview；REST + 权限模型未公开
- **Kimi / MiniMax Skills 原生上传 REST** —— bridge 钩子（`SkillInjector::registerBridge`）已就位，vendor spec 公开后无需 caller 改动
- **MCP OAuth 2.0 流程** —— `McpAuthTool` 骨架存在；完整 device-code / authorization-code 流程留到有具体 OAuth MCP server 需求时
- **`superagent swarm` 执行路径** —— 规划已 ship；三条策略的实际调度 wiring 在下个小版本（contracts 稳定后）
- **Voice cloning / voice design（MiniMax）** —— 用 `MiniMaxTtsTool` 同模式可包装；留到用户真实需求浮现时

## 版本

本矩阵对应 **SuperAgent v0.8.8**。保持更新 —— 加新能力或某 provider 新增原生支持时，同一个 PR 里把对应行一起改。
