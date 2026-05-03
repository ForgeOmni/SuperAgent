# SuperAgent — 安装

> **🌍 语言**: [English](INSTALL.md) | [中文](INSTALL_CN.md) | [Français](INSTALL_FR.md)
> **📖 文档**: [README_CN](README_CN.md) · [CHANGELOG](CHANGELOG.md) · [高级用法](docs/ADVANCED_USAGE_CN.md)

## 目录

- [系统要求](#系统要求)
- [安装路径](#安装路径)
- [认证](#认证)
- [首次运行配置](#首次运行配置)
- [可选特性配置](#可选特性配置)
  - [OpenAI Responses API](#openai-responses-api)
  - [ChatGPT 订阅 OAuth](#chatgpt-订阅-oauth)
  - [Azure OpenAI](#azure-openai)
  - [本地模型（Ollama / LM Studio）](#本地模型ollama--lm-studio)
  - [MCP catalog + sync](#mcp-catalog--sync)
  - [Wire 协议传输](#wire-协议传输)
  - [Shadow-git checkpoint](#shadow-git-checkpoint)
- [验证](#验证)
- [故障排查](#故障排查)
- [升级](#升级)
- [卸载](#卸载)

---

## 系统要求

| 要求 | 最低 |
|---|---|
| PHP | 8.1 |
| Composer | 2.0 |
| 扩展 | `curl` / `json` / `mbstring` / `openssl` |
| 可选 | `pcntl`（fork 形态 swarm）、`proc_open`（子 agent ProcessBackend，POSIX 默认启用）、`sockets`（wire 协议 unix-socket 传输）|
| 操作系统 | Linux / macOS / Windows（Windows 建议用 WSL）|

验证 PHP + 扩展：

```bash
php -v
php -m | grep -E 'curl|json|mbstring|openssl|pcntl|sockets'
```

Laravel 集成额外需要：

| 要求 | 最低 |
|---|---|
| Laravel | 10.0 |
| 数据库 | MySQL 8 / PostgreSQL 14 / SQLite 3.35（用 `ai_usage_logs` 时）|

---

## 安装路径

### 独立 CLI（v0.8.6+）

一个二进制 —— 不需要 Laravel 项目。可以部署到集群、从任何 shell 调用、接入 CI。

**方案 A —— Composer 全局：**

```bash
composer global require forgeomni/superagent
# 确保 ~/.composer/vendor/bin（或你配的 Composer bin 目录）在 PATH 里
```

**方案 B —— clone + 软链接：**

```bash
git clone https://github.com/forgeomni/superagent.git ~/.local/src/superagent
cd ~/.local/src/superagent
composer install --no-dev
ln -s "$PWD/bin/superagent" /usr/local/bin/superagent
```

**方案 C —— 引导脚本：**

```bash
# POSIX：
curl -sSL https://raw.githubusercontent.com/forgeomni/superagent/main/install.sh | bash

# Windows PowerShell：
iwr -useb https://raw.githubusercontent.com/forgeomni/superagent/main/install.ps1 | iex
```

验证：

```bash
superagent --version    # SuperAgent v0.9.7
superagent --help
```

### Laravel 依赖

```bash
composer require forgeomni/superagent
php artisan vendor:publish --tag=superagent-config
```

生成 `config/superagent.php` —— 填入 provider key 和 agent 默认值。service provider、facade (`SuperAgent`)、Artisan 命令（`superagent:chat` / `superagent:mcp` / `superagent:models` / `superagent:health`）自动注册。

**多租户 host**（SaaS 平台、per-workspace 的 provider 配置等，凭据存在数据库里）用 `ProviderRegistry::createForHost($sdkKey, $hostConfig)` 而不是手动 instantiate 每个 provider —— SDK 自己处理构造函数 shape 的 `match ($type)`。见 README 的 [Host 集成](README_CN.md#host-集成)。*v0.9.2 起。*

---

## 认证

每个要用的 provider 选一种认证方式即可。各方式可以组合 —— OpenAI API key 和已存的 ChatGPT OAuth 可以同时存在，agent 按 `auth_mode` 选择。

### 1. 环境变量 API key

阻力最小，每个有 bearer endpoint 的 provider 都支持。

```bash
# ~/.bashrc / ~/.zshrc 或部署时的 .env，按你的工作流：
export ANTHROPIC_API_KEY=sk-ant-...
export OPENAI_API_KEY=sk-...
export GEMINI_API_KEY=...
export KIMI_API_KEY=...
export QWEN_API_KEY=...            # 'qwen' 和 'qwen-native' 共用
export GLM_API_KEY=...
export MINIMAX_API_KEY=...
export DEEPSEEK_API_KEY=...        # DeepSeek V4 — v0.9.6 起
export OPENROUTER_API_KEY=...
```

可选的 scope header（v0.9.1 起 —— 在 agent 上声明一次，env 未设置时自动省略）：

```bash
export OPENAI_ORGANIZATION=org-...
export OPENAI_PROJECT=proj-...
```

### 2. 复用已有 CLI 登录

如果本地已经在用 Claude Code / Codex CLI / Gemini CLI，SuperAgent 可以直接导入它们的 OAuth token。

```bash
superagent auth login claude-code     # 导入磁盘上的 Claude Code OAuth
superagent auth login codex           # 导入 Codex 登录
superagent auth login gemini          # 导入 Gemini CLI 登录
superagent auth status                # 查看哪些 provider 已存凭据
```

### 3. 设备码登录（provider 托管）

适用于自己暴露 RFC 8628 设备流程的 provider。

```bash
superagent auth login kimi-code       # Moonshot Kimi Code 订阅（v0.9.0 起）
superagent auth login qwen-code       # 阿里 Qwen Code 订阅，PKCE S256（v0.9.0 起）
```

命令会打印验证 URL + 用户码；在浏览器里批准后 token 存到 `~/.superagent/credentials/<name>.json`。

### 4. 显式配置

适合 CI / secret manager 驱动的环境：

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $vaultSecrets['openai_oauth'],
    'account_id'   => $vaultSecrets['openai_account_id'],
    'auth_mode'    => 'oauth',
]);
```

### OAuth 刷新安全

并行 worker 共享同一个 `~/.superagent/credentials/<name>.json` 时不会互相覆盖刷新 —— `CredentialStore::withLock()` 通过跨进程文件锁串行化 HTTP 调用，带陈旧锁回收（v0.9.0 起）。无需配置，默认启用。

---

## 首次运行配置

初始化用户目录：

```bash
superagent init
```

会创建：

```
~/.superagent/
├── credentials/         # OAuth token（权限 0600）
├── models-cache/        # per-provider 缓存的 /models 响应
├── storage/             # 运行时 scratch
├── agents/              # 用户级 agent 定义（YAML / MD）
└── device.json          # 每个安装稳定的 UUID
```

验证 provider 可达：

```bash
superagent health             # 每个已配置 provider 的 5s cURL 探针
# Provider      Status    Latency     Reason
# ────────────────────────────────────────────────
# openai        ✓ ok      142ms
# anthropic     ✓ ok       98ms
# kimi          ✗ fail    —           no API key in environment
```

首次真实运行：

```bash
superagent "列出当前目录最近修改的 3 个文件"
```

---

## 可选特性配置

下面每个特性都是 opt-in，用不到可以跳过。

### OpenAI Responses API

用专门的 provider 而不是 `openai`：

```php
new Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);
```

Laravel 配置：

```php
// config/superagent.php
'providers' => [
    'openai-responses' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => 'gpt-5',
        'store'   => true,    // 用 previous_response_id 前提
    ],
],
```

完整特性集（reasoning effort、prompt cache key、verbosity、service tier、continuation）见 README 的 [OpenAI Responses API](README_CN.md#openai-responses-api) 小节。

*v0.9.1 起*

### ChatGPT 订阅 OAuth

需要 Plus / Pro / Business 订阅 + 已存的 ChatGPT access_token。执行 `superagent auth login codex`（或 host 侧的专门导入）之后，Responses provider 会自动路由到 `chatgpt.com/backend-api/codex`。

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $token,          // 来自 ~/.superagent/credentials/...
    'account_id'   => $accountId,      // 添加 chatgpt-account-id header
]);
```

不需要覆盖 base URL —— 路由切换由 `auth_mode: 'oauth'` 自动触发。

*v0.9.1 起*

### Azure OpenAI

`base_url` 指向你的 Azure 资源。6 个 base URL 标记自动检测（`openai.azure.*` / `cognitiveservices.azure.*` / `aoai.azure.*` / `azure-api.*` / `azurefd.*` / `windows.net/openai`）。

```bash
export AZURE_OPENAI_API_KEY=...
export AZURE_OPENAI_BASE=https://my-resource.openai.azure.com/openai/deployments/gpt-5
```

```php
new Agent([
    'provider'          => 'openai-responses',
    'base_url'          => getenv('AZURE_OPENAI_BASE'),
    'api_key'           => getenv('AZURE_OPENAI_API_KEY'),
    'azure_api_version' => '2025-04-01-preview',   // 默认；老 deployment 可以改
]);
```

`api-key` 和 `Authorization: Bearer ...` 两种 header 都会发 —— Azure 按它的 gateway 选择一种。

*v0.9.1 起*

### 本地模型（Ollama / LM Studio）

两个都无需 auth —— SDK 发一个占位的 Bearer token 让 Guzzle 通过。

**Ollama**（默认端口 11434）：

```bash
# 在 SuperAgent 外安装 + 拉模型：
ollama pull llama3.2
ollama serve &
```

```php
new Agent(['provider' => 'ollama', 'model' => 'llama3.2']);
```

**LM Studio**（默认端口 1234，v0.9.1 起）：

```bash
# 打开 LM Studio app，加载模型，开启 OpenAI 兼容 server。
```

```php
new Agent(['provider' => 'lmstudio', 'model' => 'qwen2.5-coder-7b-instruct']);
```

用 `base_url` 覆盖 host/端口：

```php
new Agent([
    'provider' => 'lmstudio',
    'base_url' => 'http://10.0.0.2:9876',
]);
```

### MCP catalog + sync

声明式 MCP 配置 —— 在项目里放一个 catalog，跑 `sync`，得到一个 `.mcp.json`，SuperAgent 和任何兼容 MCP 客户端都能用。

**第 1 步 —— 建 catalog：**

```bash
mkdir -p .mcp-servers
cat > .mcp-servers/catalog.json <<'EOF'
{
  "mcpServers": {
    "sqlite":     {"command": "uvx",  "args": ["mcp-server-sqlite", "--db", "./app.db"]},
    "brave":      {"command": "npx",  "args": ["@brave/mcp"], "env": {"BRAVE_API_KEY": "${BRAVE_API_KEY}"}},
    "filesystem": {"command": "npx",  "args": ["-y", "@modelcontextprotocol/server-filesystem", "."]}
  },
  "domains": {
    "baseline": ["filesystem"],
    "research": ["filesystem", "brave"],
    "all":      ["filesystem", "brave", "sqlite"]
  }
}
EOF
```

**第 2 步 —— 预览 + 应用：**

```bash
superagent mcp sync --dry-run            # 看会改什么
superagent mcp sync                      # 全量
superagent mcp sync --domain=baseline    # 仅 "baseline" 域
superagent mcp sync --servers=brave,sqlite
```

非破坏契约 —— 用户编辑过的文件会被保留。`<project>/.superagent/mcp-manifest.json` 的 manifest 追踪我们写过什么；重新 sync 只会碰我们之前拥有的文件。

*v0.9.1 起*

### Wire 协议传输

结构化事件可以发到：stdout / stderr / 文件 / TCP socket / unix socket。IDE 桥接用 listen 变体，让编辑器插件在 agent 启动后再连上。

```bash
# 默认（stdout）：
superagent --output json-stream "修复 bug"

# 持久化到文件，供事后回放：
superagent --output json-stream "修复 bug" > runs/$(date +%s).ndjson
```

编程 listen 模式（让 IDE 连上来）：

```php
$factory = new SuperAgent\CLI\AgentFactory();
[$emitter, $transport] = $factory->makeWireEmitterForDsn('listen://unix//tmp/agent.sock');

$agent = new Agent([
    'provider' => 'openai',
    'options'  => ['wire_emitter' => $emitter],
]);
$agent->run($prompt);
$transport->close();
```

*Socket / TCP / file 传输 v0.9.1 起。*

### Shadow-git checkpoint

Agent 驱动修改的文件级撤销。shadow 仓库在 `~/.superagent/history/<project-hash>/shadow.git` —— 永远不碰你项目自己的 `.git`。

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\GitShadowStore;

$mgr = new CheckpointManager(
    shadowStore: new GitShadowStore(getcwd()),
);
$mgr->createCheckpoint($agentState, label: 'before-refactor');

// 破坏性运行之后：
$list = $mgr->list();
$mgr->restoreFiles($list[0]);   // 把已跟踪文件回滚到 snapshot
```

不需要额外配置 —— shadow git 仓库在首次 snapshot 时懒创建。要求 PATH 里有 `git`。

*v0.9.0 起*

---

## 验证

### 冒烟测试

```bash
superagent --version
superagent --help
superagent health --all --json    # 探测每个已知 provider
```

### 端到端运行

```bash
superagent "这个项目目标哪个 PHP 版本？读 composer.json 回答"
```

应打印版本号并退出 0。如果一直卡住，SSE idle timeout（默认 5 分钟）最终会杀掉连接 —— 网络特别慢时可调 `stream_idle_timeout_ms`。

### CI 冒烟

```bash
set -e
superagent health --json | tee health.json
jq -e '. | map(select(.ok == true)) | length > 0' health.json
```

任何已配置 provider 探测失败都返回非零。

---

## 故障排查

**`superagent: command not found`** —— Composer 全局 bin 目录不在 `PATH`。跑 `composer global config bin-dir --absolute`，把结果加到 shell profile。

**`No API key in environment`** —— `superagent` 运行的那个 shell 里 `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / 等未设置。检查 `env | grep _API_KEY`。PHP-FPM 下要在 worker 环境里 export，不能只在交互 shell 里设。

**Responses API 抛 `UsageNotIncludedException`** —— 你的 ChatGPT 套餐不含请求的模型。换小模型、升级套餐或改用 `provider: 'openai'` + API key。

**OpenAI Responses 长会话抛 `ContextWindowExceededException`** —— 要么切到 `previous_response_id` 接续模式（只发新轮次），要么在下次运行前压缩历史。见 README 的 [OpenAI Responses API](README_CN.md#openai-responses-api) 小节。

**Agent 卡 5 分钟后超时** —— SSE 流空闲。`stream_idle_timeout_ms` 守护在起作用；根本原因通常是网络路径有问题或 provider 停机。跑 `superagent health` 确认。

**Responses API 抛 `ProviderException: stream closed before response.completed`** —— provider 在终结事件前断流。重试一次；如果反复出现，拿 OpenAI 返回的 request id（带 `--verbose` 可看到）提工单。

**`McpCommand sync` 写 `user-edited` 而不是 `written`** —— 你手动编辑过 `.mcp.json`。要么还原你的编辑、要么删除文件、要么从 `<project>/.superagent/mcp-manifest.json` 里删对应条目让下次 sync 重建。

**PHP-FPM 跑在父 Claude Code shell 里** —— claude 的递归防护会因继承的 `CLAUDECODE=*` env 跳闸。在 pool 配置里 unset：

```ini
env[CLAUDECODE] =
env[CLAUDE_CODE_ENTRYPOINT] =
env[CLAUDE_CODE_SSE_PORT] =
```

**MCP OAuth 登录一直转** —— 设备流程需要你在浏览器里批准。CLI 会打印 URL + 用户码到 stderr；复制 URL、在任何能访问 provider 的地方打开、输入码、批准。登录在 ~30 秒内恢复。

**Unix-socket wire 传输 bind 失败** —— 有陈旧的 socket 文件。`WireTransport` 会在 bind 前自动 unlink 陈旧的 `listen://unix` socket；还不行的话 `lsof -U | grep <sock-path>` 找持有者。

---

## 升级

### 独立 CLI

```bash
# 如果是 composer global 装的：
composer global update forgeomni/superagent

# 如果是 clone 装的：
cd ~/.local/src/superagent && git pull && composer install --no-dev

# 验证：
superagent --version
```

### Laravel 依赖

```bash
composer update forgeomni/superagent
php artisan vendor:publish --tag=superagent-config --force   # 可选，重新发布 config
```

本 release 没有数据库迁移。以前版本的迁移（Laravel 端）还在 —— 没跑过的话 `php artisan migrate`。

### 配置向前兼容

0.9.1 的每个新增项都是 additive 且有合理默认值。已有的 `config/superagent.php` 不用改。要 opt in 0.9.1 特性：

- 加一个 `'openai-responses'` 块启用新 provider
- 加 `'lmstudio'`（如果你本地跑 LM Studio）
- 在需要调优重试行为的 provider 上传 `'request_max_retries'` / `'stream_max_retries'` / `'stream_idle_timeout_ms'`

---

## 卸载

```bash
# 独立 CLI：
composer global remove forgeomni/superagent
# 或者如果你走了软链接 + clone 方案：
rm /usr/local/bin/superagent
rm -rf ~/.local/src/superagent

# 用户数据（凭据、model cache、shadow-git 历史）：
rm -rf ~/.superagent/

# Laravel 依赖：
composer remove forgeomni/superagent
# 如果发布过 config / 迁移，清理一下：
rm config/superagent.php
```

SuperAgent 不碰 `/etc` 和 `/var`，所有东西都在 `~/.superagent/` 和项目自己的目录树里。
