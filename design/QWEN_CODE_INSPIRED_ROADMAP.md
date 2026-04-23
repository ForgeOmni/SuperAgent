# qwen-code 启发的分步开发路线图

> **日期:** 2026-04-22 · **基线版本:** (上个 roadmap 的 6 次 commit 已 push, 版本号未定) · **状态:** 待实施
>
> **参考源:** `/Users/xiyang/PhpstormProjects/qwen-code`（Alibaba 官方 Qwen 终端 CLI，Node 20+ / TypeScript / React Ink，npm workspaces monorepo。Gemini CLI 的 fork）
>
> **原则:** 和 `KIMI_CLI_INSPIRED_ROADMAP.md` 同款 —— 每个 Phase 独立可交付、独立可回滚、独立可测试。一个 Phase 一个版本号（维护者拍）。

---

## 背景

调研 Alibaba 的 qwen-code 仓库后发现：我们的 `QwenProvider` 打的是个 **Alibaba 自己 CLI 从来不用的 endpoint**，以及 `thinking_budget` 在 OpenAI-兼容端压根不存在。这是"本质是 bug、不是 feature"那一类问题，和 Kimi thinking 那次同构。

核心发现：

1. **Endpoint 错了。** qwen-code (`packages/core/src/core/openaiContentGenerator/constants.ts:5`) 全程走 `https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions`（OpenAI-兼容 shape）。我们 `QwenProvider.php:138` 打的是 native `/api/v1/services/aigc/text-generation/generation`。Alibaba 官方客户端不碰那个 endpoint。
2. **Thinking 参数错了。** OpenAI-兼容端的 wire shape：`extra_body: {enable_thinking: bool}`（`pipeline.ts:472-482`），**没有 `thinking_budget`**。我们 `QwenProvider.php:57-64` 发 `parameters.enable_thinking` + `parameters.thinking_budget`。
3. **Qwen Code 有 OAuth。** qwen-code `packages/core/src/qwen/qwenOAuth2.ts` 实现 RFC 8628 PKCE device flow，against `chat.qwen.ai`，`client_id=f0304373b74a44d2b584a3fb70ca9e56`。响应里带 `resource_url` **动态覆盖 DashScope base URL**（per-account）—— 这个是 kimi-cli 没有的技巧。
4. **DashScope 缓存** 走 `X-DashScope-CacheControl: enable` header + Anthropic 风格 `cache_control: {type: 'ephemeral'}` markers 固定在 system msg / 最后一个 tool / 最新 history msg 三个点。
5. **Streaming error-as-content**：DashScope TPM 限流时会把错误塞进 SSE `delta.content` with `finish_reason="error_finish"`。我们的 SSE parser 会静默返回截断内容。
6. **`SharedTokenManager` 跨进程 flock** 是 qwen-code 独有的正交工程工具 —— 我们所有 OAuth provider 都该用。
7. **`LoopDetectionService`** 在 agent loop 里防 pathological 重复（工具调用、内容、思考、文件读抖动、动作停滞）。**全 provider 受益**。
8. **Permissions 用 shell AST 而非 regex** —— 安全升级。
9. **Shadow-git checkpoint** —— 独立 bare repo 在 `~/.qwen/history/<project_hash>`，不碰用户 `.git`。
10. **qwen-code 没有 region 矩阵** —— 我们的 4-region 表比他们准，保留。
11. **qwen-code 没有 `qwen_long_file` 等价工具** —— 这是我们的差异化优势，保留。

---

## 分 Phase 路线图

### Phase 1 —— Qwen endpoint 修正 + thinking 正确化（版本 TBD，建议 patch 级）

**目标：** 把 `QwenProvider` 主路径切到 OpenAI-兼容端（`/compatible-mode/v1/chat/completions`）。`thinking_budget` 在兼容端 deprecate（加 warning，不失败）。保留 native endpoint 作 legacy opt-in。

**依据：**
- qwen-code `constants.ts:5` 的 `DEFAULT_DASHSCOPE_BASE_URL = 'https://dashscope.aliyuncs.com/compatible-mode/v1'`
- `pipeline.ts:472-482` 发 `extra_body: {enable_thinking: true}`，源注释明说 "qwen3 series — model-dependent; can be manually disabled via extra_body.enable_thinking"
- grep 全仓：`thinking_budget` 零命中

**改动：**
- **拆分** `src/Providers/QwenProvider.php`：
  - 主路径：新 `QwenChatCompletionsProvider extends ChatCompletionsProvider`
    - `defaultRegion() = 'intl'` / region → base URL 表（4 个保留）
    - `chatCompletionsPath()` 里用 `compatible-mode/v1/chat/completions`
    - `thinkingRequestFragment($budget)` 返 `['extra_body' => ['enable_thinking' => true]]`；budget 参数忽略 + `error_log` warning on `SUPERAGENT_DEBUG=1`
  - Legacy：重命名现 `QwenProvider` 为 `QwenNativeProvider`，标 `@deprecated`，保留。
  - `ProviderRegistry::$providers` 增加 `qwen-native` 作为 opt-in key；`qwen` 默认绑到 `QwenChatCompletionsProvider`
- `src/Providers/Features/ThinkingAdapter.php`：当 provider instanceof `SupportsThinking` + 返回的 fragment 里有 `extra_body.enable_thinking` 时走 Phase 2 的通用 extra_body 合并路径（我们已经有了）
- `resources/models.json` Qwen 块的 description 更新 "thinking via extra_body.enable_thinking; budget no longer honored"
- `docs/NATIVE_PROVIDERS.md`（三语）Qwen 章节的 thinking 行重写
- `docs/FEATURES_MATRIX.md` 不变（qwen 仍是 native thinking provider）

**兼容：**
- `thinking_budget` 被调用方传入时：吃掉但 env `SUPERAGENT_DEBUG=1` 时 warning
- Native endpoint 的用户 opt-in `'qwen-native'` 即可
- `resources/models.json` Qwen model id 不变

**工作量：** 1-2 天
**风险：** 中
- BC break：用户代码显式传 `thinking_budget` 的会失去预算控制（但 thinking 还正常工作）
- 拆分后 `QwenNativeProvider` 的现有测试要重新 namespace
- `ProviderRegistry` 注册点 + `CapabilityRouter` 里"family = qwen"的匹配要确认

**验证：**
- Mock HTTP 跑一条 thinking chat 请求，断言 body 里有 `extra_body.enable_thinking=true`、没有 `thinking_budget`、没有 `parameters.enable_thinking`
- 真实 `QWEN_API_KEY` 跑一次（integration test，env-gated），对比响应里有 `reasoning_content` 字段

**交付物：** 一个 patch 版本。

---

### Phase 2 —— DashScope cache-control adapter（版本 TBD，patch 级）

**目标：** 把 Qwen 的 prompt caching 接上。header + 块级 markers 双发。

**依据：**
- qwen-code `packages/core/src/core/openaiContentGenerator/provider/dashscope.ts:40-54`
- Toggle：`enableCacheControl: false` 关掉整套

**改动：**
- 新 `src/Providers/Features/DashScopeCacheControlAdapter.php` extends `FeatureAdapter`
  - `FEATURE_NAME = 'dashscope_cache_control'`（或复用 `context_cache` 的 feature 名，只对 Qwen 触发？—— 先用独立名字，避免跟 Anthropic 的 `context_cache` 混淆）
  - Request body 改写：
    - 注入 `X-DashScope-CacheControl: enable` header（通过 provider 的 `extraHeaders` 或 request interceptor）
    - 找 system msg 加 `cache_control: {type: 'ephemeral'}`
    - 最后一个 tool definition 加同样 marker
    - 最后一个 history msg（仅 streaming 时）加 marker
- `QwenChatCompletionsProvider`（Phase 1 产物）加入 `SupportsContextCaching`？—— 不太合适（接口语义是 Anthropic-shape 块级 markers）。或加一个新接口 `SupportsDashScopeCaching`
- 可选：把 `enableCacheControl` 放进 `$config['cache_control']` 开关，默认 true
- `docs/FEATURES_MATRIX.md` 加一行：`dashscope_cache_control | qwen | Silent skip (perf optimization) |`

**兼容：** 纯加法。

**工作量：** 半天
**风险：** 低
- Header 和 body 合并要考虑 existing `extra_body` 是否已被 caller 用掉 —— 合并语义走我们已有的 `mergeExtraBody`
- `ToolSecurityValidator` 不干涉 header，安全

**验证：** Mock HTTP，断言请求 header 里 `X-DashScope-CacheControl: enable`，body.messages[0] 有 `cache_control`，body.tools[最后] 有 `cache_control`

**交付物：** 一个 patch 版本。

---

### Phase 3 —— `SharedTokenManager` 跨进程锁（版本 TBD，通用改进）

**目标：** 所有 OAuth provider 的 token 刷新都走文件锁，避免并发会话赛跑写凭证。

**依据：**
- qwen-code `packages/core/src/qwen/sharedTokenManager.ts:1-200` —— `.lock` sidecar，stale detection（>30s pid 死了就抢），指数退避，in-memory cache 5s TTL

**改动：**
- `src/Auth/CredentialStore.php` 加 `withLock(string $provider, Closure $critical): mixed`：
  - `flock()` on `<baseDir>/<provider>.lock`（LOCK_EX | LOCK_NB，失败就 usleep 指数退避，最多 5s）
  - Stale 检测：锁文件 mtime > 30s 且 pid 进程不存在（`posix_kill($pid, 0)`）→ 抢夺
  - 写入 `<pid>:<hostname>:<unix_ts>` 到 `.lock`，释放时 `@unlink`
  - `$critical` 在锁内跑，返回值 bubble 上来
- `KimiCodeCredentials::refresh()` 包进 `withLock()`
- `ClaudeCodeCredentials::refresh()` 同上
- `CodexCredentials::refresh()` 同上
- `GeminiCliCredentials`：只读，无需加锁
- 新 `tests/Unit/Auth/CredentialStoreLockTest.php`：
  - Happy path
  - Stale lock stolen
  - Concurrent process simulation（fork 两个 closures，断言只有一个 "wins" 写）

**兼容：** 纯加法 —— 现有 callers 不走 `withLock` 一切不变。

**工作量：** 半天-1 天
**风险：** 中
- PHP 的 `flock()` 在 NFS 上不可靠（qwen-code 假设 POSIX）—— 给出 documentation warning
- Windows 的 `flock` 语义不一样但能用；低优先级平台

**验证：** 单元测试 + 真实多进程模拟（用 `pcntl_fork` 或两个 PHP CLI）

**交付物：** 一个 patch 版本。

**依赖：** 无，但 Phase 4 强依赖这个（OAuth refresh 要锁）。

---

### Phase 4 —— Qwen OAuth device flow + `resource_url`（版本 TBD，feat 级）

**目标：** `superagent auth login qwen-code` / `logout qwen-code` CLI。动态 base URL from OAuth `resource_url`。

**依据：**
- qwen-code `packages/core/src/qwen/qwenOAuth2.ts` —— endpoint `https://chat.qwen.ai/api/v1/oauth2/device/code`，`client_id=f0304373b74a44d2b584a3fb70ca9e56`，scope `openid profile email model.completion`，PKCE S256
- Token 存 `~/.qwen/oauth_creds.json`
- 响应里的 `resource_url` 是 per-account 的 base URL（例如 `dashscope-coding.aliyuncs.com/v1`）
- `qwenContentGenerator.ts:59-71` 启动时读 `resource_url`，不存在则用默认

**改动：**
- 新 `src/Auth/QwenCodeCredentials.php`（镜像 `KimiCodeCredentials.php`，~200 行）：
  - `CLIENT_ID = 'f0304373b74a44d2b584a3fb70ca9e56'`
  - `DEFAULT_OAUTH_HOST = 'https://chat.qwen.ai'`
  - `DEVICE_AUTH_PATH = '/api/v1/oauth2/device/code'`
  - `TOKEN_PATH = '/api/v1/oauth2/token'`
  - `CREDENTIAL_NAME = 'qwen-code'`
  - PKCE S256 支持（`DeviceCodeFlow` 目前没 PKCE —— 可能要扩）
  - 多存一个字段：`resource_url`
  - `refresh()` 走 Phase 3 的 `withLock()`
- `DeviceCodeFlow.php` 如果不支持 PKCE，加 `?string $codeVerifier` + `?string $codeChallenge` 两个可选参数
- `QwenChatCompletionsProvider`（Phase 1 产物）：
  - 新 region `code` → OAuth-only
  - `resolveBearer()` region=code 时：先 `QwenCodeCredentials::currentAccessToken()` → 失败 fallback `$config['api_key']`
  - **动态 base URL**：region=code 时从 `QwenCodeCredentials::load()['resource_url']` 读，为空则用 `https://dashscope.aliyuncs.com/compatible-mode/v1`
- `src/CLI/Commands/AuthCommand.php`：加 `login qwen-code` / `login qwen` alias + `logout qwen-code` / `logout qwen` alias
- `docs/ADVANCED_USAGE.md` §37（OAuth device flow）加 Qwen Code 小节（三语）

**兼容：** 纯加法。`QwenProvider` 原 API key 模式不变。

**工作量：** 1-2 天
**风险：** 中
- Alibaba 可能轮换 public client_id（和 Moonshot 同风险）—— 代码里注释来源 + 添加环境变量 `QWEN_CODE_CLIENT_ID` override
- `resource_url` 的失败 fallback 要明确

**验证：**
- `QwenCodeCredentialsTest.php` 走 Mock HTTP（和 `KimiCodeCredentialsTest` 同结构）
- `AuthCommandQwenCodeTest.php` 走 stub `DeviceCodeFlow`（和 `AuthCommandKimiCodeTest` 同结构）
- PKCE code_verifier / code_challenge 单元测试

**交付物：** 一个 feat 版本。

**依赖：** Phase 3（跨进程锁）先行。

---

### Phase 5 —— `LoopDetectionService`（版本 TBD，通用改进）

**目标：** 防 pathological 循环 —— 工具调用 / 内容重复 / 思考重复 / 文件读抖动 / 动作停滞。**全 provider 受益**。

**依据：**
- qwen-code `packages/core/src/services/loopDetectionService.ts:22-46`：
  - Tool call loop threshold: 5 次同 tool+同 input
  - Content repetition: 10×50 字符窗口
  - Thought repetition: 3 次同 "thought" 文本
  - File read thrashing: 8/15 读同文件
  - Action stagnation: 8 次 "无进展" 动作
- Cold-start exemption: 第一个非只读工具触发前不激活（防早期探索被误杀）
- 每个 stream chunk 都 tick

**改动：**
- 新 `src/Guardrails/LoopDetector.php`
  - 状态：滑动窗口 per 类别 + cold-start flag
  - `observe(StreamEvent $event): ?LoopViolation`（某类别超阈值时返）
  - 配置可调：`new LoopDetector(config: [...thresholds...])`
- `src/Harness/HarnessLoop.php`（或等价调度器）每个 event 喂一次，violation 时 emit `LoopDetectedEvent`（新 WireEvent 类，complies with Phase 8 wire protocol）+ 抛回 orchestrator
- `src/Guardrails/LoopDetectionEvent.php`（新 StreamEvent，自动 WireEvent-compliant via Phase 8b）
- `docs/ADVANCED_USAGE.md` 新 §40 "Loop detection"，三语
- 测试：`LoopDetectorTest.php` —— 每类别独立 + cold-start + 超阈值触发 + 配置覆盖

**兼容：** 新类，默认**不启用**。通过 `Guardrails` 配置或 `$options['loop_detection'] = true` 打开。

**工作量：** 1-2 天
**风险：** 中
- 假阳性：合法长任务可能撞阈值。Cold-start exemption 是关键；所有阈值要 config-overridable
- 冷启动期内应由 caller 决定（不同 agent pattern 不同）
- 需要和我们已有的 Guardrails 生态（e.g. `src/Guardrails/`）协作而非冲突

**验证：** 每类别触发场景 + cold-start 不触发 + 配置覆盖

**交付物：** 一个 feat 版本。

---

### Phase 6 —— OpenAI-兼容 SSE parser 两项加固（版本 TBD，bug 级）

**目标：** 修 `ChatCompletionsProvider` 的 SSE parser 两个隐患：
1. `finish_reason === "error_finish"` + error 在 `delta.content` —— 目前会返截断 content
2. 流式 tool-call assembly 对空字符串 chunk / partial JSON / index 冲突鲁棒性不够

**依据：**
- qwen-code `pipeline.ts:170-175` 处理 `error_finish`
- `streamingToolCallParser.ts` 处理 index reuse、空 string chunk、JSON repair

**改动：**
- `src/Providers/ChatCompletionsProvider.php::parseSSEStream()`：
  - 新增 branch：`finish_reason === 'error_finish'` → 抛 `StreamContentError`（新异常类，extends `ProviderException`），retry loop 上游能识别
  - Tool-call assembly 改造：
    - 空 `arguments` string chunk 直接跳过（不追加、不破坏累积）
    - Index 冲突：维护 `Map<index, ToolCallAccumulator>`，每个 accumulator 有独立状态机
    - JSON 修复：累积的 arguments 字符串在完成前尝试 `json_decode`，失败用简易修复（补 `}`、处理未闭合 string）后重试一次
- 测试：`ChatCompletionsSseParserTest.php` 增加场景
  - `error_finish` 抛 `StreamContentError` + retry 激活
  - Tool call 空 string chunk 不损坏累积
  - Index reuse（罕见但可能）各自独立
  - Unclosed JSON 自动修复

**兼容：** `ChatCompletionsProvider` 公开 API 不变。内部 parseSSEStream 行为对合法流**byte-exact**不变。
- 新异常类 `StreamContentError extends ProviderException` —— 现有 `catch (ProviderException)` 继续捕获

**工作量：** 1 天
**风险：** 中
- Tool call assembly 改动容易引入 bug；要跑每个 provider 的 integration smoke
- JSON 修复的"简易"实现要保守（不能把合法不完整 JSON 错修）

**验证：** 现有 provider 测试全绿 + 新场景覆盖

**交付物：** 一个 patch 版本。

---

### Phase 7 —— DashScope metadata + vision flag 小包（版本 TBD，patch）

**目标：** 两个 10-30 分钟的小改动，一次性发。

**依据：**
- qwen-code `dashscope.ts:40-54` —— header `X-DashScope-UserAgent: qwen-code/<version>`
- qwen-code `dashscope.ts:116-128` —— 检测 `qwen-vl*` / `qwen3-vl-plus` / `qwen3.5-plus` 注入 `vl_high_resolution_images: true`
- qwen-code request body metadata: `metadata: {sessionId, promptId, channel}`

**改动：**
- `QwenChatCompletionsProvider::extraHeaders()`：加 `X-DashScope-UserAgent: SuperAgent/<composer.json version>`
- `QwenChatCompletionsProvider::customizeRequestBody()`：检测 vision model → `$body['vl_high_resolution_images'] = true`
- `QwenChatCompletionsProvider::customizeRequestBody()`：`$body['metadata'] = ['sessionId' => $options['session_id'] ?? null, 'promptId' => $options['prompt_id'] ?? null, 'channel' => 'superagent']`（过滤 null）

**兼容：** 纯加法。

**工作量：** 1 小时
**风险：** 极低 —— Alibaba 侧忽略未知 header 和 metadata 字段

**验证：** Mock HTTP 断言 header + body shape

**交付物：** 一个 patch 版本。

---

### Phase 8 —— Shadow-git checkpoint（版本 TBD，长期）

**目标：** 重构 `src/CheckPoint/`：用一个独立的 bare git repo 在 `~/.superagent/history/<project_hash>` 做快照，不碰用户的 `.git`。工具调用前自动 commit，`/restore` 三联还原（git + 对话 state + pending tool call）。

**依据：**
- qwen-code `docs/users/features/checkpointing.md` + `packages/core/src/services/gitService.ts`
- Shadow repo hash 用 project 绝对路径的 sha256 前 16 位
- 每个 checkpoint tag name: `<timestamp>_<tool_name>`

**改动：**
- `src/CheckPoint/GitShadowStore.php`：
  - `init()` 创建 bare repo
  - `snapshot(array $conversation, ?array $pendingTool): string` → commit hash
  - `restore(string $commit): {conversation, pendingTool}`
  - `list(): CheckpointEntry[]`
- `src/CheckPoint/CheckpointService.php`：原 snapshot-dir 实现保留，增加 `backend: 'shadow-git'|'snapshot-dir'` 配置开关
- `src/CLI/Commands/SlashCommands/RestoreCommand.php`（如果不存在）
- `docs/ADVANCED_USAGE.md` §41 重写 checkpoint 一章，三语

**兼容：** 配置驱动 —— 默认保留 `snapshot-dir` 行为；opt-in `shadow-git`。

**工作量：** 多日（3-5 天）
**风险：** 高
- git 交互复杂（binary files、symlinks、large files、submodules）
- 需要 `libgit2`/`pecl git` OR shell-out to `git` CLI（shell-out 更简单但跨平台头疼）
- 用户可能已经有一个名字撞车的 shadow repo

**验证：** 大量集成测试 —— checkpoint → 修改文件 → restore → 文件状态对齐

**交付物：** 一个 minor 版本。

**依赖：** 无，但和 Phase 5 (loop detection) 互补 —— checkpoint 让用户能从 loop detection 误伤中恢复。

---

### Phase 9 —— 可选长期：Memory 系统扩展（多日，不急）

**目标：** `QWEN.md` 风格的三层 memory + `@file` inclusion + 周期性 consolidation。

**依据：**
- qwen-code `packages/core/src/memory/` 17 个文件 —— `extract`, `dream` (周期固化), `forget`, `recall`, `relevanceSelector`
- 三层：团队 `QWEN.md` in repo / 个人 `~/.qwen/QWEN.md` / auto-memory agent 写入 `~/.qwen/projects/<hash>/memory/` 周期 consolidate

**改动略** —— 这是个大坑，单独立项。

**工作量：** 多日
**风险：** 高 —— 用户心智模型不统一
**交付物：** 一个 minor 版本，单独立项。

---

## 依赖关系

```
Phase 1 (endpoint + thinking 修)             ← 独立，可先做
Phase 2 (DashScope cache-control)            ← 依赖 Phase 1 的 ChatCompletions 路径
Phase 3 (SharedTokenManager flock)           ← 独立，应先做（Phase 4 依赖）
    ↓
Phase 4 (Qwen OAuth + resource_url)          ← 依赖 Phase 1 (新 ChatCompletions QwenProvider) + Phase 3 (flock)
Phase 5 (LoopDetectionService)               ← 独立
Phase 6 (SSE parser 加固)                    ← 独立，但动静大，建议晚做（触及所有 OpenAI-兼容 provider）
Phase 7 (metadata + vision flag 小包)         ← 依赖 Phase 1
Phase 8 (Shadow-git checkpoint)              ← 独立，长期
Phase 9 (Memory 扩展)                        ← 独立，超长期
```

**可以并行的：** Phase 1 / 3 / 5 / 6 两两无耦合。
**不能并的：** Phase 2/4/7 都依赖 Phase 1；Phase 4 额外依赖 Phase 3。

---

## 不做的事（非目标）

- **不迁移到 qwen-code 的 TS/Node 架构** —— 我们是 PHP，生态不同。抄架构抄接口抄流程。
- **不抛弃 `QwenLongFileTool`** —— 这是我们的差异化优势。qwen-code 没有等价物。
- **不去掉 4-region 矩阵** —— 我们的 intl/us/cn/hk 表比 qwen-code 更准；保留。
- **不抄 React-Ink UI** —— TUI 技术栈差异太大；抄 slash-command 设计可以，UI 实现自己写。
- **不做 speculative followup + overlay FS** —— qwen-code 从 gemini-cli 继承的那个 COW overlay 预判执行，PHP 生态没等价物，ROI 低。
- **不抄 `GeminiChat` 类名遗留** —— qwen-code 从 gemini-cli fork 后没清理，我们没这个历史债。

---

## 测试与验证策略

每个 Phase 必须附带：

1. **单元测试** —— mock HTTP，pin 住请求 body / response 解析形状
2. **如果涉及真实 endpoint，补 integration test** —— 放 `tests/Integration/`，受 `SUPERAGENT_INTEGRATION=1` + `QWEN_API_KEY` 双闸门保护
3. **Compat 红线** —— 每个 Phase 不得破坏现有 `tests/Compat/` 套件
4. **文档同步** —— 每个 Phase 改动涉及 `docs/FEATURES_MATRIX.md` / `NATIVE_PROVIDERS.md` / `ADVANCED_USAGE.md` 三语同步
5. **CHANGELOG `[Unreleased]` 更新** —— 每个 Phase 加条目

---

## 参考文件速查表

| 功能 | qwen-code 参考 | 我们对应改动位置 |
|---|---|---|
| OpenAI-兼容端点常量 | `packages/core/src/core/openaiContentGenerator/constants.ts:5` | `src/Providers/QwenProvider.php`（拆分为 `QwenChatCompletionsProvider` + `QwenNativeProvider`） |
| Thinking 请求形状 | `pipeline.ts:472-482` | `QwenChatCompletionsProvider::thinkingRequestFragment()` |
| DashScope cache-control | `provider/dashscope.ts:40-54` | 新 `src/Providers/Features/DashScopeCacheControlAdapter.php` |
| OAuth device flow | `packages/core/src/qwen/qwenOAuth2.ts` | 新 `src/Auth/QwenCodeCredentials.php`（镜像 `KimiCodeCredentials.php`） |
| Resource URL 动态 base URL | `qwenContentGenerator.ts:59-71` | `QwenChatCompletionsProvider::resolveBearer()` + dynamic base URL |
| SharedTokenManager flock | `packages/core/src/qwen/sharedTokenManager.ts:1-200` | `src/Auth/CredentialStore.php` 加 `withLock()` |
| PKCE code_verifier / challenge | `qwenOAuth2.ts` | `src/Auth/DeviceCodeFlow.php` 加 PKCE 可选参数 |
| LoopDetectionService | `services/loopDetectionService.ts:22-46` | 新 `src/Guardrails/LoopDetector.php` |
| Streaming error_finish | `pipeline.ts:170-175` | `src/Providers/ChatCompletionsProvider.php::parseSSEStream()` |
| StreamingToolCallParser | `streamingToolCallParser.ts` | 同上 SSE parser |
| Shell AST permissions | `permission-manager.ts:37-42` + `shell-semantics.ts` | `src/Permissions/` 升级（未在本 roadmap 具体 Phase，备忘） |
| Shadow-git checkpoint | `services/gitService.ts` + `docs/.../checkpointing.md` | `src/CheckPoint/GitShadowStore.php`（Phase 8） |
| ChatRecord JSONL + UUID tree | `services/chatRecordingService.ts:40-60` | `src/Session/` 升级（未在具体 Phase，备忘） |
| Vision flag `vl_high_resolution_images` | `dashscope.ts:116-128` | `QwenChatCompletionsProvider::customizeRequestBody()` |
| `X-DashScope-UserAgent` header | `dashscope.ts:40-54` | `QwenChatCompletionsProvider::extraHeaders()` |
| Memory 三层 + /dream | `packages/core/src/memory/` | 单独立项 Phase 9 |

---

## Qwen 三个 endpoint 的新认知

（更正之前 roadmap 里的 region 陈述）

| Endpoint | 用途 | 认证 | 目前我们支持? |
|---|---|---|---|
| `dashscope.aliyuncs.com/compatible-mode/v1` | **全球默认**（Alibaba 自家 CLI 只打这个） | `Bearer DASHSCOPE_API_KEY` | ❌ Phase 1 加 |
| `dashscope-intl.aliyuncs.com/compatible-mode/v1` | 国际版 | 同上 | ❌ Phase 1 加 |
| `dashscope-us.aliyuncs.com` / `-hk.aliyuncs.com` | US / HK region | 同上 | ❌ Phase 1 加 |
| `coding.dashscope.aliyuncs.com/v1` | Qwen Coding Plan | OAuth 或 resource_url 动态 | ❌ Phase 4 加 |
| `dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation` | **native endpoint**（我们现在用的，Alibaba 自家不用） | `Bearer` | ✅ `QwenNativeProvider`（Phase 1 保留） |

Phase 1-4 完成后四个访问模式都齐。

---

## 交付节奏建议

按一天一 Phase 的节奏：

- Week 1: Phase 1（Qwen endpoint + thinking 修，1-2 d）+ Phase 7（metadata + vision flag 小包，1 h）
- Week 2: Phase 3（SharedTokenManager flock，半天-1 d）+ Phase 2（DashScope cache-control，半天）
- Week 3: Phase 4（Qwen OAuth + resource_url，1-2 d）
- Week 4: Phase 5（LoopDetectionService，1-2 d）
- Week 5: Phase 6（SSE parser 加固，1 d，要细心）
- Week 6+: Phase 8（Shadow-git checkpoint，多日）
- 不定期: Phase 9（Memory 扩展）

**最小增量交付**：Phase 1 + 7 一起发，修最大的 bug。之后其他按优先级并行。

如果只做一个，选 **Phase 1** —— 它是 bug 修复，不是 feature；当前 `QwenProvider` 在生产里调用应该一直失败或响应异常。
