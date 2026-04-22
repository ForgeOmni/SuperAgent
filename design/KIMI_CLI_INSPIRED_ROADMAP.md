# kimi-cli 启发的分步开发路线图

> **日期:** 2026-04-22 · **基线版本:** v0.8.9 · **状态:** 待实施
>
> **参考源:** `/Users/xiyang/PhpstormProjects/kimi-cli`（MoonshotAI 官方 Kimi Code CLI，Python 3.12 + asyncio + Typer + prompt-toolkit，独立 LLM 子包 `packages/kosong/`）
>
> **原则:** 不把所有改动塞一个版本。每个 Phase 独立可交付、独立可回滚、独立可测试。一个 Phase 一个版本号。

---

## 背景

调研了 Moonshot 的 kimi-cli 仓库，发现若干我们 KimiProvider（及泛化到其他 provider）可借鉴的地方。核心发现：

1. **Kimi thinking 的实现方式已经改了** —— 不再是"换 `kimi-k2-thinking-preview` 模型名"，而是在同一模型上发 `reasoning_effort` + `extra_body.thinking.type`。我们 `src/Providers/KimiProvider.php:36-39` 是过时实现。
2. **Kimi 有三个 endpoint，不是两个** —— 除了 `api.moonshot.ai/v1`（intl API key）和 `api.moonshot.cn/v1`（cn API key），还有 `api.kimi.com/coding/v1`（**Kimi Code 订阅走 OAuth**，对应 Moonshot 的 `kimi-code` 付费方案用户）。
3. **`prompt_cache_key` 作为 session id 送进去，Kimi 自动做会话级缓存** —— 不是 Anthropic 那种块级 `cache_control`。我们 `SupportsContextCaching` 接口是 Anthropic 形状的，对 Kimi 不合适。
4. **kimi-cli 自己都没实现 Swarm / Batch client** —— 证实 Moonshot 至今没公开 wire shape；我们 `KimiSwarmTool` / `KimiBatchTool` 继续标 provisional 是对的。
5. **6 个 `X-Msh-*` device header** 后端用来做限流 / abuse policy —— 我们一个都不发。
6. **`GET {base_url}/models` 活动目录拉取** 是业界通用模式，不只 Kimi —— OpenAI / Anthropic / Gemini / OpenRouter / GLM / Qwen 全有；kimi-cli 登录后和 `/usage` 之后各刷一次，`resources/models.json` 降级为 fallback。我们现在是静态 JSON + 手工维护，catalog drift 是长期隐患。
7. **Device Authorization Grant (OAuth Device Code Flow)** —— Claude Pro / ChatGPT Plus / Gemini / Kimi Code 全是近乎同款的流程，kimi-cli 的 `src/kimi_cli/auth/oauth.py` 是很好的参考范本（包括 "Linux keyring 不可靠，坚持纯文件 + flock" 这一决策）。
8. **Wire Event Protocol** 作为 TUI / ACP IDE / stream-json 三前端的唯一边界 —— 我们 `src/Harness/*Event.php` 已经很接近，差正式版本化。
9. **Agent spec as YAML + `extend:` 继承** —— sub-agent 从 YAML 加载，用户无需写 PHP 就能定制 team。
10. **`kimi mcp {add,remove,list,auth,reset-auth,test}` 作为一级 CLI 子命令组**，per-server OAuth token 存储。

---

## 分 Phase 路线图

每个 Phase 独立交付。下方括号里的版本号是**建议**，维护者可以调。

### Phase 1 —— Kimi thinking 修正（小补丁 0.8.10）

**目标:** 修掉 `KimiProvider::thinkingRequestFragment()` 的过时模型名交换实现，换成 Moonshot 当前正确的请求参数形式。

**依据:**
- kimi-cli `packages/kosong/src/kosong/chat_provider/kimi.py:187-204` 使用 `reasoning_effort: "low|medium|high"` + `extra_body.thinking = {"type": "enabled"|"disabled"}` 形式
- 同一模型上切换，不是换 model id

**改动:**
- `src/Providers/KimiProvider.php` —— 重写 `thinkingRequestFragment()`，返回 `['reasoning_effort' => $effort, 'thinking' => ['type' => 'enabled']]`
- `src/Providers/ChatCompletionsProvider.php::buildRequestBody()` —— 支持 `extra_body` 透传（Kimi / GLM / MiniMax 将来都会用到），或把 `thinking` 作为顶层字段写进 body 再由 provider 的 `customizeRequestBody()` 搬到 `extra_body`
- `resources/models.json` —— 重新审视 `kimi-k2-thinking-preview` 这个条目是否还有意义；若无则移除，若有（作为旧行为 fallback）则加注释
- `tests/Unit/Providers/KimiProviderTest.php` —— 新增 thinking 请求 body 形状断言；移除老的"切模型名"断言

**兼容:**
- `thinkingRequestFragment()` 是内部接口，改动不破坏公共 API
- `FeatureDispatcher` 的 `ThinkingAdapter` 路径不变 —— 还是先调 provider 的 fragment 方法，再 deep-merge 进 body

**工作量:** 0.5 天
**风险:** 低。`resources/models.json` 中那条 `kimi-k2-thinking-preview` 如果被用户代码显式指定过，移除要留 deprecation 警告。
**验证:** 本地跑一条真实 Kimi 调用（`KIMI_API_KEY` 环境变量），对比响应里是否有 `reasoning_content` 字段（Kimi 思考输出的标志）。

**交付物:** 0.8.10 补丁发布。

---

### Phase 2 —— `extra_body` 透传机制 + 3 个 ChatCompletionsProvider 统一调整（0.8.11）

**目标:** 把 Phase 1 临时塞进 Kimi 的 `extra_body` 机制做成 `ChatCompletionsProvider` 基类的一等公民，让 GLM / MiniMax 共用。

**依据:**
- `extra_body` 是 OpenAI SDK 定义的"透传到后端但不走 OpenAI schema 验证"的标准口子
- Kimi、GLM、MiniMax 三家都通过它塞各自的 `thinking` / `agent_teams` / 其他 provider-specific 字段
- 当前我们的 `customizeRequestBody()` hook 能做这件事，但三家各自实现造成重复

**改动:**
- `ChatCompletionsProvider.php` —— 新 protected helper `mergeExtraBody(array &$body, array $extraBody)`；`$options['extra_body']` 若存在则合并进去
- `KimiProvider.php` / `GlmProvider.php` / `MiniMaxProvider.php` —— 简化 `customizeRequestBody()`，复用新 helper
- `src/Providers/Features/ThinkingAdapter.php` —— 写 thinking 字段时走 `extra_body`（对 Kimi / GLM 都生效）
- 新 test：`ExtraBodyMergingTest`

**工作量:** 1 天
**风险:** 低 —— 纯重构，行为不变
**交付物:** 0.8.11

---

### Phase 3 —— `GET /models` 活动 catalog 刷新（0.9.0）

**目标:** `resources/models.json` 降级为"离线 fallback"，主路径变成"登录后从 `{base_url}/models` 拉真实模型列表 + 能力 flag"。

**依据:**
- kimi-cli `src/kimi_cli/auth/platforms.py:122-232` 的 `refresh_managed_models()` —— 登录成功后 / `/usage` 之后各刷一次
- OpenAI / Anthropic / Gemini / OpenRouter / Moonshot / Zhipu / Qwen 全部暴露 `/models`，返回 `{id, context_length, display_name, supports_reasoning, supports_image_in, supports_video_in, ...}` 等标准字段
- 当前静态 JSON 导致 catalog drift —— Kimi K2.7 / GPT-5.2 / Claude Opus 4.7 发布时我们要改代码，不改就错价、错 capability

**改动:**
- 新 `src/Providers/ModelCatalogRefresher.php` —— 支持 per-provider 的 `refresh(ProviderInterface $p): array`，返回标准化模型列表
- `src/Providers/ModelCatalog.php` —— 增加 `applyRefreshed(string $provider, array $models)` 方法，支持增 / 改 / 删（port kimi-cli 的 `_apply_models` 合并逻辑）
- 缓存策略：`~/.superagent/models-cache/<provider>.json`（atomic write），24h TTL，`superagent models refresh` 手动刷
- `resources/models.json` 变成"首次运行前的种子 + 离线 fallback"
- CLI 新 subcommand `superagent models refresh [<provider>]`
- 测试 `tests/Unit/Providers/ModelCatalogRefresherTest.php` —— mock `/models` 响应

**工作量:** 1.5-2 天
**风险:** 中 —— 每家 provider 的 `/models` 响应字段略有差别，需要 per-provider 适配层。`/models` 要 auth，所以必须在 ProviderRegistry 有凭证后才能调。
**验证:** 本地跑 `superagent models refresh` 对比 5-6 家 provider 返回的模型数。
**交付物:** 0.9.0 —— 这是一个里程碑，值得 minor bump

---

### Phase 4 —— OAuth Device Authorization Grant 统一实现（0.9.1）

**目标:** 把 Kimi Code / Claude Pro / ChatGPT Plus / Gemini 的 OAuth 登录统一到一套代码路径；加 Kimi 的第三个 endpoint `api.kimi.com/coding/v1` 作为一个新的 region 叫 `code`。

**依据:**
- kimi-cli `src/kimi_cli/auth/oauth.py:454-713` 的完整实现（device code 请求 → 轮询 token → 原子写凭证文件 → 过期前 5 分钟自动续签 → 跨进程 flock）
- 明确决策：不碰 keyring（Linux 不稳），全部走 `~/.kimi/credentials/<name>.json` mode 0600
- 设备识别 header 族：`X-Msh-Platform / X-Msh-Version / X-Msh-Device-Id / X-Msh-Device-Name / X-Msh-Device-Model / X-Msh-Os-Version`（Moonshot 家后端用来做限流）

**改动:**
- 新 `src/Auth/OAuthDeviceFlow.php` —— 通用 Device Authorization Grant 实现（RFC 8628），可被多 provider 共用
- 新 `src/Auth/CredentialStore.php` —— 原子读写 `~/.superagent/credentials/<name>.json`，跨进程 flock，自动刷新
- 新 `src/Auth/Providers/KimiCodeAuthProvider.php`（或在 `KimiProvider` 里注册）—— 实现 device_code endpoint、client_id、token endpoint、refresh endpoint
- `src/Providers/KimiProvider.php` —— 新 region `code` → `api.kimi.com/coding/v1`；`resolveBearer()` 优先读 OAuth 凭证，fallback 到 `KIMI_API_KEY`
- `ChatCompletionsProvider::extraHeaders()` —— 加 6 个 `X-Msh-*` device header（对 Kimi / Kimi Code 区域有效，其他 region 不发）
- 新 CLI `superagent login kimi` / `superagent login kimi-code` / `superagent logout kimi`
- Device header 来源：`src/Auth/DeviceIdentity.php`（生成稳定 UUID 存 `~/.superagent/device.json`，读系统信息填 model/os_version）
- 测试：`OAuthDeviceFlowTest`、`CredentialStoreTest`、`KimiCodeAuthProviderTest`（mock HTTP）

**工作量:** 2 天
**风险:** 中 —— OAuth 的 edge cases 多（refresh token 失效、clock skew、并发 refresh 竞态）。kimi-cli 已经踩过的坑抄过来就行
**验证:**
- 本地跑 `superagent login kimi-code` 完成一次真实 device flow
- 跑一天多次调用看自动刷新能不能无缝过期切换
**交付物:** 0.9.1

---

### Phase 5 —— `SupportsPromptCacheKey` 接口 + Kimi 会话级缓存（0.9.2）

**目标:** 补齐会话级 prompt cache 这种 Kimi 模式的接口形状。我们现有 `SupportsContextCaching` 是块级 Anthropic 形状，对 Kimi 不适用。

**依据:**
- kimi-cli `packages/kosong/src/kosong/chat_provider/kimi.py:89-91` + `src/kimi_cli/llm.py:144-145` —— 把当前 session id 作为 `prompt_cache_key` 传给 Kimi API，Kimi 自动做会话级 cache
- usage 结构里读 `prompt_tokens_details.cached_tokens`（新形状）和 `cached_tokens`（legacy），两个都要解析

**改动:**
- 新接口 `src/Providers/Capabilities/SupportsPromptCacheKey.php` —— 声明 `promptCacheKeyFragment(string $sessionId): array`
- `KimiProvider.php` 实现它 —— 返回 `['prompt_cache_key' => $sessionId]`
- `src/Providers/Features/PromptCacheKeyAdapter.php` —— 把 `$options['session_id']` 路由到实现了 `SupportsPromptCacheKey` 的 provider
- `src/Messages/Usage.php` —— 解析 `prompt_tokens_details.cached_tokens`，在 `cachedInputTokens` 字段上和 Anthropic 分支共用
- `FeatureDispatcher::registerDefaults()` —— 注册新 adapter
- `docs/FEATURES_MATRIX.md` —— 加 `prompt_cache_key` 列，标注 Kimi 原生、其他 provider N/A

**工作量:** 1 天
**风险:** 低 —— 新接口是纯加法
**交付物:** 0.9.2

---

### Phase 6 —— Agent spec as YAML + `extend:` 继承（0.9.3）

**目标:** Sub-agent 定义从 PHP 代码迁出到 YAML，用户不写 PHP 就能定制 team。对齐 Claude Code / Codex / kimi-cli 三家都已收敛到的模式。

**依据:**
- kimi-cli `src/kimi_cli/agentspec.py:111-160` + `.agents/default/coder.yaml` —— YAML 定义 `system_prompt_path` / `allowed_tools` / `exclude_tools` / `subagents`，支持 `extend:` 继承一个 base agent
- 用户可以在 `~/.superagent/agents/` / 项目 `.superagent/agents/` 放自定义 agent，和 skills 共用一套加载层级

**改动:**
- 新 `src/Agent/AgentSpec.php` value object
- 新 `src/Agent/AgentSpecLoader.php` —— 加载层级：bundled → `~/.superagent/agents/` → project `.superagent/agents/`；解析 `extend:` 继承链
- 新 `src/Agent/AgentRegistry.php` —— 按名查 agent
- **改造** `src/Tools/Builtin/AgentTool.php` —— 从 `AgentRegistry` 拿 sub-agent 定义（可选参数 `agent_type` 已经存在，把它变成 YAML registry 查找）
- 新 CLI `superagent agents list / show / install / path`（和 skills CLI 一套模板）
- 测试：`AgentSpecLoaderTest`、`ExtendInheritanceTest`、`AgentToolWithYamlSpecTest`

**工作量:** 1.5-2 天
**风险:** 中 —— `AgentTool` 正在开发中（0.8.9 刚改过 productivity instrumentation），要协调
**交付物:** 0.9.3

---

### Phase 7 —— MCP CLI 子命令组 + 远程 MCP server OAuth（0.9.4）

**目标:** `superagent mcp {add,remove,list,auth,reset-auth,test}` 子命令组，加 remote MCP server 的 OAuth 凭证 per-server 存储（对接 Linear / GitHub 官方 MCP server 等）。

**依据:**
- kimi-cli `src/kimi_cli/cli/mcp.py` —— 子命令组完整实现
- 我们 0.8.8 已经有 `src/MCP/McpOAuth.php` 的骨架，差的是 CLI 入口 + `~/.superagent/mcp-tokens/<host>.json` 落盘规范 + `list` 标 "需要 auth" 的 server
- 全局 `~/.superagent/mcp.json` 继续用 `mcpServers` 标准格式

**改动:**
- `src/CLI/Commands/McpCommand.php` —— 扩展现有结构成子命令组
- `src/MCP/McpOAuth.php` —— 完善 `FileTokenStorage` 等价类，按 server hostname 分文件存
- `src/MCP/MCPManager.php` —— `list()` 返回每个 server 是否需要 auth、是否已 auth、token 是否过期
- 测试：`McpCommandSubgroupTest`、`RemoteMcpOAuthTest`

**工作量:** 0.5-1 天
**风险:** 低 —— 大部分骨架已在
**交付物:** 0.9.4

---

### Phase 8（可选，长期）—— Wire Event Protocol v1 正式化

**目标:** 把 `src/Harness/*Event.php` 这 14 个事件类正式化成版本化 Wire 协议，作为 TUI / ACP IDE server / stream-json 三种前端的唯一边界。

**依据:**
- kimi-cli `src/kimi_cli/wire/types.py` —— Pydantic 定义，一份 spec 驱动 shell / ACP / stream-json 三个前端
- 我们已经非常接近这个形状，但：
  1. 没正式版本号（`wire_version: 1`）
  2. 没覆盖 ACP 目标
  3. 没 schema export（JSON Schema / OpenAPI）给 IDE 插件用

**改动:**
- 新 `src/Harness/Wire/WireEvent.php` 作为所有事件基类，加 `wireVersion()`、`toArray()`、`fromArray()` 契约
- 统一 14 个事件类 —— 全部继承 `WireEvent`
- 新 `docs/WIRE_PROTOCOL.md` —— 完整事件目录 + JSON 示例
- 导出 `resources/wire-schema.json` —— JSON Schema 版
- `--output json-stream` 模式把事件原样输出到 stdout

**工作量:** 多日（3-5 天）
**风险:** 中高 —— 会触及 14 个事件类的所有消费者
**交付物:** 1.0.0 的先决条件之一

---

## 依赖关系

```
Phase 1 (Kimi thinking 修正)              ← 独立，0.8.10
    ↓
Phase 2 (extra_body 透传)                 ← 依赖 Phase 1，0.8.11
    ↓
Phase 3 (活动 catalog)                    ← 独立，0.9.0（也可与 P1/P2 并行）
    ↓
Phase 4 (OAuth device flow)               ← 依赖 Phase 3 的凭证模型，0.9.1
    ↓
Phase 5 (prompt_cache_key)                ← 独立，0.9.2（可随时做）
    ↓
Phase 6 (YAML agent spec)                 ← 独立，0.9.3
    ↓
Phase 7 (MCP CLI 子命令)                  ← 独立，0.9.4
    ↓
Phase 8 (Wire Protocol)                   ← 长期，1.0 准备期
```

**可以并行的:** Phase 3 / 5 / 6 / 7 两两之间无耦合，只要 reviewer 带宽够就能并。

**不要并的:** Phase 1 / 2（extra_body 机制是 Phase 1 的重构版），Phase 3 / 4（OAuth 要凭证模型先到位）。

---

## 不做的事（非目标）

- **不反向实现 Kimi Swarm / Batch**：kimi-cli 自己都没有，证实 Moonshot 至今没公开 wire shape。我们 `KimiSwarmTool.php` / `KimiBatchTool.php` 继续保留并标 provisional，等 Moonshot 官方文档。
- **不抄 kimi-cli 的 UI 层**：他们用 `prompt-toolkit`，我们 PHP 用 Symfony Console，UI 技术栈差异太大，抄架构而非实现。
- **不做 Kosong-style 把 Providers 拆成独立 package**：目前 `src/Providers/` 耦合度可控，拆包的收益小于切分本身的成本。延后到 1.x 考虑。
- **不抄 `plugin.json` 的 `inject:` 配置注入机制**：设计有意思但用户面上太小众，现有 env 变量够用。

---

## 测试与验证策略

每个 Phase 必须附带：

1. **单元测试** —— mock HTTP，pin 住请求 body / response 解析的形状（和 0.8.8 的 Compat 套件同风格）
2. **如果涉及真实 endpoint，补一个 Integration test** —— 放 `tests/Integration/`，受 `SUPERAGENT_INTEGRATION=1` + 对应 `*_API_KEY` 双闸门保护，CI 默认不跑
3. **Compat 红线** —— 每个 Phase 不得破坏现有 `tests/Compat/` 套件
4. **文档同步** —— `README / INSTALL / ADVANCED_USAGE`（三语）+ 相关 `docs/FEATURES_MATRIX.md` / `NATIVE_PROVIDERS.md` 同 PR 更新

---

## 参考文件速查表

| 功能 | kimi-cli 参考 | 我们对应改动位置 |
|---|---|---|
| Thinking 请求形状 | `packages/kosong/src/kosong/chat_provider/kimi.py:187-204` | `src/Providers/KimiProvider.php:36-39` |
| extra_body 机制 | 同上，`kimi.py:160-170` | `src/Providers/ChatCompletionsProvider.php::buildRequestBody()` |
| 活动 catalog `/models` | `src/kimi_cli/auth/platforms.py:122-232` | `src/Providers/ModelCatalog.php`、新 `ModelCatalogRefresher.php` |
| OAuth device flow | `src/kimi_cli/auth/oauth.py:454-713` | 新 `src/Auth/OAuthDeviceFlow.php`、`CredentialStore.php` |
| Device headers | `src/kimi_cli/auth/oauth.py:250-261` | `KimiProvider::extraHeaders()`、新 `DeviceIdentity.php` |
| prompt_cache_key | `packages/kosong/src/kosong/chat_provider/kimi.py:89-91` | 新 `SupportsPromptCacheKey.php` 接口 |
| Agent YAML spec | `src/kimi_cli/agentspec.py:111-160` + `.agents/default/*.yaml` | 新 `src/Agent/AgentSpec*.php` |
| MCP CLI 子命令 | `src/kimi_cli/cli/mcp.py` | `src/CLI/Commands/McpCommand.php` 扩展 |
| Wire event protocol | `src/kimi_cli/wire/types.py` | `src/Harness/*Event.php` 全量整理 |

---

## Kimi 三个 endpoint 对照

| Endpoint | 用途 | 认证 | 目前我们支持? |
|---|---|---|---|
| `api.moonshot.ai/v1` | 国际 API key 用户 | `KIMI_API_KEY` | ✅ region `intl` |
| `api.moonshot.cn/v1` | 国内 API key 用户 | `KIMI_API_KEY` | ✅ region `cn` |
| `api.kimi.com/coding/v1` | **Kimi Code 订阅用户**（付费方案） | OAuth device flow（Phase 4 才支持） | ❌ 暂缺 |

Phase 4 完成后三个都齐。

---

## 交付节奏建议

按一天一 Phase 的节奏大概如此：

- Week 1: Phase 1 + Phase 2 + Phase 5（都是小改动，可以连着出 3 个补丁）
- Week 2: Phase 3（催化剂 —— 很多后续 Phase 受益于活动 catalog）
- Week 3: Phase 4（硬骨头，OAuth 要认真测）
- Week 4: Phase 6
- Week 5: Phase 7
- Phase 8 之后再说

如果只做一个也值得，优先选 **Phase 3（活动 catalog）** —— 它解决 catalog drift 这个长期隐患，而且同时改善所有 10 个 provider。
