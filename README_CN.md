# SuperAgent - 企业级Laravel多智能体编排SDK 🚀

[![PHP版本](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel版本](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![许可证](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![版本](https://img.shields.io/badge/version-0.7.5-purple)](https://github.com/xiyanyang/superagent)

> **🌍 语言**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)  
> **📖 文档**: [Installation Guide](INSTALL.md) | [安装手册](INSTALL_CN.md) | [Guide d'Installation](INSTALL_FR.md) | [高级用法](docs/ADVANCED_USAGE_CN.md) | [API文档](docs/)

SuperAgent是一个功能强大的企业级Laravel AI智能体SDK，提供Claude级别的能力，支持多智能体编排、实时监控和分布式扩展。构建并部署可并行工作的AI智能体团队，具有自动任务检测和智能资源管理功能。

## ✨ 核心特性

### 🆕 v0.7.5 — Claude Code 工具名兼容
- **`ToolNameResolver`** (`src/Tools/ToolNameResolver.php`) — Claude Code PascalCase 工具名（`Read`、`Write`、`Edit`、`Bash`、`Glob`、`Grep`、`Agent`、`WebSearch` 等）与 SuperAgent snake_case 工具名（`read_file`、`write_file`、`edit_file`、`bash`、`glob`、`grep`、`agent`、`web_search` 等）的双向映射。40+ 工具映射，包括 CC 旧名称（`Task` → `agent`）
- **Agent 定义自动解析** — `MarkdownAgentDefinition::allowedTools()` 和 `disallowedTools()` 通过 `ToolNameResolver::resolveAll()` 自动解析 CC 工具名。`.claude/agents/` 中的定义可使用任一格式：`allowed_tools: [Read, Grep, Glob]` 或 `allowed_tools: [read_file, grep, glob]` 均可
- **权限系统兼容** — `QueryEngine::isToolAllowed()` 同时检查原始名称和解析后名称，CC 或 SA 格式的权限列表都能正确工作
- **向后兼容** — 现有 SuperAgent 工具名继续正常工作，解析器是增量添加不破坏现有功能

### 🆕 v0.7.0 — 性能优化套件（13 项策略，全部可配置）
- **工具结果压缩** — 自动将旧的工具结果（超过最近 N 轮）压缩为简洁摘要，减少 30-50% input tokens。保留错误结果和近期上下文不变。配置：`optimization.tool_result_compaction`（`enabled`、`preserve_recent_turns`、`max_result_length`）
- **按需工具 Schema** — 根据任务阶段（探索/编辑/规划）动态选择相关工具子集，省略未使用的工具 schema 节省约 10K tokens。始终包含最近使用的工具。配置：`optimization.selective_tool_schema`（`enabled`、`max_tools`）
- **逐轮模型路由** — 纯工具调用轮自动降级到快速模型（可配置，默认 Haiku），推理时自动升级。检测连续工具调用轮并据此路由，降低 40-60% 成本。配置：`optimization.model_routing`（`enabled`、`fast_model`、`min_turns_before_downgrade`）
- **响应预填充** — 使用 Anthropic assistant prefill 在长时间工具调用后引导输出格式，鼓励总结而非更多工具调用。保守策略：仅在连续 3+ 轮工具调用后预填。配置：`optimization.response_prefill`（`enabled`）
- **提示缓存固定** — 在缺少缓存边界的 system prompt 中自动插入 cache boundary 标记，将静态部分（工具说明、角色）与动态部分（记忆、上下文）分离，实现约 90% prompt cache 命中率。配置：`optimization.prompt_cache_pinning`（`enabled`、`min_static_length`）
- **所有优化默认启用**，可通过环境变量单独禁用（`SUPERAGENT_OPT_TOOL_COMPACTION`、`SUPERAGENT_OPT_SELECTIVE_TOOLS`、`SUPERAGENT_OPT_MODEL_ROUTING`、`SUPERAGENT_OPT_RESPONSE_PREFILL`、`SUPERAGENT_OPT_CACHE_PINNING`）
- **无硬编码模型 ID** — 路由用的快速模型完全通过 `SUPERAGENT_OPT_FAST_MODEL` 配置；低价模型检测使用启发式名称匹配而非硬编码列表
- **并行工具执行** — PHP Fiber 并行执行只读工具，耗时 = max 而非 sum。配置：`performance.parallel_tool_execution`
- **流式工具分发** — SSE 流中收到 tool_use 块后立即启动执行。配置：`performance.streaming_tool_dispatch`
- **HTTP 连接池** — cURL keep-alive 复用连接。配置：`performance.connection_pool`
- **推测性预读** — Read 后预读相关文件到内存缓存。配置：`performance.speculative_prefetch`
- **流式 Bash 执行** — 超时截断 + 尾部摘要。配置：`performance.streaming_bash`
- **自适应 max_tokens** — 工具调用 2048，推理 8192。配置：`performance.adaptive_max_tokens`
- **批量 API** — Anthropic Batches API（50% 折扣）。配置：`performance.batch_api`
- **本地工具零拷贝** — Read/Edit/Write 间文件内容缓存。配置：`performance.local_tool_zero_copy`

### 🆕 v0.6.19 — In-Process NDJSON 日志支持进程监控
- **`NdjsonStreamingHandler`** (`src/Logging/NdjsonStreamingHandler.php`) — 工厂类，一行代码创建写 CC 兼容 NDJSON 到日志文件的 `StreamingHandler`。用于 in-process agent 执行（直接调用 `$agent->prompt()` 而不经过 `agent-runner.php`/`ProcessBackend` 的场景）
- **`create(logTarget, agentId)`** — 返回带 `onToolUse`、`onToolResult`、`onTurn` 回调的 `StreamingHandler`，自动写入 `NdjsonWriter`。接受文件路径（自动创建目录）或可写流资源
- **`createWithWriter(logTarget, agentId)`** — 返回 `{handler, writer}` 对，调用方可在执行完成后发出 `writeResult()`/`writeError()`。writer 和 handler 共享同一 NDJSON 流
- **进程监控兼容** — 日志文件与子进程 stderr 格式完全一致，`parseStreamJsonIfNeeded()` 可直接解析并显示工具调用活动（🔧 Read、Edit、Grep 等）、token 计数和执行状态

### 🆕 v0.6.18 — Claude Code 兼容 NDJSON 结构化日志
- **`NdjsonWriter`** (`src/Logging/NdjsonWriter.php`) — 新增 Claude Code 兼容的 NDJSON（换行符分隔 JSON）事件写入器。支持 5 种事件方法：`writeAssistant()`（含 text/tool_use 内容块 + 每轮 usage 的 LLM 回复）、`writeToolUse()`（单个工具调用）、`writeToolResult()`（工具执行结果，`type:user` + `parent_tool_use_id`）、`writeResult()`（成功结果含 usage/cost/duration）、`writeError()`（错误含 subtype）。转义 U+2028/U+2029 行分隔符，与 CC 的 `ndjsonSafeStringify` 一致
- **NDJSON 替代 `__PROGRESS__:` 协议** — `agent-runner.php` 现在在 stderr 上使用 `NdjsonWriter` 输出标准 NDJSON，替代自定义 `__PROGRESS__:` 前缀。事件可被 CC 的 bridge/sessionRunner `extractActivities()` 直接解析。每个 assistant 事件包含每轮 `usage`（inputTokens、outputTokens、cacheReadInputTokens、cacheCreationInputTokens）用于实时 token 追踪
- **ProcessBackend NDJSON 解析** — `ProcessBackend::poll()` 升级为检测 NDJSON 行（以 `{` 开头的 JSON 对象），同时兼容旧 `__PROGRESS__:` 格式。非 JSON stderr 行（如 `[agent-runner]` 日志）继续转发到 PSR-3 logger
- **AgentTool CC 格式支持** — `applyProgressEvents()` 现在同时处理 CC NDJSON 格式（`assistant` → 提取 tool_use 块 + usage，`user` → tool_result，`result` → 最终 usage）和旧格式，实现无缝进程监控集成

### 🆕 v0.6.17 — 子Agent 进程实时进度监控
- **结构化进度事件** — 子 agent 进程现在通过 stderr 发送 `__PROGRESS__:` 协议的结构化 JSON 进度事件。事件包括 `tool_use`（工具名、输入参数）、`tool_result`（成功/失败、结果大小）和 `turn`（每轮 LLM 调用的 token 用量）
- **子进程 StreamingHandler** — `agent-runner.php` 创建带有 `onToolUse`、`onToolResult` 和 `onTurn` 回调的 `StreamingHandler`，将执行事件序列化回传给父进程。从 `Agent::run()` 改为 `Agent::prompt()` 以传递 handler
- **ProcessBackend 事件解析** — `ProcessBackend::poll()` 现在识别 stderr 中 `__PROGRESS__:` 前缀的行，解析为 JSON 并按 agent 排队。新增 `consumeProgressEvents(agentId)` 方法返回并清空排队事件。普通日志行仍照常转发给 logger
- **AgentTool 协调器集成** — `waitForProcessCompletion()` 将子 agent 注册到 `ParallelAgentCoordinator`，每次轮询时将进度事件注入 `AgentProgressTracker`。跟踪器实时更新工具使用计数、当前活动描述（如"Editing /src/Agent.php"）、token 计数和最近活动列表
- **进程监控可见性** — `ParallelAgentDisplay` 现在可显示子 agent 实时进度（当前工具、token 计数、工具使用次数），无需修改显示代码——现有 UI 直接读取协调器的 tracker，而 tracker 现已对进程级 agent 填充数据

### 🆕 v0.6.16 — 父进程注册数据透传子进程
- **Agent 定义透传** — 父进程通过 `AgentManager::exportDefinitions()` 序列化所有已注册 agent 定义（内置 + `.claude/agents/` 自定义），经 stdin JSON 传给子进程。子进程通过 `importDefinitions()` 导入——无需 Laravel bootstrap 或文件系统访问
- **MCP Server 配置透传** — 父进程序列化所有已注册 MCP server 配置（`ServerConfig::toArray()`）传给子进程。子进程通过 `MCPManager::registerServer()` 注册，无需重新读取配置文件或 `.mcp.json`
- **已验证** — 子进程收到 9 个 agent 类型（7 内置 + 2 自定义含完整 system prompt）、2 个 MCP server（stdio + http）、6 个内置 skill、58 个工具

### 🆕 v0.6.15 — MCP Server TCP 桥接共享
- **MCP TCP 桥接** (`MCPBridge`) — 父进程连接 stdio MCP server 后，自动在随机端口启动轻量 TCP 代理。子进程通过注册文件发现桥接，用 `HttpTransport` 连接而非各自启动 MCP server。N 个子 agent 共享 1 个 MCP server 进程
- **MCPManager 自动检测** — `createTransport()` 在创建 `StdioTransport` 前检查父进程桥接，若存在则透明使用 `HttpTransport`
- **ProcessBackend 桥接轮询** — `poll()` 同时调用 `MCPBridge::poll()` 处理子进程的 TCP 请求

### 🆕 v0.6.12 — 子进程 Laravel 引导与 Provider 修复
- **子进程 Laravel 引导** — `agent-runner.php` 现在在收到 `base_path` 时执行完整 Laravel 引导（`$app->make(Kernel)->bootstrap()`）。子进程可访问 `config()`、`AgentManager`、`SkillManager`、`MCPManager`、`.claude/agents/` 目录及所有 service provider——与父进程完全一致
- **Provider 配置序列化修复** — 当 `Agent` 以 `LLMProvider` 对象（非字符串）构造时，对象被 JSON 序列化为 `{}`，子进程无法获取 API 凭证。`injectProviderConfigIntoAgentTools()` 现在将对象替换为 `$provider->name()` 字符串，从 Laravel config 回填 `api_key`，并始终设置 provider 名称和 model
- **子进程完整工具集** — `ProcessBackend` 默认设置 `load_tools='all'`（58 个工具），子 agent 可访问 agent、skill、mcp、web_search 等全部工具

### 🆕 v0.6.11 — 真正的进程级并行子智能体
- **基于进程的子智能体** — `AgentTool` 现在默认使用 `ProcessBackend`（`proc_open`）而非 `InProcessBackend`（Fiber）。每个子智能体在独立 OS 进程中运行，拥有独立的 Guzzle 连接，实现真正并行。PHP Fiber 是协作式的——Fiber 内的阻塞 I/O（HTTP 调用、bash 命令）会阻塞整个进程，导致旧方案实际上是串行的
- **重写 `bin/agent-runner.php`** — 一次性运行器：从 stdin 读取 JSON 配置，创建带完整 LLM Provider 和工具的 `SuperAgent\Agent`，执行 prompt，将 JSON 结果写入 stdout
- **`ProcessBackend` 重构** — `spawn()` 通过 stdin 传递配置后关闭；`poll()` 非阻塞轮询 stdout/stderr；`waitAll()` 等待所有追踪中的智能体完成。实测：5 个各 sleep 500ms 的智能体总计 544ms 完成（4.6x 加速）
- **InProcessBackend 降级** — Fiber 后端作为 `proc_open` 不可用时的降级方案保留

### 🆕 v0.6.10 — 多智能体同步执行修复
- **同步智能体死锁修复** — `InProcessBackend::spawn()` 现在无论 `runInBackground` 设置如何都会创建执行 Fiber。此前同步模式从未创建 Fiber，导致 `waitForSynchronousCompletion()` 无限轮询（5 分钟超时死锁）
- **后端类型不匹配修复** — `AgentTool::$activeTasks` 现在在 `BackendType` 枚举旁额外存储实际后端实例。同步等待循环此前在枚举值上调用 `->getStatus()` 和 `instanceof InProcessBackend`，结果始终错误
- **Fiber 生命周期修复** — `ParallelAgentCoordinator::processAllFibers()` 现在可处理未启动的 Fiber（`!$fiber->isStarted()` → `start()`）。修复了 `AgentProgressTracker` 缺失的 `$status` 属性，以及 stub 智能体中的 null usage 类型错误

### 🆕 v0.6.9 — Guzzle Base URL 路径修复
- **多 Provider Base URL 修复** — `OpenAIProvider`、`OpenRouterProvider` 和 `OllamaProvider` 现在正确地在 `base_uri` 末尾追加斜杠，并使用相对请求路径。此前，任何带路径前缀的自定义 `base_url`（如 `https://gateway.example.com/openai`）都会因 Guzzle 的 RFC 3986 解析器在使用绝对路径（如 `/v1/chat/completions`）时将路径前缀静默丢弃。四个 Provider（`AnthropicProvider` 已在 v0.6.8 修复）现均采用正确模式

### 🆕 v0.6.8 — 增量上下文与工具按需加载
- **增量上下文** (`IncrementalContextManager`) — 基于 Delta 的上下文同步：只传输差异（新增/修改/删除的消息）而非完整历史。自动检查点、一步还原、可配置 Token 阈值触发自动压缩，以及 `getSmartWindow(maxTokens)` API 用于 Token 预算内的上下文检索
- **懒加载上下文** (`LazyContextManager`) — 注册上下文片段（含类型、优先级、标签、大小元数据）无需立即加载内容。片段在任务请求时按需获取，通过关键词/标签相关性评分选择。支持 TTL 缓存、LRU 淘汰、`preloadPriority()`、`loadByTags()` 和 `getSmartWindow(maxTokens, focusArea)` 精细化内存管理
- **工具按需加载** (`ToolLoader` / `LazyToolResolver`) — 注册工具类而无需实例化；工具在模型调用时才被加载。`predictAndPreload(task)` 根据任务关键词预热工具。`loadForTask(task)` 返回最小工具集。任务间可卸载闲置工具释放内存
- **子智能体 Provider 继承** — `AgentTool` 现在接收父智能体的 provider 配置（API Key、模型、Base URL），并通过 `AgentSpawnConfig::$providerConfig` 注入每个生成的子智能体。`InProcessBackend` 创建的子智能体是真实的 `SuperAgent\Agent` 实例，具备真正的 LLM 连接，而非空操作 stub
- **WebSearch 无 Key 降级** — `WebSearchTool` 在未设置 `SEARCH_API_KEY` 时不再直接报错，而是通过 `WebFetchTool` 自动降级到 DuckDuckGo HTML 搜索（使用 cURL 或 `file_get_contents`，浏览器级 User-Agent）
- **WebFetch 加固** — `WebFetchTool` 现在优先使用 cURL；检查 HTTP 状态码（4xx/5xx → 报错而非静默返回错误页内容）；在 cURL 和 `allow_url_fopen` 均不可用时给出明确错误信息

### 🆕 多智能体编排 (v0.6.7)
- **并行智能体执行** - 同时运行多个智能体，实时跟踪每个智能体进度
- **Claude Code兼容结果** - 以精确的Claude Code格式返回结果，无缝集成
- **自动任务检测** - 分析任务复杂度，自动决定单智能体或多智能体模式
- **智能体团队管理** - 协调具有领导者/成员关系和基于角色执行的团队
- **智能体间通信** - SendMessage工具用于智能体间消息传递和协调
- **持久化邮箱系统** - 可靠的消息队列，支持过滤、归档和广播
- **进度聚合** - 实时令牌计数、活动跟踪和跨所有智能体的成本聚合
- **WebSocket监控** - 基于浏览器的实时仪表板，监控并行智能体执行
- **资源池化** - 智能体池化，带并发限制和依赖管理
- **检查点与恢复** - 长时间运行的多智能体工作流的自动状态恢复

### 🎯 自动模式检测
- **智能任务分析** - 自动判断是否需要多智能体协作
- **复杂度评估** - 基于任务复杂度自动选择执行模式
- **资源优化** - 简单任务单智能体，复杂任务多智能体并行

### 📊 企业级功能
- **WebSocket实时监控** - 浏览器端实时仪表板
- **性能分析** - 全面的性能指标和瓶颈分析
- **依赖管理** - 复杂工作流编排与拓扑排序
- **分布式扩展** - 跨多台机器/进程运行智能体
- **持久化存储** - 自动保存进度，支持崩溃恢复
- **智能体池化** - 预热智能体池，即时任务分配
- **模板系统** - 10+预置模板，快速部署常见任务

### 🔧 强大工具集
- **59+内置工具** - 文件操作、代码编辑、Web搜索、任务管理等
- **安全验证器** - 23项注入/混淆检查，命令分类
- **智能上下文压缩** - 语义边界保护的会话记忆压缩
- **Token预算控制** - 动态预算管理，智能成本控制

### 🌍 多供应商支持
- **Claude (Anthropic)** - 最新Claude 4.6，包括Opus、Sonnet和Haiku变体
- **OpenAI** - GPT-5.4、GPT-5、GPT-4 Turbo及旧版模型
- **AWS Bedrock** - 通过AWS使用Claude，支持最新模型
- **Ollama** - 本地模型，包括Llama 3、Mistral等
- **OpenRouter** - 100+模型统一API

## 📦 安装

### 系统要求
- PHP >= 8.1
- Composer
- Laravel >= 10.0（可选，支持独立使用）

### 通过Composer安装

```bash
composer require forgeomni/superagent
```

### Laravel项目安装

1. **安装包：**
```bash
composer require forgeomni/superagent
```

2. **发布配置文件：**
```bash
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"
```

3. **配置`.env`文件：**
```env
# 主要供应商
SUPERAGENT_PROVIDER=anthropic
ANTHROPIC_API_KEY=你的API密钥

# 可选供应商
OPENAI_API_KEY=你的OpenAI密钥
AWS_BEDROCK_REGION=us-east-1

# 多智能体功能
SUPERAGENT_WEBSOCKET_ENABLED=true
SUPERAGENT_WEBSOCKET_PORT=8080
SUPERAGENT_STORAGE_PATH=storage/superagent

# 自动模式检测
SUPERAGENT_AUTO_MODE=true
```

### 独立安装（无Laravel）

```bash
# 安装包
composer require forgeomni/superagent

# 创建配置文件
cp vendor/forgeomni/superagent/config/superagent.php config/superagent.php
```

```php
// 在应用中初始化
use SuperAgent\SuperAgent;

$config = require 'config/superagent.php';
SuperAgent::initialize($config);
```

## 🚀 快速开始

### 基础智能体

```php
use SuperAgent\Agent;

$agent = new Agent([
    'provider' => 'anthropic',
    'model' => 'claude-4.6-opus-latest',
]);

$result = $agent->run("分析这个代码库并提出改进建议");
echo $result->message->content;
```

### 自动多智能体模式

```php
use SuperAgent\Agent;

// 启用自动检测
$agent = new Agent([
    'auto_mode' => true,  // 自动判断是否使用多智能体
]);

// 简单任务 - 自动使用单智能体
$result = $agent->run("2+2等于多少？");

// 复杂任务 - 自动启动多智能体团队
$result = $agent->run("
    分析这个项目的代码质量，
    找出所有安全漏洞，
    生成详细的修复方案，
    并为每个问题创建测试用例
");

// 系统自动分析任务并决定：
// ✅ 检测到4个子任务
// ✅ 需要多种工具（代码分析、安全扫描、文档生成、测试创建）
// ✅ 预估Token数超过10000
// → 自动启动多智能体模式
```

### 多智能体团队编排

```php
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Console\Output\ParallelAgentDisplay;

// 创建团队
$team = new TeamContext('research_team', 'team_leader');

// 设置后端
$backend = new InProcessBackend();
$backend->setTeamContext($team);

// 生成多个智能体
$agents = [
    $backend->spawn(new AgentSpawnConfig(
        name: '数据收集器',
        prompt: '从数据库收集销售数据',
        teamName: 'research_team'
    )),
    $backend->spawn(new AgentSpawnConfig(
        name: '数据分析师',
        prompt: '分析销售趋势和异常',
        teamName: 'research_team'
    )),
    $backend->spawn(new AgentSpawnConfig(
        name: '报告撰写员',
        prompt: '基于分析结果撰写报告',
        teamName: 'research_team'
    ))
];

// 实时监控进度
$display = new ParallelAgentDisplay($output);
$display->displayWithRefresh(500); // 每500ms刷新
```

### 智能体间通信

```php
use SuperAgent\Tools\Builtin\SendMessageTool;

$messageTool = new SendMessageTool();

// 直接发送消息给特定智能体
$messageTool->execute([
    'to' => 'researcher-agent',
    'message' => '请优先考虑安全最佳实践',
    'summary' => '优先级更新',
]);

// 广播给所有智能体
$messageTool->execute([
    'to' => '*',
    'message' => '团队更新：关注性能优化',
    'summary' => '团队公告',
]);
```

### WebSocket实时监控

```bash
# 启动WebSocket服务器
php artisan superagent:websocket

# 访问监控仪表板
open http://localhost:8080/superagent/monitor
```

仪表板功能：
- 🔴 实时智能体状态指示器
- 📊 每个智能体的Token使用情况
- 💰 成本聚合与预算追踪
- 📈 进度可视化与ETA
- 📬 消息队列监控

### 使用智能体模板

```php
use SuperAgent\Swarm\Templates\AgentTemplateManager;

$templates = AgentTemplateManager::getInstance();

// 使用预置模板 - 代码审查
$config = $templates->createSpawnConfig('code_reviewer', [
    'repository' => '/path/to/repo',
    'focus_areas' => '安全性、性能优化',
    'standards' => 'PSR-12、安全最佳实践'
]);

$agent = $backend->spawn($config);

// 可用模板类别：
// - 数据处理：data_processor, etl_pipeline
// - 代码分析：code_reviewer, security_scanner
// - 研究任务：web_researcher, documentation_writer
// - 测试生成：test_generator, performance_tester
// - 自动化：ci_cd_agent, deployment_agent
```

### 依赖管理

```php
use SuperAgent\Swarm\Dependency\AgentDependencyManager;

$depManager = new AgentDependencyManager();

// 定义执行链
$depManager->registerChain([
    '数据提取',
    '数据清洗',
    '数据分析',
    '报告生成'
]);

// 定义并行任务
$depManager->registerParallel([
    '单元测试',
    '集成测试',
    '性能测试'
]);

// 自动按依赖关系执行
$depManager->processWaitingAgents($backend);
```

## 📊 实时监控仪表板

### 启动WebSocket服务器

```bash
# 启动WebSocket服务器
php artisan superagent:websocket

# 另一个终端，启动仪表板
php artisan superagent:dashboard

# 访问 http://localhost:8080/dashboard
```

### 仪表板功能
- 🔄 实时智能体状态更新
- 📈 Token使用和成本追踪
- 🎯 任务进度可视化
- 📊 性能指标图表
- 🌳 团队层级显示

## 🎯 自动模式检测机制

SuperAgent通过以下维度自动判断任务复杂度：

### 检测维度
1. **任务复杂度分析**
   - 子任务数量检测
   - 任务描述长度
   - 关键词模式匹配

2. **工具需求评估**
   - 预测需要的工具种类
   - 工具调用频率估算

3. **Token预估**
   - 输入/输出Token预测
   - 上下文窗口需求

4. **并行机会识别**
   - 可并行执行的子任务
   - 任务间依赖关系

### 触发条件
```php
// 配置自动模式阈值
'auto_mode' => [
    'enabled' => true,
    'threshold' => [
        'complexity_score' => 0.7,  // 复杂度评分阈值
        'min_subtasks' => 3,        // 最少子任务数
        'min_tools' => 4,           // 最少工具种类
        'estimated_tokens' => 10000, // 预估Token数
    ],
],
```

## 🛠️ 高级配置

### 性能优化

```php
// config/superagent.php
'performance' => [
    'pool' => [
        'enabled' => true,
        'min_idle_agents' => 2,      // 最小空闲智能体
        'max_idle_agents' => 10,     // 最大空闲智能体
        'max_agent_lifetime' => 3600, // 智能体最大生命周期（秒）
    ],
    'cache' => [
        'prompt_cache' => true,       // 启用提示缓存
        'result_cache' => true,       // 启用结果缓存
        'ttl' => 3600,               // 缓存生存时间
    ],
],
```

### 安全设置

```php
'security' => [
    'bash_validator' => true,         // Bash命令验证
    'permission_mode' => 'standard',  // 权限模式
    'max_file_size' => 10485760,     // 最大文件大小（10MB）
    'allowed_directories' => [        // 允许访问的目录
        base_path(),
        storage_path(),
    ],
],
```

## 📚 完整文档

- [快速入门指南](docs/getting-started_CN.md)
- [多智能体编排](docs/PARALLEL_AGENT_TRACKING_CN.md)
- [高级特性](docs/PARALLEL_AGENT_ENHANCEMENTS_CN.md)
- [API参考](docs/api-reference_CN.md)
- [示例代码](examples/)

## 🧪 测试

```bash
# 运行所有测试
composer test

# 运行单元测试
composer test:unit

# 运行集成测试
composer test:integration

# 运行多智能体测试
php vendor/bin/phpunit tests/Unit/ParallelAgentTrackingTest.php
```

## 📈 性能基准

| 指标 | 单智能体 | 10智能体 | 100智能体 | 1000智能体 |
|------|---------|----------|-----------|------------|
| 内存开销 | 2 MB | 15 MB | 120 MB | 1.1 GB |
| 追踪延迟 | <1ms | <2ms | <10ms | <50ms |
| WebSocket广播 | N/A | 5ms | 20ms | 100ms |
| 存储写入 | 1ms | 5ms | 50ms | 500ms |

## 🤝 贡献

欢迎贡献！请查看[贡献指南](CONTRIBUTING_CN.md)了解详情。

### 开发路线图
- [ ] 支持更多LLM供应商
- [ ] GraphQL API支持
- [ ] Kubernetes原生部署
- [ ] 智能体市场
- [ ] 可视化工作流编辑器

## 📄 许可证

SuperAgent是基于[MIT许可证](LICENSE)的开源软件。

## 🙏 致谢

- 受Claude Code架构启发
- 感谢Laravel和PHP社区
- 特别感谢Anthropic提供Claude API

## 🔗 相关链接

- [GitHub仓库](https://github.com/xiyanyang/superagent)
- [官方文档](https://superagent.xiyanyang.com)
- [Discord社区](https://discord.gg/superagent)
- [示例项目](https://github.com/xiyanyang/superagent-examples)

---

由SuperAgent团队用❤️制作 | [English](README.md) | [报告问题](https://github.com/xiyanyang/superagent/issues)