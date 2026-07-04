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
superagent --version    # SuperAgent v1.1.5
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
export XAI_API_KEY=...             # xAI Grok — depuis v1.0.8 (GROK_API_KEY accepté aussi)
export OPENROUTER_API_KEY=...

# Relais multi-upstream DeepSeek (v0.9.8) — mêmes poids V4, hôtes alternatifs.
# DEEPSEEK_API_KEY fonctionne aussi avec upstream='openrouter' etc.
export NVIDIA_NIM_API_KEY=...
export FIREWORKS_API_KEY=...
export NOVITA_API_KEY=...

# Plafond de récursion sub-agent (v0.9.8). Par défaut 5 ; à monter pour les workflows profonds.
export SUPERAGENT_MAX_AGENT_DEPTH=5

# Kimi Agent Swarm (v1.0.10) est EXPÉRIMENTAL et désactivé par défaut — Moonshot
# n'a pas publié de spec REST publique pour le Swarm, donc l'outil `kimi_swarm`
# renvoie une erreur sauf si vous l'activez (à ne pointer que vers un endpoint
# de préversion/privé).
export SUPERAGENT_KIMI_SWARM_ENABLED=1

# SmartFlow (v1.1.0) — flux dynamiques cross-modèle. Tout est optionnel.
export MULTI_AI_FAKE_PROVIDER=1         # forcer la répétition à coût nul partout
export SUPERAGENT_FLOW_CONCURRENCY=4    # workers parallèles max (pool de processus)
export SUPERAGENT_FLOW_DIR=...          # répertoire du registre d'appels (déf. ~/.superagent/flows)
export SUPERAGENT_FLOW_BUDGET_USD=2.0   # plafond USD strict par run (non défini = illimité)
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

### Mode Smart (orchestration par score d'évaluation)

Deux étapes. D'abord, construire un catalogue de scores en évaluant les modèles dont vous avez les clés :

```bash
# Évalue chaque modèle sur les cas livrés (coding / reasoning / json_mode /
# instruction_following) et écrit ~/.superagent/model_scores.json.
superagent eval run

# Inspecter le résultat :
superagent eval show
```

Puis lancer une tâche. L'orchestrateur lit ce catalogue pour choisir un modèle « brain » (planning + merge) et route chaque sous-tâche vers le modèle qui a le meilleur score sur la dimension concernée :

```bash
superagent smart "<task>"                   # bout-en-bout
superagent smart "<task>" --dry-run         # plan seul, sans exécution
superagent smart "<task>" --max-cost 0.50   # interrompt si la dépense dépasse le plafond
superagent smart "<task>" --max-parallel 3  # plafond de sous-processus concurrents (défaut 4)
superagent smart "<task>" --json | jq       # JSON sur stdout, événements sur stderr

# Inspecter les runs persistés :
superagent smart show                       # 20 plus récents
superagent smart show <id|--last>           # plan + sorties de sous-tâches d'un run
superagent smart replay <id|--last>         # rejoue un plan sauvegardé avec d'autres réglages
```

REPL : dans le mode interactif `superagent`, `/smart <task>` lance la même orchestration en ligne.

Le REPL interactif embarque aussi les commandes slash du harness Opus 4.8 — `/workflows`, `/ultraplan`, `/ultrareview`, et `/deep-research <question>` (recherche web en éventail → vérification → rapport sourcé, ajoutée en v1.0.9). Chacune construit un workflow dynamique au niveau de la session, inspectable avec `/workflows plan <id>` et exécutable avec `/workflows run <id> --run` ; référence complète dans [ADVANCED_USAGE §87](docs/ADVANCED_USAGE.md).

Les run-logs vont dans `~/.superagent/smart_runs/<ISO>_<shortid>.json`. Pipeline complet + référence des flags dans [ADVANCED_USAGE §59](docs/ADVANCED_USAGE.md#59-superagent-smart--eval-score-driven-orchestration).

*Depuis v0.9.9 (sous-commande CLI + garde-fous).*

### Mode Squad (Équipe adaptative multi-modèles)

Le mode Squad est une variante en collaboration pair-à-pair du mode auto : chaque sous-tâche est dispatchée vers un modèle choisi par sa classe de difficulté (TRIVIAL/EASY/MODERATE/HARD/EXPERT). Aucun agent maître, verrous HITL en ligne, reprise possible depuis n'importe quelle étape. Accessible via `superagent auto` une fois activé.

Variables d'environnement (à placer dans `.env` ou la config provider) :

```bash
SUPERAGENT_PREFER_SQUAD=true            # défaut ; mettre à false pour garder le multi-agent classique
SUPERAGENT_SQUAD_MAX_COST=5.00          # plafond USD ; les étapes restantes rétrogradent à 80 %
SUPERAGENT_SQUAD_CHECKPOINT_DIR=/var/lib/superagent/squad   # snapshots JSON par étape
```

Déclencheurs :

```bash
# Auto-mode choisit squad automatiquement quand le prompt couvre 2+ bandes de difficulté.
superagent auto "1. étudier le module d'auth  2. concevoir la migration  3. implémenter"

# Forcer squad même quand l'heuristique ne l'aurait pas choisi :
superagent auto "<task>" --squad

# Désactiver squad pour cette invocation :
superagent auto "<task>" --no-squad

# Plafond de coût par exécution (surcharge SUPERAGENT_SQUAD_MAX_COST) :
superagent auto "<task>" --max-cost 2.50
```

La `ModelTierMap` par défaut est multi-fournisseurs (Anthropic + DeepSeek). Surcharger une bande dans `config/superagent.php` :

```php
'squad' => [
    'tier_map' => [
        'expert' => ['provider' => 'openai', 'model' => 'gpt-5-pro'],
    ],
],
```

Référence complète du mode (règles de décomposition, groupes parallèles, sémantique de reprise, format checkpoint) dans [ADVANCED_USAGE §60](docs/ADVANCED_USAGE.md#60-squad-mode--adaptive-cross-model-squad).

*Depuis v0.9.9.*

### SmartFlow — flux dynamiques cross-modèle *(v1.1.0)*

Un portage cross-modèle du moteur `Workflow` de Claude Code. Aucune configuration au-delà de vos clés provider habituelles ; les flux tournent sur les providers que vous avez configurés. Répétez d'abord n'importe quel flux de bout en bout à coût nul :

```bash
# Lister les 11 flux intégrés.
superagent flow list

# Répéter avec le provider fake déterministe — 0 $, aucune clé requise.
superagent flow run dev-from-scratch --args goal="un todo CLI" --rehearse

# Exécuter pour de vrai (cross-modèle : épinglez un provider par rôle dans le flux / les personas).
superagent flow run research-trio --args question="..."

# Reprise : rejouer le préfixe inchangé depuis le registre d'appels, ne réexécuter que ce qui a changé.
superagent flow run research-trio --args question="..." --resume <run-id>
```

Les registres d'appels sont écrits sous `~/.superagent/flows/` (surchargez avec `SUPERAGENT_FLOW_DIR`). Ajoutez vos propres flux en YAML sous `./flows` ou `./.superagent/flows`. Guide complet : [docs/smartflow.md](docs/smartflow.md) et [ADVANCED_USAGE §90](docs/ADVANCED_USAGE.md#90-smartflow--cross-model-dynamic-flows-v110).

### Bibliothèque d'équipes YAML *(v1.0.1)*

Le SDK livre 21 équipes squad prêtes à l'emploi sous `resources/squad-teams/`. Aucune configuration nécessaire — `Squad\TeamRegistry` les découvre automatiquement.

```bash
# Lister toutes les équipes connues du registre (livrées + surcharges hôte) :
php -r "require 'vendor/autoload.php'; print_r((new SuperAgent\Squad\TeamRegistry())->list());"

# Exécuter une équipe (tout dispatcher d'agent fonctionne — voir ADVANCED_USAGE §61) :
superagent auto --squad --team code-review-loop "<task>"
```

Pour superposer vos propres YAML par-dessus la bibliothèque livrée, pointez le registre vers un répertoire au démarrage :

```php
use SuperAgent\Squad\TeamRegistry;

$registry = new TeamRegistry();
$registry->addDirectory('/etc/myapp/squad-teams');   // surcharge les livrées par nom
$plan = $registry->require('my-custom-team');
```

Les répertoires ajoutés plus tard surchargent les précédents ; `register($name, $plan)` runtime surcharge tout. Même schéma à 3 niveaux que `ModelCatalog`.

**SquadPlan reste définissable en PHP** — YAML n'est qu'une façon de produire un SquadPlan ; `new SquadPlan(...)` est strictement équivalent :

```php
use SuperAgent\Squad\{SquadPlan, SubTask, ReviewerLoopBinding, DifficultyClass};

$plan = new SquadPlan(
    name: 'my-custom-team',
    description: 'Code review with feedback injection',
    subTasks: [
        new SubTask('write', 'writer', '{{task}}', DifficultyClass::HARD),
        new SubTask('review', 'reviewer', "Artefact :\n{{steps.write.output}}", DifficultyClass::EXPERT, ['write']),
    ],
    tierMap: [
        'hard'   => ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'],
        'expert' => ['provider' => 'openai',    'model' => 'gpt-5.1-codex'],
    ],
    loops: [new ReviewerLoopBinding('write', 'review', 'review.feedback', maxRetries: 3)],
);
$registry->register('my-custom-team', $plan);
```

### Orchestration cross-mode *(v1.0.1)*

Les trois modes (`auto / smart / squad`) partagent un `ModeContext` afin de pouvoir s'imbriquer, se transmettre la main et accumuler le coût dans un seul registre. La plupart des appelants n'ont pas besoin de nouvelles variables d'environnement — la récursion se produit automatiquement quand une étape YAML déclare `mode: smart` ou `mode: squad`.

Réglage optionnel de la politique (à mettre dans `.env`) :

```bash
# Profondeur maximale de récursion cross-mode avant déclenchement d'une erreur. Défaut 4.
SUPERAGENT_MODE_MAX_DEPTH=4

# Plafond de coût strict sur l'ensemble du run imbriqué. Défaut illimité.
SUPERAGENT_MODE_BUDGET_USD=10.00

# Activer la remontée vers un mode plus grand quand ReviewerLoopRunner épuise max_retries.
# Défaut true. Mode cible (défaut `smart`) contrôlé par SUPERAGENT_MODE_ESCALATE_TO.
SUPERAGENT_MODE_AUTO_ESCALATE=true
SUPERAGENT_MODE_ESCALATE_TO=smart
```

Référence complète (cycle de vie de ModeContext, installation SPI, détection de cycles, escalade de ReviewerLoopRunner) dans [ADVANCED_USAGE §62](docs/ADVANCED_USAGE.md#62-cross-mode-orchestration).

### Gemini 3.5 *(v1.0.5)*

Rien à installer au-delà du paquet standard — `gemini-3.5-pro` / `gemini-3.5-flash` / `gemini-3.5-flash-lite` sont déjà dans le `resources/models.json` livré. Définir la clé :

```bash
export GEMINI_API_KEY=AIzaSy…    # clé AI Studio, ou VERTEX_* pour OAuth/Vertex
superagent --provider gemini --model gemini-3.5-pro "explique ce fichier" ./src/Foo.php
```

Le modèle par défaut du provider est maintenant `gemini-3.5-flash` ; passer `--model gemini-3.5-pro` pour les tâches les plus dures ou `--model gemini-3.5-flash-lite` pour le moins cher.

### Serveurs LSP *(v1.0.5)*

`Tools\Builtin\LSPTool` démarre les serveurs depuis PATH. Installer ceux dont vous avez besoin ; l'agent ne spawn que si la sonde réussit.

```bash
# PHP
composer global require phpactor/phpactor
# ou :  npm i -g intelephense

# JS/TS
npm i -g typescript-language-server typescript

# Go
go install golang.org/x/tools/gopls@latest

# Rust
rustup component add rust-analyzer

# Python
npm i -g pyright

# C/C++
brew install llvm        # ou : apt install clangd

# Bash
npm i -g bash-language-server
```

Vérifier la détection :

```bash
superagent run --tool LSPTool --tool-input '{"action":"diagnostics","path":"/abs/path/to/file.php"}'
```

### Auto-formateurs *(v1.0.5)*

`Format\Formatters` sonde ~26 formateurs ; chacun ne déclenche que si le projet le déclare (p.ex. Pint exige `laravel/pint` dans `composer.json`, Prettier dans `package.json`). Installer ceux de votre stack :

```bash
# PHP — niveau projet (préféré)
composer require --dev laravel/pint

# JS/TS — niveau projet
npm i -D prettier
# ou :  npm i -D --save-exact @biomejs/biome

# Python
pip install ruff
# ou :  uv tool install ruff

# Go / Rust / Zig / Terraform — fournis avec la toolchain

# Shell
brew install shfmt
```

### Serveur ACP *(v1.0.5)*

Pas d'installation — le serveur JSON-RPC stdio fait partie du paquet. Les éditeurs qui parlent ACP le câblent comme un serveur MCP :

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

Puis `Cmd-Shift-A` dans Zed sélectionne SuperAgent comme agent actif.

### Découverte auto de skills externes *(v1.0.5)*

`SkillManager::discoverExternalSkills()` est opt-in — appeler depuis l'hôte ou câbler dans l'agent factory. Les skills se chargent depuis n'importe quel chemin entre cwd et la racine du projet :

```
.claude/skills/<name>/SKILL.md
.agents/skills/<name>/SKILL.md
skills/<name>/SKILL.md          (racine projet uniquement)
skill/<name>/SKILL.md           (racine projet uniquement)
```

Chaque SKILL.md est un fichier Markdown avec frontmatter YAML (`name:`, `description:`) suivi du corps. Le walk s'arrête à la frontière du worktree — un parent monorepo ne peut pas polluer un sous-projet.

### Tracing & observabilité *(v1.0.6)*

Le tracing est activé par défaut et écrit des fichiers Chrome Trace Event JSON dans `sys_get_temp_dir()/superagent-traces/`. Trois variables d'env le contrôlent :

```bash
export SUPERAGENT_TRACE_ENABLED=true               # défaut : true
export SUPERAGENT_TRACE_PATH=/var/log/sa-traces    # défaut : sys_get_temp_dir()/superagent-traces
export SUPERAGENT_TRACE_RING_SIZE=2048             # défaut : 1024 événements
```

Viewers recommandés :

- **`ui.perfetto.dev`** — préféré. Drag & drop le fichier trace JSON.
- **`chrome://tracing`** — viewer intégré de Chrome (legacy mais fonctionne).
- Les snippets de **`docs/cookbook/`** référencent directement le format de fichier.

Pour les gateways haut-RPS où le ring buffer singleton est de trop, mettre `SUPERAGENT_TRACE_ENABLED=false` ou injecter un `TraceCollector` désactivé dans le graphe DI.

Le `PiEventStream` aligné pi est un émetteur listener séparé — câblez-le en souscrivant un `PiEventStreamWriter` dans votre bootstrap :

```php
use SuperAgent\Tracing\PiEventStream;
use SuperAgent\Tracing\PiEventStreamWriter;

PiEventStream::subscribe(new PiEventStreamWriter(
    storage_path('sa-sessions/' . $sessionId . '.events.jsonl')
));
```

### Compression de sortie structurée RTK *(v1.0.6)*

Zéro config — `Tools\Compression\RtkPipeline` est câblé dans `QueryEngine` et tire sur chaque résultat d'outil non-erreur par défaut. Désactiver par appel quand vous avez besoin de fidélité byte-à-byte (ex. vous nourrissez la sortie à `git apply` qui a besoin de chaque ligne de contexte) :

```php
$result = $agent->run($prompt, ['disable_rtk_compression' => true]);
```

Les hosts peuvent aussi enregistrer des compresseurs supplémentaires pour des outils custom :

```php
use SuperAgent\Tools\Compression\RtkPipeline;
use SuperAgent\Tools\Compression\CompressorInterface;

$pipeline = new RtkPipeline();
$pipeline->register('my_custom_tool', new MyCompressor());
```

Voir [ADVANCED_USAGE §83](docs/ADVANCED_USAGE_FR.md) pour le registre complet et les économies par outil.

### Qwen 3.7 / Qwen-Anthropic *(v1.0.6)*

Le modèle Qwen par défaut est maintenant `qwen3.7-max` (1M ctx, $2.50 / $7.50 par 1M tokens, support natif du protocole Anthropic). Trois clés provider accèdent à Qwen :

```php
// Endpoint OpenAI-compat (recommandé pour la parité avec le reste du SDK)
$agent = new Agent(['provider' => 'qwen', 'api_key' => env('DASHSCOPE_API_KEY')]);

// Endpoint DashScope natif (à utiliser uniquement si vous avez besoin du contrôle thinking_budget — famille 3.6)
$agent = new Agent(['provider' => 'qwen-native', 'api_key' => env('DASHSCOPE_API_KEY')]);

// Endpoint compatible protocole Anthropic (drop-in pour clients Claude Code)
$agent = new Agent(['provider' => 'qwen-anthropic', 'api_key' => env('DASHSCOPE_API_KEY')]);
```

> L'URL du endpoint `qwen-anthropic` n'est pas officiellement documentée par Alibaba en anglais au 2026-05-22. Le défaut `https://dashscope.aliyuncs.com/anthropic-mode/v1` est une supposition ; override via `base_url` s'il renvoie 404. Vérifier `~/.qwen/settings.json` après avoir installé qwen-code v0.16+ pour un champ `anthropic-base-url` explicite.

OAuth Qwen a été EOL le 2026-04-15 — seul l'auth par clé API est supporté.

### Import de session pi *(v1.0.6)*

Rejouer des sessions pi existantes (`~/.pi/agent/sessions/`) dans SuperAgent :

```php
use SuperAgent\Conversation\Importers\PiImporter;

$importer = new PiImporter();
foreach ($importer->listSessions(50) as $row) {
    echo "{$row['id']}  {$row['started_at']}  {$row['first_user_message']}\n";
}

$messages = $importer->load('/abs/path/to/2026-05-22_abc123.jsonl');
// → SuperAgent\Messages\Message[] prêts à amorcer l'historique d'un Agent
```

Aucun setup nécessaire — `~/.pi/agent/sessions` est la racine par défaut ; override via argument constructeur si le host utilise un layout non-standard.

### CI chaîne d'approvisionnement *(v1.0.6)*

Un nouveau workflow GitHub Actions (`.github/workflows/supply-chain.yml`) applique trois règles à chaque push, PR et lundi matin :

1. `composer validate --strict`
2. `composer audit --no-dev` (avis de sécurité Symfony)
3. Aucun script lifecycle Composer (`post-install-cmd`, `post-update-cmd`, …) — l'installation tourne avec `--no-scripts`.

Si vous forkez le SDK, ce workflow fonctionne out of the box ; si vous l'embarquez via Composer, le lockdown est appliqué de VOTRE côté à l'installation quand vous passez aussi `--no-scripts` (recommandé pour la sécurité).

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
