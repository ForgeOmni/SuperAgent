# SuperAgent — Installation

> **🌍 Langue**: [English](INSTALL.md) | [中文](INSTALL_CN.md) | [Français](INSTALL_FR.md)
> **📖 Documentation**: [README_FR](README_FR.md) · [CHANGELOG](CHANGELOG.md) · [Utilisation avancée](docs/ADVANCED_USAGE_FR.md)

## Sommaire

- [Prérequis système](#prérequis-système)
- [Chemins d'installation](#chemins-dinstallation)
- [Authentification](#authentification)
- [Configuration première exécution](#configuration-première-exécution)
- [Configuration des fonctionnalités optionnelles](#configuration-des-fonctionnalités-optionnelles)
  - [API OpenAI Responses](#api-openai-responses)
  - [OAuth abonnement ChatGPT](#oauth-abonnement-chatgpt)
  - [Azure OpenAI](#azure-openai)
  - [Modèles locaux (Ollama / LM Studio)](#modèles-locaux-ollama--lm-studio)
  - [Catalogue MCP + sync](#catalogue-mcp--sync)
  - [Transports wire-protocol](#transports-wire-protocol)
  - [Checkpoints shadow-git](#checkpoints-shadow-git)
- [Vérification](#vérification)
- [Dépannage](#dépannage)
- [Mise à jour](#mise-à-jour)
- [Désinstallation](#désinstallation)

---

## Prérequis système

| Exigence | Minimum |
|---|---|
| PHP | 8.1 |
| Composer | 2.0 |
| Extensions | `curl`, `json`, `mbstring`, `openssl` |
| Optionnel | `pcntl` (swarm par fork), `proc_open` (ProcessBackend des sous-agents — activé par défaut sur POSIX), `sockets` (transport unix-socket du wire protocol) |
| OS | Linux / macOS / Windows (WSL recommandé sous Windows) |

Vérifier PHP + extensions :

```bash
php -v
php -m | grep -E 'curl|json|mbstring|openssl|pcntl|sockets'
```

Pour l'intégration Laravel :

| Exigence | Minimum |
|---|---|
| Laravel | 10.0 |
| Base de données | MySQL 8 / PostgreSQL 14 / SQLite 3.35 (pour `ai_usage_logs` si utilisé) |

---

## Chemins d'installation

### CLI autonome (v0.8.6+)

Un binaire — sans projet Laravel. Déployable sur toute votre flotte, appelable depuis n'importe quel shell, intégrable en CI.

**Option A — Composer global :**

```bash
composer global require forgeomni/superagent
# Assurez-vous que ~/.composer/vendor/bin (ou le bin Composer configuré) est dans PATH
```

**Option B — clone + lien symbolique :**

```bash
git clone https://github.com/forgeomni/superagent.git ~/.local/src/superagent
cd ~/.local/src/superagent
composer install --no-dev
ln -s "$PWD/bin/superagent" /usr/local/bin/superagent
```

**Option C — scripts de bootstrap :**

```bash
# POSIX :
curl -sSL https://raw.githubusercontent.com/forgeomni/superagent/main/install.sh | bash

# Windows PowerShell :
iwr -useb https://raw.githubusercontent.com/forgeomni/superagent/main/install.ps1 | iex
```

Vérifier :

```bash
superagent --version    # SuperAgent v0.9.8
superagent --help
```

### Dépendance Laravel

```bash
composer require forgeomni/superagent
php artisan vendor:publish --tag=superagent-config
```

`config/superagent.php` existe maintenant — renseignez les clés provider et défauts agent. Le service provider, la façade (`SuperAgent`) et les commandes Artisan (`superagent:chat`, `superagent:mcp`, `superagent:models`, `superagent:health`) s'enregistrent automatiquement.

**Hôtes multi-tenant** qui stockent les credentials en ligne de base (plateformes SaaS, config provider par workspace, etc.) utilisent `ProviderRegistry::createForHost($sdkKey, $hostConfig)` au lieu d'instancier chaque provider directement — le SDK gère le `match ($type)` sur la forme du constructeur. Voir [Intégrations hôtes](README_FR.md#intégrations-hôtes) dans le README. *Depuis v0.9.2.*

---

## Authentification

Configurez exactement une méthode d'auth par provider utilisé. Les méthodes se composent — une clé API OpenAI et un login OAuth ChatGPT stocké peuvent coexister, l'agent choisit selon `auth_mode`.

### 1. Clé API en variable d'environnement

Option la moins friction. Fonctionne pour tout provider avec endpoint bearer.

```bash
# ~/.bashrc, ~/.zshrc, ou un .env de déploiement — selon votre workflow :
export ANTHROPIC_API_KEY=sk-ant-...
export OPENAI_API_KEY=sk-...
export GEMINI_API_KEY=...
export KIMI_API_KEY=...
export QWEN_API_KEY=...            # partagé par 'qwen' et 'qwen-native'
export GLM_API_KEY=...
export MINIMAX_API_KEY=...
export DEEPSEEK_API_KEY=...        # DeepSeek V4 — depuis v0.9.6
export OPENROUTER_API_KEY=...

# Relais multi-upstream DeepSeek (v0.9.8) — mêmes poids V4, hôtes alternatifs.
# DEEPSEEK_API_KEY fonctionne aussi avec upstream='openrouter' etc.
export NVIDIA_NIM_API_KEY=...
export FIREWORKS_API_KEY=...
export NOVITA_API_KEY=...

# Plafond de récursion sub-agent (v0.9.8). Par défaut 5 ; à monter pour les workflows profonds.
export SUPERAGENT_MAX_AGENT_DEPTH=5
```

En-têtes de scoping optionnels (depuis v0.9.1 — déclarez-les une fois sur l'agent, ils s'omettent si l'env n'est pas défini) :

```bash
export OPENAI_ORGANIZATION=org-...
export OPENAI_PROJECT=proj-...
```

### 2. Réutiliser un login CLI existant

Si vous utilisez déjà Claude Code, Codex CLI ou Gemini CLI localement, SuperAgent peut importer leurs tokens OAuth.

```bash
superagent auth login claude-code     # importe le token OAuth Claude Code sur disque
superagent auth login codex           # importe la connexion Codex
superagent auth login gemini          # importe la connexion Gemini CLI
superagent auth status                # providers avec credentials stockés
```

### 3. Login device-code (hébergé provider)

Pour les providers qui exposent un flux device RFC 8628 directement.

```bash
superagent auth login kimi-code       # abonnement Moonshot Kimi Code (depuis v0.9.0)
superagent auth login qwen-code       # abonnement Alibaba Qwen Code, PKCE S256 (depuis v0.9.0)
```

Chaque commande affiche l'URL de vérification + code utilisateur ; validez dans le navigateur, le token persiste à `~/.superagent/credentials/<name>.json`.

### 4. Config explicite

Bon pour CI / environnements pilotés par secret-manager :

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $vaultSecrets['openai_oauth'],
    'account_id'   => $vaultSecrets['openai_account_id'],
    'auth_mode'    => 'oauth',
]);
```

### Sécurité du refresh OAuth

Les workers parallèles partageant un même `~/.superagent/credentials/<name>.json` ne se marchent pas dessus — `CredentialStore::withLock()` sérialise l'appel HTTP via verrous de fichier cross-process, avec récupération des verrous bloqués (depuis v0.9.0). Aucune action requise, activé par défaut.

---

## Configuration première exécution

Initialiser le répertoire utilisateur :

```bash
superagent init
```

Crée :

```
~/.superagent/
├── credentials/         # tokens OAuth (mode 0600)
├── models-cache/        # réponses /models mises en cache par provider
├── storage/             # scratch runtime
├── agents/              # définitions d'agents utilisateur (YAML/MD)
└── device.json          # UUID stable par installation
```

Vérifier qu'un provider est joignable :

```bash
superagent health             # probe cURL 5s de chaque provider configuré
# Provider      Status    Latency     Reason
# ────────────────────────────────────────────────
# openai        ✓ ok      142ms
# anthropic     ✓ ok       98ms
# kimi          ✗ fail    —           no API key in environment
```

Premier vrai run :

```bash
superagent "liste les trois fichiers les plus récents du répertoire"
```

---

## Configuration des fonctionnalités optionnelles

Chaque fonctionnalité ci-dessous est opt-in. Ignorez celles dont vous n'avez pas besoin.

### API OpenAI Responses

Sélectionnez le provider dédié au lieu de `openai` :

```php
new Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);
```

Config Laravel :

```php
// config/superagent.php
'providers' => [
    'openai-responses' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => 'gpt-5',
        'store'   => true,    // requis pour previous_response_id
    ],
],
```

Ensemble complet des fonctionnalités (reasoning effort, prompt cache key, verbosity, service tier, continuation) dans la section [API OpenAI Responses](README_FR.md#api-openai-responses) du README.

*Depuis v0.9.1*

### OAuth abonnement ChatGPT

Nécessite un abonnement Plus / Pro / Business + un access_token ChatGPT stocké. Après `superagent auth login codex` (ou un import spécifique au host), le provider Responses route automatiquement vers `chatgpt.com/backend-api/codex`.

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $token,          // depuis ~/.superagent/credentials/...
    'account_id'   => $accountId,      // ajoute l'en-tête chatgpt-account-id
]);
```

Pas besoin de surcharger la base URL — le basculement de routage est automatique avec `auth_mode: 'oauth'`.

*Depuis v0.9.1*

### Azure OpenAI

Pointez `base_url` sur votre ressource Azure. Détection automatique via six marqueurs (`openai.azure.*`, `cognitiveservices.azure.*`, `aoai.azure.*`, `azure-api.*`, `azurefd.*`, `windows.net/openai`).

```bash
export AZURE_OPENAI_API_KEY=...
export AZURE_OPENAI_BASE=https://my-resource.openai.azure.com/openai/deployments/gpt-5
```

```php
new Agent([
    'provider'          => 'openai-responses',
    'base_url'          => getenv('AZURE_OPENAI_BASE'),
    'api_key'           => getenv('AZURE_OPENAI_API_KEY'),
    'azure_api_version' => '2025-04-01-preview',   // défaut ; surcharger pour deployments plus anciens
]);
```

Les en-têtes `api-key` ET `Authorization: Bearer ...` sont envoyés — Azure honore celui que sa gateway attend.

*Depuis v0.9.1*

### Modèles locaux (Ollama / LM Studio)

Tous deux sans auth — le SDK envoie un Bearer token placeholder pour que Guzzle passe.

**Ollama** (port 11434 par défaut) :

```bash
# Installer + pull un modèle (hors SuperAgent) :
ollama pull llama3.2
ollama serve &
```

```php
new Agent(['provider' => 'ollama', 'model' => 'llama3.2']);
```

**LM Studio** (port 1234 par défaut, depuis v0.9.1) :

```bash
# Lancez LM Studio, chargez un modèle, activez le serveur OpenAI-compat.
```

```php
new Agent(['provider' => 'lmstudio', 'model' => 'qwen2.5-coder-7b-instruct']);
```

Surcharger host/port via `base_url` :

```php
new Agent([
    'provider' => 'lmstudio',
    'base_url' => 'http://10.0.0.2:9876',
]);
```

### Catalogue MCP + sync

Configuration MCP déclarative — déposez un catalogue dans votre projet, exécutez `sync`, obtenez un `.mcp.json` consommable par SuperAgent et tout client MCP compatible.

**Étape 1 — créer le catalogue :**

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

**Étape 2 — prévisualiser et appliquer :**

```bash
superagent mcp sync --dry-run            # montre ce qui changerait
superagent mcp sync                      # catalogue complet
superagent mcp sync --domain=baseline    # seulement le domaine "baseline"
superagent mcp sync --servers=brave,sqlite
```

Contrat non destructif — les fichiers édités par l'utilisateur sont préservés. Un manifest à `<project>/.superagent/mcp-manifest.json` trace ce que nous écrivons ; les re-syncs ne touchent que les fichiers qui étaient à nous.

*Depuis v0.9.1*

### Transports wire-protocol

Diffuser les événements structurés vers : stdout, stderr, fichier, socket TCP, socket unix. Les ponts IDE utilisent les variantes listen, de sorte que le plugin éditeur se connecte après le démarrage de l'agent.

```bash
# Défaut (stdout) :
superagent --output json-stream "corrige le bug"

# Persister dans un fichier pour replay post-hoc :
superagent --output json-stream "corrige le bug" > runs/$(date +%s).ndjson
```

Mode listen programmatique (l'IDE se connecte) :

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

*Transports socket / TCP / file depuis v0.9.1.*

### Checkpoints shadow-git

Annulation au niveau fichier des éditions pilotées par l'agent. Le repo shadow vit sous `~/.superagent/history/<project-hash>/shadow.git` — ne touche jamais au `.git` de votre projet.

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\GitShadowStore;

$mgr = new CheckpointManager(
    shadowStore: new GitShadowStore(getcwd()),
);
$mgr->createCheckpoint($agentState, label: 'before-refactor');

// Après un run destructif :
$list = $mgr->list();
$mgr->restoreFiles($list[0]);   // réverse les fichiers suivis vers le snapshot
```

Pas de config supplémentaire — le repo shadow est créé à la demande au premier snapshot. `git` doit être dans PATH.

*Depuis v0.9.0*

---

## Vérification

### Smoke tests

```bash
superagent --version
superagent --help
superagent health --all --json    # probe tous les providers connus
```

### Run end-to-end

```bash
superagent "quelle version de PHP ce projet cible ? lis composer.json pour répondre"
```

Doit afficher la version et sortir 0. Si ça bloque, le SSE idle timeout (5 min par défaut) finit par tuer la connexion — ajustez via `stream_idle_timeout_ms` si votre réseau est particulièrement lent.

### Smoke CI

```bash
set -e
superagent health --json | tee health.json
jq -e '. | map(select(.ok == true)) | length > 0' health.json
```

Sortie non nulle si un provider configuré échoue.

---

## Dépannage

**`superagent: command not found`** — le bin global de Composer n'est pas dans `PATH`. Exécutez `composer global config bin-dir --absolute` et ajoutez le résultat à votre profile shell.

**`No API key in environment`** — la variable `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / etc. n'est pas définie dans le shell où `superagent` tourne. Vérifiez `env | grep _API_KEY`. Sous PHP-FPM, assurez-vous que la clé est exportée dans l'env du worker (pas seulement en shell interactif).

**L'API Responses renvoie `UsageNotIncludedException`** — votre plan ChatGPT n'inclut pas le modèle demandé. Changez de modèle, upgradez le plan, ou basculez sur `provider: 'openai'` avec clé API.

**`ContextWindowExceededException` sur longues sessions OpenAI Responses** — basculez au pattern de continuation `previous_response_id` (envoyez seulement le nouveau tour), ou compactez l'historique avant le run suivant. Voir la section [API OpenAI Responses](README_FR.md#api-openai-responses) du README.

**L'agent bloque 5 minutes puis timeout** — le flux SSE est devenu inactif. C'est la garde `stream_idle_timeout_ms` qui se déclenche ; le problème sous-jacent est habituellement un chemin réseau défaillant ou une panne provider. `superagent health` pour confirmer.

**`ProviderException: stream closed before response.completed` sur l'API Responses** — le provider a abandonné le flux avant l'événement terminal. Retry une fois ; si récurrent, ouvrez un ticket de support avec le request id retourné par OpenAI (visible via `--verbose`).

**`McpCommand sync` écrit `user-edited` au lieu de `written`** — vous avez édité à la main `.mcp.json`. Soit annulez vos éditions, soit supprimez le fichier, soit supprimez l'entrée correspondante de `<project>/.superagent/mcp-manifest.json` pour laisser le prochain sync le régénérer.

**PHP-FPM sous un shell parent Claude Code** — la garde de récursion de claude déclenche sur les variables d'env `CLAUDECODE=*` héritées. Unsettez-les dans la config du pool :

```ini
env[CLAUDECODE] =
env[CLAUDE_CODE_ENTRYPOINT] =
env[CLAUDE_CODE_SSE_PORT] =
```

**Le login OAuth MCP bloque** — le flux device attend que vous approuviez dans un navigateur. La CLI affiche l'URL + code utilisateur sur stderr ; copiez l'URL, ouvrez-la où vous voulez (pour atteindre le provider), entrez le code, approuvez. Le login reprend dans ~30 secondes.

**Le transport wire unix-socket échoue au bind** — un fichier socket obsolète existe. `WireTransport` unlink automatiquement les sockets `listen://unix` obsolètes avant le bind ; si ça échoue encore, `lsof -U | grep <sock-path>` pour trouver qui détient le socket.

---

## Mise à jour

### CLI autonome

```bash
# Si installé via composer global :
composer global update forgeomni/superagent

# Si installé via clone :
cd ~/.local/src/superagent && git pull && composer install --no-dev

# Vérifier :
superagent --version
```

### Dépendance Laravel

```bash
composer update forgeomni/superagent
php artisan vendor:publish --tag=superagent-config --force   # optionnel — re-publie la config
```

Aucune migration de base ne ship avec cette release. Les migrations des versions précédentes (Laravel-only) restent applicables — `php artisan migrate` si pas déjà fait.

### Compatibilité ascendante de la config

Chaque addition 0.9.1 est additive avec des défauts raisonnables. Les `config/superagent.php` existants n'ont besoin d'aucun changement. Pour opt-in aux fonctionnalités 0.9.1 :

- Ajoutez un bloc `'openai-responses'` pour le nouveau provider
- Ajoutez `'lmstudio'` si vous faites tourner un serveur LM Studio local
- Passez `'request_max_retries'` / `'stream_max_retries'` / `'stream_idle_timeout_ms'` sur tout provider nécessitant un retry ajusté

---

## Désinstallation

```bash
# CLI autonome :
composer global remove forgeomni/superagent
# Ou retirez le lien + clone si vous avez choisi cette voie :
rm /usr/local/bin/superagent
rm -rf ~/.local/src/superagent

# Données utilisateur (credentials, cache models, historique shadow-git) :
rm -rf ~/.superagent/

# Dépendance Laravel :
composer remove forgeomni/superagent
# Nettoyer la config + migrations si vous les avez publiées :
rm config/superagent.php
```

Rien dans `/etc` ni `/var` n'est touché par SuperAgent — tout vit sous `~/.superagent/` et l'arbre du projet.
