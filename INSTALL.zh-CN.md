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

# 可观测性
SUPERAGENT_TELEMETRY_ENABLED=false

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

### Skill 和 Agent 自动载入

Skills 和 Agent 定义文件会从 `config/superagent.php` 中配置的路径自动载入，所有路径递归扫描。不存在的路径会自动跳过。

```php
// config/superagent.php
'skills' => [
    'paths' => [
        '.claude/skills',                       // 默认值，相对项目根目录
        app_path('SuperAgent/Skills'),
    ],
],
'agents' => [
    'paths' => [
        '.claude/agents',                       // 默认值，相对项目根目录
        app_path('SuperAgent/Agents'),
    ],
],
```

创建默认目录：

```bash
mkdir -p .claude/skills .claude/agents
```

将 `*Skill.php` 和 `*Agent.php` 文件放入任意已配置的目录即可自动发现，不限制命名空间。

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
| 0.5.x      | 10.x+   | 8.1+ | 当前稳定版 |

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
- 邮件支持：support@superagent.dev
- 企业支持：enterprise@superagent.dev
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

© 2024 SuperAgent. 保留所有权利。