# Guide d'Installation SuperAgent

> **🌍 Langue**: [English](INSTALL.md) | [中文](INSTALL_CN.md) | [Français](INSTALL_FR.md)  
> **📖 Documentation**: [README](README.md) | [README 中文](README_CN.md) | [README Français](README_FR.md)

## Table des Matières
- [Prérequis Système](#prérequis-système)
- [Étapes d'Installation](#étapes-dinstallation)
- [Configuration](#configuration)
- [Configuration Multi-Agents](#configuration-multi-agents)
- [Vérification](#vérification)
- [Dépannage](#dépannage)
- [Guide de Mise à Jour](#guide-de-mise-à-jour)

## Prérequis Système

### Configuration Minimale
- **PHP**: 8.1 ou supérieur
- **Laravel**: 10.0 ou supérieur
- **Composer**: 2.0 ou supérieur
- **Mémoire**: Au moins 256MB de limite de mémoire PHP
- **Espace Disque**: Au moins 100MB d'espace disponible

### Extensions PHP Requises
```bash
# Extensions principales
- json        # Traitement JSON
- mbstring    # Chaînes multi-octets
- openssl     # Cryptage
- curl        # Requêtes HTTP
- fileinfo    # Informations sur les fichiers
```

### Extensions PHP Optionnelles
```bash
# Fonctionnalités avancées
- redis       # Support du cache Redis
- pcntl       # Contrôle de processus (collaboration multi-agents)
- yaml        # Fichiers de configuration YAML
- zip         # Compression de fichiers
```

### Vérification de l'Environnement

```bash
# Vérifier la version PHP
php -v

# Vérifier les extensions installées
php -m

# Vérifier la version Laravel
php artisan --version

# Vérifier la version Composer
composer --version
```

## Étapes d'Installation

### 1️⃣ Installation via Composer

#### Installation Standard (Recommandée)
```bash
composer require forgeomni/superagent
```

#### Installer la Version de Développement
```bash
composer require forgeomni/superagent:dev-main
```

#### Installer une Version Spécifique
```bash
composer require forgeomni/superagent:^1.0
```

### 2️⃣ Enregistrer le Fournisseur de Services

Laravel 10+ enregistrera automatiquement. Pour l'enregistrement manuel, éditez `config/app.php`:

```php
'providers' => [
    // Autres fournisseurs...
    SuperAgent\SuperAgentServiceProvider::class,
],

'aliases' => [
    // Autres alias...
    'SuperAgent' => SuperAgent\Facades\SuperAgent::class,
],
```

### 3️⃣ Publier les Fichiers de Ressources

```bash
# Publier toutes les ressources
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider"

# Ou publier séparément
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config"
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="migrations"
```

### 4️⃣ Exécuter les Migrations de Base de Données

Si vous utilisez le système de mémoire et les fonctionnalités de gestion des tâches:

```bash
php artisan migrate
```

### 5️⃣ Configurer les Variables d'Environnement

Éditez votre fichier `.env` et ajoutez la configuration nécessaire:

```env
# ========== Configuration de Base SuperAgent ==========

# Fournisseur IA par défaut (anthropic|openai|bedrock|ollama)
SUPERAGENT_PROVIDER=anthropic

# Configuration Anthropic Claude
ANTHROPIC_API_KEY=sk-ant-xxxxxxxxxxxxx
ANTHROPIC_MODEL=claude-4.6-haiku-latest
ANTHROPIC_MAX_TOKENS=4096
ANTHROPIC_TEMPERATURE=0.7

# Configuration OpenAI (optionnel)
OPENAI_API_KEY=sk-xxxxxxxxxxxxx
OPENAI_MODEL=gpt-5.4
OPENAI_ORG_ID=org-xxxxxxxxxxxxx

# Configuration AWS Bedrock (optionnel)
AWS_ACCESS_KEY_ID=AKIAXXXXXXXXXXXXX
AWS_SECRET_ACCESS_KEY=xxxxxxxxxxxxx
AWS_DEFAULT_REGION=us-east-1
BEDROCK_MODEL=anthropic.claude-v2

# Modèles Ollama Locaux (optionnel)
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=llama2

# ========== Bascules de Fonctionnalités ==========

# Sortie en flux
SUPERAGENT_STREAMING=true

# Fonctionnalité de cache
SUPERAGENT_CACHE_ENABLED=true
SUPERAGENT_CACHE_TTL=3600

# Mode débogage
SUPERAGENT_DEBUG=false

# Observabilité (interrupteur principal — tous les sous-systèmes désactivés si faux)
SUPERAGENT_TELEMETRY_ENABLED=false
SUPERAGENT_TELEMETRY_LOGGING=false
SUPERAGENT_TELEMETRY_METRICS=false
SUPERAGENT_TELEMETRY_EVENTS=false
SUPERAGENT_TELEMETRY_COST_TRACKING=false

# Garde-fous de sécurité
SUPERAGENT_SECURITY_GUARDRAILS=false

# Fonctionnalités expérimentales (interrupteur principal — tous les indicateurs activés si vrai)
SUPERAGENT_EXPERIMENTAL=true

# ========== Configuration des Permissions ==========

# Modes de permission:
# bypass - Ignorer toutes les vérifications de permissions
# acceptEdits - Approuver automatiquement les modifications de fichiers
# plan - Toutes les opérations nécessitent confirmation
# default - Jugement intelligent
# dontAsk - Refuser automatiquement les opérations nécessitant confirmation
# auto - Classification automatique par IA
SUPERAGENT_PERMISSION_MODE=default

# ========== Configuration du Stockage ==========

SUPERAGENT_STORAGE_DISK=local
SUPERAGENT_STORAGE_PATH=superagent
```

### 6️⃣ Créer les Répertoires Nécessaires

```bash
# Créer les répertoires de stockage
mkdir -p storage/app/superagent/{snapshots,memories,tasks,cache}

# Définir les permissions
chmod -R 755 storage/app/superagent

# Si vous utilisez un serveur web
chown -R www-data:www-data storage/app/superagent  # Ubuntu/Debian
chown -R nginx:nginx storage/app/superagent        # CentOS/RHEL
```

## Configuration

### Fichier de Configuration Principal

Éditez `config/superagent.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fournisseur IA par Défaut
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    
    /*
    |--------------------------------------------------------------------------
    | Configuration des Fournisseurs IA
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
    | Configuration des Outils
    |--------------------------------------------------------------------------
    */
    'tools' => [
        // Liste des outils activés
        'enabled' => [
            \SuperAgent\Tools\Builtin\FileReadTool::class,
            \SuperAgent\Tools\Builtin\FileWriteTool::class,
            \SuperAgent\Tools\Builtin\FileEditTool::class,
            \SuperAgent\Tools\Builtin\BashTool::class,
            \SuperAgent\Tools\Builtin\WebSearchTool::class,
            \SuperAgent\Tools\Builtin\WebFetchTool::class,
        ],
        
        // Paramètres de permission des outils
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
    | Gestion du Contexte
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
    | Configuration du Cache
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('SUPERAGENT_CACHE_ENABLED', true),
        'driver' => env('CACHE_DRIVER', 'file'),
        'ttl' => env('SUPERAGENT_CACHE_TTL', 3600),
    ],
];
```

## Configuration Multi-Agents

### Configuration du Mode Automatique (NOUVEAU en v0.6.7)

Activer l'orchestration multi-agents automatique:

```env
# Activer la détection multi-agents automatique
SUPERAGENT_AUTO_MODE=true

# Nombre maximum d'agents simultanés
SUPERAGENT_MAX_CONCURRENT_AGENTS=10

# Pool de ressources d'agents
SUPERAGENT_AGENT_POOL_SIZE=20

# Surveillance WebSocket
SUPERAGENT_WEBSOCKET_MONITORING=true
SUPERAGENT_WEBSOCKET_PORT=8080
```

### Utilisation Multi-Agents de Base

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;

// Créer l'agent principal avec mode automatique
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

// L'agent décide automatiquement mode unique vs multi-agents
$result = $agent->run("Tâche complexe en plusieurs étapes...");
```

### Configuration Manuelle d'Équipes d'Agents

```php
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Swarm\ParallelAgentCoordinator;

// Configurer l'équipe d'agents
$coordinator = ParallelAgentCoordinator::getInstance();
$coordinator->configure([
    'max_concurrent' => 10,
    'timeout' => 300, // 5 minutes par agent
    'checkpoint_interval' => 60, // Sauvegarder l'état chaque minute
]);

// Créer des agents spécialisés
$agentTool = new AgentTool();

// Agent de recherche
$chercheur = $agentTool->execute([
    'description' => 'Recherche',
    'prompt' => 'Rechercher les meilleures pratiques',
    'subagent_type' => 'researcher',
    'run_in_background' => true,
]);

// Agent d'écriture de code
$codeur = $agentTool->execute([
    'description' => 'Implémentation',
    'prompt' => 'Écrire le code d\'implémentation',
    'subagent_type' => 'code-writer',
    'run_in_background' => true,
]);

// Surveiller le progrès
$status = $coordinator->getTeamStatus();
foreach ($status['agents'] as $agentId => $info) {
    echo "Agent {$agentId}: {$info['status']} - {$info['progress']}%\n";
}
```

### Système de Boîte aux Lettres d'Agents

Configurer la communication persistante entre agents:

```php
// config/superagent.php
'mailbox' => [
    'enabled' => true,
    'storage' => 'redis', // ou 'database', 'file'
    'ttl' => 3600, // TTL des messages en secondes
    'max_messages' => 1000, // Nombre max de messages par agent
],
```

Utilisation:

```php
use SuperAgent\Tools\Builtin\SendMessageTool;

$messageTool = new SendMessageTool();

// Message direct
$messageTool->execute([
    'to' => 'agent-123',
    'message' => 'Mise à jour prioritaire',
    'summary' => 'Mise à jour',
]);

// Diffusion
$messageTool->execute([
    'to' => '*',
    'message' => 'Annonce à l\'équipe',
    'summary' => 'Annonce',
]);
```

### Tableau de Bord de Surveillance WebSocket

Activer la surveillance en temps réel:

```bash
# Démarrer le serveur WebSocket
php artisan superagent:websocket

# Accéder au tableau de bord
open http://localhost:8080/superagent/monitor
```

Fonctionnalités du tableau de bord:
- État des agents en temps réel
- Utilisation de tokens par agent
- Agrégation des coûts
- Visualisation du progrès
- Surveillance de la file de messages

### Configuration des Rôles d'Agents

Définir des rôles d'agents spécialisés:

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

### Point de Contrôle & Reprise pour Workflows Multi-Agents

```php
// Activer les points de contrôle
$coordinator->enableCheckpoints([
    'interval' => 60, // Sauvegarder toutes les 60 secondes
    'storage' => 'database',
]);

// Reprendre depuis un point de contrôle après échec
$coordinator->resumeFromCheckpoint($checkpointId);
```

### Pool de Ressources & Contrôle de Concurrence

```php
// Configurer le pool d'agents
use SuperAgent\Swarm\AgentPool;

$pool = new AgentPool([
    'max_agents' => 20,
    'max_concurrent' => 10,
    'queue_timeout' => 300,
]);

// Soumettre des tâches au pool
$taskIds = [];
foreach ($tasks as $task) {
    $taskIds[] = $pool->submit($task);
}

// Attendre la complétion
$results = $pool->waitAll($taskIds);
```

### Optimisation des Performances Multi-Agents

```env
# Optimiser pour l'exécution parallèle
SUPERAGENT_PARALLEL_CHUNK_SIZE=5
SUPERAGENT_PARALLEL_TIMEOUT=300
SUPERAGENT_PARALLEL_RETRY_COUNT=3

# Optimisation de la mémoire
SUPERAGENT_AGENT_MEMORY_LIMIT=256M
SUPERAGENT_SHARED_CONTEXT_CACHE=true

# Optimisation réseau
SUPERAGENT_API_CONNECTION_POOL=50
SUPERAGENT_API_KEEPALIVE=true
```

## Notes de Mise à Jour v0.6.12

v0.6.12 corrige trois problèmes où les processus enfants des sous-agents ne pouvaient pas accéder aux services Laravel, aux identifiants API ou à l'ensemble complet des outils. **Aucune modification de configuration requise.**

Si vous utilisez des définitions d'agents personnalisées dans `.claude/agents/`, des skills dans `.claude/commands/` ou des serveurs MCP configurés via `config('superagent.mcp')`, ceux-ci fonctionnent désormais correctement dans les processus sous-agents.

```bash
composer update forgeomni/superagent
```

## Notes de Mise à Jour v0.6.11

v0.6.11 remplace le backend d'exécution par défaut des sous-agents. **Aucune modification de configuration requise** — le nouveau comportement est automatique.

**Ce qui change :** `AgentTool` lance désormais chaque sous-agent dans un processus OS séparé via `proc_open()` au lieu d'utiliser les Fibers PHP dans le même processus. Cela fournit un vrai parallélisme — 5 agents concurrents terminent en ~544ms vs ~2500ms en séquentiel.

**Changement incompatible pour le code de test uniquement :** Si vos tests mockent `InProcessBackend` ou reposent sur l'exécution par Fiber, ils peuvent nécessiter une mise à jour. Le code de production qui appelle simplement `AgentTool` n'est pas affecté.

**Prérequis :** `proc_open()` doit être disponible (c'est le cas sur les installations PHP standard). Si désactivé, `AgentTool` se replie automatiquement sur `InProcessBackend`.

```bash
composer update forgeomni/superagent
```

## Notes de Mise à Jour v0.6.10

v0.6.10 est une version de correction de bugs sans modification de configuration. Si vous utilisez des agents synchrones en processus (`run_in_background: false` avec le backend `in-process`), cette mise à jour résout un blocage critique où la fiber de l'agent n'était jamais démarrée, provoquant un timeout de 5 minutes à chaque appel.

**Changement incompatible pour le code de test uniquement** : Le résultat synchrone de `AgentTool::execute()` retourne maintenant `'agentId'` (camelCase) et `'status' => 'completed'` au lieu de l'ancien format asynchrone inaccessible. Mettez à jour vos assertions de test en conséquence.

```bash
composer update forgeomni/superagent
```

## Configuration des Fonctionnalités v0.6.9

### URL de Base Personnalisée avec Préfixe de Chemin

Les providers gèrent maintenant correctement les valeurs `base_url` incluant un préfixe de chemin (passerelles API, proxies inverses) :

```php
// Passerelle compatible Anthropic avec chemin personnalisé
$agent = new Agent([
    'provider'  => 'anthropic',
    'api_key'   => env('ANTHROPIC_API_KEY'),
    'base_url'  => 'https://gateway.example.com/anthropic', // préfixe de chemin préservé
    'model'     => 'claude-sonnet-4-6',
]);

// Proxy compatible OpenAI
$agent = new Agent([
    'provider'  => 'openai',
    'api_key'   => env('OPENAI_API_KEY'),
    'base_url'  => 'https://proxy.example.com/openai',      // préfixe de chemin préservé
    'model'     => 'gpt-4o',
]);

// Ollama local derrière un proxy inverse à sous-chemin
$agent = new Agent([
    'provider'  => 'ollama',
    'base_url'  => 'http://localhost:8080/ollama',          // préfixe de chemin préservé
    'model'     => 'llama3',
]);
```

> **Note** : Dans les versions v0.6.8 et antérieures, les valeurs `base_url` avec préfixe de chemin échouaient silencieusement pour OpenAI, OpenRouter et Ollama — le résolveur RFC 3986 de Guzzle supprimait le chemin lors de l'utilisation d'un chemin de requête absolu. Les quatre providers sont maintenant corrigés.

## Configuration des Fonctionnalités v0.6.8

### Contexte Incrémental

```php
use SuperAgent\IncrementalContext\IncrementalContextManager;

$manager = new IncrementalContextManager([
    'auto_compress'       => true,
    'compress_threshold'  => 4000,
    'auto_checkpoint'     => true,
    'checkpoint_interval' => 10,
    'compression_level'   => 'balanced',
]);

$manager->initialize($messages);
$delta  = $manager->getDelta();
$full   = $manager->applyDelta($delta, $base);
$manager->restoreCheckpoint($checkpointId);
$window = $manager->getSmartWindow(maxTokens: 8000);
```

### Chargement Paresseux du Contexte

```php
use SuperAgent\LazyContext\LazyContextManager;

$lazy = new LazyContextManager(['cache_ttl' => 600]);

$lazy->registerContext('system-rules', [
    'type' => 'system', 'priority' => 9,
    'tags' => ['rules'], 'size' => 200,
    'source' => '/path/to/rules.json',
]);

$context = $lazy->getContextForTask('refactoriser le service PHP');
$window  = $lazy->getSmartWindow(maxTokens: 12000, focusArea: 'php');
```

### Chargement Différé des Outils

```php
use SuperAgent\Tools\ToolLoader;

$loader = new ToolLoader(['lazy_load' => true]);
$tools  = $loader->loadForTask('rechercher et éditer des fichiers PHP');
$agent  = new Agent(['provider' => 'anthropic', 'tools' => $tools]);
```

### Recherche Web Sans Clé API

`WebSearchTool` se replie automatiquement sur DuckDuckGo quand `SEARCH_API_KEY` n'est pas définie. Pour la production :

```env
SEARCH_API_KEY=votre_cle_serper
SEARCH_ENGINE=serper
```

## Vérification

### 1️⃣ Exécuter la Vérification de Santé

Créer le script de vérification de santé `check-superagent.php`:

```php
<?php

require 'vendor/autoload.php';

$checks = [
    'Version PHP' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Installation Laravel' => class_exists('Illuminate\Foundation\Application'),
    'Installation SuperAgent' => class_exists('SuperAgent\Agent'),
    'Extension JSON' => extension_loaded('json'),
    'Extension CURL' => extension_loaded('curl'),
    'Extension OpenSSL' => extension_loaded('openssl'),
];

echo "Vérification de l'Installation SuperAgent\n";
echo "========================================\n\n";

$allPassed = true;
foreach ($checks as $name => $result) {
    $status = $result ? '✅' : '❌';
    echo "$status $name\n";
    if (!$result) $allPassed = false;
}

if ($allPassed) {
    echo "\n🎉 Toutes les vérifications réussies! SuperAgent est prêt.\n";
} else {
    echo "\n⚠️ Certaines vérifications ont échoué, veuillez résoudre les problèmes ci-dessus.\n";
    exit(1);
}
```

Exécuter la vérification:
```bash
php check-superagent.php
```

### 2️⃣ Tester les Fonctionnalités de Base

```php
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;

// Tester une requête de base
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-haiku-20240307',
    ],
]);

$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

$response = $agent->query("Dites 'Installation réussie!'");
echo $response->content;
```

### 3️⃣ Tester les Outils CLI

```bash
# Lister les outils disponibles
php artisan superagent:tools

# Tester la fonctionnalité de chat
php artisan superagent:chat

# Exécuter une requête simple
php artisan superagent:run --prompt="Qu'est-ce que 2+2?"
```

## Dépannage

### ❓ L'Installation Composer Échoue

**Message d'erreur**:
```
Your requirements could not be resolved to an installable set of packages
```

**Solution**:
```bash
# Vider le cache
composer clear-cache

# Mettre à jour les dépendances
composer update --with-dependencies

# Utiliser un miroir domestique (utilisateurs français)
composer config repo.packagist composer https://packagist.fr
```

### ❓ Fournisseur de Services Non Trouvé

**Message d'erreur**:
```
Class 'SuperAgent\SuperAgentServiceProvider' not found
```

**Solution**:
```bash
# Régénérer l'autoload
composer dump-autoload

# Vider le cache Laravel
php artisan optimize:clear
```

### ❓ Clé API Invalide

**Message d'erreur**:
```
Invalid API key provided
```

**Solution**:
1. Vérifier que la clé API dans le fichier `.env` est correcte
2. S'assurer qu'il n'y a pas d'espaces ou de guillemets supplémentaires autour de la clé
3. Vérifier que la clé est activée et non expirée
4. Vider le cache de configuration: `php artisan config:clear`

### ❓ Mémoire Épuisée

**Message d'erreur**:
```
Allowed memory size of X bytes exhausted
```

**Solution**:

Éditer `php.ini`:
```ini
memory_limit = 512M
```

Ou définir temporairement dans le code:
```php
ini_set('memory_limit', '512M');
```

### ❓ Permission Refusée

**Message d'erreur**:
```
Permission denied
```

**Solution**:
```bash
# Définir les permissions correctes
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Définir le propriétaire (ajuster selon le système)
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

## Guide de Mise à Jour

### De 0.x à 1.0

```bash
# 1. Sauvegarder les données existantes
php artisan backup:run

# 2. Mettre à jour les dépendances
composer update forgeomni/superagent

# 3. Exécuter les nouvelles migrations
php artisan migrate

# 4. Mettre à jour les fichiers de configuration
php artisan vendor:publish --provider="SuperAgent\SuperAgentServiceProvider" --tag="config" --force

# 5. Vider tous les caches
php artisan optimize:clear
```

### Matrice de Compatibilité des Versions

| SuperAgent | Laravel | PHP   | Notes |
|------------|---------|-------|-------|
| 0.6.12     | 10.x+   | 8.1+  | Bootstrap Laravel dans processus enfants, correction sérialisation provider, ensemble complet d'outils |
| 0.6.11     | 10.x+   | 8.1+  | Vrais sous-agents parallèles au niveau processus (proc_open remplace Fiber), accélération 4.6x |
| 0.6.10     | 10.x+   | 8.1+  | Correction de l'exécution synchrone multi-agents (blocage fiber, incompatibilité type backend, tracker de progression) |
| 0.6.9      | 10.x+   | 8.1+  | Correction du chemin base URL Guzzle (providers OpenAI / OpenRouter / Ollama) |
| 0.6.8      | 10.x+   | 8.1+  | Contexte incrémental, chargement paresseux contexte/outils, héritage provider sous-agent, repli WebSearch sans clé, renforcement WebFetch |
| 0.6.7      | 10.x+   | 8.1+  | Suivi d'Agents Parallèles Multi & Mode Auto |
| 0.6.6      | 10.x+   | 8.1+  | Fenêtre de Contexte Intelligent (888 tests) |
| 0.6.5      | 10.x+   | 8.1+  | Distillation de Compétences, Point de Contrôle & Reprise (865 tests) |
| 0.6.2      | 10.x+   | 8.1+  | Pipeline DSL, Autopilote de Coûts, Feedback Adaptatif (776 tests) |
| 0.6.1      | 10.x+   | 8.1+  | Guardrails DSL (644 tests) |
| 0.6.0      | 10.x+   | 8.1+  | Mode Bridge |
| 0.5.7      | 10.x+   | 8.1+  | Interrupteur principal télémétrie, garde-fous de sécurité (452 tests) |

## Déploiement en Production

### Optimisation des Performances

```bash
# Cache de configuration
php artisan config:cache

# Cache des routes
php artisan route:cache

# Optimiser l'autoloader
composer install --optimize-autoloader --no-dev
```

### Configurer les Files d'Attente

Créer la configuration Supervisor `/etc/supervisor/conf.d/superagent.conf`:

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

### Configurer Nginx

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
    
    # Support de réponse en streaming
    location /superagent/stream {
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 3600;
    }
}
```

## Obtenir de l'Aide

### 📚 Ressources

- 📖 [Documentation Officielle](https://superagent-docs.example.com)
- 💬 [Forum Communautaire](https://forum.superagent.dev)
- 🐛 [Suivi des Problèmes](https://github.com/yourusername/superagent/issues)
- 📺 [Tutoriels Vidéo](https://youtube.com/@superagent)

### 💼 Support Technique

- Support communautaire: [GitHub Discussions](https://github.com/yourusername/superagent/discussions)
- Support par email: mliz1984@gmail.com
- Serveur Discord: [Rejoindre notre communauté](https://discord.gg/superagent)

### 🔍 Conseils de Débogage

Activer le mode débogage:
```env
SUPERAGENT_DEBUG=true
APP_DEBUG=true
```

Consulter les logs:
```bash
# Logs Laravel
tail -f storage/logs/laravel.log

# Logs spécifiques SuperAgent
tail -f storage/logs/superagent.log

# Débogage en temps réel
php artisan tinker
```

---

© 2024-2026 SuperAgent. Tous droits réservés.