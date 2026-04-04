# SuperAgent 安装手册

> **🌍 语言**: [English](INSTALL.md) | [中文](INSTALL.zh-CN.md)  
> **📖 文档**: [README](README.md) | [README 中文](README.zh-CN.md)

## 目录
- [系统要求](#系统要求)
- [安装步骤](#安装步骤)
- [配置说明](#配置说明)
- [验证安装](#验证安装)
- [常见问题](#常见问题)
- [升级指南](#升级指南)

## 系统要求

### 最低配置
- **PHP**: 8.1 或更高版本
- **Laravel**: 10.0 或更高版本
- **Composer**: 2.0 或更高版本
- **内存**: 至少 256MB PHP 内存限制
- **磁盘空间**: 至少 100MB 可用空间

### 必需的 PHP 扩展
```bash
# 核心扩展
- json        # JSON 处理
- mbstring    # 多字节字符串
- openssl     # 加密功能
- curl        # HTTP 请求
- fileinfo    # 文件信息
```

### 可选的 PHP 扩展
```bash
# 增强功能
- redis       # Redis 缓存支持
- pcntl       # 进程控制（多 Agent 协作）
- yaml        # YAML 配置文件
- zip         # 文件压缩
```

### 检查环境

```bash
# 检查 PHP 版本
php -v

# 检查已安装的扩展
php -m

# 检查 Laravel 版本  
php artisan --version

# 检查 Composer 版本
composer --version
```

## 安装步骤

### 1️⃣ 使用 Composer 安装

#### 标准安装（推荐）
```bash
composer require forgeomni/superagent
```

#### 安装开发版本
```bash
composer require forgeomni/superagent:dev-main
```

#### 安装指定版本
```bash
composer require forgeomni/superagent:^1.0
```

### 2️⃣ 注册服务提供者

Laravel 10+ 会自动注册。如需手动注册，编辑 `config/app.php`：

```php
'providers' => [
    // 其他服务提供者...
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

# 或者分别发布
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config"
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="migrations"
```

### 4️⃣ 运行数据库迁移

如果使用记忆系统和任务管理功能：

```bash
php artisan migrate
```

### 5️⃣ 配置环境变量

编辑 `.env` 文件，添加必要的配置：

```env
# ========== SuperAgent 基础配置 ==========

# 默认 AI 提供商 (anthropic|openai|bedrock|ollama)
SUPERAGENT_PROVIDER=anthropic

# Anthropic Claude 配置
ANTHROPIC_API_KEY=sk-ant-xxxxxxxxxxxxx
ANTHROPIC_MODEL=claude-3-haiku-20240307
ANTHROPIC_MAX_TOKENS=4096
ANTHROPIC_TEMPERATURE=0.7

# OpenAI 配置（可选）
OPENAI_API_KEY=sk-xxxxxxxxxxxxx
OPENAI_MODEL=gpt-4
OPENAI_ORG_ID=org-xxxxxxxxxxxxx

# AWS Bedrock 配置（可选）
AWS_ACCESS_KEY_ID=AKIAXXXXXXXXXXXXX
AWS_SECRET_ACCESS_KEY=xxxxxxxxxxxxx
AWS_DEFAULT_REGION=us-east-1
BEDROCK_MODEL=anthropic.claude-v2

# 本地模型 Ollama（可选）
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

# 可观测性（主开关 — 关闭时所有子系统不采集数据）
SUPERAGENT_TELEMETRY_ENABLED=false
SUPERAGENT_TELEMETRY_LOGGING=false
SUPERAGENT_TELEMETRY_METRICS=false
SUPERAGENT_TELEMETRY_EVENTS=false
SUPERAGENT_TELEMETRY_COST_TRACKING=false

# 安全 Prompt 护栏
SUPERAGENT_SECURITY_GUARDRAILS=false

# Guardrails DSL（声明式 YAML 规则引擎）
SUPERAGENT_GUARDRAILS_ENABLED=false
# SUPERAGENT_GUARDRAILS_INTEGRATION=permission_engine

# Pipeline DSL（声明式 YAML 多 Agent 工作流引擎）
SUPERAGENT_PIPELINES_ENABLED=false

# Cost Autopilot（自动模型降级和预算控制）
SUPERAGENT_COST_AUTOPILOT_ENABLED=false
# SUPERAGENT_SESSION_BUDGET=0
# SUPERAGENT_MONTHLY_BUDGET=0

# 自适应反馈（从用户修正中学习）
SUPERAGENT_ADAPTIVE_FEEDBACK_ENABLED=false
# SUPERAGENT_FEEDBACK_THRESHOLD=3
# SUPERAGENT_FEEDBACK_AUTO_PROMOTE=true

# 技能蒸馏（将昂贵执行自动蒸馏为可复用技能）
SUPERAGENT_SKILL_DISTILLATION_ENABLED=false
# SUPERAGENT_DISTILL_MIN_STEPS=3
# SUPERAGENT_DISTILL_MIN_COST=0.01

# 知识图谱（跨 Agent 共享知识）
SUPERAGENT_KNOWLEDGE_GRAPH_ENABLED=false

# 智能上下文窗口（动态思考/上下文分配）
SUPERAGENT_SMART_CONTEXT_ENABLED=false
# SUPERAGENT_SMART_CONTEXT_BUDGET=100000
# SUPERAGENT_SMART_CONTEXT_MIN_THINKING=5000
# SUPERAGENT_SMART_CONTEXT_MAX_THINKING=128000

# 断点续跑（周期性状态快照用于崩溃恢复）
SUPERAGENT_CHECKPOINT_ENABLED=false
# SUPERAGENT_CHECKPOINT_INTERVAL=5
# SUPERAGENT_CHECKPOINT_MAX=5

# 实验性功能（总开关 — 为 true 时所有 flag 默认启用）
SUPERAGENT_EXPERIMENTAL=true
# SUPERAGENT_EXP_ULTRATHINK=true
# SUPERAGENT_EXP_TOKEN_BUDGET=true
# SUPERAGENT_EXP_PROMPT_CACHE=true
# SUPERAGENT_EXP_BUILTIN_AGENTS=true
# SUPERAGENT_EXP_VERIFICATION_AGENT=true
# SUPERAGENT_EXP_PLAN_INTERVIEW=true
# SUPERAGENT_EXP_AGENT_TRIGGERS=true
# SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE=true
# SUPERAGENT_EXP_EXTRACT_MEMORIES=true
# SUPERAGENT_EXP_COMPACTION_REMINDERS=true
# SUPERAGENT_EXP_CACHED_MICROCOMPACT=true
# SUPERAGENT_EXP_TEAM_MEMORY=true
# SUPERAGENT_EXP_BASH_CLASSIFIER=true
# SUPERAGENT_EXP_PIPELINES=false
# SUPERAGENT_EXP_COST_AUTOPILOT=false
# SUPERAGENT_EXP_ADAPTIVE_FEEDBACK=false
# SUPERAGENT_EXP_SKILL_DISTILLATION=false
# SUPERAGENT_EXP_CHECKPOINT=false
# SUPERAGENT_EXP_KNOWLEDGE_GRAPH=false
# SUPERAGENT_EXP_SMART_CONTEXT=false

# ========== 权限配置 ==========

# 权限模式
# bypass - 跳过所有权限检查
# acceptEdits - 自动批准文件编辑
# plan - 所有操作需确认
# default - 智能判断
# dontAsk - 自动拒绝需确认的操作
# auto - AI 自动分类
SUPERAGENT_PERMISSION_MODE=default

# ========== 存储配置 ==========

SUPERAGENT_STORAGE_DISK=local
SUPERAGENT_STORAGE_PATH=superagent
```

### 6️⃣ 创建必要目录

```bash
# 创建存储目录
mkdir -p storage/app/superagent/{snapshots,memories,tasks,cache}

# 设置权限
chmod -R 755 storage/app/superagent

# 如果使用 Web 服务器
chown -R www-data:www-data storage/app/superagent  # Ubuntu/Debian
chown -R nginx:nginx storage/app/superagent        # CentOS/RHEL
```

## 配置说明

### 主配置文件

编辑 `config/superagent.php`：

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 默认 AI 提供商
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    
    /*
    |--------------------------------------------------------------------------
    | AI 提供商配置
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-haiku-20240307'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
            'temperature' => env('ANTHROPIC_TEMPERATURE', 0.7),
            'timeout' => 60,
        ],
        
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4'),
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
];
```

### 工具自动发现

创建自定义工具目录：

```bash
# 创建目录结构
mkdir -p app/SuperAgent/{Tools,Skills,Plugins,Agents}

# 工具会自动被发现和注册
```

### Skill、Agent 和 MCP 自动载入

Skills、Agents 和 MCP 服务可以通过 `load_claude_code` 从 Claude Code 目录自动载入，也可以从自定义路径载入。在 `config/superagent.php` 中配置：

```php
// config/superagent.php
'skills' => [
    'load_claude_code' => false,                // .claude/commands/ 和 .claude/skills/
    'paths' => [],                              // 额外的目录
],
'agents' => [
    'load_claude_code' => false,                // .claude/agents/
    'paths' => [],                              // 额外的目录
],
'mcp' => [
    'load_claude_code' => false,                // .mcp.json 和 ~/.claude.json
    'paths' => [],                              // 额外的 JSON 配置文件
],
```

所有目录路径递归扫描，不存在的路径自动跳过。Skills 和 agents 同时支持 PHP（`.php`）和 Markdown（`.md`）文件。PHP 文件不限制命名空间。Markdown 文件使用 YAML frontmatter 存放元数据（name、description、allowed_tools 等），正文作为 prompt 模板 — `$ARGUMENTS`、`$LANGUAGE` 等占位符由 LLM 理解，程序不做替换。MCP 配置文件同时支持 Claude Code 格式（`mcpServers`）和 SuperAgent 格式（`servers`），支持 `${VAR}` 和 `${VAR:-default}` 环境变量展开。

### Extended Thinking（扩展思考）

为复杂任务启用深度推理：

```php
use SuperAgent\Thinking\ThinkingConfig;

// 自适应思考（模型自行决定何时思考）
$agent = new Agent([
    'options' => ['thinking' => ThinkingConfig::adaptive()],
]);

// 固定预算思考
$agent = new Agent([
    'options' => ['thinking' => ThinkingConfig::enabled(budgetTokens: 20000)],
]);

// 在用户消息中包含 "ultrathink" 关键词可自动提升到最大预算
```

通过环境变量设置：`MAX_THINKING_TOKENS=20000`

### Coordinator 模式

为复杂多 Agent 编排启用双模式架构：

```env
# 启用 Coordinator 模式
CLAUDE_CODE_COORDINATOR_MODE=1
```

Coordinator 仅有 Agent/SendMessage/TaskStop 三个工具，将所有工作委派给隔离的 Worker Agent。

### Batch 技能

使用 `/batch` 并行化大规模变更：

```bash
# 在 Agent CLI 中
/batch migrate from react to vue
/batch replace all uses of lodash with native equivalents
```

需要在 git 仓库中运行。会启动 5-30 个 worktree 隔离的 Agent，各自提 PR。

### 远程 Agent 任务

配置进程外 Agent 执行和 cron 调度：

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiBaseUrl: 'https://api.anthropic.com',
    apiKey: env('ANTHROPIC_API_KEY'),
);

$manager->create(
    name: 'nightly-review',
    prompt: '审查今天合并的所有 PR',
    cronExpression: '0 2 * * *', // 每天 UTC 凌晨 2 点
    gitRepoUrl: 'https://github.com/org/repo',
);
```

### 遥测主开关

所有遥测子系统（追踪、日志、指标、事件、成本追踪）受主开关控制。当 `telemetry.enabled` 为 `false` 时，无论子系统设置如何，不采集任何数据：

```php
// config/superagent.php
'telemetry' => [
    'enabled' => env('SUPERAGENT_TELEMETRY_ENABLED', false),
    'logging'       => ['enabled' => env('SUPERAGENT_TELEMETRY_LOGGING', false)],
    'metrics'       => ['enabled' => env('SUPERAGENT_TELEMETRY_METRICS', false)],
    'events'        => ['enabled' => env('SUPERAGENT_TELEMETRY_EVENTS', false)],
    'cost_tracking' => ['enabled' => env('SUPERAGENT_TELEMETRY_COST_TRACKING', false)],
],
```

### 安全 Prompt 护栏

启用后，额外的安全指令会注入 System Prompt，限制安全相关操作。禁用时仅依赖模型自身的安全训练：

```php
// config/superagent.php
'security_guardrails' => env('SUPERAGENT_SECURITY_GUARDRAILS', false),
```

### 实验性 Feature Flags

22 个细粒度 feature flag 独立控制实验性功能。部分工具、Agent 和行为受这些 flag 控制：

```php
// config/superagent.php
'experimental' => [
    'enabled' => env('SUPERAGENT_EXPERIMENTAL', true),

    'ultrathink' => env('SUPERAGENT_EXP_ULTRATHINK', true),
    'token_budget' => env('SUPERAGENT_EXP_TOKEN_BUDGET', true),
    'prompt_cache_break_detection' => env('SUPERAGENT_EXP_PROMPT_CACHE', true),
    'builtin_agents' => env('SUPERAGENT_EXP_BUILTIN_AGENTS', true),
    'verification_agent' => env('SUPERAGENT_EXP_VERIFICATION_AGENT', true),
    'plan_interview' => env('SUPERAGENT_EXP_PLAN_INTERVIEW', true),
    'agent_triggers' => env('SUPERAGENT_EXP_AGENT_TRIGGERS', true),
    'agent_triggers_remote' => env('SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE', true),
    'extract_memories' => env('SUPERAGENT_EXP_EXTRACT_MEMORIES', true),
    'compaction_reminders' => env('SUPERAGENT_EXP_COMPACTION_REMINDERS', true),
    'cached_microcompact' => env('SUPERAGENT_EXP_CACHED_MICROCOMPACT', true),
    'team_memory' => env('SUPERAGENT_EXP_TEAM_MEMORY', true),
    'bash_classifier' => env('SUPERAGENT_EXP_BASH_CLASSIFIER', true),
    'bridge_mode' => env('SUPERAGENT_EXP_BRIDGE_MODE', false),  // 增强非 Anthropic 模型
    'pipelines' => env('SUPERAGENT_EXP_PIPELINES', false),     // Pipeline DSL 工作流引擎
    'cost_autopilot' => env('SUPERAGENT_EXP_COST_AUTOPILOT', false), // 自动预算控制
    'adaptive_feedback' => env('SUPERAGENT_EXP_ADAPTIVE_FEEDBACK', false), // 从修正中学习
    'skill_distillation' => env('SUPERAGENT_EXP_SKILL_DISTILLATION', false), // 技能蒸馏
    'checkpoint' => env('SUPERAGENT_EXP_CHECKPOINT', false),                 // 断点续跑
    'knowledge_graph' => env('SUPERAGENT_EXP_KNOWLEDGE_GRAPH', false),       // 跨 Agent 知识图谱
    'smart_context' => env('SUPERAGENT_EXP_SMART_CONTEXT', false),           // 动态思考/上下文分配
],
```

**受控组件对照表：**

| Flag | 受控组件 |
|------|---------|
| `builtin_agents` | ExploreAgent、PlanAgent 注册 |
| `verification_agent` | VerificationAgent 注册 |
| `agent_triggers` | `schedule_cron` 工具 |
| `agent_triggers_remote` | `remote_trigger` 工具 |
| `team_memory` | `team_create`、`team_delete` 工具 |
| `ultrathink` | ultrathink 关键词提升行为 |
| `token_budget` | QueryEngine 中的 Token 预算追踪 |
| `prompt_cache_break_detection` | AnthropicProvider 自动 Prompt 缓存 |
| `bash_classifier` | 分类器辅助 bash 权限决策 |
| `plan_interview` | Plan V2 面试阶段工作流 |
| `extract_memories` | CompressionConfig 会话记忆提取默认值 |
| `compaction_reminders` | CompressionConfig 自动压缩默认值 |
| `cached_microcompact` | CompressionConfig 微压缩默认值 |
| `bridge_mode` | 非 Anthropic Provider 的 Bridge 增强 |
| `pipelines` | PipelineEngine 注册和 YAML 加载 |
| `cost_autopilot` | CostAutopilot 注册和预算追踪 |
| `adaptive_feedback` | FeedbackManager 注册和修正追踪 |
| `skill_distillation` | DistillationManager 注册和技能生成 |
| `checkpoint` | CheckpointManager 注册和状态快照 |
| `knowledge_graph` | KnowledgeGraphManager 注册和图谱追踪 |
| `smart_context` | SmartContextManager 注册和动态分配 |

### Bridge 模式配置

启用 `bridge_mode` 后，非 Anthropic Provider 会自动被 CC 优化机制增强。Anthropic/Claude 永远不会被包装——它原生已有这些优化。

```php
// config/superagent.php
'bridge' => [
    'auto_enhance' => env('SUPERAGENT_BRIDGE_AUTO_ENHANCE', null), // null = 使用 bridge_mode flag
    'provider' => env('SUPERAGENT_BRIDGE_PROVIDER', 'openai'),
    'api_keys' => array_filter(explode(',', env('SUPERAGENT_BRIDGE_API_KEYS', ''))),
    'max_tokens' => (int) env('SUPERAGENT_BRIDGE_MAX_TOKENS', 16384),
    'enhancers' => [
        'system_prompt'      => env('SUPERAGENT_BRIDGE_ENH_SYSTEM_PROMPT', true),
        'context_compaction' => env('SUPERAGENT_BRIDGE_ENH_COMPACTION', true),
        'bash_security'      => env('SUPERAGENT_BRIDGE_ENH_BASH_SECURITY', true),
        'memory_injection'   => env('SUPERAGENT_BRIDGE_ENH_MEMORY', false),
        'tool_schema'        => env('SUPERAGENT_BRIDGE_ENH_TOOL_SCHEMA', true),
        'tool_summary'       => env('SUPERAGENT_BRIDGE_ENH_TOOL_SUMMARY', false),
        'token_budget'       => env('SUPERAGENT_BRIDGE_ENH_TOKEN_BUDGET', false),
        'cost_tracking'      => env('SUPERAGENT_BRIDGE_ENH_COST_TRACKING', true),
    ],
],
```

**优先级控制**（从高到低）：
1. 实例级：`new Agent(['provider' => 'openai', 'bridge_mode' => true])`
2. 配置文件：`SUPERAGENT_BRIDGE_AUTO_ENHANCE=true`
3. Feature flag：`SUPERAGENT_EXP_BRIDGE_MODE=true`
4. 默认：关闭

`ExperimentalFeatures` 类在 Laravel 应用外运行时（如单元测试）会回退到环境变量。

### 分析采样率控制

按事件类型配置采样率：

```php
use SuperAgent\Telemetry\EventSampler;

$sampler = new EventSampler([
    'api_query' => ['sample_rate' => 0.1],     // 记录 10% 的 API 查询
    'tool_execution' => ['sample_rate' => 0.5], // 记录 50% 的工具执行
]);

$tracingManager->setEventSampler($sampler);
```

### Prompt 缓存

为 Anthropic Provider 启用 Prompt 缓存以降低 Token 成本。`SystemPromptBuilder` 使用缓存边界标记将 System Prompt 拆分为可缓存的静态前缀和会话特定的动态后缀：

```php
use SuperAgent\Prompt\SystemPromptBuilder;

$prompt = SystemPromptBuilder::create()
    ->withTools($toolNames)
    ->withMcpInstructions($mcpManager)
    ->withMemory($memory)
    ->build();

// 传入 Agent 并启用 prompt_caching
$agent = new Agent([
    'api_key' => env('ANTHROPIC_API_KEY'),
    'system_prompt' => $prompt,
    'options' => ['prompt_caching' => true],
]);
```

## 验证安装

### 1️⃣ 运行健康检查

创建健康检查脚本 `check-superagent.php`：

```php
<?php

require 'vendor/autoload.php';

$checks = [
    'PHP 版本' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Laravel 安装' => class_exists('Illuminate\Foundation\Application'),
    'SuperAgent 安装' => class_exists('SuperAgent\Agent'),
    'JSON 扩展' => extension_loaded('json'),
    'CURL 扩展' => extension_loaded('curl'),
    'OpenSSL 扩展' => extension_loaded('openssl'),
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
    echo "\n⚠️ 部分检查失败，请解决上述问题后重试。\n";
    exit(1);
}
```

运行检查：
```bash
php check-superagent.php
```

### 2️⃣ 测试基础功能

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// 测试基础查询
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-haiku-20240307',
    ],
]);

$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

$response = $agent->query("说'安装成功！'");
echo $response->content;
```

### 3️⃣ 测试命令行工具

```bash
# 列出可用工具
php artisan superagent:tools

# 测试聊天功能
php artisan superagent:chat

# 执行简单查询
php artisan superagent:run --prompt="2+2等于几？"
```

## 常见问题

### ❓ Composer 安装失败

**错误信息**：
```
Your requirements could not be resolved to an installable set of packages
```

**解决方案**：
```bash
# 清除缓存
composer clear-cache

# 更新依赖
composer update --with-dependencies

# 使用国内镜像（中国用户）
composer config repo.packagist composer https://mirrors.aliyun.com/composer/
```

### ❓ 找不到服务提供者

**错误信息**：
```
Class 'SuperAgent\SuperAgentServiceProvider' not found
```

**解决方案**：
```bash
# 重新生成自动加载
composer dump-autoload

# 清除 Laravel 缓存
php artisan optimize:clear
```

### ❓ API 密钥无效

**错误信息**：
```
Invalid API key provided
```

**解决方案**：
1. 检查 `.env` 文件中的 API 密钥是否正确
2. 确保密钥没有多余的空格或引号
3. 验证密钥是否已激活且未过期
4. 清除配置缓存：`php artisan config:clear`

### ❓ 内存不足

**错误信息**：
```
Allowed memory size of X bytes exhausted
```

**解决方案**：

编辑 `php.ini`：
```ini
memory_limit = 512M
```

或在代码中临时设置：
```php
ini_set('memory_limit', '512M');
```

### ❓ 权限错误

**错误信息**：
```
Permission denied
```

**解决方案**：
```bash
# 设置正确的权限
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# 设置所有者（根据系统调整）
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

## 升级指南

### 从 0.x 升级到 1.0

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

### 版本兼容性对照表

| SuperAgent | Laravel | PHP   | 说明 |
|------------|---------|-------|------|
| 0.6.12     | 10.x+   | 8.1+ | 子进程 Laravel 引导、Provider 序列化修复、子 agent 完整工具集 |
| 0.6.11     | 10.x+   | 8.1+ | 真正的进程级并行子智能体（proc_open 替代 Fiber），4.6x 加速 |
| 0.6.10     | 10.x+   | 8.1+ | 多智能体同步执行修复（Fiber 死锁、后端类型不匹配） |
| 0.6.9      | 10.x+   | 8.1+ | Guzzle Base URL 路径修复（OpenAI / OpenRouter / Ollama Provider） |
| 0.6.8      | 10.x+   | 8.1+ | 增量上下文、懒加载上下文与工具、子智能体 Provider 继承、WebSearch 无 Key 降级、WebFetch 加固 |
| 0.6.7      | 10.x+   | 8.1+ | 多智能体并行追踪与自动模式 |
| 0.6.6      | 10.x+   | 8.1+ | 智能上下文窗口（888 项测试） |
| 0.6.5      | 10.x+   | 8.1+ | 技能蒸馏、断点续跑、知识图谱（865 项测试） |
| 0.6.2      | 10.x+   | 8.1+ | Pipeline DSL（含 Review-Fix 循环）、Cost Autopilot、自适应反馈（776 项测试） |
| 0.6.1      | 10.x+   | 8.1+ | Guardrails DSL（644 项测试） |
| 0.6.0      | 10.x+   | 8.1+ | Bridge 模式 |
| 0.5.7      | 10.x+   | 8.1+ | 遥测主开关、安全护栏、实验性 feature flags（452 项测试） |

## 生产环境部署

### 优化性能

```bash
# 缓存配置
php artisan config:cache

# 缓存路由
php artisan route:cache

# 优化自动加载
composer install --optimize-autoloader --no-dev
```

### 配置队列

创建 Supervisor 配置文件 `/etc/supervisor/conf.d/superagent.conf`：

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

### 配置 Nginx

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

### 📚 资源链接

- 📖 [官方文档](https://superagent-docs.example.com)
- 💬 [社区论坛](https://forum.superagent.dev)
- 🐛 [问题反馈](https://github.com/yourusername/superagent/issues)
- 📺 [视频教程](https://youtube.com/@superagent)

### 💼 技术支持

- 社区支持：[GitHub Discussions](https://github.com/yourusername/superagent/discussions)
- 邮件支持：mliz1984@gmail.com
- 微信群：扫码加入技术交流群

### 🔍 调试技巧

启用调试模式：
```env
SUPERAGENT_DEBUG=true
APP_DEBUG=true
```

查看日志：
```bash
# Laravel 日志
tail -f storage/logs/laravel.log

# SuperAgent 专用日志
tail -f storage/logs/superagent.log

# 实时调试
php artisan tinker
```

## 📚 文档导航

### 语言版本
- 🇺🇸 [English Installation Guide](INSTALL.md)
- 🇨🇳 [中文安装手册](INSTALL.zh-CN.md)

### 主要文档
- 📖 [English README](README.md)
- 📖 [中文 README](README.zh-CN.md)

### 其他资源
- 🤝 [贡献指南](CONTRIBUTING.md)
- 📄 [许可证](LICENSE)

---

© 2024-2026 SuperAgent. 保留所有权利。