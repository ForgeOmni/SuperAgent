# SuperAgent 安装手册（v0.8.5）

> **🌍 语言**: [English](INSTALL.md) | [中文](INSTALL_CN.md) | [Français](INSTALL_FR.md)  
> **📖 文档**: [README](README.md) | [README 中文](README_CN.md) | [README Français](README_FR.md)

## 目录
- [系统需求](#系统需求)
- [安装步骤](#安装步骤)
- [配置](#配置)
- [多智能体设置](#多智能体设置)
- [验证](#验证)
- [故障排除](#故障排除)
- [升级指南](#升级指南)

## 系统需求

### 最低需求
- **PHP**: 8.1 或更高版本
- **Laravel**: 10.0 或更高版本
- **Composer**: 2.0 或更高版本
- **内存**: 至少 256MB PHP 内存限制
- **磁盘空间**: 至少 100MB 可用空间

### 必需的PHP扩展
```bash
# 核心扩展
- json        # JSON处理
- mbstring    # 多字节字符串
- openssl     # 加密
- curl        # HTTP请求
- fileinfo    # 文件信息
```

### 可选的PHP扩展
```bash
# 增强功能
- redis       # Redis缓存支持
- pcntl       # 进程控制（多智能体协作）
- yaml        # YAML配置文件
- zip         # 文件压缩
- pdo_sqlite  # SQLite 会话存储与 FTS5 搜索（v0.8.0+）
```

### 环境检查

```bash
# 检查PHP版本
php -v

# 检查已安装的扩展
php -m

# 检查Laravel版本
php artisan --version

# 检查Composer版本
composer --version
```

## 安装步骤

### 1️⃣ 通过Composer安装

#### 标准安装（推荐）
```bash
composer require forgeomni/superagent
```

#### 安装开发版本
```bash
composer require forgeomni/superagent:dev-main
```

#### 安装特定版本
```bash
composer require forgeomni/superagent:^1.0
```

### 2️⃣ 注册服务提供者

Laravel 10+ 会自动注册。手动注册请编辑 `config/app.php`:

```php
'providers' => [
    // 其他提供者...
    SuperAgent\SuperAgentServiceProvider::class,
],

'aliases' => [
    // 其他别名...
    'SuperAgent' => SuperAgent\Facades\SuperAgent::class,
],
```

### 3️⃣ 发布资源文件

```bash
# 发布所有资源
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"

# 或分别发布
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config"
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="migrations"
```

### 4️⃣ 运行数据库迁移

如果使用内存系统和任务管理功能：

```bash
php artisan migrate
```

### 5️⃣ 配置环境变量

编辑您的 `.env` 文件并添加必要的配置：

```env
# ========== SuperAgent 基础配置 ==========

# 默认AI提供商 (anthropic|openai|bedrock|ollama)
SUPERAGENT_PROVIDER=anthropic

# Anthropic Claude 配置
ANTHROPIC_API_KEY=sk-ant-xxxxxxxxxxxxx
ANTHROPIC_MODEL=claude-4.6-haiku-latest
ANTHROPIC_MAX_TOKENS=4096
ANTHROPIC_TEMPERATURE=0.7

# OpenAI 配置（可选）
OPENAI_API_KEY=sk-xxxxxxxxxxxxx
OPENAI_MODEL=gpt-5.4
OPENAI_ORG_ID=org-xxxxxxxxxxxxx

# AWS Bedrock 配置（可选）
AWS_ACCESS_KEY_ID=AKIAXXXXXXXXXXXXX
AWS_SECRET_ACCESS_KEY=xxxxxxxxxxxxx
AWS_DEFAULT_REGION=us-east-1
BEDROCK_MODEL=anthropic.claude-v2

# 本地Ollama模型（可选）
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=llama2

# ========== 功能开关 ==========

# 流式输出
SUPERAGENT_STREAMING=true

# 缓存功能
SUPERAGENT_CACHE_ENABLED=true
SUPERAGENT_CACHE_TTL=3600

# 调试模式
SUPERAGENT_DEBUG=false

# 可观测性（主开关 - 关闭时所有子系统都关闭）
SUPERAGENT_TELEMETRY_ENABLED=false
SUPERAGENT_TELEMETRY_LOGGING=false
SUPERAGENT_TELEMETRY_METRICS=false
SUPERAGENT_TELEMETRY_EVENTS=false
SUPERAGENT_TELEMETRY_COST_TRACKING=false

# 安全提示防护
SUPERAGENT_SECURITY_GUARDRAILS=false

# 实验性功能（主开关 - 开启时所有标志都启用）
SUPERAGENT_EXPERIMENTAL=true

# ========== 权限配置 ==========

# 权限模式：
# bypass - 跳过所有权限检查
# acceptEdits - 自动批准文件编辑
# plan - 所有操作需要确认
# default - 智能判断
# dontAsk - 自动拒绝需要确认的操作
# auto - AI自动分类
SUPERAGENT_PERMISSION_MODE=default

# ========== 存储配置 ==========

SUPERAGENT_STORAGE_DISK=local
SUPERAGENT_STORAGE_PATH=superagent
```

### 6️⃣ 创建必要的目录

```bash
# 创建存储目录
mkdir -p storage/app/superagent/{snapshots,memories,tasks,cache}

# 设置权限
chmod -R 755 storage/app/superagent

# 如果使用Web服务器
chown -R www-data:www-data storage/app/superagent  # Ubuntu/Debian
chown -R nginx:nginx storage/app/superagent        # CentOS/RHEL
```

## 配置

### 主配置文件

编辑 `config/superagent.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 默认AI提供商
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    
    /*
    |--------------------------------------------------------------------------
    | AI提供商配置
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-4.6-haiku-latest'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
            'temperature' => env('ANTHROPIC_TEMPERATURE', 0.7),
            'timeout' => 60,
        ],
        
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5.4'),
            'organization' => env('OPENAI_ORG_ID'),
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 工具配置
    |--------------------------------------------------------------------------
    */
    'tools' => [
        // 启用的工具列表
        'enabled' => [
            \SuperAgent\Tools\Builtin\FileReadTool::class,
            \SuperAgent\Tools\Builtin\FileWriteTool::class,
            \SuperAgent\Tools\Builtin\FileEditTool::class,
            \SuperAgent\Tools\Builtin\BashTool::class,
            \SuperAgent\Tools\Builtin\WebSearchTool::class,
            \SuperAgent\Tools\Builtin\WebFetchTool::class,
        ],
        
        // 工具权限设置
        'permissions' => [
            'bash' => [
                'commands' => [
                    'allow' => ['ls', 'cat', 'grep', 'find'],
                    'deny' => ['rm -rf', 'sudo', 'chmod 777'],
                ],
            ],
            'file_write' => [
                'paths' => [
                    'deny' => ['.env', 'database.php', '/etc/*'],
                ],
            ],
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 上下文管理
    |--------------------------------------------------------------------------
    */
    'context' => [
        'max_tokens' => 100000,
        'auto_compact' => true,
        'compact_threshold' => 80000,
        'compact_strategy' => 'smart',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 缓存配置
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('SUPERAGENT_CACHE_ENABLED', true),
        'driver' => env('CACHE_DRIVER', 'file'),
        'ttl' => env('SUPERAGENT_CACHE_TTL', 3600),
    ],
    // Memory Palace（v0.8.5，默认开启）
    'palace' => [
        'enabled' => env('SUPERAGENT_PALACE_ENABLED', true),
        'base_path' => env('SUPERAGENT_PALACE_PATH'),          // 默认：{memory}/palace
        'default_wing' => env('SUPERAGENT_PALACE_DEFAULT_WING'),
        'vector' => [
            'enabled' => env('SUPERAGENT_PALACE_VECTOR_ENABLED', false),
            'embed_fn' => null,                                // 传入 callable(string): float[]
        ],
        'dedup' => [
            'enabled' => env('SUPERAGENT_PALACE_DEDUP_ENABLED', true),
            'threshold' => (float) env('SUPERAGENT_PALACE_DEDUP_THRESHOLD', 0.85),
        ],
        'scoring' => [
            'keyword' => 1.0,
            'vector'  => 2.0,
            'recency' => 0.5,
            'access'  => 0.3,
        ],
    ],
];
```

### 记忆宫殿（v0.8.5 新功能，默认开启）

受 MemPalace 启发的分层记忆系统（LongMemEval 96.6%）。通过现有 `MemoryProviderManager`
作为外部 Provider 插入，**不替换**内置的 `MEMORY.md` 流程。

```env
# 记忆宫殿总开关（默认：true）
SUPERAGENT_PALACE_ENABLED=true

# 可选：为 Wing 路由锁定默认 Wing
# SUPERAGENT_PALACE_DEFAULT_WING=wing_myproject

# 可选：向量评分（需要在运行时注入 embed_fn 回调）
# SUPERAGENT_PALACE_VECTOR_ENABLED=false

# 可选：调整近似去重阈值（5-gram Jaccard）
# SUPERAGENT_PALACE_DEDUP_THRESHOLD=0.85
```

磁盘存储布局：

```
{memory_path}/palace/
  identity.txt                         # L0 身份（~50 tok，始终加载）
  critical_facts.md                    # L1 关键事实（~120 tok）
  wings.json                           # Wing 注册表
  tunnels.json                         # 跨 Wing 链接
  wings/{wing_slug}/
    wing.json
    halls/{hall}/rooms/{room_slug}/
      room.json
      closet.json
      drawers/{drawer_id}.md           # 原始逐字内容
      drawers/{drawer_id}.emb          # 可选嵌入 sidecar
```

**Wake-Up CLI** —— 无需全量加载即可加载 L0+L1（~600–900 tok）：

```bash
php artisan superagent:wake-up
php artisan superagent:wake-up --wing=wing_myproject
php artisan superagent:wake-up --wing=wing_myproject --search="auth decisions"
php artisan superagent:wake-up --stats
```

**启用向量评分** —— 在运行时注入嵌入回调（例如在 `MemoryProviderManager`
构建之后的 Service Provider 中）：

```php
use SuperAgent\Memory\Palace\PalaceBundle;

$bundle = app(PalaceBundle::class);
// 提供你自己的嵌入函数：fn(string $text): float[]
// （例如封装 OpenAI 或本地嵌入端点）
```

**明确跳过的部分**：AAAK 方言 —— MemPalace 自己的 README 承认 AAAK 在
LongMemEval 上相对原始模式回退 12.4 分。SuperAgent 的 Palace 使用原始逐字存储
—— 这正是 96.6% 基准数字的来源 —— 不引入有损压缩层。

## 多智能体设置

### 自动模式配置（v0.6.7新功能）

启用自动多智能体编排：

```env
# 启用自动多智能体检测
SUPERAGENT_AUTO_MODE=true

# 最大并发智能体数
SUPERAGENT_MAX_CONCURRENT_AGENTS=10

# 智能体资源池
SUPERAGENT_AGENT_POOL_SIZE=20

# WebSocket监控
SUPERAGENT_WEBSOCKET_MONITORING=true
SUPERAGENT_WEBSOCKET_PORT=8080
```

### 多智能体协作管道配置（v0.8.2新功能）

```php
// 多智能体协作管道 (v0.8.2)
'task_routing' => [
    'enabled' => env('SUPERAGENT_TASK_ROUTING', true),
    'tier_models' => [
        1 => ['provider' => 'anthropic', 'model' => 'claude-opus-4'],   // 强力层
        2 => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4'], // 平衡层
        3 => ['provider' => 'anthropic', 'model' => 'claude-haiku-4'],  // 速度层
    ],
],
```

### 基本多智能体用法

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;

// 创建带自动模式的主智能体
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],
    'multi_agent' => [
        'auto_mode' => true,
        'max_concurrent' => 10,
    ],
]);

$agent = new Agent($provider, $config);
$agent->enableAutoMode();

// 智能体自动决定单体或多体模式
$result = $agent->run("复杂的多步骤任务...");
```

### 手动智能体团队配置

```php
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Swarm\ParallelAgentCoordinator;

// 配置智能体团队
$coordinator = ParallelAgentCoordinator::getInstance();
$coordinator->configure([
    'max_concurrent' => 10,
    'timeout' => 300, // 每个智能体5分钟
    'checkpoint_interval' => 60, // 每分钟保存状态
]);

// 创建专门的智能体
$agentTool = new AgentTool();

// 研究智能体
$researcher = $agentTool->execute([
    'description' => '研究',
    'prompt' => '研究最佳实践',
    'subagent_type' => 'researcher',
    'run_in_background' => true,
]);

// 代码编写智能体
$coder = $agentTool->execute([
    'description' => '实现',
    'prompt' => '编写实现代码',
    'subagent_type' => 'code-writer',
    'run_in_background' => true,
]);

// 监控进度
$status = $coordinator->getTeamStatus();
foreach ($status['agents'] as $agentId => $info) {
    echo "智能体 {$agentId}: {$info['status']} - {$info['progress']}%\n";
}
```

### 智能体邮箱系统

配置持久化智能体通信：

```php
// config/superagent.php
'mailbox' => [
    'enabled' => true,
    'storage' => 'redis', // 或 'database', 'file'
    'ttl' => 3600, // 消息TTL（秒）
    'max_messages' => 1000, // 每个智能体最大消息数
],
```

用法：

```php
use SuperAgent\Tools\Builtin\SendMessageTool;

$messageTool = new SendMessageTool();

// 直接消息
$messageTool->execute([
    'to' => 'agent-123',
    'message' => '优先级更新',
    'summary' => '更新',
]);

// 广播
$messageTool->execute([
    'to' => '*',
    'message' => '团队公告',
    'summary' => '公告',
]);
```

### WebSocket监控仪表板

启用实时监控：

```bash
# 启动WebSocket服务器
php artisan superagent:websocket

# 访问仪表板
open http://localhost:8080/superagent/monitor
```

仪表板功能：
- 实时智能体状态
- 每个智能体的Token使用量
- 成本聚合
- 进度可视化
- 消息队列监控

### 智能体角色配置

定义专门的智能体角色：

```php
// config/superagent.php
'agent_roles' => [
    'researcher' => [
        'model' => 'claude-3-haiku-20240307',
        'tools' => ['web_search', 'web_fetch'],
        'max_tokens' => 8192,
    ],
    'code-writer' => [
        'model' => 'claude-3-sonnet-20240229',
        'tools' => ['file_read', 'file_write', 'file_edit'],
        'max_tokens' => 16384,
    ],
    'reviewer' => [
        'model' => 'claude-3-opus-20240229',
        'tools' => ['file_read', 'grep'],
        'max_tokens' => 4096,
    ],
],
```

### 多智能体工作流的检查点与恢复

```php
// 启用检查点
$coordinator->enableCheckpoints([
    'interval' => 60, // 每60秒保存
    'storage' => 'database',
]);

// 失败后从检查点恢复
$coordinator->resumeFromCheckpoint($checkpointId);
```

### 资源池化与并发控制

```php
// 配置智能体池
use SuperAgent\Swarm\AgentPool;

$pool = new AgentPool([
    'max_agents' => 20,
    'max_concurrent' => 10,
    'queue_timeout' => 300,
]);

// 提交任务到池
$taskIds = [];
foreach ($tasks as $task) {
    $taskIds[] = $pool->submit($task);
}

// 等待完成
$results = $pool->waitAll($taskIds);
```

### 多智能体性能优化

```env
# 优化并行执行
SUPERAGENT_PARALLEL_CHUNK_SIZE=5
SUPERAGENT_PARALLEL_TIMEOUT=300
SUPERAGENT_PARALLEL_RETRY_COUNT=3

# 内存优化
SUPERAGENT_AGENT_MEMORY_LIMIT=256M
SUPERAGENT_SHARED_CONTEXT_CACHE=true

# 网络优化
SUPERAGENT_API_CONNECTION_POOL=50
SUPERAGENT_API_KEEPALIVE=true
```

## v0.7.9 升级说明

v0.7.9 是纯架构加固版本。**无需配置更改，完全向后兼容。**

主要变更：
- 19个单例类的 `getInstance()` 方法标记为 `@deprecated` — 现有代码继续正常工作，但新代码建议使用构造函数注入
- 14个内建工具类现使用 `ToolStateManager` 替代 `private static` 属性 — 在 Swarm 模式下注入共享实例以确保跨进程正确性
- `SessionManager` 分解为 `SessionStorage` + `SessionPruner` — 公开 API 不变
- `executeProcessParallel()` 现按 `$maxParallel`（默认 5）分批处理 — 此前无限制生成并发进程

```bash
composer update forgeomni/superagent
```

## v0.7.0 升级说明

v0.7.0 新增 13 项性能优化（5 项 token + 8 项执行）。**除 Batch API 外全部默认启用，无需修改配置。**

如需禁用某项优化，设置对应环境变量为 `false`：
```env
SUPERAGENT_OPT_TOOL_COMPACTION=false
SUPERAGENT_OPT_SELECTIVE_TOOLS=false
SUPERAGENT_OPT_MODEL_ROUTING=false
SUPERAGENT_OPT_RESPONSE_PREFILL=false
SUPERAGENT_OPT_CACHE_PINNING=false
```

路由用的快速模型默认为 `claude-haiku-4-5-20251001`，可通过 `SUPERAGENT_OPT_FAST_MODEL=模型ID` 覆盖。

```bash
composer update forgeomni/superagent
```

## v0.6.19 升级说明

v0.6.19 新增 `NdjsonStreamingHandler`，为 in-process agent 执行提供日志支持。**无需修改配置。**

此前仅子进程（通过 `agent-runner.php`）输出 NDJSON 日志。现在 in-process 的 `$agent->prompt()` 调用也可通过 `NdjsonStreamingHandler::create()` 或 `createWithWriter()` 写 CC 兼容 NDJSON 到日志文件，使其在进程监控中可见。

```bash
composer update forgeomni/superagent
```

## v0.6.18 升级说明

v0.6.18 将子 agent 日志从自定义协议升级为 Claude Code 兼容的 NDJSON 格式。**无需修改配置。**

子进程现在在 stderr 上输出标准 NDJSON 事件（`{"type":"assistant",...}`、`{"type":"result",...}`），替代 `__PROGRESS__:` 前缀协议。父进程的 `ProcessBackend` 自动检测两种格式，完全向后兼容。

```bash
composer update forgeomni/superagent
```

## v0.6.17 升级说明

v0.6.17 为子 agent 进程添加实时进度监控。**无需修改配置。**

此前子 agent 通过 `ProcessBackend` 在独立 OS 进程中运行时，进程监控无法显示其工作进度（使用的工具、token 计数等）。现在子进程通过 stderr 使用 `__PROGRESS__:` 协议发送结构化进度事件，父进程解析后注入 `AgentProgressTracker`——使子 agent 的活动在 `ParallelAgentDisplay` 和 WebSocket 仪表板中可见。

```bash
composer update forgeomni/superagent
```

## v0.6.16 升级说明

v0.6.16 确保子 agent 进程可访问父进程的所有 agent 定义和 MCP server 配置。**无需修改配置。**

此前子进程依赖 Laravel bootstrap 加载 `.claude/agents/` 自定义 agent 定义和 config 中的 MCP server。现在父进程直接序列化这些注册数据通过 stdin JSON 传递——子进程无论 Laravel 是否可用都能正常工作。

```bash
composer update forgeomni/superagent
```

## v0.6.15 升级说明

v0.6.15 添加了自动 MCP server 共享。**无需修改配置。**

当父 agent 连接 stdio MCP server（如 Valhalla）时，会自动启动 TCP 桥接。子 agent 通过桥接连接而非各自启动 MCP server 进程，消除 N 个子进程各自启动相同 Node.js/Python MCP server 的开销。

```bash
composer update forgeomni/superagent
```

## v0.6.12 升级说明

v0.6.12 修复了子 agent 进程无法访问 Laravel 服务、API 凭证和完整工具集的三个问题。**无需修改配置。**

如果你使用 `.claude/agents/` 中的自定义 agent 定义、`.claude/commands/` 中的自定义 skill 或通过 `config('superagent.mcp')` 配置的 MCP 服务器，这些现在在子 agent 进程中均可正常工作。

```bash
composer update forgeomni/superagent
```

## v0.6.11 升级说明

v0.6.11 替换了默认的子智能体执行后端。**无需修改配置** — 新行为自动生效。

**变更内容：** `AgentTool` 现在通过 `proc_open()` 在独立 OS 进程中执行每个子智能体，而非在同一进程中使用 PHP Fiber。实现真正并行 — 5 个并发智能体从 ~2500ms 降至 ~544ms。

**仅影响测试代码的破坏性变更：** 如果你的测试 mock 了 `InProcessBackend` 或依赖 Fiber 执行，可能需要更新。直接调用 `AgentTool` 的生产代码不受影响。

**前置条件：** `proc_open()` 必须可用（标准 PHP 安装默认可用）。如被禁用，`AgentTool` 自动降级到 `InProcessBackend`。

```bash
composer update forgeomni/superagent
```

## v0.6.10 升级说明

v0.6.10 是纯 Bug 修复版本，无需修改配置。如果你正在使用同步进程内智能体（`run_in_background: false` 搭配 `in-process` 后端），此更新修复了一个关键死锁问题——智能体 Fiber 从未启动，导致每次调用都会超时 5 分钟。

**仅影响测试代码的破坏性变更**：同步模式下 `AgentTool::execute()` 的返回结果现在使用 `'agentId'`（驼峰命名）和 `'status' => 'completed'`，而非此前永远无法到达的异步格式。如有相关测试断言，请相应更新。

```bash
composer update forgeomni/superagent
```

## v0.6.9 功能配置

### 带路径前缀的自定义 Base URL

各 Provider 现在可以正确处理带路径前缀的 `base_url`（如 API 网关、反向代理）：

```php
// 带自定义路径的 Anthropic 兼容网关
$agent = new Agent([
    'provider'  => 'anthropic',
    'api_key'   => env('ANTHROPIC_API_KEY'),
    'base_url'  => 'https://gateway.example.com/anthropic', // 路径前缀将被保留
    'model'     => 'claude-sonnet-4-6',
]);

// OpenAI 兼容代理
$agent = new Agent([
    'provider'  => 'openai',
    'api_key'   => env('OPENAI_API_KEY'),
    'base_url'  => 'https://proxy.example.com/openai',      // 路径前缀将被保留
    'model'     => 'gpt-4o',
]);

// 在子路径后运行的本地 Ollama
$agent = new Agent([
    'provider'  => 'ollama',
    'base_url'  => 'http://localhost:8080/ollama',          // 路径前缀将被保留
    'model'     => 'llama3',
]);
```

> **说明**：v0.6.8 及更早版本中，带路径前缀的 `base_url` 对 OpenAI、OpenRouter 和 Ollama Provider 是静默失效的 —— Guzzle 的 RFC 3986 解析器会在使用绝对请求路径（如 `/v1/chat/completions`）时丢弃路径前缀。四个 Provider 现已全部修复。

## v0.6.8 功能配置

### 增量上下文

```php
use SuperAgent\IncrementalContext\IncrementalContextManager;

$manager = new IncrementalContextManager([
    'auto_compress'       => true,
    'compress_threshold'  => 4000,   // 超过 N token 时压缩
    'auto_checkpoint'     => true,
    'checkpoint_interval' => 10,     // 每 N 条消息创建检查点
    'max_checkpoints'     => 10,
    'compression_level'   => 'balanced', // minimal | balanced | aggressive
]);

$manager->initialize($messages);
$delta  = $manager->getDelta();                     // 只获取变化
$full   = $manager->applyDelta($delta, $base);      // 重建完整上下文
$manager->restoreCheckpoint($checkpointId);         // 回滚到检查点
$window = $manager->getSmartWindow(maxTokens: 8000); // Token 预算窗口
```

### 懒加载上下文

```php
use SuperAgent\LazyContext\LazyContextManager;

$lazy = new LazyContextManager(['cache_ttl' => 600]);

// 注册片段（不加载内容）
$lazy->registerContext('system-rules', [
    'type' => 'system', 'priority' => 9,
    'tags' => ['rules'], 'size' => 200,
    'source' => '/path/to/rules.json', // 或可调用
]);

// 按任务按需加载
$context = $lazy->getContextForTask('重构 PHP 服务层');
$window  = $lazy->getSmartWindow(maxTokens: 12000, focusArea: 'php');
```

### 工具按需加载

```php
use SuperAgent\Tools\ToolLoader;

$loader = new ToolLoader(['lazy_load' => true]);
$tools  = $loader->loadForTask('搜索并编辑 PHP 文件');
$agent  = new Agent(['provider' => 'anthropic', 'tools' => $tools]);
```

### 无 Key 的 Web 搜索

`WebSearchTool` 在未设置 `SEARCH_API_KEY` 时自动降级到 DuckDuckGo HTML 搜索。
如需生产级搜索，配置任意一个：

```env
SEARCH_API_KEY=your_serper_key   # Serper（推荐）
SEARCH_ENGINE=serper

SEARCH_API_KEY=your_google_key   # Google Custom Search
SEARCH_ENGINE=google

SEARCH_API_KEY=your_bing_key     # Bing
SEARCH_ENGINE=bing
```

## 验证

### 1️⃣ 运行健康检查

创建健康检查脚本 `check-superagent.php`:

```php
<?php

require 'vendor/autoload.php';

$checks = [
    'PHP版本' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Laravel安装' => class_exists('Illuminate\Foundation\Application'),
    'SuperAgent安装' => class_exists('SuperAgent\Agent'),
    'JSON扩展' => extension_loaded('json'),
    'CURL扩展' => extension_loaded('curl'),
    'OpenSSL扩展' => extension_loaded('openssl'),
];

echo "SuperAgent 安装检查\n";
echo "==================\n\n";

$allPassed = true;
foreach ($checks as $name => $result) {
    $status = $result ? '✅' : '❌';
    echo "$status $name\n";
    if (!$result) $allPassed = false;
}

if ($allPassed) {
    echo "\n🎉 所有检查通过！SuperAgent 已准备就绪。\n";
} else {
    echo "\n⚠️ 某些检查失败，请解决上述问题。\n";
    exit(1);
}
```

运行检查：
```bash
php check-superagent.php
```

### 2️⃣ 测试基本功能

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// 测试基本查询
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-haiku-20240307',
    ],
]);

$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

$response = $agent->query("说 '安装成功！'");
echo $response->content;
```

### 3️⃣ 测试CLI工具

```bash
# 列出可用工具
php artisan superagent:tools

# 测试聊天功能
php artisan superagent:chat

# 执行简单查询
php artisan superagent:run --prompt="2+2等于多少？"
```

## 故障排除

### ❓ Composer安装失败

**错误信息**:
```
Your requirements could not be resolved to an installable set of packages
```

**解决方案**:
```bash
# 清除缓存
composer clear-cache

# 更新依赖
composer update --with-dependencies

# 使用国内镜像（中国用户）
composer config repo.packagist composer https://mirrors.aliyun.com/composer/
```

### ❓ 服务提供者未找到

**错误信息**:
```
Class 'SuperAgent\SuperAgentServiceProvider' not found
```

**解决方案**:
```bash
# 重新生成自动加载
composer dump-autoload

# 清除Laravel缓存
php artisan optimize:clear
```

### ❓ API密钥无效

**错误信息**:
```
Invalid API key provided
```

**解决方案**:
1. 检查 `.env` 文件中的API密钥是否正确
2. 确保密钥周围没有额外的空格或引号
3. 验证密钥已激活且未过期
4. 清除配置缓存：`php artisan config:clear`

### ❓ 内存耗尽

**错误信息**:
```
Allowed memory size of X bytes exhausted
```

**解决方案**:

编辑 `php.ini`:
```ini
memory_limit = 512M
```

或在代码中临时设置：
```php
ini_set('memory_limit', '512M');
```

### ❓ 权限被拒绝

**错误信息**:
```
Permission denied
```

**解决方案**:
```bash
# 设置正确的权限
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# 设置所有者（根据系统调整）
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

## 升级指南

### 从0.x升级到1.0

```bash
# 1. 备份现有数据
php artisan backup:run

# 2. 更新依赖
composer update forgeomni/superagent

# 3. 运行新的迁移
php artisan migrate

# 4. 更新配置文件
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config" --force

# 5. 清除所有缓存
php artisan optimize:clear
```

### 版本兼容性矩阵

| SuperAgent | Laravel | PHP   | 说明 |
|------------|---------|-------|------|
| 0.8.0      | 10.x+   | 8.1+ | 19项改进：SQLite+FTS5 会话存储（含加密）、统一上下文压缩、Prompt 注入检测（集成 prompt builder）、凭证池（集成 provider registry）、查询复杂度路由、路径级写冲突检测、Memory Provider（向量+情景）、SecurityCheckChain、Skill 渐进式披露、安全流写入、批量 FileSnapshot I/O、内存限制、ReplayStore 验证、PromptHook 消毒、架构图。18项测试修复。1687 测试，0 失败 |
| 0.7.8      | 10.x+   | 8.1+  | Agent Harness 模式 + 企业级子系统：持久化任务与会话、StreamEvent 统一事件、REPL 交互循环、自动压缩器、E2E 场景框架、API 重试中间件、iTerm2 后端、插件系统、可观察应用状态、Hook 热重载、Prompt/Agent Hook、多通道网关、后端协议、OAuth 设备码流程、权限路径规则、协调器任务通知。628 个新测试 |
| 0.7.7      | 10.x+   | 8.1+  | 可调试性加固：27个吞没异常添加日志、Agent核心单元测试（31个测试）、docs/REVIEW.md代码审查框架 |
| 0.7.6      | 10.x+   | 8.1+  | 6大创新子系统：Agent Replay时间旅行调试、对话分叉、Agent辩论协议、成本预测引擎、自然语言护栏、自愈流水线 |
| 0.7.5      | 10.x+   | 8.1+  | Claude Code 工具名兼容：双向 ToolNameResolver，agent 定义和权限系统自动解析 |
| 0.7.2      | 10.x+   | 8.1+  | 修复 .claude/ 路径解析：AgentManager/SkillManager/MCPManager 使用项目根目录而非 cwd |
| 0.7.1      | 10.x+   | 8.1+  | 修复 AgentTool PermissionMode 'bypass' 枚举不匹配 |
| 0.7.0      | 10.x+   | 8.1+  | 13 项性能优化：token 压缩、按需工具、模型路由、预填充、缓存固定 + 并行工具、流式分发、连接池、预读、自适应 tokens、批量 API、零拷贝：工具结果压缩、按需工具 Schema、模型路由、响应预填充、提示缓存固定 |
| 0.6.19     | 10.x+   | 8.1+  | In-process NDJSON 日志（`NdjsonStreamingHandler`）支持进程监控 |
| 0.6.18     | 10.x+   | 8.1+  | Claude Code 兼容 NDJSON 结构化日志替代 `__PROGRESS__:` 协议 |
| 0.6.17     | 10.x+   | 8.1+  | 子 agent 进程实时进度监控（`__PROGRESS__:` stderr 协议） |
| 0.6.16     | 10.x+   | 8.1+  | 父进程 agent/MCP 注册数据透传子进程（stdin 序列化） |
| 0.6.15     | 10.x+   | 8.1+  | MCP Server TCP 桥接共享 — N 个子 agent 共享 1 个 MCP server 进程 |
| 0.6.12     | 10.x+   | 8.1+  | 子进程 Laravel 引导、Provider 序列化修复、子 agent 完整工具集 |
| 0.6.11     | 10.x+   | 8.1+  | 真正的进程级并行子智能体（proc_open 替代 Fiber），4.6x 加速 |
| 0.6.10     | 10.x+   | 8.1+  | 多智能体同步执行修复（Fiber 死锁、后端类型不匹配、进度追踪器） |
| 0.6.9      | 10.x+   | 8.1+  | Guzzle Base URL 路径修复（OpenAI / OpenRouter / Ollama Provider） |
| 0.6.8      | 10.x+   | 8.1+  | 增量上下文、懒加载上下文与工具、子智能体 Provider 继承、WebSearch 无 Key 降级、WebFetch 加固 |
| 0.6.7      | 10.x+   | 8.1+  | 多智能体并行追踪与自动模式 |
| 0.6.6      | 10.x+   | 8.1+  | 智能上下文窗口（888个测试） |
| 0.6.5      | 10.x+   | 8.1+  | 技能蒸馏、检查点与恢复、知识图谱（865个测试） |
| 0.6.2      | 10.x+   | 8.1+  | Pipeline DSL（带审查修复循环）、成本自动驾驶、自适应反馈（776个测试） |
| 0.6.1      | 10.x+   | 8.1+  | Guardrails DSL（644个测试） |
| 0.6.0      | 10.x+   | 8.1+  | Bridge模式 |
| 0.5.7      | 10.x+   | 8.1+  | 遥测主开关、安全防护、实验性功能标志（452个测试） |

## 生产部署

### 性能优化

```bash
# 缓存配置
php artisan config:cache

# 缓存路由
php artisan route:cache

# 优化自动加载
composer install --optimize-autoloader --no-dev
```

### 配置队列

创建Supervisor配置 `/etc/supervisor/conf.d/superagent.conf`:

```ini
[program:superagent-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --queue=superagent
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/superagent-worker.log
```

### 配置Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/public;
    
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # 流式响应支持
    location /superagent/stream {
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 3600;
    }
}
```

## 获取帮助

### 📚 资源

- 📖 [官方文档](https://superagent-docs.example.com)
- 💬 [社区论坛](https://forum.superagent.dev)
- 🐛 [问题跟踪器](https://github.com/yourusername/superagent/issues)
- 📺 [视频教程](https://youtube.com/@superagent)

### 💼 技术支持

- 社区支持：[GitHub Discussions](https://github.com/yourusername/superagent/discussions)
- 电子邮件支持：mliz1984@gmail.com
- Discord服务器：[加入我们的社区](https://discord.gg/superagent)

### 🔍 调试提示

启用调试模式：
```env
SUPERAGENT_DEBUG=true
APP_DEBUG=true
```

查看日志：
```bash
# Laravel日志
tail -f storage/logs/laravel.log

# SuperAgent专用日志
tail -f storage/logs/superagent.log

# 实时调试
php artisan tinker
```

---

© 2024-2026 SuperAgent. 保留所有权利。