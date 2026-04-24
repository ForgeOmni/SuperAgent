# SuperAgent

[![PHP 版本](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel 版本](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![许可证](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![版本](https://img.shields.io/badge/version-0.9.2-purple)](https://github.com/forgeomni/superagent)

> **🌍 语言**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)
> **📖 文档**: [安装](INSTALL_CN.md) · [Installation EN](INSTALL.md) · [Installation FR](INSTALL_FR.md) · [高级用法](docs/ADVANCED_USAGE_CN.md) · [API 文档](docs/)

PHP 的 AI agent SDK —— 在进程内跑完整的 agentic loop（LLM 轮次 → 工具调用 → 工具结果 → 下一轮），内置 12 个 provider、实时流式、多 agent 编排、以及机器可读的 wire 协议。既可以作为独立 CLI 使用，也可以作为 Laravel 依赖。

```bash
superagent "修复 src/Auth/ 里的登录 bug"
```

```php
$agent = new SuperAgent\Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);

$result = $agent->run('用一段话总结 docs/ADVANCED_USAGE.md');
echo $result->text();
```

---

## 目录

- [快速开始](#快速开始)
- [Provider 与认证](#provider-与认证)
- [OpenAI Responses API](#openai-responses-api)
- [Agent 循环](#agent-循环)
- [工具与多 Agent](#工具与多-agent)
- [Agent 定义](#agent-定义yaml--markdown)
- [Skills](#skills)
- [MCP 集成](#mcp-集成)
- [Wire Protocol](#wire-protocol)
- [重试、错误与可观测性](#重试错误与可观测性)
- [Guardrails 与 Checkpoint](#guardrails-与-checkpoint)
- [独立 CLI](#独立-cli)
- [Laravel 集成](#laravel-集成)
- [配置参考](#配置参考)

每个特性小节末尾会标注起始版本。完整发布日志见 [CHANGELOG.md](CHANGELOG.md)。

---

## 快速开始

安装：

```bash
# 作为独立 CLI：
composer global require forgeomni/superagent

# 或作为 Laravel 依赖：
composer require forgeomni/superagent
```

完整矩阵（系统要求、认证配置、IDE 桥接、CI 集成）见 [INSTALL_CN.md](INSTALL_CN.md)。

最小 agent 运行：

```php
$agent = new SuperAgent\Agent(['provider' => 'anthropic']);
$result = $agent->run('今天几号？');
echo $result->text();
```

带工具的最小 agent 运行：

```php
$agent = (new SuperAgent\Agent(['provider' => 'openai']))
    ->loadTools(['read', 'write', 'bash']);

$result = $agent->run('检查 composer.json，告诉我这个项目目标 PHP 版本');
echo $result->text();
```

CLI 单次调用：

```bash
export ANTHROPIC_API_KEY=sk-...
superagent "检查 composer.json，告诉我这个项目目标 PHP 版本"
```

---

## Provider 与认证

12 个注册表驱动的 provider，每个都支持 region 感知的 base URL 和多种认证方式。全部实现同一个 `LLMProvider` 契约，所以换 provider 只需改一行。

| 注册表 key | Provider | 说明 |
|---|---|---|
| `anthropic` | Anthropic | API key 或已存的 Claude Code OAuth |
| `openai` | OpenAI Chat Completions (`/v1/chat/completions`) | API key、`OPENAI_ORGANIZATION` / `OPENAI_PROJECT` |
| `openai-responses` | OpenAI Responses API (`/v1/responses`) | [下方专门小节](#openai-responses-api) |
| `openrouter` | OpenRouter | API key |
| `gemini` | Google Gemini | API key |
| `kimi` | Moonshot Kimi | API key；region `intl` / `cn` / `code`（OAuth）|
| `qwen` | 阿里 Qwen（OpenAI 兼容，默认）| API key；region `intl` / `us` / `cn` / `hk` / `code`（OAuth + PKCE）|
| `qwen-native` | 阿里 Qwen（DashScope 原生 body）| 保留给依赖 `parameters.thinking_budget` 的调用方 |
| `glm` | BigModel GLM | API key；region `intl` / `cn` |
| `minimax` | MiniMax | API key；region `intl` / `cn` |
| `bedrock` | AWS Bedrock | AWS SigV4 |
| `ollama` | 本地 Ollama daemon | 无需 auth — 默认 localhost:11434 |
| `lmstudio` | 本地 LM Studio server | 占位 auth — 默认 localhost:1234 *（v0.9.1 起）* |

认证方式，按优先级：

1. **环境变量 API key** —— `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `KIMI_API_KEY` / `QWEN_API_KEY` / `GLM_API_KEY` / `MINIMAX_API_KEY` / `OPENROUTER_API_KEY` / `GEMINI_API_KEY`。
2. **已存储的 OAuth 凭据** 位于 `~/.superagent/credentials/<name>.json`。设备码流程 —— 运行 `superagent auth login <name>`：
   - `claude-code` —— 复用现有的 Claude Code 登录
   - `codex` —— 复用 Codex CLI 登录
   - `gemini` —— 复用 Gemini CLI 登录
   - `kimi-code` —— 对 `auth.kimi.com` 的 RFC 8628 设备流程 *（v0.9.0 起）*
   - `qwen-code` —— 设备流程 + PKCE S256 + per-account `resource_url` *（v0.9.0 起）*
3. **显式配置** —— agent options 里的 `api_key` / `access_token` / `account_id`。

OAuth 刷新跨进程串行化，经由 `CredentialStore::withLock()` —— 并行 queue worker 共享一个凭据文件时不会互相覆盖刷新 *（v0.9.0 起）*。

### 声明式 header

```php
new Agent([
    'provider'         => 'openai',
    'env_http_headers' => [
        'OpenAI-Project'      => 'OPENAI_PROJECT',      // 仅当 env 设置且非空时发送
        'OpenAI-Organization' => 'OPENAI_ORGANIZATION',
    ],
    'http_headers' => [
        'x-app' => 'my-host-app',                       // 静态 header
    ],
]);
```

*v0.9.1 起*

### 模型 catalog

每个 provider 内置 model id + 定价元数据，在 `resources/models.json`。随时可以刷新到 vendor 的实时 `/models` endpoint：

```bash
superagent models refresh              # 所有配置了 env creds 的 provider
superagent models refresh openai       # 单个 provider
superagent models list                 # 显示合并后的 catalog
superagent models status               # catalog 来源 + age
```

*v0.9.0 起*

---

## OpenAI Responses API

专门的 provider：`provider: 'openai-responses'`。打 `/v1/responses`，完整支持 OpenAI 现代 API shape。

**相比 `openai` 的优势：**

| 特性 | Responses | Chat Completions |
|---|---|---|
| `previous_response_id` 接续 | ✅ —— 服务端持有状态，新轮次跳过重发上下文 | ❌ —— 每轮都得重发 `messages[]` |
| `reasoning.effort`（`minimal / low / medium / high / xhigh`）| ✅ 原生 | ❌ o 系列要靠 model id 技巧 |
| `reasoning.summary` | ✅ 原生 | ❌ |
| `prompt_cache_key`（服务端 cache 绑定）| ✅ 原生 | ❌ |
| `text.verbosity`（`low / medium / high`）| ✅ 原生 | ❌ |
| `service_tier`（`priority / default / flex / scale`）| ✅ 原生 | ❌ |
| 分类错误 | ✅ 通过 `response.failed` 事件 code | 从 HTTP body 字符串匹配 |

```php
$agent = new Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);

$result = $agent->run('分析这个代码库并提出重构建议', [
    'reasoning'        => ['effort' => 'high', 'summary' => 'auto'],
    'verbosity'        => 'low',
    'prompt_cache_key' => 'session:42',
    'service_tier'     => 'priority',
    'store'            => true,           // 下一轮想用 previous_response_id 必须设 true
]);

// 不重发历史继续对话：
$provider = $agent->getProvider();
$nextAgent = new Agent([
    'provider' => 'openai-responses',
    'options'  => ['previous_response_id' => $provider->lastResponseId()],
]);
$nextResult = $nextAgent->run('现在对 auth 层再深入一层');
```

### ChatGPT 订阅路由

传 `access_token`（或设 `auth_mode: 'oauth'`）会自动路由到 `chatgpt.com/backend-api/codex` —— 让 Plus / Pro / Business 订阅者按订阅额度计费，而不是在 `api.openai.com` 被拒绝。

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $token,
    'account_id'   => $accountId,   // 添加 chatgpt-account-id header
]);
```

### Azure OpenAI

6 个 base URL 标记会自动把 provider 切到 Azure 模式。`api-version` query 会被加上（默认 `2025-04-01-preview`，可覆盖），`api-key` header 会与 `Authorization` 并行发送。

```php
new Agent([
    'provider'          => 'openai-responses',
    'base_url'          => 'https://my-resource.openai.azure.com/openai/deployments/gpt-5',
    'api_key'           => $azureKey,
    'azure_api_version' => '2024-12-01-preview',   // 可选覆盖
]);
```

### Trace-context 透传

把 W3C `traceparent` 注入 `client_metadata`，让 OpenAI 端日志可以和你的分布式 trace 对齐：

```php
$tc = SuperAgent\Support\TraceContext::fresh();              // 新建一个
// 或：SuperAgent\Support\TraceContext::parse($headerValue); // 从入站 HTTP header 解析

$agent->run($prompt, ['trace_context' => $tc]);
// 或：$agent->run($prompt, ['traceparent' => '00-0af7-...', 'tracestate' => 'v=1']);
```

*v0.9.1 起*

---

## Agent 循环

`Agent::run($prompt, $options)` 跑完整的轮次循环直到模型不再发 `tool_use` 块。每轮的成本、usage、消息都流进 `AgentResult`。

```php
$result = $agent->run('...', [
    'model'             => 'claude-sonnet-4-5-20250929',  // 单次调用覆盖
    'max_tokens'        => 8192,
    'temperature'       => 0.3,
    'response_format'   => ['type' => 'json_schema', 'json_schema' => [...]],
    'idempotency_key'   => 'job-42:turn-7',               // v0.9.1 起
    'system_prompt'     => '你是一个精确的分析师。',
]);

echo $result->text();
$result->turns();          // 轮次计数
$result->totalUsage();     // Usage{inputTokens, outputTokens, cache*}
$result->totalCostUsd;     // float，跨所有轮次
$result->idempotencyKey;   // 用于 usage-log 去重的透传 key（v0.9.1 起）
```

### 预算 + 轮次上限

```php
$agent = (new Agent(['provider' => 'openai']))
    ->withMaxTurns(50)
    ->withMaxBudget(5.00);            // USD —— 硬上限；循环中超出会终止
```

### 流式输出

```php
foreach ($agent->stream('...') as $assistantMessage) {
    echo $assistantMessage->text();
}
```

机器可读的事件流（给 IDE / CI 消费者用的 JSON / NDJSON）见 [Wire Protocol](#wire-protocol) 小节。

### Auto-mode（任务检测）

```php
new Agent([
    'provider'  => 'anthropic',
    'auto_mode' => true,               // 委派给 TaskAnalyzer 挑 model + 工具
]);
```

### 幂等性

```php
$result = $agent->run($prompt, ['idempotency_key' => $queueJobId . ':' . $turnNumber]);
// $result->idempotencyKey 截断到 80 字符；surface 在 AgentResult 上
// 写 ai_usage_logs 的 host 可以用它去重。
```

*v0.9.1 起*

---

## 工具与多 Agent

工具是 `SuperAgent\Tools\Tool` 的子类。内置工具 —— read / write / edit / bash / glob / grep / search / fetch —— 默认自动加载。自定义工具通过 `$agent->registerTool(new MyTool())` 注册。

```php
$agent = (new Agent(['provider' => 'anthropic']))
    ->loadTools(['read', 'write', 'bash'])
    ->registerTool(new MyDomainTool());

$result = $agent->run('按 ./plan.md 的重构计划执行');
```

### 多 agent 编排（`AgentTool`）

在一条 assistant 消息里发多个 `agent` tool_use 块，子 agent 会并行派发：

```php
$agent->registerTool(new AgentTool());

$result = $agent->run(<<<PROMPT
并行做这三项调研：
1. 读 CHANGELOG.md，总结最近三个 release
2. 读 composer.json，列出所有 runtime 依赖
3. 在 src/ 里 grep TODO 注释
然后把三份报告汇总。
PROMPT);
```

每个子 agent 跑在自己的 PHP 进程里（通过 `ProcessBackend`）；一个子的阻塞 I/O 不会阻塞兄弟。`proc_open` 被禁用时回退到 fiber。

#### 产出证据

每个 `AgentTool` 结果都带子 agent 实际做了什么的硬证据 —— 而不只是 `success: true`：

```php
[
    'status'              => 'completed',          // 或 'completed_empty' / 'async_launched'
    'filesWritten'        => ['/abs/path/a.md'],   // 去重的绝对路径
    'toolCallsByName'     => ['Read' => 3, 'Write' => 1],
    'totalToolUseCount'   => 4,                    // 观测到的，而不是自报的 turn 数
    'productivityWarning' => null,                 // 或 advisory 字符串（CJK 本地化 —— v0.9.1 起）
    'outputWarnings'      => [],                   // v0.9.1 起 —— 文件系统审计结果
]
```

`completed_empty` —— 观察到零次工具调用。重新派发或换更强的模型。
`completed` + 非空 `productivityWarning` —— 子 agent 调用了工具但没写文件（咨询类子任务经常这样；看文本是否已经有答案）。

*产出埋点 v0.8.9 起。CJK 本地化 + 文件系统审计 v0.9.1 起。*

#### 输出目录审计 + guard 注入

传 `output_subdir` 同时开启 (a) CJK 感知的 guard block 注入到子 agent prompt，和 (b) 退出后的文件系统扫描：

```php
$agent->run('...', [
    'output_subdir' => '/abs/path/to/reports/analyst-1',
]);
// 审计捕捉：
//   - 非白名单扩展名（默认 .md / .csv / .png）
//   - consolidator 保留文件名（summary.md / 摘要.md / mindmap.md / ...）
//   - 同级角色子目录（ceo / cfo / cto / marketing / ... 或 kebab-case 角色 slug）
// 通过 AgentOutputAuditor 构造函数可配置。永远不改磁盘。
```

*v0.9.1 起*

### Provider 原生工具

任何主脑都能把下面这些当普通工具调用 —— 不用切 provider。

**Moonshot 服务端托管 builtin**（服务端执行，结果内联在 assistant 回复里）：

| 工具 | 属性 | 起始 |
|---|---|---|
| `KimiMoonshotWebSearchTool`（`$web_search`）| network | v0.9.0 |
| `KimiMoonshotWebFetchTool`（`$web_fetch`）| network | v0.9.1 |
| `KimiMoonshotCodeInterpreterTool`（`$code_interpreter`）| network, cost, sensitive | v0.9.1 |

**其他 provider 原生工具族：**
- Kimi —— `KimiFileExtractTool` / `KimiBatchTool` / `KimiSwarmTool` / `KimiMediaUploadTool`
- Qwen —— `QwenLongFileTool` + `dashscope_cache_control` feature
- GLM —— `glm_web_search` / `glm_web_reader` / `glm_ocr` / `glm_asr`
- MiniMax —— `minimax_tts` / `minimax_music` / `minimax_video` / `minimax_image`

---

## Agent 定义（YAML / Markdown）

从 `~/.superagent/agents/`（用户级）和 `<project>/.superagent/agents/`（项目级）自动加载。三种格式：`.yaml` / `.yml` / `.md`。跨格式 `extend:` 继承。

```yaml
# ~/.superagent/agents/reviewer.yaml
name: reviewer
description: 严格代码审查
extend: base-coder              # 可以是 .yaml / .yml / .md
system_prompt: |
  你审查 PR，关注正确性和隐藏状态。
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
你的任务是浮现架构风险。发现用 Markdown 格式输出。
```

工具列表字段（`allowed_tools` / `disallowed_tools` / `exclude_tools`）在 `extend:` 链中累加。循环深度受限。

*v0.9.0 起*

---

## Skills

Markdown 驱动的能力，可以全局注册并在任何 agent 运行中引入：

```bash
superagent skills install ./my-skill.md
superagent skills list
superagent skills show review
superagent skills remove review
superagent skills path        # 显示安装目录
```

Skill markdown 支持 frontmatter（`name` / `description` / `allowed_tools` / `system_prompt`）。Skill 运行时继承调用方的 provider。

---

## MCP 集成

### Server 注册

```bash
superagent mcp list
superagent mcp add sqlite stdio uvx --arg mcp-server-sqlite
superagent mcp add brave stdio npx --arg @brave/mcp --env BRAVE_API_KEY=...
superagent mcp remove sqlite
superagent mcp status
superagent mcp path
```

配置原子写入到 `~/.superagent/mcp.json`。

### 需要 OAuth 的 MCP server

```bash
superagent mcp auth <name>          # 跑 RFC 8628 设备码流程
superagent mcp reset-auth <name>    # 清除已存 token
superagent mcp test <name>          # 探测可用性（stdio `command -v` 或 HTTP 可达性）
```

config 里声明 `oauth: {client_id, device_endpoint, token_endpoint}` 的 server 走此流程。*v0.9.0 起。*

### 声明式 catalog + 非破坏性同步

在项目根放一个 catalog 文件：`.mcp-servers/catalog.json`（或 `.mcp-catalog.json`）：

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

同步到项目 `.mcp.json`：

```bash
superagent mcp sync                         # 全量
superagent mcp sync --domain=baseline       # 仅 "baseline" 域
superagent mcp sync --servers=sqlite,brave  # 显式子集
superagent mcp sync --dry-run               # 预览，不写盘
```

非破坏契约 —— 磁盘 hash 与渲染后 hash 相同 → `unchanged`；用户已编辑过的文件保持不动并标记 `user-edited`；首次写入或我们上次写的 hash 匹配 → `written`。manifest 在 `<project>/.superagent/mcp-manifest.json`，记录我们写过的每个文件的 sha256，所以陈旧条目自动清理。

*v0.9.1 起*

---

## Wire Protocol

v1 —— 行分隔 JSON（NDJSON），每行一个事件，通过顶层 `wire_version` + `type` 字段自描述。IDE 桥接、CI 集成、结构化日志的基础。

```bash
superagent --output json-stream "总结 src/"
# 产生类似这样的事件：
# {"wire_version":1,"type":"turn.begin","turn_number":1}
# {"wire_version":1,"type":"text.delta","delta":"我先从..."}
# {"wire_version":1,"type":"tool.call","name":"read","input":{"path":"src/"}}
# {"wire_version":1,"type":"turn.end","turn_number":1,"usage":{...}}
```

### Transport（v0.9.1 起）

通过 DSN 选择流的去向：

| DSN | 含义 |
|---|---|
| `stdout`（默认）/ `stderr` | 标准流 |
| `file:///path/to/log.ndjson` | append 模式写文件 |
| `tcp://host:port` | 连接到监听中的 TCP peer |
| `unix:///path/to/sock` | 连接到监听中的 unix socket |
| `listen://tcp/host:port` | 监听 TCP，接受一个 client |
| `listen://unix//path/to/sock` | 监听 unix socket，接受一个 client |

编程使用：

```php
$factory = new SuperAgent\CLI\AgentFactory();
[$emitter, $transport] = $factory->makeWireEmitterForDsn('listen://unix//tmp/agent.sock');

// IDE 插件连接上来，然后：
$agent->run($prompt, ['wire_emitter' => $emitter]);

$transport->close();
```

非阻塞 peer socket 意味着 IDE 掉线不会卡住 agent loop。

*Wire Protocol v1 v0.9.0 起。Socket / TCP / file transport v0.9.1 起。*

---

## 重试、错误与可观测性

### 分层重试

```php
new Agent([
    'provider'               => 'openai',
    'request_max_retries'    => 4,       // HTTP connect / 4xx / 5xx（默认 3）
    'stream_max_retries'     => 5,       // 为 mid-stream resume 预留（Responses API）
    'stream_idle_timeout_ms' => 60_000,  // cURL 在 SSE 上的 low-speed 断流阈值（默认 300 000）
]);
```

带抖动的指数退避（0.9–1.1× 乘数）防止多 worker 并发重试的 thundering herd。`Retry-After` header 精确按服务端给的值（不抖动 —— 服务端最清楚）。

*v0.9.1 起*

### 分类错误

6 个 `ProviderException` 子类，由 `OpenAIErrorClassifier` 根据 response body 的 `error.code` / `error.type` / HTTP 状态派发：

```php
try {
    $agent->run($prompt);
} catch (\SuperAgent\Exceptions\Provider\ContextWindowExceededException $e) {
    // prompt 过长；压缩历史或换模型
} catch (\SuperAgent\Exceptions\Provider\QuotaExceededException $e) {
    // 月额度打满；通知 operator
} catch (\SuperAgent\Exceptions\Provider\UsageNotIncludedException $e) {
    // ChatGPT 套餐不含此模型；升级或切 API key
} catch (\SuperAgent\Exceptions\Provider\CyberPolicyException $e) {
    // 策略拒绝 —— 不要重试
} catch (\SuperAgent\Exceptions\Provider\ServerOverloadedException $e) {
    // 可退避重试；查 $e->retryAfterSeconds
} catch (\SuperAgent\Exceptions\Provider\InvalidPromptException $e) {
    // body 畸形 —— 检查并修复
} catch (\SuperAgent\Exceptions\ProviderException $e) {
    // 兜底基类；上面每个子类都 extend 它
}
```

所有子类都 extend `ProviderException`，所以已有的 `catch (ProviderException)` 调用点保持不变。

*v0.9.1 起*

### 健康检查面板

```bash
superagent health                # 对每个已配置 provider 做 5s cURL 探针
superagent health --all          # 包括未配置 env key 的（用于"我忘了设哪个？"）
superagent health --json         # 机器可读表格；任何失败都返回非零
```

封装 `ProviderRegistry::healthCheck()` —— 区分 auth 拒绝（401/403）vs 网络超时 vs "没 API key"，operator 可以对症下药而不用猜。

*v0.9.1 起*

### SSE parser 硬化（v0.9.0 起）

- **按 index 组装 tool call** —— 一次流式调用被切成 N 个 chunk 后产出一个 tool-use 块，不再是 N 个碎片。
- **`finish_reason: error_finish` 识别** —— DashScope-compat 的限流信号抛 `StreamContentError`（可重试，HTTP 429），而不是把错误文本偷偷塞进 message body。
- **tool call JSON 截断修复** —— 一次性尝试闭合不平衡的 `{` 后回退到空 arg dict。
- **双形态 cached token 读取** —— `usage.prompt_tokens_details.cached_tokens`（当前 OpenAI 形状）和 `usage.cached_tokens`（legacy）都会填到 `Usage::cacheReadInputTokens`。

---

## Guardrails 与 Checkpoint

### 循环检测（v0.9.0 起）

5 个检测器观察流式事件总线，首次触发后粘滞：

| 检测器 | 信号 |
|---|---|
| `TOOL_LOOP` | 同工具 + 同归一化参数连续 5 次 |
| `STAGNATION` | 同工具名连续 8 次，无论参数 |
| `FILE_READ_LOOP` | 最近 15 个 tool call 里有 ≥ 8 个是读类，带冷启动豁免 |
| `CONTENT_LOOP` | 相同的 50 字符滑窗在流式文本里出现 10 次 |
| `THOUGHT_LOOP` | 相同的 thinking channel 文本出现 3 次 |

```php
new Agent([
    'provider'        => 'openai',
    'loop_detection'  => true,           // 默认值
    // 或 per-detector 覆盖：
    // 'loop_detection' => ['TOOL_LOOP' => 10, 'STAGNATION' => 15],
]);
```

违规通过 `loop_detected` wire 事件扇出 —— agent 继续跑，由 host 决定是否干预。

### Checkpoint + shadow-git（v0.9.0 起）

每轮都 snapshot agent 状态（messages、cost、usage）。挂一个 `GitShadowStore` 后，文件级 snapshot 同步落到一个**独立的 bare git repo**，位置 `~/.superagent/history/<project-hash>/shadow.git` —— 永远不碰用户自己的 `.git`。

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\GitShadowStore;

$mgr = new CheckpointManager(shadowStore: new GitShadowStore('/path/to/project'));
$mgr->createCheckpoint($agentState, label: 'after-refactor');

// 之后：
$checkpoints = $mgr->list();
$mgr->restore($checkpoints[0]->id);
$mgr->restoreFiles($checkpoints[0]);   // 回放 shadow commit
```

Restore 回滚已跟踪文件，留下未跟踪文件（更安全）。项目自己的 `.gitignore` 被尊重（shadow 的 worktree 就是项目目录）。

### Permission mode

```php
new Agent([
    'provider'        => 'anthropic',
    'permission_mode' => 'ask',     // 或 'default' / 'plan' / 'bypassPermissions'
]);
```

`ask` 在任何写入类工具前向调用方的 `PermissionCallbackInterface` 请示。用 `WireProjectingPermissionCallback` 包一层会把请求投射为 wire 事件给 IDE 用。

---

## 独立 CLI

```bash
superagent                                  # 交互式 REPL
superagent "修复登录 bug"                    # 单次
superagent init                             # 初始化 ~/.superagent/
superagent auth login <provider>            # 导入 OAuth 登录
superagent auth status                      # 显示已存凭据
superagent models list / update / refresh / status / reset
superagent mcp list / add / remove / sync / auth / reset-auth / test / status / path
superagent skills install / list / show / remove / path
superagent swarm <prompt>                   # 规划 + 执行 swarm
superagent health [--all] [--json] [--providers=a,b,c]   # provider 可达性
```

**选项：**

```
  -m, --model <model>                  模型名
  -p, --provider <provider>            Provider key（openai、anthropic、openai-responses...）
      --max-turns <n>                  最大 agent 轮次（默认 50）
  -s, --system-prompt <prompt>         自定义 system prompt
      --project <path>                 项目工作目录
      --json                           结果以 JSON 输出
      --output json-stream             发 NDJSON wire 事件
      --verbose-thinking               显示完整 thinking 流
      --no-thinking                    隐藏 thinking
      --plain                          禁用 ANSI 颜色
      --no-rich                        legacy 最小渲染器
  -V, --version                        显示版本
  -h, --help                           显示帮助
```

**交互式命令**（在 REPL 里）：

```
  /help                    可用命令
  /model <name>            切换模型
  /cost                    显示成本跟踪
  /compact                 强制上下文压缩
  /session save|load|list|delete
  /clear                   清空对话
  /quit                    退出
```

*独立 CLI v0.8.6 起。*

---

## Laravel 集成

`composer require forgeomni/superagent` 后 service provider 自动注册：

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
    ->run('总结本周提交');
```

Artisan 命令镜像 CLI：

```bash
php artisan superagent:chat "修复 bug"
php artisan superagent:mcp sync
php artisan superagent:models refresh
php artisan superagent:health --json
```

queue 集成、job 派发、`ai_usage_logs` schema 见 `docs/LARAVEL.md`。

---

## Host 集成

嵌入 SuperAgent 的 framework —— 通常是把 provider 凭据加密存在数据库里、每个请求起一个 agent 的多租户平台 —— 用 `ProviderRegistry::createForHost()` 而不是 `create()`。Host 传规范化后的 shape，SDK 通过 per-provider adapter 分发到对应的构造函数。

```php
use SuperAgent\Providers\ProviderRegistry;

// 一次调用覆盖所有 provider —— host 侧不再需要 `match ($type)`。
$agent = ProviderRegistry::createForHost($sdkKey, [
    'api_key'     => $aiProvider->decrypted_api_key,
    'base_url'    => $aiProvider->base_url,
    'model'       => $resolvedModel,
    'max_tokens'  => $extra['max_tokens']  ?? null,
    'region'      => $extra['region']      ?? null,
    'credentials' => $extra,                // 不透明 blob；adapter 按需挑选
    'extra'       => $extra,                // provider 特定透传（organization / reasoning / verbosity 等）
]);
```

每个 ChatCompletions 风格的 provider（Anthropic / OpenAI / OpenAI-Responses / OpenRouter / Ollama / LM Studio / Gemini / Kimi / Qwen / Qwen-native / GLM / MiniMax）都用默认的透传 adapter。Bedrock 自带一个专门的 adapter，把 `credentials.aws_access_key_id` / `aws_secret_access_key` / `aws_region` 拆成 AWS SDK 认识的 shape。

需要定制 adapter 的 plugin 或 host 可以自己注册：

```php
ProviderRegistry::registerHostConfigAdapter('my-custom-provider', function (array $host): array {
    return [
        'api_key' => $host['credentials']['my_custom_token'] ?? null,
        'model'   => $host['model'] ?? 'default-model',
        // ... 任意 transform
    ];
});
```

未来 SDK 加的新 provider key 自带自己的 adapter（或走默认），所以 host 侧的工厂代码永远不需要加新 `match` 分支。

*v0.9.2 起*

---

## 配置参考

`Agent` 构造函数接受的所有选项，分组列出。括号内是默认值。

**Provider 选择**

| Key | 接受 |
|---|---|
| `provider` | 注册表 key 或一个 `LLMProvider` 实例 |
| `model` | 模型 id —— 覆盖 provider 默认 |
| `base_url` | URL —— 覆盖 provider 默认；也触发自动检测（Azure）|
| `region` | `intl` / `cn` / `us` / `hk` / `code`（按 provider）|
| `api_key` | Provider API key |
| `access_token` + `account_id` | OAuth（OpenAI ChatGPT / Anthropic Claude Code）|
| `auth_mode` | `'api_key'`（默认）或 `'oauth'` |
| `organization` | OpenAI org id（加 `OpenAI-Organization` header）|

**Agent 循环**

| Key | 默认 |
|---|---|
| `max_turns` | `50` |
| `max_budget_usd` | `0.0`（无上限）|
| `system_prompt` | `null` |
| `auto_mode` | `false` |
| `allowed_tools` / `denied_tools` | `null` / `[]` |
| `permission_mode` | `'default'` |
| `options` | `[]`（forward 给 provider 的 per-call 默认）|

**Per-call 选项**（`$agent->run($prompt, $options)`）

| Key | 起始 | 说明 |
|---|---|---|
| `model` / `max_tokens` / `temperature` / `tool_choice` / `response_format` | v0.1.0 | 标准 Chat Completions 旋钮 |
| `features` | v0.8.8 | `thinking` / `prompt_cache_key` / `dashscope_cache_control` / ... 经 `FeatureDispatcher` 分发 |
| `extra_body` | v0.9.0 | 高级用户逃生门 —— deep-merge 进 request body |
| `loop_detection` | v0.9.0 | `true`（默认）、`false`、或阈值覆盖 |
| `idempotency_key` | v0.9.1 | 透传到 `AgentResult::$idempotencyKey` |
| `reasoning` | v0.9.1 | Responses API —— `{effort, summary}` |
| `verbosity` | v0.9.1 | Responses API —— `low` / `medium` / `high` |
| `prompt_cache_key` | v0.9.0 | Kimi + OpenAI Responses 的 cache key |
| `previous_response_id` | v0.9.1 | Responses API 接续 |
| `store` / `include` / `service_tier` / `parallel_tool_calls` | v0.9.1 | Responses API |
| `client_metadata` | v0.9.1 | Responses API 不透明 key-value map |
| `trace_context` / `traceparent` / `tracestate` | v0.9.1 | W3C Trace Context 注入 |
| `output_subdir` | v0.9.1 | `AgentTool` guard block + 退出后审计 |

**重试 + 传输**（provider 级）

| Key | 默认 | 起始 |
|---|---|---|
| `max_retries` | `3` | v0.1.0（legacy 单旋钮）|
| `request_max_retries` | `3`（继承 `max_retries`）| v0.9.1 |
| `stream_max_retries` | `5` | v0.9.1 |
| `stream_idle_timeout_ms` | `300_000` | v0.9.1 |
| `env_http_headers` | `[]` | v0.9.1 |
| `http_headers` | `[]` | v0.9.1 |
| `experimental_ws_transport` | `false` | v0.9.1（脚手架）|
| `azure_api_version` | `'2025-04-01-preview'` | v0.9.1（仅 Azure）|

---

## 链接

- [CHANGELOG](CHANGELOG.md) —— 完整 per-release 日志
- [INSTALL_CN](INSTALL_CN.md) —— 安装 + 首次运行
- [高级用法](docs/ADVANCED_USAGE_CN.md) —— 模式、示例 agent、调试
- [原生 provider](docs/NATIVE_PROVIDERS.md) —— region 映射 + capability 矩阵
- [Wire protocol](docs/WIRE_PROTOCOL.md) —— v1 规范
- [特性矩阵](docs/FEATURES_MATRIX.md) —— 哪个 provider 支持哪个特性

## 许可证

MIT —— 见 [LICENSE](LICENSE)。
