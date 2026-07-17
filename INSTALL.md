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
superagent --version    # SuperAgent v1.1.7
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
export XAI_API_KEY=...             # xAI Grok — since v1.0.8 (GROK_API_KEY also accepted)
export OPENROUTER_API_KEY=...

# DeepSeek multi-upstream relays (v0.9.8) — same V4 weights, alternate hosts.
# DEEPSEEK_API_KEY also works against these via upstream='openrouter' etc.
export NVIDIA_NIM_API_KEY=...
export FIREWORKS_API_KEY=...
export NOVITA_API_KEY=...

# Sub-agent recursion cap (v0.9.8). Default 5; raise for deep workflows.
export SUPERAGENT_MAX_AGENT_DEPTH=5

# Kimi Agent Swarm (v1.0.10) is EXPERIMENTAL and OFF by default — Moonshot has
# not published a public Swarm REST spec, so the `kimi_swarm` tool errors out
# unless you opt in (only point this at a preview/private endpoint).
export SUPERAGENT_KIMI_SWARM_ENABLED=1

# SmartFlow (v1.1.0) — cross-model dynamic flows. All optional.
export MULTI_AI_FAKE_PROVIDER=1         # force zero-cost rehearsal everywhere
export SUPERAGENT_FLOW_CONCURRENCY=4    # max parallel workers (process pool)
export SUPERAGENT_FLOW_DIR=...          # call-ledger dir (default ~/.superagent/flows)
export SUPERAGENT_FLOW_BUDGET_USD=2.0   # hard USD ceiling per run (unset = unbounded)
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

### Smart mode (eval-score-driven orchestration)

Two-step setup. First, build a score catalog by evaluating the models you actually have keys for:

```bash
# Probe model strengths on the bundled eval cases — coding / reasoning /
# json_mode / instruction_following — and write ~/.superagent/model_scores.json.
superagent eval run

# Inspect what you got:
superagent eval show
```

Then run a task. The orchestrator reads that catalog to pick a "brain" model for planning + merging, and routes each subtask to the model that scored best on the relevant dimension:

```bash
superagent smart "<task>"                   # end-to-end
superagent smart "<task>" --dry-run         # plan only, no execution
superagent smart "<task>" --max-cost 0.50   # abort if running spend exceeds the cap
superagent smart "<task>" --max-parallel 3  # cap concurrent subprocesses (default 4)
superagent smart "<task>" --json | jq       # JSON envelope on stdout, events on stderr

# Inspect persisted runs:
superagent smart show                       # newest 20
superagent smart show <id|--last>           # one run's plan + subtask outputs
superagent smart replay <id|--last>         # re-execute a saved plan with new routing knobs
```

REPL: inside `superagent` interactive mode, `/smart <task>` runs the same orchestration inline.

The interactive REPL also carries the Opus 4.8 harness slash commands — `/workflows`, `/ultraplan`, `/ultrareview`, and `/deep-research <question>` (fan-out web research → verify → cited report, added v1.0.9). Each builds a session-scoped dynamic workflow you can inspect with `/workflows plan <id>` and run with `/workflows run <id> --run`; full reference in [ADVANCED_USAGE §87](docs/ADVANCED_USAGE.md).

Run logs persist to `~/.superagent/smart_runs/<ISO>_<shortid>.json`. The full pipeline + flag reference is in [ADVANCED_USAGE §59](docs/ADVANCED_USAGE.md#59-superagent-smart--eval-score-driven-orchestration).

*Since v0.9.9 (CLI subcommand + guardrails).*

### Squad mode (Adaptive Cross-Model Squad)

Squad mode is a peer-collaboration variant of auto mode: each subtask is dispatched to a model picked by its difficulty class (TRIVIAL/EASY/MODERATE/HARD/EXPERT). No master agent, HITL gates inline, resumable from any step. Reached through `superagent auto` once you flip it on.

Environment toggles (drop into `.env` or your provider config):

```bash
SUPERAGENT_PREFER_SQUAD=true            # default; flip false to keep legacy multi-agent
SUPERAGENT_SQUAD_MAX_COST=5.00          # USD cap; remaining steps downshift at 80 %
SUPERAGENT_SQUAD_CHECKPOINT_DIR=/var/lib/superagent/squad   # per-step JSON snapshots
```

Triggers:

```bash
# Auto mode picks squad automatically when the prompt spans 2+ difficulty bands.
superagent auto "1. research the auth module  2. design migration  3. implement"

# Force squad even when the heuristic wouldn't:
superagent auto "<task>" --squad

# Disable squad for this invocation:
superagent auto "<task>" --no-squad

# Per-run cost cap (overrides SUPERAGENT_SQUAD_MAX_COST):
superagent auto "<task>" --max-cost 2.50
```

The default `ModelTierMap` is cross-vendor (Anthropic + DeepSeek). Override individual bands in `config/superagent.php`:

```php
'squad' => [
    'tier_map' => [
        'expert' => ['provider' => 'openai', 'model' => 'gpt-5-pro'],
    ],
],
```

The full mode reference (decomposition rules, parallel groups, resume semantics, checkpoint format) is in [ADVANCED_USAGE §60](docs/ADVANCED_USAGE.md#60-squad-mode--adaptive-cross-model-squad).

*Since v0.9.9.*

### SmartFlow — cross-model dynamic flows *(v1.1.0)*

A cross-model port of Claude Code's `Workflow` engine. No setup beyond your usual provider keys; flows run on whatever providers you have configured. Rehearse any flow end-to-end at zero token cost first:

```bash
# List the 11 built-in flows.
superagent flow list

# Rehearse with the deterministic fake provider — $0.00, no keys needed.
superagent flow run dev-from-scratch --args goal="a todo CLI" --rehearse

# Run for real (cross-model: pin providers per role in the flow / personas).
superagent flow run research-trio --args question="..."

# Resume: replay the unchanged prefix from the call-ledger, rerun only what changed.
superagent flow run research-trio --args question="..." --resume <run-id>
```

Call-ledgers are written under `~/.superagent/flows/` (override with `SUPERAGENT_FLOW_DIR`). Add your own flows as YAML under `./flows` or `./.superagent/flows`. Full guide: [docs/smartflow.md](docs/smartflow.md) and [ADVANCED_USAGE §90](docs/ADVANCED_USAGE.md#90-smartflow--cross-model-dynamic-flows-v110).

### YAML team library *(v1.0.1)*

The SDK ships 21 ready-to-use squad teams as YAML under `resources/squad-teams/`. No configuration is required — they're auto-discovered by `Squad\TeamRegistry`.

```bash
# List every team known to the registry (bundled + host overlays):
php -r "require 'vendor/autoload.php'; print_r((new SuperAgent\Squad\TeamRegistry())->list());"

# Run one (any agent dispatcher works — see ADVANCED_USAGE §61):
superagent auto --squad --team code-review-loop "<task>"
```

To layer your own team YAMLs on top of the bundled set, point the registry at a directory at boot:

```php
use SuperAgent\Squad\TeamRegistry;

$registry = new TeamRegistry();
$registry->addDirectory('/etc/myapp/squad-teams');   // overrides bundled by name
$plan = $registry->require('my-custom-team');
```

Later directories override earlier ones; runtime `register($name, $plan)` overrides everything. Same 3-tier pattern as `ModelCatalog`.

### Cross-mode orchestration *(v1.0.1)*

The three modes (`auto / smart / squad`) now share a `ModeContext` so they can nest, hand off, and accumulate cost in one ledger. Most callers don't need new env vars — recursion happens automatically when a YAML step declares `mode: smart` or `mode: squad`.

Optional policy tuning (drop into `.env`):

```bash
# Maximum cross-mode recursion depth before throwing. Default 4.
SUPERAGENT_MODE_MAX_DEPTH=4

# Hard cost cap across the whole nested run. Default unlimited.
SUPERAGENT_MODE_BUDGET_USD=10.00

# Whether ReviewerLoopRunner escalates to a bigger mode on max_retries.
# Default true. Target mode (default `smart`) controlled by SUPERAGENT_MODE_ESCALATE_TO.
SUPERAGENT_MODE_AUTO_ESCALATE=true
SUPERAGENT_MODE_ESCALATE_TO=smart
```

Full reference (ModeContext lifecycle, SPI installation, cycle detection, ReviewerLoopRunner escalation) is in [ADVANCED_USAGE §62](docs/ADVANCED_USAGE.md#62-cross-mode-orchestration).

### Gemini 3.5 *(v1.0.5)*

Nothing to install beyond the standard package — `gemini-3.5-pro` / `gemini-3.5-flash` / `gemini-3.5-flash-lite` already live in the bundled `resources/models.json`. Set the key once:

```bash
export GEMINI_API_KEY=AIzaSy…    # AI Studio key, or VERTEX_* for OAuth/Vertex
superagent --provider gemini --model gemini-3.5-pro "explain this file" ./src/Foo.php
```

The provider default is now `gemini-3.5-flash`; pass `--model gemini-3.5-pro` for hardest tasks or `--model gemini-3.5-flash-lite` for cheapest.

### LSP servers *(v1.0.5)*

`Tools\Builtin\LSPTool` autostarts language servers from PATH. Install whichever you need; the agent only spawns when probe succeeds.

```bash
# PHP
composer global require phpactor/phpactor
# or:  npm i -g intelephense

# JS/TS
npm i -g typescript-language-server typescript

# Go
go install golang.org/x/tools/gopls@latest

# Rust
rustup component add rust-analyzer

# Python
npm i -g pyright

# C/C++
brew install llvm        # or apt install clangd

# Bash
npm i -g bash-language-server
```

Verify the server is discovered:

```bash
superagent run --tool LSPTool --tool-input '{"action":"diagnostics","path":"/abs/path/to/file.php"}'
```

### Auto-formatters *(v1.0.5)*

`Format\Formatters` probes for ~26 formatters; each only fires when the project declares it (e.g. Pint requires `laravel/pint` in `composer.json`, Prettier requires it in `package.json`). Install the ones your stack uses:

```bash
# PHP — project-scoped (preferred)
composer require --dev laravel/pint

# JS/TS — project-scoped
npm i -D prettier
# or:  npm i -D --save-exact @biomejs/biome

# Python
pip install ruff
# or:  uv tool install ruff

# Go / Rust / Zig / Terraform — bundled with the toolchain, no extra step

# Shell
brew install shfmt
```

### ACP server *(v1.0.5)*

No install — the JSON-RPC stdio server lives in the package. Editors that speak ACP wire it up like an MCP server:

```jsonc
// Zed settings.json
{
  "assistant": {
    "agents": {
      "superagent": {
        "command": "superagent",
        "args": ["acp"]      // see superagent acp --help for flags
      }
    }
  }
}
```

Then `Cmd-Shift-A` in Zed picks SuperAgent as the active agent.

### External skill auto-discovery *(v1.0.5)*

`SkillManager::discoverExternalSkills()` is opt-in — call it from your host or wire it into the agent factory. Skills auto-load from any of these paths between cwd and the project root:

```
.claude/skills/<name>/SKILL.md
.agents/skills/<name>/SKILL.md
skills/<name>/SKILL.md          (project root only)
skill/<name>/SKILL.md           (project root only)
```

Each SKILL.md is a Markdown file with YAML frontmatter (`name:`, `description:`) followed by the skill body. The walk stops at the worktree boundary, so a monorepo parent can't bleed skills into a sub-project.

### Tracing & observability *(v1.0.6)*

Tracing is enabled by default and writes Chrome Trace Event JSON files to `sys_get_temp_dir()/superagent-traces/`. Three env vars control it:

```bash
export SUPERAGENT_TRACE_ENABLED=true               # default: true
export SUPERAGENT_TRACE_PATH=/var/log/sa-traces    # default: sys_get_temp_dir()/superagent-traces
export SUPERAGENT_TRACE_RING_SIZE=2048             # default: 1024 events
```

Recommended viewers:

- **`ui.perfetto.dev`** — preferred. Drag and drop the trace JSON file.
- **`chrome://tracing`** — Chrome's built-in viewer (legacy but still works).
- **`docs/cookbook/`** snippets reference the file format directly.

For high-RPS gateways where the singleton ring buffer is too much overhead, set `SUPERAGENT_TRACE_ENABLED=false` or inject a disabled `TraceCollector` into the DI graph.

The Pi-aligned `PiEventStream` is a separate listener-pattern emitter — wire it up by subscribing a `PiEventStreamWriter` in your bootstrap:

```php
use SuperAgent\Tracing\PiEventStream;
use SuperAgent\Tracing\PiEventStreamWriter;

PiEventStream::subscribe(new PiEventStreamWriter(
    storage_path('sa-sessions/' . $sessionId . '.events.jsonl')
));
```

### RTK structured-output compression *(v1.0.6)*

Zero config — `Tools\Compression\RtkPipeline` is wired into `QueryEngine` and fires on every non-error tool result by default. Disable per-call when you need raw byte fidelity (e.g. you're feeding the output to `git apply` and need every context line):

```php
$result = $agent->run($prompt, ['disable_rtk_compression' => true]);
```

Hosts can also register additional compressors for custom tools:

```php
use SuperAgent\Tools\Compression\RtkPipeline;
use SuperAgent\Tools\Compression\CompressorInterface;

$pipeline = new RtkPipeline();
$pipeline->register('my_custom_tool', new MyCompressor());
```

See [ADVANCED_USAGE §83](docs/ADVANCED_USAGE.md) for the full registry and per-tool savings.

### Qwen 3.7 / Qwen-Anthropic *(v1.0.6)*

The default Qwen model is now `qwen3.7-max` (1M context, $2.50/$7.50 per 1M tokens, native Anthropic protocol on the side). Three provider keys access Qwen:

```php
// OpenAI-compat endpoint (recommended for parity with the rest of the SDK)
$agent = new Agent(['provider' => 'qwen', 'api_key' => env('DASHSCOPE_API_KEY')]);

// DashScope native endpoint (use only if you need thinking_budget control — 3.6 family)
$agent = new Agent(['provider' => 'qwen-native', 'api_key' => env('DASHSCOPE_API_KEY')]);

// Anthropic-protocol-compatible endpoint (drop-in for Claude Code clients)
$agent = new Agent(['provider' => 'qwen-anthropic', 'api_key' => env('DASHSCOPE_API_KEY')]);
```

> The `qwen-anthropic` endpoint URL has not been officially documented by Alibaba in English as of 2026-05-22. The default `https://dashscope.aliyuncs.com/anthropic-mode/v1` is a best-guess; override via `base_url` if it 404s. Check `~/.qwen/settings.json` after installing qwen-code v0.16+ for an explicit `anthropic-base-url` field.

Qwen OAuth was discontinued 2026-04-15 — only API key auth is supported.

### Pi session import *(v1.0.6)*

Replay existing pi sessions (`~/.pi/agent/sessions/`) into SuperAgent:

```php
use SuperAgent\Conversation\Importers\PiImporter;

$importer = new PiImporter();
foreach ($importer->listSessions(50) as $row) {
    echo "{$row['id']}  {$row['started_at']}  {$row['first_user_message']}\n";
}

$messages = $importer->load('/abs/path/to/2026-05-22_abc123.jsonl');
// → SuperAgent\Messages\Message[] ready to seed an Agent's history
```

No setup needed — `~/.pi/agent/sessions` is the default root; override via constructor argument if the host uses a non-standard layout.

### Supply-chain CI *(v1.0.6)*

A new GitHub Actions workflow (`.github/workflows/supply-chain.yml`) enforces three rules on every push, PR, and Monday morning:

1. `composer validate --strict`
2. `composer audit --no-dev` (Symfony security advisories)
3. No composer lifecycle scripts (`post-install-cmd`, `post-update-cmd`, …) — the install is run with `--no-scripts`.

If you fork the SDK, this workflow runs out of the box; if you embed it via Composer, the lockdown is enforced on YOUR side at install time when you also pass `--no-scripts` (recommended for security).

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
