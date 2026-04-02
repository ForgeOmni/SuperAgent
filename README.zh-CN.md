# SuperAgent - Laravel 多模型 AI Agent SDK

[![PHP 版本](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Laravel 版本](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![许可证](https://img.shields.io/badge/license-MIT-green)](LICENSE)

> **🌍 语言**: [English](README.md) | [中文](README.zh-CN.md)  
> **📖 文档**: [Installation Guide](INSTALL.md) | [安装手册](INSTALL.zh-CN.md)

SuperAgent 是一个功能强大的 Laravel AI Agent SDK，提供多模型支持、完整的工具体系、高级权限管理和可观测性功能。

## 🚀 核心特性

### 基础功能
- **多模型支持** - 支持 Anthropic Claude、OpenAI GPT、AWS Bedrock、OpenRouter 等主流模型
- **59+ 内置工具** - 文件操作、代码编辑、Web 搜索、任务管理、工具搜索等开箱即用（核心工具始终可用；实验性工具受 feature flag 控制）
- **流式输出** - 实时响应，提供更好的用户体验
- **成本追踪** - 精确统计 Token 使用量和费用

### 高级功能
- **智能权限系统** - 6 种权限模式，智能安全控制
- **Bash 安全验证器** - 23 项注入/混淆检查（命令替换、IFS 注入、Unicode 空白、Zsh 攻击、混淆 flag、解析差异），附带只读命令分类
- **生命周期钩子** - 工具执行 pipeline 中的权限决策（allow/deny/ask）、输入修改，及停止钩子管道（Stop → TaskCompleted → TeammateIdle）
- **智能上下文压缩** - 带语义边界保护的会话记忆压缩器（tool_use/tool_result 对保护、最小 token/消息扩展、9 段式结构化摘要），微压缩器，以及带分析草稿剥离的对话压缩器
- **Token 预算续跑逻辑** - 动态预算驱动的 Agent 循环控制（90% 完成阈值、收益递减检测），替代固定 maxTurns
- **记忆系统** - 跨会话持久化，含实时会话记忆提取（三重门控：10K 初始化、5K 增长、3 次工具调用）、KAIROS 追加式每日日志、及夜间 auto-dream 合并到 MEMORY.md
- **Extended Thinking** - 自适应/启用/禁用三种模式，ultrathink 关键词触发，模型能力检测（Claude 4+），思考 token 预算管理
- **Coordinator 模式** - 双模式架构：Coordinator（纯综合委派，仅 Agent/SendMessage/TaskStop）vs Worker（全部执行工具），4 阶段工作流及会话模式持久化
- **多 Agent 协作** - Swarm 模式，内置专业 Agent（Explore、Plan、Verification、Code-Writer、Researcher、Reviewer、Coordinator），支持 Fork 语义共享上下文
- **Batch 技能** - `/batch` 命令，将大任务拆分为 5-30 个独立工作单元，在隔离 worktree 中并行执行并各自提 PR
- **MCP 协议支持** - 接入 Model Context Protocol 生态，支持服务端指令注入 System Prompt
- **Prompt 缓存优化** - 动态 System Prompt 组装，静态/动态边界分离实现 Prompt 缓存
- **遥测主开关** - 分层遥测控制：`telemetry.enabled` 总开关加子系统独立开关（logging、metrics、events、cost_tracking）— 总开关关闭时，无论子系统设置如何，均不采集数据
- **安全 Prompt 护栏** - 可选的安全指令注入 System Prompt，限制安全相关操作；通过 `security_guardrails` 开关控制
- **Bridge 模式** - Provider 无关的增强代理，将 CC 优化机制（系统提示词增强、上下文压缩、Bash 安全验证、记忆注入、工具 Schema 优化、成本追踪）注入到非 Anthropic 模型（OpenAI、Bedrock、Ollama、OpenRouter）。支持 HTTP 代理模式（供 Codex CLI 等工具使用）和 SDK 自动增强模式，三级优先级控制（`bridge_mode` 参数 > 配置文件 `auto_enhance` > feature flag）
- **实验性 Feature Flags** - 15 个细粒度 feature flag（含总开关），独立控制实验性功能：ultrathink、token budget、prompt 缓存检测、内置 agents、验证 agent、plan 面试、agent 触发器（本地/远程）、记忆提取、压缩提醒、缓存微压缩、团队记忆、bash 分类器、Bridge 模式
- **可观测性** - OpenTelemetry 集成，完整链路追踪，及按事件类型动态调整分析采样率
- **文件版本控制** - LRU 缓存（100 个消息级快照），按消息回退，diff 统计（insertions/deletions/filesChanged），快照继承
- **工具使用摘要** - Haiku 生成 git-commit-subject 风格的工具批次执行摘要
- **工具搜索与延迟加载** - 模糊关键词搜索带评分、select 模式、自动阈值延迟加载（10% 上下文窗口）
- **远程 Agent 任务** - 通过 API 触发器实现进程外 Agent 执行，支持 cron 调度
- **Plan V2 面试阶段** - 迭代式结对规划，结构化计划文件，周期性提醒，执行前用户审批
- **Claude Code 兼容** - 自动载入 Claude Code 目录下的 skills、agents 和 MCP 配置

## 📦 快速安装

### 系统要求
- PHP >= 8.1
- Laravel >= 10.0
- Composer >= 2.0

### 安装步骤

```bash
# 1. 通过 Composer 安装
composer require forgeomni/superagent

# 2. 发布配置文件
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"

# 3. 配置环境变量
# 在 .env 文件中添加 API 密钥
ANTHROPIC_API_KEY=your_api_key_here
```

📋 **快速链接**: [Installation Guide](INSTALL.md) | [中文安装手册](INSTALL.zh-CN.md) | [English Version](README.md)

## 🎯 快速上手

### 基础用法

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// 创建配置
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-haiku-20240307',
    ],
    'streaming' => true,
]);

// 初始化 Agent
$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

// 执行查询
$response = $agent->query("帮我分析这段代码的性能问题");
echo $response->content;
```

### 流式响应

```php
// 启用流式输出
$stream = $agent->stream("写一个快速排序算法");

foreach ($stream as $chunk) {
    if (isset($chunk['content'])) {
        echo $chunk['content'];  // 实时输出内容
    }
}
```

### 使用工具

```php
use SuperAgent\Tools\Builtin\FileReadTool;
use SuperAgent\Tools\Builtin\FileWriteTool;
use SuperAgent\Tools\Builtin\BashTool;

// 注册工具
$agent->registerTool(new FileReadTool());
$agent->registerTool(new FileWriteTool());
$agent->registerTool(new BashTool());

// Agent 会自动使用工具完成任务
$response = $agent->query("读取 config.php 文件，分析配置并生成优化建议");
```

### 多 Provider 实例

你可以注册多个 Anthropic 兼容 API（或任何 Provider），并在每次创建 Agent 时指定使用哪一个：

```php
// config/superagent.php
'default_provider' => 'anthropic',
'providers' => [
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-sonnet-4-20250514',
    ],
    'my-proxy' => [
        'driver' => 'anthropic',           // 复用 AnthropicProvider 类
        'api_key' => env('MY_PROXY_KEY'),
        'base_url' => 'https://proxy.example.com',
        'model' => 'claude-sonnet-4-20250514',
    ],
    'another-api' => [
        'driver' => 'anthropic',
        'api_key' => env('ANOTHER_API_KEY'),
        'base_url' => 'https://another.example.com',
        'model' => 'claude-3-haiku-20240307',
    ],
],
```

创建 Agent 时通过 provider 名称指定：

```php
use SuperAgent\Agent;

$agent1 = new Agent(['provider' => 'anthropic']);     // 官方 Anthropic API
$agent2 = new Agent(['provider' => 'my-proxy']);       // 代理 API
$agent3 = new Agent(['provider' => 'another-api']);    // 另一个兼容 API
```

`driver` 字段决定使用哪个 Provider 类来实例化，配置的 key（如 `my-proxy`）作为实例名用于选择。如果省略 `driver`，则使用 key 本身作为 driver 名，保持向后兼容。

**支持的 driver 类型：**

| Driver | Provider 类 | 说明 |
|--------|-------------|------|
| `anthropic` | `AnthropicProvider` | Anthropic Claude API 及兼容端点 |
| `openai` | `OpenAIProvider` | OpenAI API 及兼容端点（如 DeepSeek、Azure OpenAI） |
| `openrouter` | `OpenRouterProvider` | OpenRouter 多模型网关 |
| `bedrock` | `BedrockProvider` | AWS Bedrock 托管 AI 服务 |
| `ollama` | `OllamaProvider` | Ollama 本地模型运行时 |

## 🛠 高级功能

### 权限管理

```php
use SuperAgent\Permissions\PermissionMode;

// 设置权限模式
$config->permissions->mode = PermissionMode::AcceptEdits; // 自动批准文件编辑

// 自定义权限策略
$config->permissions->callback = function($tool, $params) {
    // 禁止删除操作
    if ($tool === 'bash' && str_contains($params['command'], 'rm')) {
        return false;
    }
    return true;
};
```

### 生命周期钩子

```php
use SuperAgent\Hooks\HookRegistry;

$hooks = HookRegistry::getInstance();

// 工具调用前的钩子
$hooks->register('pre_tool_use', function($data) {
    logger()->info('工具调用', $data);
    return $data;
});

// 查询完成后的钩子
$hooks->register('on_query_complete', function($response) {
    DB::table('agent_logs')->insert([
        'response' => $response->content,
        'timestamp' => now(),
    ]);
});
```

### 上下文压缩

```php
// 配置自动压缩
$config->context->autoCompact = true;
$config->context->compactThreshold = 3000;  // Token 阈值
$config->context->compactStrategy = 'smart'; // 压缩策略

// 手动触发压缩
$agent->compactContext();
```

### 任务管理

```php
use SuperAgent\Tasks\TaskManager;

$taskManager = TaskManager::getInstance();

// 创建任务
$task = $taskManager->createTask([
    'subject' => '优化数据库查询',
    'description' => '分析并优化系统中的慢查询',
    'status' => 'pending',
    'metadata' => ['priority' => 'high'],
]);

// 更新任务进度
$taskManager->updateTask($task->id, [
    'status' => 'in_progress',
    'metadata' => ['progress' => 50],
]);
```

### MCP 集成

```php
use SuperAgent\MCP\MCPManager;
use SuperAgent\MCP\Types\ServerConfig;

$mcpManager = MCPManager::getInstance();

// 注册 MCP 服务器（使用静态工厂方法）
$config = ServerConfig::stdio(
    name: 'github-mcp',
    command: 'npx',
    args: ['-y', '@modelcontextprotocol/server-github'],
    env: ['GITHUB_TOKEN' => env('GITHUB_TOKEN')]
);

$mcpManager->registerServer($config);
$mcpManager->connect('github-mcp');
```

### Bridge 模式（增强非 Anthropic 模型）

Bridge 模式将 Claude Code 的优化机制注入到非 Anthropic 模型（OpenAI、Bedrock、Ollama、OpenRouter）。Anthropic/Claude 不需要此功能——它原生已有这些优化。

**SDK 自动增强模式** —— 自动包装非 Anthropic Provider：

```php
use SuperAgent\Agent;

// 实例级强制开启
$agent = new Agent(['provider' => 'openai', 'bridge_mode' => true]);

// 实例级强制关闭（即使配置文件开启）
$agent = new Agent(['provider' => 'openai', 'bridge_mode' => false]);

// 使用配置默认值（bridge.auto_enhance 或 bridge_mode feature flag）
$agent = new Agent(['provider' => 'openai']);

// Anthropic 永远不被包装，无论设置如何
$agent = new Agent(['provider' => 'anthropic', 'bridge_mode' => true]); // 仍为原始 Provider
```

**HTTP 代理模式** —— 暴露 OpenAI 兼容端点供 Codex CLI 等工具使用：

```env
SUPERAGENT_EXP_BRIDGE_MODE=true
SUPERAGENT_BRIDGE_PROVIDER=openai
```

```bash
# Codex CLI 连接到 SuperAgent Bridge
export OPENAI_BASE_URL=http://localhost:8000/v1
codex "修复登录 bug"
```

端点：`POST /v1/chat/completions`、`POST /v1/responses`、`GET /v1/models`

**可用增强器**（每个可独立开关）：

| 增强器 | 配置键 | 默认 | 效果 |
|-------|-------|------|------|
| 系统提示词 | `system_prompt` | 开 | 注入 CC 任务/工具/风格指令 |
| 上下文压缩 | `context_compaction` | 开 | 截断旧工具结果，剥离 thinking 块 |
| Bash 安全 | `bash_security` | 开 | 23 项安全检查拦截危险命令 |
| 记忆注入 | `memory_injection` | 关 | 将跨会话记忆注入系统提示词 |
| 工具 Schema | `tool_schema` | 开 | 修复 JSON Schema 问题，增强描述 |
| 工具摘要 | `tool_summary` | 关 | 压缩冗长的旧工具结果 |
| Token 预算 | `token_budget` | 关 | 追踪 Token 用量，检测收益递减 |
| 成本追踪 | `cost_tracking` | 开 | 每请求成本计算，预算控制 |

```env
SUPERAGENT_BRIDGE_ENH_SYSTEM_PROMPT=true
SUPERAGENT_BRIDGE_ENH_COMPACTION=true
SUPERAGENT_BRIDGE_ENH_BASH_SECURITY=true
SUPERAGENT_BRIDGE_ENH_MEMORY=false
SUPERAGENT_BRIDGE_ENH_COST_TRACKING=true
```

## 🔧 命令行工具

```bash
# 交互式对话
php artisan superagent:chat

# 执行单次查询
php artisan superagent:run --prompt="优化这段代码"

# 列出可用工具
php artisan superagent:tools

# 创建自定义工具
php artisan superagent:make-tool MyCustomTool
```

## 🎨 扩展开发

### 创建自定义工具

```php
namespace App\SuperAgent\Tools;

use SuperAgent\Tools\BaseTool;
use SuperAgent\Tools\ToolResult;

class CustomTool extends BaseTool
{
    public function name(): string
    {
        return 'custom_tool';
    }
    
    public function description(): string
    {
        return '自定义工具的描述';
    }
    
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'input' => ['type' => 'string', 'description' => '输入参数'],
            ],
            'required' => ['input'],
        ];
    }
    
    public function execute(array $params): ToolResult
    {
        // 实现工具逻辑
        $result = $this->processInput($params['input']);
        
        return new ToolResult(
            success: true,
            data: ['result' => $result]
        );
    }
}
```

### 创建插件

```php
namespace App\SuperAgent\Plugins;

use SuperAgent\Plugins\BasePlugin;

class MyPlugin extends BasePlugin
{
    public function name(): string
    {
        return 'my-plugin';
    }
    
    public function boot(): void
    {
        // 注册自定义工具
        $this->registerTool(new MyCustomTool());
        
        // 注册钩子
        $this->registerHook('pre_query', [$this, 'preQueryHandler']);
    }
    
    public function preQueryHandler($query)
    {
        // 查询前的处理逻辑
        return $query;
    }
}
```

### 创建技能

```php
namespace App\SuperAgent\Skills;

use SuperAgent\Skills\Skill;

class CodeReviewSkill extends Skill
{
    public function name(): string
    {
        return 'code_review';
    }
    
    public function description(): string
    {
        return '执行代码审查';
    }
    
    public function template(): string
    {
        return <<<PROMPT
请对以下代码进行全面审查：

代码：
{code}

审查要点：
1. 潜在的 bug 和错误
2. 代码质量和可读性
3. 性能优化建议
4. 安全漏洞检查
5. 最佳实践建议

请提供详细的改进建议。
PROMPT;
    }
    
    public function execute(array $args = []): string
    {
        $prompt = str_replace('{code}', $args['code'], $this->template());
        return $this->agent->query($prompt)->content;
    }
}
```

### 创建 Agent 定义

同时支持 PHP 类和 Markdown 文件两种格式。

**Markdown 格式**（推荐 — 放在 `.claude/agents/` 目录下）：

```markdown
---
name: ai-advisor
description: "AI 策略顾问"
model: inherit
allowed_tools:
  - read_file
  - web_search
---

# AI 策略 Agent

你是一个 AI 策略顾问，用务实的方法评估 AI/ML 应用场景。

## 输入

$ARGUMENTS

## 语言

使用 $LANGUAGE 输出。如未指定，默认使用英语。
```

模板中的 `$ARGUMENTS`、`$LANGUAGE` 等占位符由 LLM 根据用户输入的上下文来理解和填充，程序不做替换。所有 frontmatter 字段都会保留，可通过 `getMeta()` 访问。

**PHP 格式：**

```php
namespace App\SuperAgent\Agents;

use SuperAgent\Agent\AgentDefinition;

class TranslatorAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'translator';
    }

    public function description(): string
    {
        return '多语言翻译专家';
    }

    public function systemPrompt(): ?string
    {
        return '你是一个翻译专家，在翻译内容时保持准确性，同时保留原文的语气和上下文。';
    }

    public function allowedTools(): ?array
    {
        return ['read_file', 'write_file', 'edit_file'];
    }

    public function category(): string
    {
        return 'content';
    }
}
```

### 自动载入 Skills、Agents 和 MCP

SuperAgent 可以通过 `load_claude_code` 开关自动载入 Claude Code 标准目录下的 skills、agents 和 MCP 服务，也支持从自定义路径载入。Skills 和 agents 同时支持 `.php` 和 `.md` 文件。所有目录路径递归扫描，不存在的路径自动跳过。

```php
// config/superagent.php
'skills' => [
    'load_claude_code' => false,                // 从 .claude/commands/ 和 .claude/skills/ 载入
    'paths' => [
        // app_path('SuperAgent/Skills'),
        // '/absolute/path/to/shared/skills',
    ],
],
'agents' => [
    'load_claude_code' => false,                // 从 .claude/agents/ 载入
    'paths' => [
        // app_path('SuperAgent/Agents'),
    ],
],
'mcp' => [
    'load_claude_code' => false,                // 从 .mcp.json 和 ~/.claude.json 载入
    'paths' => [
        // 'custom/mcp-servers.json',            // 额外的 MCP 配置文件（JSON）
    ],
],
```

也可以在运行时手动载入：

```php
use SuperAgent\Skills\SkillManager;
use SuperAgent\Agent\AgentManager;
use SuperAgent\MCP\MCPManager;

// 从任意目录载入（递归）
SkillManager::getInstance()->loadFromDirectory('/any/path', recursive: true);
AgentManager::getInstance()->loadFromDirectory('/any/path', recursive: true);

// 载入单个文件（PHP 或 Markdown）
SkillManager::getInstance()->loadFromFile('/path/to/biznet.md');
AgentManager::getInstance()->loadFromFile('/path/to/ai-advisor.md');

// 从 Claude Code 配置或自定义 JSON 文件载入 MCP 服务
MCPManager::getInstance()->loadFromClaudeCode();
MCPManager::getInstance()->loadFromJsonFile('/path/to/mcp-servers.json');
```

PHP 文件可以使用任意命名空间 — 载入器会从源文件中解析 `namespace` 和 `class` 声明。Markdown 文件使用 YAML frontmatter 存放元数据，正文作为 prompt 模板。MCP 配置文件同时支持 Claude Code 格式（`mcpServers`）和 SuperAgent 格式（`servers`），支持 `${VAR}` 和 `${VAR:-default}` 环境变量展开。

### Fork 语义

Fork 一个继承父级完整对话上下文和 System Prompt 的子 Agent。Fork 子 Agent 共享 Prompt 缓存前缀，节省 Token。

```php
use SuperAgent\Agent\ForkContext;

// 从当前 Agent 状态创建 fork 上下文
$fork = new ForkContext(
    parentMessages: $agent->getMessages(),
    parentSystemPrompt: $currentSystemPrompt,
    parentToolNames: ['bash', 'read_file', 'edit_file'],
);

// Fork 上下文传递给 AgentSpawnConfig
$config = new AgentSpawnConfig(
    name: 'research-fork',
    prompt: '调查认证模块',
    forkContext: $fork,
);
// $config->isFork() === true
```

Fork 子 Agent 禁止递归 fork，遵循结构化输出格式（Scope/Result/Key files/Issues），直接执行不委托。

### 动态 System Prompt

System Prompt 由模块化的 section 组成，静态/动态分离设计优化 Prompt 缓存：

```php
use SuperAgent\Prompt\SystemPromptBuilder;

$prompt = SystemPromptBuilder::create()
    ->withTools(['bash', 'read_file', 'edit_file', 'agent'])
    ->withMcpInstructions($mcpManager)    // 注入 MCP 服务端使用说明
    ->withMemory($memoryContent)           // 注入跨会话记忆
    ->withLanguage('zh-CN')                // 设置响应语言
    ->withEnvironment([                    // 注入运行时信息
        'Platform' => 'darwin',
        'PHP Version' => PHP_VERSION,
    ])
    ->withCustomSection('project', $projectRules)
    ->build();
```

**Section 布局：**
- 静态前缀（可缓存）：身份、系统规则、任务哲学、风险操作、工具使用、语气、输出效率
- 缓存边界标记（`__SYSTEM_PROMPT_DYNAMIC_BOUNDARY__`）
- 动态后缀（会话特定）：MCP 指令、记忆、环境信息、语言、自定义 section

启用 Prompt 缓存时，Anthropic Provider 会在边界标记处拆分 System Prompt，对静态前缀应用 `cache_control`，使其在多轮对话中保持缓存，动态后缀可自由变化。

### MCP 指令注入

已连接的 MCP 服务可以提供其工具的使用说明。这些说明在 MCP 初始化握手时被捕获，通过 `SystemPromptBuilder::withMcpInstructions()` 自动注入 System Prompt。

```php
$mcpManager = MCPManager::getInstance();
$mcpManager->connect('github-mcp');

// 服务端提供的使用说明现在可用：
$instructions = $mcpManager->getConnectedInstructions();
// ['github-mcp' => '使用 search_repos 查找仓库...']
```

### 遥测主开关

所有遥测子系统受主开关控制。当 `telemetry.enabled` 为 `false` 时，无论子系统设置如何，不采集任何遥测数据：

```env
# 主开关 — 必须为 true 才能启用任何遥测功能
SUPERAGENT_TELEMETRY_ENABLED=false

# 子系统独立开关（仅在主开关为 ON 时生效）
SUPERAGENT_TELEMETRY_LOGGING=false
SUPERAGENT_TELEMETRY_METRICS=false
SUPERAGENT_TELEMETRY_EVENTS=false
SUPERAGENT_TELEMETRY_COST_TRACKING=false
```

### 安全 Prompt 护栏

启用后，额外的安全指令会注入 System Prompt，限制安全相关操作（如拒绝破坏性技术、要求双用途安全工具的授权上下文）：

```env
SUPERAGENT_SECURITY_GUARDRAILS=false
```

### 实验性 Feature Flags

细粒度的 feature flag 允许独立启用或禁用实验性功能。当总开关为 `true` 时，所有功能默认启用：

```env
# 总开关 — 设为 false 可禁用所有实验性功能
SUPERAGENT_EXPERIMENTAL=true

# 各功能独立开关
SUPERAGENT_EXP_ULTRATHINK=true           # "ultrathink" 关键词提升推理预算
SUPERAGENT_EXP_TOKEN_BUDGET=true          # Token 预算追踪和用量警告
SUPERAGENT_EXP_PROMPT_CACHE=true          # Prompt 缓存中断检测
SUPERAGENT_EXP_BUILTIN_AGENTS=true        # Explore/Plan Agent 预设
SUPERAGENT_EXP_VERIFICATION_AGENT=true    # 验证 Agent
SUPERAGENT_EXP_PLAN_INTERVIEW=true        # Plan V2 面试阶段工作流
SUPERAGENT_EXP_AGENT_TRIGGERS=true        # 本地 cron/触发器工具
SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE=true # 远程触发器工具（API）
SUPERAGENT_EXP_EXTRACT_MEMORIES=true      # 查询后自动记忆提取
SUPERAGENT_EXP_COMPACTION_REMINDERS=true  # 上下文压缩智能提醒
SUPERAGENT_EXP_CACHED_MICROCOMPACT=true   # 缓存微压缩状态
SUPERAGENT_EXP_TEAM_MEMORY=true           # 团队记忆文件（共享记忆）
SUPERAGENT_EXP_BASH_CLASSIFIER=true       # 分类器辅助 bash 权限决策
SUPERAGENT_EXP_BRIDGE_MODE=false          # Bridge 模式：用 CC 优化机制增强非 Anthropic 模型
```

`ExperimentalFeatures` 类在 Laravel 应用外运行时（如单元测试）会回退到环境变量，确保 feature flag 在所有环境中一致工作。

## 📊 性能优化

### 缓存配置

```php
// config/superagent.php
'cache' => [
    'enabled' => true,
    'driver' => 'redis',  // 使用 Redis 提升性能
    'ttl' => 3600,        // 缓存时间（秒）
    'prefix' => 'superagent_',
],
```

### 批量处理

```php
// 并发执行多个任务
$tasks = [
    "分析代码质量",
    "生成单元测试",
    "编写技术文档",
];

$results = $agent->batch($tasks, [
    'concurrency' => 3,  // 并发数
    'timeout' => 30,      // 超时时间
]);
```

## 🔐 安全最佳实践

1. **API 密钥管理**
   - 使用环境变量存储密钥，不要硬编码
   - 定期轮换 API 密钥
   - 使用密钥管理服务（如 AWS Secrets Manager）

2. **权限控制**
   - 生产环境使用严格的权限模式
   - 审计所有工具调用记录
   - 限制敏感操作的访问

3. **输入验证**
   - 验证和清理所有用户输入
   - 使用参数化查询防止注入攻击
   - 实施速率限制

4. **错误处理**
   - 不要向用户暴露内部错误信息
   - 记录详细日志用于调试
   - 实现优雅的降级策略

## 📈 监控与日志

### 配置日志记录

```php
// config/superagent.php
'logging' => [
    'enabled' => true,
    'channel' => 'superagent',
    'level' => 'info',
    'separate_files' => true,  // 分离日志文件
],
```

### 使用结构化日志

```php
use SuperAgent\Telemetry\StructuredLogger;

$logger = StructuredLogger::getInstance();

// 设置全局上下文
$logger->setGlobalContext([
    'user_id' => auth()->id(),
]);
$logger->setSessionId('session-123');

// 记录 LLM 请求
$logger->logLLMRequest(
    model: 'claude-3-haiku-20240307',
    inputTokens: 500,
    outputTokens: 200,
    duration: $duration,
    metadata: ['query_type' => 'analysis']
);

// 记录错误
$logger->logError('API 超时', new \RuntimeException('连接超时'), [
    'provider' => 'anthropic',
]);
```

## 🤝 贡献指南

我们欢迎所有形式的贡献！无论是报告问题、提出新功能建议，还是提交代码。

1. Fork 本仓库
2. 创建功能分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 提交 Pull Request

详情请查看 [贡献指南](CONTRIBUTING.md)

## 📄 许可证

本项目采用 MIT 许可证 - 详见 [LICENSE](LICENSE) 文件

## 🙋 获取支持

- 📖 [文档中心](https://superagent-docs.example.com)
- 💬 [社区讨论](https://github.com/yourusername/superagent/discussions)
- 🐛 [问题反馈](https://github.com/yourusername/superagent/issues)
- 📧 邮箱: mliz1984@gmail.com

## 🗺 路线图


### 即将推出
- ✨ 更多模型支持（Gemini、Mistral）
- 🎯 可视化调试工具
- 🔄 自动任务编排
- 📊 性能分析面板
- 🌐 多语言支持

## 📚 文档导航

### 语言版本
- 🇺🇸 [English README](README.md)
- 🇨🇳 [中文 README](README.zh-CN.md)

### 安装指南
- 📖 [English Installation Guide](INSTALL.md)
- 📖 [中文安装手册](INSTALL.zh-CN.md)

### 其他资源
- 🤝 [贡献指南](CONTRIBUTING.md)
- 📄 [许可证](LICENSE)

---

<p align="center">
  用 ❤️ 打造 by SuperAgent 团队
</p>