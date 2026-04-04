# SuperAgent 安装手册

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
];
```

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