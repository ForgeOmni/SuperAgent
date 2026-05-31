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
superagent --version    # SuperAgent v0.9.8
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
export XAI_API_KEY=...             # xAI Grok — v1.0.8 起（也接受 GROK_API_KEY）
export OPENROUTER_API_KEY=...

# DeepSeek 多上游 relay (v0.9.8) —— 同一份 V4 权重的不同入口。
# DEEPSEEK_API_KEY 也能配 upstream='openrouter' 等使用。
export NVIDIA_NIM_API_KEY=...
export FIREWORKS_API_KEY=...
export NOVITA_API_KEY=...

# 子 agent 递归深度上限 (v0.9.8)。默认 5；深度工作流可以调高。
export SUPERAGENT_MAX_AGENT_DEPTH=5

# Kimi Agent Swarm (v1.0.10) 为实验特性，默认关闭 —— Moonshot 尚未公布公开的
# Swarm REST 规范，因此除非显式开启（且仅指向预览/私有端点），`kimi_swarm`
# 工具会直接报错。
export SUPERAGENT_KIMI_SWARM_ENABLED=1
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

### Smart 模式（基于评测分数的编排）

两步走。先在你实际有 key 的模型上跑一轮能力评测，写出分数 catalog：

```bash
# 在内置评测案例（coding / reasoning / json_mode / instruction_following）
# 上探测每个模型的强项,写到 ~/.superagent/model_scores.json。
superagent eval run

# 看一下结果：
superagent eval show
```

然后跑任务。Orchestrator 读这份 catalog 来挑「brain」模型做 plan + merge,并把每个子任务路由到该维度上分数最高的模型：

```bash
superagent smart "<task>"                   # 端到端
superagent smart "<task>" --dry-run         # 只出 plan，不执行
superagent smart "<task>" --max-cost 0.50   # 累计花费超过上限就中止
superagent smart "<task>" --max-parallel 3  # 并发子进程上限（默认 4）
superagent smart "<task>" --json | jq       # stdout 是 JSON，事件走 stderr

# 查看持久化的运行：
superagent smart show                       # 最近 20 条
superagent smart show <id|--last>           # 单次运行的 plan + 子任务输出
superagent smart replay <id|--last>         # 用新的路由参数重放已保存的 plan
```

REPL：在 `superagent` 交互模式里 `/smart <task>` 直接内联跑同样的编排。

交互式 REPL 还内置了 Opus 4.8 harness 斜杠命令 —— `/workflows`、`/ultraplan`、`/ultrareview`，以及 `/deep-research <问题>`（扇出式联网调研 → 核验 → 带引用的报告，v1.0.9 新增）。它们都会生成会话级动态工作流，可用 `/workflows plan <id>` 查看、`/workflows run <id> --run` 运行；完整参考见 [ADVANCED_USAGE §87](docs/ADVANCED_USAGE.md)。

运行日志写到 `~/.superagent/smart_runs/<ISO>_<shortid>.json`。完整管线和参数见 [ADVANCED_USAGE §59](docs/ADVANCED_USAGE.md#59-superagent-smart--eval-score-driven-orchestration)。

*v0.9.9 起（CLI 子命令 + 护栏）。*

### Squad 模式（自适应跨模型小队）

Squad 模式是 auto 模式的对等协作变体：每个子任务按难度档（TRIVIAL/EASY/MODERATE/HARD/EXPERT）路由到对应模型。无主控 agent，HITL 卡点内嵌，任意步骤可断点续跑。通过 `superagent auto` 开启。

环境变量（写到 `.env` 或 provider 配置）：

```bash
SUPERAGENT_PREFER_SQUAD=true            # 默认开启；置 false 回退到旧的 multi-agent 主从模式
SUPERAGENT_SQUAD_MAX_COST=5.00          # USD 预算，剩余步骤在 80% 时自动降档
SUPERAGENT_SQUAD_CHECKPOINT_DIR=/var/lib/superagent/squad   # 每步 JSON 快照目录
```

触发方式：

```bash
# Auto 模式在 prompt 跨 2+ 个难度档时自动选 squad
superagent auto "1. 调研认证模块  2. 设计迁移方案  3. 实现 OAuth2"

# 强制启用 squad（即使启发式判断不会选）：
superagent auto "<task>" --squad

# 单次禁用 squad：
superagent auto "<task>" --no-squad

# 单次成本上限（覆盖 SUPERAGENT_SQUAD_MAX_COST）：
superagent auto "<task>" --max-cost 2.50
```

默认 `ModelTierMap` 是跨厂商的（Anthropic + DeepSeek）。可在 `config/superagent.php` 单独覆盖任意一档：

```php
'squad' => [
    'tier_map' => [
        'expert' => ['provider' => 'openai', 'model' => 'gpt-5-pro'],
    ],
],
```

完整说明（分解规则、并行组、resume 语义、checkpoint 文件格式）见 [ADVANCED_USAGE §60](docs/ADVANCED_USAGE.md#60-squad-mode--adaptive-cross-model-squad)。

*v0.9.9 起。*

### YAML 团队库 *(v1.0.1)*

SDK 在 `resources/squad-teams/` 自带 21 个开箱即用的 squad 团队。零配置 —— `Squad\TeamRegistry` 自动发现。

```bash
# 列出 registry 中所有团队（自带 + host 叠加）：
php -r "require 'vendor/autoload.php'; print_r((new SuperAgent\Squad\TeamRegistry())->list());"

# 跑某个团队（任何 agent dispatcher 都可以 —— 见 ADVANCED_USAGE §61）：
superagent auto --squad --team code-review-loop "<task>"
```

要在自带库之上叠加自己的团队 YAML，把 registry 指向额外目录：

```php
use SuperAgent\Squad\TeamRegistry;

$registry = new TeamRegistry();
$registry->addDirectory('/etc/myapp/squad-teams');   // 同名覆盖自带的
$plan = $registry->require('my-custom-team');
```

后注册的目录覆盖先注册的；运行时 `register($name, $plan)` 覆盖一切。和 `ModelCatalog` 同样的三层模式。

**SquadPlan 仍然可以 PHP 定义** —— YAML 只是产 SquadPlan 的一种方式，直接 `new SquadPlan(...)` 也完全等价：

```php
use SuperAgent\Squad\{SquadPlan, SubTask, ReviewerLoopBinding, DifficultyClass};

$plan = new SquadPlan(
    name: 'my-custom-team',
    description: 'Code review with feedback injection',
    subTasks: [
        new SubTask('write', 'writer', '{{task}}', DifficultyClass::HARD),
        new SubTask('review', 'reviewer', "Artefact:\n{{steps.write.output}}", DifficultyClass::EXPERT, ['write']),
    ],
    tierMap: [
        'hard'   => ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'],
        'expert' => ['provider' => 'openai',    'model' => 'gpt-5.1-codex'],
    ],
    loops: [new ReviewerLoopBinding('write', 'review', 'review.feedback', maxRetries: 3)],
);
$registry->register('my-custom-team', $plan);
```

### 跨模式协同 *(v1.0.1)*

三模式（`auto / smart / squad`）共享 `ModeContext`，可以嵌套、切换、把成本累加到同一份 ledger。绝大多数调用者无需新加环境变量 —— 一旦 YAML 某步声明 `mode: smart` 或 `mode: squad`，递归自动发生。

可选的策略调优（写进 `.env`）：

```bash
# 跨模式递归最大深度，超出抛错。默认 4。
SUPERAGENT_MODE_MAX_DEPTH=4

# 整个嵌套调用的硬成本上限。默认无限。
SUPERAGENT_MODE_BUDGET_USD=10.00

# 是否在 ReviewerLoopRunner 耗尽 max_retries 时升级到更大的模式。
# 默认 true。目标模式（默认 `smart`）由 SUPERAGENT_MODE_ESCALATE_TO 控制。
SUPERAGENT_MODE_AUTO_ESCALATE=true
SUPERAGENT_MODE_ESCALATE_TO=smart
```

完整参考（ModeContext 生命周期、SPI 注入、循环检测、ReviewerLoopRunner 升级）见 [ADVANCED_USAGE §62](docs/ADVANCED_USAGE.md#62-cross-mode-orchestration)。

### Gemini 3.5 *(v1.0.5)*

无需额外安装 —— `gemini-3.5-pro` / `gemini-3.5-flash` / `gemini-3.5-flash-lite` 已经在内置 `resources/models.json` 里。配好 key 即可：

```bash
export GEMINI_API_KEY=AIzaSy…    # AI Studio key，或 VERTEX_* 用 OAuth/Vertex
superagent --provider gemini --model gemini-3.5-pro "解释这个文件" ./src/Foo.php
```

Provider 默认模型现在是 `gemini-3.5-flash`；最难的任务用 `--model gemini-3.5-pro`，最便宜的用 `--model gemini-3.5-flash-lite`。

### LSP servers *(v1.0.5)*

`Tools\Builtin\LSPTool` 从 PATH 自动启动 language server。装上你需要的那几个；probe 失败时 agent 不会 spawn。

```bash
# PHP
composer global require phpactor/phpactor
# 或者：npm i -g intelephense

# JS/TS
npm i -g typescript-language-server typescript

# Go
go install golang.org/x/tools/gopls@latest

# Rust
rustup component add rust-analyzer

# Python
npm i -g pyright

# C/C++
brew install llvm        # 或 apt install clangd

# Bash
npm i -g bash-language-server
```

验证是否被发现：

```bash
superagent run --tool LSPTool --tool-input '{"action":"diagnostics","path":"/abs/path/to/file.php"}'
```

### 自动 formatter *(v1.0.5)*

`Format\Formatters` 探测约 26 种 formatter；每个只在项目明确声明时才触发（如 Pint 需要 `composer.json` 列出 `laravel/pint`，Prettier 需要 `package.json` 列出）。装上你技术栈用的那几个：

```bash
# PHP —— 项目级（推荐）
composer require --dev laravel/pint

# JS/TS —— 项目级
npm i -D prettier
# 或者：npm i -D --save-exact @biomejs/biome

# Python
pip install ruff
# 或者：uv tool install ruff

# Go / Rust / Zig / Terraform —— toolchain 自带

# Shell
brew install shfmt
```

### ACP server *(v1.0.5)*

无需安装 —— JSON-RPC stdio server 已在包内。说 ACP 的编辑器像配 MCP server 一样接入：

```jsonc
// Zed settings.json
{
  "assistant": {
    "agents": {
      "superagent": {
        "command": "superagent",
        "args": ["acp"]
      }
    }
  }
}
```

之后在 Zed 里 `Cmd-Shift-A` 把 SuperAgent 选为活动 agent。

### 外部 Skill 自动发现 *(v1.0.5)*

`SkillManager::discoverExternalSkills()` 是 opt-in 的 —— 从 host 调用或接到 agent factory。Skills 会从 cwd 到项目根之间的任意路径自动加载：

```
.claude/skills/<name>/SKILL.md
.agents/skills/<name>/SKILL.md
skills/<name>/SKILL.md          （仅项目根目录）
skill/<name>/SKILL.md           （仅项目根目录）
```

每个 SKILL.md 是 Markdown 文件，含 YAML frontmatter（`name:`、`description:`）+ skill 正文。Walk 在 worktree 边界停止，避免 monorepo 父目录污染子项目。

### Tracing 与可观测性 *(v1.0.6)*

Tracing 默认开启，把 Chrome Trace Event JSON 文件写到 `sys_get_temp_dir()/superagent-traces/`。三个环境变量控制：

```bash
export SUPERAGENT_TRACE_ENABLED=true               # 默认 true
export SUPERAGENT_TRACE_PATH=/var/log/sa-traces    # 默认 sys_get_temp_dir()/superagent-traces
export SUPERAGENT_TRACE_RING_SIZE=2048             # 默认 1024 事件
```

推荐查看器：

- **`ui.perfetto.dev`** —— 首选。拖入 trace JSON 文件。
- **`chrome://tracing`** —— Chrome 自带的查看器（老牌但仍然可用）。
- **`docs/cookbook/`** 里的片段直接引用文件格式。

对高 RPS gateway 而言 ring buffer 也是一笔开销，可以 `SUPERAGENT_TRACE_ENABLED=false`，或者在 DI 容器里注入一个禁用版本的 `TraceCollector`。

Pi 对齐的 `PiEventStream` 是单独的 listener 式发射器 —— 在 bootstrap 里订阅一个 `PiEventStreamWriter`：

```php
use SuperAgent\Tracing\PiEventStream;
use SuperAgent\Tracing\PiEventStreamWriter;

PiEventStream::subscribe(new PiEventStreamWriter(
    storage_path('sa-sessions/' . $sessionId . '.events.jsonl')
));
```

### RTK 结构化输出压缩 *(v1.0.6)*

零配置 —— `Tools\Compression\RtkPipeline` 已接到 `QueryEngine`，默认对每个非错误工具结果生效。需要原始字节保真（比如要把输出喂给 `git apply` 而 git-apply 需要每一行 context）时按调用关闭：

```php
$result = $agent->run($prompt, ['disable_rtk_compression' => true]);
```

host 也可以为自定义工具注册额外压缩器：

```php
use SuperAgent\Tools\Compression\RtkPipeline;
use SuperAgent\Tools\Compression\CompressorInterface;

$pipeline = new RtkPipeline();
$pipeline->register('my_custom_tool', new MyCompressor());
```

完整注册表和按工具节省比详见 [ADVANCED_USAGE §83](docs/ADVANCED_USAGE_CN.md)。

### Qwen 3.7 / Qwen-Anthropic *(v1.0.6)*

Qwen 默认模型现在是 `qwen3.7-max`（1M ctx、$2.50 / $7.50 每 1M token、原生 Anthropic 协议支持）。三种 provider key 可访问 Qwen：

```php
// OpenAI-compat 端点（建议默认走这个，与 SDK 其他部分一致）
$agent = new Agent(['provider' => 'qwen', 'api_key' => env('DASHSCOPE_API_KEY')]);

// DashScope native 端点（只有需要 thinking_budget 控制（3.6 系列）时用）
$agent = new Agent(['provider' => 'qwen-native', 'api_key' => env('DASHSCOPE_API_KEY')]);

// Anthropic 协议兼容端点（Claude Code 客户端直插）
$agent = new Agent(['provider' => 'qwen-anthropic', 'api_key' => env('DASHSCOPE_API_KEY')]);
```

> 2026-05-22 阿里还没在英文文档里正式公布 `qwen-anthropic` 的端点 URL。默认 `https://dashscope.aliyuncs.com/anthropic-mode/v1` 是合理猜测；如果 404，请通过 `base_url` 覆盖。装了 qwen-code v0.16+ 后，可以在 `~/.qwen/settings.json` 里看是否有 `anthropic-base-url` 字段。

Qwen OAuth 已于 2026-04-15 停用 —— 只支持 API key 认证。

### Pi session 导入 *(v1.0.6)*

把已有的 pi session（`~/.pi/agent/sessions/`）回放到 SuperAgent：

```php
use SuperAgent\Conversation\Importers\PiImporter;

$importer = new PiImporter();
foreach ($importer->listSessions(50) as $row) {
    echo "{$row['id']}  {$row['started_at']}  {$row['first_user_message']}\n";
}

$messages = $importer->load('/abs/path/to/2026-05-22_abc123.jsonl');
// → SuperAgent\Messages\Message[]，可以直接喂给一个 Agent 作为初始历史
```

无需额外配置 —— `~/.pi/agent/sessions` 是默认 root；如果 host 用了非标准路径，通过构造参数覆盖。

### 供应链 CI *(v1.0.6)*

新的 GitHub Actions workflow（`.github/workflows/supply-chain.yml`）在每次 push、PR、以及每周一早晨执行三条规则：

1. `composer validate --strict`
2. `composer audit --no-dev`（Symfony 安全公告）
3. 不允许声明 composer 生命周期脚本（`post-install-cmd`、`post-update-cmd` 等等）—— 安装时用 `--no-scripts`。

如果你 fork SDK，这个 workflow 开箱即用；如果你通过 Composer 嵌入它，建议你也在自己侧用 `--no-scripts` 来强制相同的安全策略。

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
