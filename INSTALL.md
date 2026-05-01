# SuperAgent — Installation

> **🌍 Language**: [English](INSTALL.md) | [中文](INSTALL_CN.md) | [Français](INSTALL_FR.md)
> **📖 Docs**: [README](README.md) · [CHANGELOG](CHANGELOG.md) · [Advanced usage](docs/ADVANCED_USAGE.md)

## Contents

- [System requirements](#system-requirements)
- [Install paths](#install-paths)
- [Authentication](#authentication)
- [First-run setup](#first-run-setup)
- [Optional feature setup](#optional-feature-setup)
  - [OpenAI Responses API](#openai-responses-api)
  - [ChatGPT subscription OAuth](#chatgpt-subscription-oauth)
  - [Azure OpenAI](#azure-openai)
  - [Local models (Ollama / LM Studio)](#local-models-ollama--lm-studio)
  - [MCP catalog + sync](#mcp-catalog--sync)
  - [Wire protocol transports](#wire-protocol-transports)
  - [Shadow-git checkpoints](#shadow-git-checkpoints)
- [Verification](#verification)
- [Troubleshooting](#troubleshooting)
- [Upgrade](#upgrade)
- [Uninstall](#uninstall)

---

## System requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1 |
| Composer | 2.0 |
| Extensions | `curl`, `json`, `mbstring`, `openssl` |
| Optional | `pcntl` (fork-based swarm), `proc_open` (sub-agent ProcessBackend — enabled by default on POSIX), `sockets` (wire-protocol unix-socket transport) |
| OS | Linux / macOS / Windows (WSL recommended for Windows) |

Verify PHP + extensions:

```bash
php -v
php -m | grep -E 'curl|json|mbstring|openssl|pcntl|sockets'
```

For Laravel integration add:

| Requirement | Minimum |
|---|---|
| Laravel | 10.0 |
| Database | MySQL 8 / PostgreSQL 14 / SQLite 3.35 (for `ai_usage_logs` if used) |

---

## Install paths

### Standalone CLI (v0.8.6+)

One binary — no Laravel project required. Ship across your fleet, call from any shell, wire into CI.

**Option A — Composer global:**

```bash
composer global require forgeomni/superagent
# Make sure ~/.composer/vendor/bin (or your configured Composer bin dir) is in PATH
```

**Option B — Clone + symlink:**

```bash
git clone https://github.com/forgeomni/superagent.git ~/.local/src/superagent
cd ~/.local/src/superagent
composer install --no-dev
ln -s "$PWD/bin/superagent" /usr/local/bin/superagent
```

**Option C — Bootstrap scripts:**

```bash
# POSIX:
curl -sSL https://raw.githubusercontent.com/forgeomni/superagent/main/install.sh | bash

# Windows PowerShell:
iwr -useb https://raw.githubusercontent.com/forgeomni/superagent/main/install.ps1 | iex
```

Verify:

```bash
superagent --version    # SuperAgent v0.9.6
superagent --help
```

### Laravel dependency

```bash
composer require forgeomni/superagent
php artisan vendor:publish --tag=superagent-config
```

`config/superagent.php` now exists — fill in provider keys and agent defaults. The service provider, facade (`SuperAgent`), and Artisan commands (`superagent:chat`, `superagent:mcp`, `superagent:models`, `superagent:health`) auto-register.

**Multi-tenant hosts** that store credentials in a database row (SaaS platforms, per-workspace provider configuration, etc.) use `ProviderRegistry::createForHost($sdkKey, $hostConfig)` instead of instantiating each provider directly — the SDK owns the `match ($type)` on the constructor shape. See [Host Integrations](README.md#host-integrations) in the README. *Since v0.9.2.*

---

## Authentication

Run exactly one of the four auth setups per provider you plan to use. They compose — an OpenAI API key plus a stored ChatGPT OAuth login can coexist, the agent picks based on `auth_mode`.

### 1. API key in environment

The lowest-friction option. Works for every provider with a bearer endpoint.

```bash
# ~/.bashrc, ~/.zshrc, or a deploy-time .env — whichever your workflow uses:
export ANTHROPIC_API_KEY=sk-ant-...
export OPENAI_API_KEY=sk-...
export GEMINI_API_KEY=...
export KIMI_API_KEY=...
export QWEN_API_KEY=...            # shared by 'qwen' and 'qwen-native'
export GLM_API_KEY=...
export MINIMAX_API_KEY=...
export DEEPSEEK_API_KEY=...        # DeepSeek V4 — since v0.9.6
export OPENROUTER_API_KEY=...
```

Optional scoping headers (since v0.9.1 — declare them once on the agent, they auto-omit when the env isn't set):

```bash
export OPENAI_ORGANIZATION=org-...
export OPENAI_PROJECT=proj-...
```

### 2. Reuse an existing CLI login

If you already use Claude Code, Codex CLI, or Gemini CLI locally, SuperAgent can import their OAuth tokens.

```bash
superagent auth login claude-code     # imports the on-disk Claude Code OAuth token
superagent auth login codex           # imports the on-disk Codex login
superagent auth login gemini          # imports the on-disk Gemini CLI login
superagent auth status                # shows which providers have stored creds
```

### 3. Device-code login (provider-hosted)

For providers that expose an RFC 8628 device flow directly.

```bash
superagent auth login kimi-code       # Moonshot Kimi Code subscription (since v0.9.0)
superagent auth login qwen-code       # Alibaba Qwen Code subscription, PKCE S256 (since v0.9.0)
```

Both commands print a verification URL + user code; approve in the browser, the token persists to `~/.superagent/credentials/<name>.json`.

### 4. Explicit config

Good for CI / secret-manager-driven environments:

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $vaultSecrets['openai_oauth'],
    'account_id'   => $vaultSecrets['openai_account_id'],
    'auth_mode'    => 'oauth',
]);
```

### OAuth refresh safety

Parallel workers sharing one `~/.superagent/credentials/<name>.json` don't race on refresh — `CredentialStore::withLock()` serialises the HTTP call via cross-process file locks, with stale-lock stealing (since v0.9.0). No action required; it's on by default.

---

## First-run setup

Initialise the user directory:

```bash
superagent init
```

Creates:

```
~/.superagent/
├── credentials/         # OAuth tokens (mode 0600)
├── models-cache/        # per-provider cached /models responses
├── storage/             # runtime scratch
├── agents/              # user-scope agent definitions (YAML/MD)
└── device.json          # stable per-install UUID
```

Verify a provider is reachable:

```bash
superagent health             # 5s cURL probe of each configured provider
# Provider      Status    Latency     Reason
# ────────────────────────────────────────────────
# openai        ✓ ok      142ms
# anthropic     ✓ ok       98ms
# kimi          ✗ fail    —           no API key in environment
```

First real run:

```bash
superagent "list the three most recent files in this directory"
```

---

## Optional feature setup

Every feature below is opt-in. Skip any you don't need.

### OpenAI Responses API

Select the dedicated provider instead of `openai`:

```php
new Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);
```

Laravel config:

```php
// config/superagent.php
'providers' => [
    'openai-responses' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => 'gpt-5',
        'store'   => true,    // required for previous_response_id
    ],
],
```

Full feature set (reasoning effort, prompt cache key, verbosity, service tier, continuation) in the README's [OpenAI Responses API](README.md#openai-responses-api) section.

*Since v0.9.1*

### ChatGPT subscription OAuth

Requires a Plus / Pro / Business subscription + a stored ChatGPT access_token. After `superagent auth login codex` (or a host-specific import), the Responses provider auto-routes to `chatgpt.com/backend-api/codex`.

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $token,          // from ~/.superagent/credentials/...
    'account_id'   => $accountId,      // adds chatgpt-account-id header
]);
```

No base-URL override needed — the routing switch is automatic from `auth_mode: 'oauth'`.

*Since v0.9.1*

### Azure OpenAI

Point `base_url` at your Azure resource. Detection is automatic across six base-URL markers (`openai.azure.*`, `cognitiveservices.azure.*`, `aoai.azure.*`, `azure-api.*`, `azurefd.*`, `windows.net/openai`).

```bash
export AZURE_OPENAI_API_KEY=...
export AZURE_OPENAI_BASE=https://my-resource.openai.azure.com/openai/deployments/gpt-5
```

```php
new Agent([
    'provider'          => 'openai-responses',
    'base_url'          => getenv('AZURE_OPENAI_BASE'),
    'api_key'           => getenv('AZURE_OPENAI_API_KEY'),
    'azure_api_version' => '2025-04-01-preview',   // default; override for older deployments
]);
```

Both `api-key` and `Authorization: Bearer ...` headers are sent — Azure honours whichever its gateway expects.

*Since v0.9.1*

### Local models (Ollama / LM Studio)

Both are zero-auth — the SDK sends a placeholder Bearer token to keep Guzzle happy.

**Ollama** (default port 11434):

```bash
# Install + pull a model (outside SuperAgent):
ollama pull llama3.2
ollama serve &
```

```php
new Agent(['provider' => 'ollama', 'model' => 'llama3.2']);
```

**LM Studio** (default port 1234, since v0.9.1):

```bash
# Launch LM Studio app, load a model, enable the OpenAI-compat server.
```

```php
new Agent(['provider' => 'lmstudio', 'model' => 'qwen2.5-coder-7b-instruct']);
```

Override host/port via `base_url`:

```php
new Agent([
    'provider' => 'lmstudio',
    'base_url' => 'http://10.0.0.2:9876',
]);
```

### MCP catalog + sync

Declarative MCP configuration — drop a catalog in your project, run `sync`, get a `.mcp.json` that both SuperAgent and any compatible MCP client can consume.

**Step 1 — create the catalog:**

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

**Step 2 — preview and apply:**

```bash
superagent mcp sync --dry-run            # show what would change
superagent mcp sync                      # full catalog
superagent mcp sync --domain=baseline    # only the "baseline" domain
superagent mcp sync --servers=brave,sqlite
```

Non-destructive contract — user-edited files are left alone. A manifest at `<project>/.superagent/mcp-manifest.json` tracks what we've written; re-syncs only touch files we previously owned.

*Since v0.9.1*

### Wire protocol transports

Pipe structured events to any of: stdout, stderr, file, TCP socket, unix socket. IDE bridges use the listen variants so the editor plugin attaches after the agent starts.

```bash
# Default (stdout):
superagent --output json-stream "fix the bug"

# Persist to a log file for post-hoc replay:
superagent --output json-stream "fix the bug" > runs/$(date +%s).ndjson
```

Programmatic listen-mode for IDE attach:

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

*Socket / TCP / file transports since v0.9.1.*

### Shadow-git checkpoints

File-level undo for agent-driven edits. The shadow repo lives under `~/.superagent/history/<project-hash>/shadow.git` — never touches your project's own `.git`.

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\GitShadowStore;

$mgr = new CheckpointManager(
    shadowStore: new GitShadowStore(getcwd()),
);
$mgr->createCheckpoint($agentState, label: 'before-refactor');

// After a destructive run:
$list = $mgr->list();
$mgr->restoreFiles($list[0]);   // revert tracked files to the snapshot
```

No extra config — the shadow git repo is created lazily on first snapshot. Requires `git` on PATH.

*Since v0.9.0*

---

## Verification

### Smoke tests

```bash
superagent --version
superagent --help
superagent health --all --json    # probe every known provider
```

### End-to-end run

```bash
superagent "what PHP version does this project target? read composer.json to answer"
```

Should print the version and exit 0. If it hangs, the SSE idle timeout (default 5 minutes) will kill the connection eventually — tune via `stream_idle_timeout_ms` if your network is particularly sluggish.

### CI smoke test

```bash
set -e
superagent health --json | tee health.json
jq -e '. | map(select(.ok == true)) | length > 0' health.json
```

Exit non-zero when any configured provider fails its probe.

---

## Troubleshooting

**`superagent: command not found`** — Composer's global bin dir isn't in `PATH`. Run `composer global config bin-dir --absolute` and add that to your shell profile.

**`No API key in environment`** — the `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / etc. env var isn't set in the shell where `superagent` runs. Check `env | grep _API_KEY`. Under PHP-FPM, ensure the key is exported in the worker's environment (not just interactive shell).

**Responses API returns `UsageNotIncludedException`** — your ChatGPT plan doesn't include the model you requested. Either downgrade the model, upgrade the plan, or switch to `provider: 'openai'` with an API key.

**`ContextWindowExceededException` on long sessions with OpenAI Responses** — either switch to the `previous_response_id` continuation pattern (send only the new turn) or compact history before the next run. See the [OpenAI Responses API](README.md#openai-responses-api) section in the README.

**Agent hangs for 5 minutes then times out** — the SSE stream went idle. This is the `stream_idle_timeout_ms` guard firing; the underlying issue is usually a bad network path or a provider outage. Run `superagent health` to confirm which.

**`ProviderException: stream closed before response.completed`** on the Responses API — the provider dropped the stream before the terminal event. Retry once; if it recurs, file a support ticket with the request id OpenAI returned (visible via `--verbose`).

**`McpCommand sync` writes `user-edited` instead of `written`** — you've hand-edited the `.mcp.json`. Either revert your edits, remove the file, or delete the matching entry from `<project>/.superagent/mcp-manifest.json` to let the next sync regenerate it.

**PHP-FPM under a parent Claude Code shell** — claude's recursion guard trips on inherited `CLAUDECODE=*` env vars. Unset them in the pool config:

```ini
env[CLAUDECODE] =
env[CLAUDE_CODE_ENTRYPOINT] =
env[CLAUDE_CODE_SSE_PORT] =
```

**MCP OAuth login hangs** — the device flow needs you to approve in a browser. The CLI prints the URL + user code to stderr; copy the URL, open it anywhere that can reach the provider, enter the code, approve. Login resumes within ~30 seconds.

**Unix-socket wire transport fails to bind** — a stale socket file exists. `WireTransport` unlinks stale `listen://unix` sockets automatically before binding; if it still fails, `lsof -U | grep <sock-path>` to find the holder.

---

## Upgrade

### Standalone CLI

```bash
# If installed via composer global:
composer global update forgeomni/superagent

# If installed via clone:
cd ~/.local/src/superagent && git pull && composer install --no-dev

# Verify:
superagent --version
```

### Laravel dependency

```bash
composer update forgeomni/superagent
php artisan vendor:publish --tag=superagent-config --force   # optional — re-publishes config
```

No database migrations ship with this release. Previous releases' migrations (Laravel-only) still apply — `php artisan migrate` if you haven't.

### Config forward-compatibility

Every 0.9.1 addition is additive with sensible defaults. Existing `config/superagent.php` files need no changes. To opt into 0.9.1 features:

- Add a `'openai-responses'` block for the new provider
- Add `'lmstudio'` if you run a local LM Studio server
- Pass `'request_max_retries'` / `'stream_max_retries'` / `'stream_idle_timeout_ms'` on any provider that needs tuned retry behaviour

---

## Uninstall

```bash
# Standalone CLI:
composer global remove forgeomni/superagent
# Or remove the symlink + clone directory if you went that way:
rm /usr/local/bin/superagent
rm -rf ~/.local/src/superagent

# User data (credentials, model cache, shadow-git history):
rm -rf ~/.superagent/

# Laravel dependency:
composer remove forgeomni/superagent
# Clean up config + migrations if you published them:
rm config/superagent.php
```

Nothing in `/etc` or `/var` is touched by SuperAgent — everything lives under `~/.superagent/` and the project's own tree.
