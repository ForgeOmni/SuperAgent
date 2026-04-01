# SuperAgent Installation Guide

> **🌍 Language**: [English](INSTALL.md) | [中文](INSTALL.zh-CN.md)  
> **📖 Documentation**: [README](README.md) | [README 中文](README.zh-CN.md)

## Table of Contents
- [System Requirements](#system-requirements)
- [Installation Steps](#installation-steps)
- [Configuration](#configuration)
- [Verification](#verification)
- [Troubleshooting](#troubleshooting)
- [Upgrade Guide](#upgrade-guide)

## System Requirements

### Minimum Requirements
- **PHP**: 8.1 or higher
- **Laravel**: 10.0 or higher
- **Composer**: 2.0 or higher
- **Memory**: At least 256MB PHP memory limit
- **Disk Space**: At least 100MB available space

### Required PHP Extensions
```bash
# Core extensions
- json        # JSON processing
- mbstring    # Multi-byte string
- openssl     # Encryption
- curl        # HTTP requests
- fileinfo    # File information
```

### Optional PHP Extensions
```bash
# Enhanced features
- redis       # Redis cache support
- pcntl       # Process control (multi-agent collaboration)
- yaml        # YAML configuration files
- zip         # File compression
```

### Environment Check

```bash
# Check PHP version
php -v

# Check installed extensions
php -m

# Check Laravel version  
php artisan --version

# Check Composer version
composer --version
```

## Installation Steps

### 1️⃣ Install via Composer

#### Standard Installation (Recommended)
```bash
composer require forgeomni/superagent
```

#### Install Development Version
```bash
composer require forgeomni/superagent:dev-main
```

#### Install Specific Version
```bash
composer require forgeomni/superagent:^1.0
```

### 2️⃣ Register Service Provider

Laravel 10+ will auto-register. For manual registration, edit `config/app.php`:

```php
'providers' => [
    // Other providers...
    SuperAgent\SuperAgentServiceProvider::class,
],

'aliases' => [
    // Other aliases...
    'SuperAgent' => SuperAgent\Facades\SuperAgent::class,
],
```

### 3️⃣ Publish Resource Files

```bash
# Publish all resources
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"

# Or publish separately
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config"
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="migrations"
```

### 4️⃣ Run Database Migrations

If using memory system and task management features:

```bash
php artisan migrate
```

### 5️⃣ Configure Environment Variables

Edit your `.env` file and add the necessary configuration:

```env
# ========== SuperAgent Base Configuration ==========

# Default AI Provider (anthropic|openai|bedrock|ollama)
SUPERAGENT_PROVIDER=anthropic

# Anthropic Claude Configuration
ANTHROPIC_API_KEY=sk-ant-xxxxxxxxxxxxx
ANTHROPIC_MODEL=claude-3-haiku-20240307
ANTHROPIC_MAX_TOKENS=4096
ANTHROPIC_TEMPERATURE=0.7

# OpenAI Configuration (optional)
OPENAI_API_KEY=sk-xxxxxxxxxxxxx
OPENAI_MODEL=gpt-4
OPENAI_ORG_ID=org-xxxxxxxxxxxxx

# AWS Bedrock Configuration (optional)
AWS_ACCESS_KEY_ID=AKIAXXXXXXXXXXXXX
AWS_SECRET_ACCESS_KEY=xxxxxxxxxxxxx
AWS_DEFAULT_REGION=us-east-1
BEDROCK_MODEL=anthropic.claude-v2

# Local Ollama Models (optional)
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=llama2

# ========== Feature Toggles ==========

# Streaming output
SUPERAGENT_STREAMING=true

# Cache functionality
SUPERAGENT_CACHE_ENABLED=true
SUPERAGENT_CACHE_TTL=3600

# Debug mode
SUPERAGENT_DEBUG=false

# Observability
SUPERAGENT_TELEMETRY_ENABLED=false

# ========== Permission Configuration ==========

# Permission modes:
# bypass - Skip all permission checks
# acceptEdits - Auto-approve file edits
# plan - All operations need confirmation
# default - Smart judgment
# dontAsk - Auto-deny operations needing confirmation
# auto - AI automatic classification
SUPERAGENT_PERMISSION_MODE=default

# ========== Storage Configuration ==========

SUPERAGENT_STORAGE_DISK=local
SUPERAGENT_STORAGE_PATH=superagent
```

### 6️⃣ Create Necessary Directories

```bash
# Create storage directories
mkdir -p storage/app/superagent/{snapshots,memories,tasks,cache}

# Set permissions
chmod -R 755 storage/app/superagent

# If using web server
chown -R www-data:www-data storage/app/superagent  # Ubuntu/Debian
chown -R nginx:nginx storage/app/superagent        # CentOS/RHEL
```

## Configuration

### Main Configuration File

Edit `config/superagent.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
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
    | Tools Configuration
    |--------------------------------------------------------------------------
    */
    'tools' => [
        // Enabled tools list
        'enabled' => [
            \SuperAgent\Tools\Builtin\FileReadTool::class,
            \SuperAgent\Tools\Builtin\FileWriteTool::class,
            \SuperAgent\Tools\Builtin\FileEditTool::class,
            \SuperAgent\Tools\Builtin\BashTool::class,
            \SuperAgent\Tools\Builtin\WebSearchTool::class,
            \SuperAgent\Tools\Builtin\WebFetchTool::class,
        ],
        
        // Tool permission settings
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
    | Context Management
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
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('SUPERAGENT_CACHE_ENABLED', true),
        'driver' => env('CACHE_DRIVER', 'file'),
        'ttl' => env('SUPERAGENT_CACHE_TTL', 3600),
    ],
];
```

### Tool Auto-Discovery

Create custom tool directories:

```bash
# Create directory structure
mkdir -p app/SuperAgent/{Tools,Skills,Plugins,Agents}

# Tools will be automatically discovered and registered
```

### Skill & Agent Auto-Loading

Skills and agent definitions are automatically loaded from paths configured in `config/superagent.php`. All paths are scanned recursively. Non-existent paths are silently skipped.

```php
// config/superagent.php
'skills' => [
    'paths' => [
        '.claude/skills',                       // default, relative to project root
        app_path('SuperAgent/Skills'),
    ],
],
'agents' => [
    'paths' => [
        '.claude/agents',                       // default, relative to project root
        app_path('SuperAgent/Agents'),
    ],
],
```

Create the default directories:

```bash
mkdir -p .claude/skills .claude/agents
```

Both PHP (`.php`) and Markdown (`.md`) files are supported. PHP files can use any namespace. Markdown files use YAML frontmatter for metadata (name, description, allowed_tools, etc.) and the body as the prompt template — placeholders like `$ARGUMENTS` and `$LANGUAGE` are interpreted by the LLM, not substituted by the program.

## Verification

### 1️⃣ Run Health Check

Create health check script `check-superagent.php`:

```php
<?php

require 'vendor/autoload.php';

$checks = [
    'PHP Version' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Laravel Installation' => class_exists('Illuminate\Foundation\Application'),
    'SuperAgent Installation' => class_exists('SuperAgent\Agent'),
    'JSON Extension' => extension_loaded('json'),
    'CURL Extension' => extension_loaded('curl'),
    'OpenSSL Extension' => extension_loaded('openssl'),
];

echo "SuperAgent Installation Check\n";
echo "============================\n\n";

$allPassed = true;
foreach ($checks as $name => $result) {
    $status = $result ? '✅' : '❌';
    echo "$status $name\n";
    if (!$result) $allPassed = false;
}

if ($allPassed) {
    echo "\n🎉 All checks passed! SuperAgent is ready.\n";
} else {
    echo "\n⚠️ Some checks failed, please resolve the issues above.\n";
    exit(1);
}
```

Run the check:
```bash
php check-superagent.php
```

### 2️⃣ Test Basic Functionality

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// Test basic query
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-haiku-20240307',
    ],
]);

$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

$response = $agent->query("Say 'Installation successful!'");
echo $response->content;
```

### 3️⃣ Test CLI Tools

```bash
# List available tools
php artisan superagent:tools

# Test chat functionality
php artisan superagent:chat

# Execute simple query
php artisan superagent:run --prompt="What is 2+2?"
```

## Troubleshooting

### ❓ Composer Installation Fails

**Error Message**:
```
Your requirements could not be resolved to an installable set of packages
```

**Solution**:
```bash
# Clear cache
composer clear-cache

# Update dependencies
composer update --with-dependencies

# Use domestic mirror (China users)
composer config repo.packagist composer https://mirrors.aliyun.com/composer/
```

### ❓ Service Provider Not Found

**Error Message**:
```
Class 'SuperAgent\SuperAgentServiceProvider' not found
```

**Solution**:
```bash
# Regenerate autoload
composer dump-autoload

# Clear Laravel cache
php artisan optimize:clear
```

### ❓ Invalid API Key

**Error Message**:
```
Invalid API key provided
```

**Solution**:
1. Check API key in `.env` file is correct
2. Ensure no extra spaces or quotes around the key
3. Verify key is activated and not expired
4. Clear config cache: `php artisan config:clear`

### ❓ Memory Exhausted

**Error Message**:
```
Allowed memory size of X bytes exhausted
```

**Solution**:

Edit `php.ini`:
```ini
memory_limit = 512M
```

Or set temporarily in code:
```php
ini_set('memory_limit', '512M');
```

### ❓ Permission Denied

**Error Message**:
```
Permission denied
```

**Solution**:
```bash
# Set correct permissions
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Set owner (adjust based on system)
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

## Upgrade Guide

### From 0.x to 1.0

```bash
# 1. Backup existing data
php artisan backup:run

# 2. Update dependencies
composer update forgeomni/superagent

# 3. Run new migrations
php artisan migrate

# 4. Update configuration files
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config" --force

# 5. Clear all caches
php artisan optimize:clear
```

### Version Compatibility Matrix

| SuperAgent | Laravel | PHP   | Notes |
|------------|---------|-------|-------|
| 0.5.x      | 10.x+   | 8.1+ | Current stable |

## Production Deployment

### Performance Optimization

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev
```

### Configure Queues

Create Supervisor configuration `/etc/supervisor/conf.d/superagent.conf`:

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

### Configure Nginx

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
    
    # Streaming response support
    location /superagent/stream {
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 3600;
    }
}
```

## Get Help

### 📚 Resources

- 📖 [Official Documentation](https://superagent-docs.example.com)
- 💬 [Community Forum](https://forum.superagent.dev)
- 🐛 [Issue Tracker](https://github.com/yourusername/superagent/issues)
- 📺 [Video Tutorials](https://youtube.com/@superagent)

### 💼 Technical Support

- Community support: [GitHub Discussions](https://github.com/yourusername/superagent/discussions)
- Email support: support@superagent.dev
- Enterprise support: enterprise@superagent.dev
- Discord server: [Join our community](https://discord.gg/superagent)

### 🔍 Debugging Tips

Enable debug mode:
```env
SUPERAGENT_DEBUG=true
APP_DEBUG=true
```

View logs:
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# SuperAgent specific logs
tail -f storage/logs/superagent.log

# Real-time debugging
php artisan tinker
```

## 📚 Documentation Navigation

### Language Versions
- 🇺🇸 [English Installation Guide](INSTALL.md)
- 🇨🇳 [中文安装手册](INSTALL.zh-CN.md)

### Main Documentation
- 📖 [English README](README.md)
- 📖 [中文 README](README.zh-CN.md)

### Additional Resources
- 🤝 [Contributing Guide](CONTRIBUTING.md)
- 📄 [License](LICENSE)

---

© 2024 SuperAgent. All rights reserved.