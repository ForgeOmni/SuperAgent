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
- **56+ 内置工具** - 文件操作、代码编辑、Web 搜索、任务管理等开箱即用
- **流式输出** - 实时响应，提供更好的用户体验
- **成本追踪** - 精确统计 Token 使用量和费用

### 高级功能
- **智能权限系统** - 6 种权限模式，智能安全控制
- **生命周期钩子** - 在关键节点插入自定义逻辑
- **上下文压缩** - 智能管理对话历史，突破 Token 限制
- **记忆系统** - 跨会话持久化，长期学习能力
- **多 Agent 协作** - Swarm 模式，任务分发与协同
- **MCP 协议支持** - 接入 Model Context Protocol 生态
- **可观测性** - OpenTelemetry 集成，完整链路追踪
- **文件版本控制** - 自动快照，随时回滚

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

// 注册 MCP 服务器
$config = new ServerConfig(
    name: 'github-mcp',
    command: 'npx',
    args: ['-y', '@modelcontextprotocol/server-github'],
    env: ['GITHUB_TOKEN' => env('GITHUB_TOKEN')]
);

$mcpManager->registerServer($config);
$mcpManager->connect('github-mcp');
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

### 自动载入 Skills 和 Agents

Skills 和 Agent 定义文件可以从配置的目录中自动载入，所有路径会递归扫描。不存在的路径会自动跳过。

```php
// config/superagent.php
'skills' => [
    'paths' => [
        '.claude/skills',                       // 默认值，相对项目根目录
        app_path('SuperAgent/Skills'),           // Laravel app 目录
        '/absolute/path/to/shared/skills',       // 绝对路径
    ],
],
'agents' => [
    'paths' => [
        '.claude/agents',                       // 默认值，相对项目根目录
        app_path('SuperAgent/Agents'),           // Laravel app 目录
    ],
],
```

也可以在运行时手动载入：

```php
use SuperAgent\Skills\SkillManager;
use SuperAgent\Agent\AgentManager;

// 从任意目录载入（递归）
SkillManager::getInstance()->loadFromDirectory('/any/path', recursive: true);
AgentManager::getInstance()->loadFromDirectory('/any/path', recursive: true);

// 载入单个文件
SkillManager::getInstance()->loadFromFile('/path/to/MySkill.php');
AgentManager::getInstance()->loadFromFile('/path/to/MyAgent.php');
```

文件可以使用任意命名空间 — 载入器会直接从源文件中解析 `namespace` 和 `class` 声明。

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

// 添加上下文信息
$logger->withContext([
    'user_id' => auth()->id(),
    'request_id' => request()->id(),
]);

// 记录操作
$logger->info('Agent 查询完成', [
    'tokens_used' => $response->usage->total_tokens,
    'cost' => $response->usage->estimated_cost,
    'duration' => $duration,
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
- 📧 邮箱: support@superagent.dev
- 💼 商业支持: enterprise@superagent.dev

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