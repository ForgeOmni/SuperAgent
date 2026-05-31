# SuperAgent

[![Version PHP](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Version Laravel](https://img.shields.io/badge/laravel-%3E%3D10.0-orange)](https://laravel.com)
[![Licence](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.0.8-purple)](https://github.com/forgeomni/superagent)

> **🌍 Langue**: [English](README.md) | [中文](README_CN.md) | [Français](README_FR.md)
> **📖 Documentation**: [Installation FR](INSTALL_FR.md) · [Installation EN](INSTALL.md) · [安装](INSTALL_CN.md) · [Utilisation avancée](docs/ADVANCED_USAGE_FR.md) · [Docs API](docs/)

SDK d'agent IA pour PHP — exécutez la boucle agentique complète (tour LLM → appel d'outil → résultat → tour suivant) en processus, avec quatorze providers, streaming temps réel, orchestration multi-agents et un protocole wire lisible par machine. Utilisable en CLI autonome ou comme dépendance Laravel.

```bash
superagent "corrige le bug de connexion dans src/Auth/"
```

```php
$agent = new SuperAgent\Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);

$result = $agent->run('Résume docs/ADVANCED_USAGE.md en un paragraphe');
echo $result->text();
```

---

## Table des matières

- [Démarrage rapide](#démarrage-rapide)
- [Providers et authentification](#providers-et-authentification)
- [API OpenAI Responses](#api-openai-responses)
- [Bascule inter-providers](#bascule-inter-providers)
- [DeepSeek V4](#deepseek-v4)
- [Goal mode (parité codex `/goal`)](#goal-mode-parité-codex-goal-v098)
- [Garde-fous opérationnels](#garde-fous-opérationnels-v098)
- [Outils compagnons (inspirés de jcode)](#outils-compagnons-inspirés-de-jcode)
- [Boucle d'agent](#boucle-dagent)
- [Outils et multi-agents](#outils-et-multi-agents)
- [Définitions d'agents](#définitions-dagents-yaml--markdown)
- [Skills](#skills)
- [Intégration MCP](#intégration-mcp)
- [Wire Protocol](#wire-protocol)
- [Retry, erreurs, observabilité](#retry-erreurs-observabilité)
- [Garde-fous et checkpoints](#garde-fous-et-checkpoints)
- [CLI autonome](#cli-autonome)
- [Intégration Laravel](#intégration-laravel)
- [Référence de configuration](#référence-de-configuration)

Chaque section se termine par une ligne *Depuis* indiquant la version qui introduit la fonctionnalité. Notes de version complètes dans [CHANGELOG.md](CHANGELOG.md).

---

## Démarrage rapide

Installer :

```bash
# En CLI autonome :
composer global require forgeomni/superagent

# Ou en dépendance Laravel :
composer require forgeomni/superagent
```

Matrice complète (prérequis système, auth, ponts IDE, CI) dans [INSTALL_FR.md](INSTALL_FR.md).

Agent minimal :

```php
$agent = new SuperAgent\Agent(['provider' => 'anthropic']);
$result = $agent->run('quel jour sommes-nous ?');
echo $result->text();
```

Agent minimal avec outils :

```php
$agent = (new SuperAgent\Agent(['provider' => 'openai']))
    ->loadTools(['read', 'write', 'bash']);

$result = $agent->run('inspecte composer.json et dis-moi quelle version PHP ce projet cible');
echo $result->text();
```

Appel unique via CLI :

```bash
export ANTHROPIC_API_KEY=sk-...
superagent "inspecte composer.json et dis-moi quelle version PHP ce projet cible"
```

---

## Providers et authentification

Quatorze providers pilotés par un registre, avec URL de base par région et plusieurs modes d'authentification. Tous implémentent le même contrat `LLMProvider` — échanger un provider pour un autre est une seule ligne.

| Clé de registre | Provider | Notes |
|---|---|---|
| `anthropic` | Anthropic | Clé API ou OAuth Claude Code stocké |
| `openai` | OpenAI Chat Completions (`/v1/chat/completions`) | Clé API, `OPENAI_ORGANIZATION` / `OPENAI_PROJECT` |
| `openai-responses` | OpenAI Responses API (`/v1/responses`) | [Section dédiée ci-dessous](#api-openai-responses) |
| `openrouter` | OpenRouter | Clé API |
| `gemini` | Google Gemini | Clé API |
| `kimi` | Moonshot Kimi | Clé API ; régions `intl` / `cn` / `code` (OAuth) |
| `qwen` | Alibaba Qwen (OpenAI-compat par défaut) | Clé API ; régions `intl` / `us` / `cn` / `hk` / `code` (OAuth + PKCE) |
| `qwen-native` | Alibaba Qwen (body DashScope natif) | Conservé pour les appels avec `parameters.thinking_budget` |
| `glm` | BigModel GLM | Clé API ; régions `intl` / `cn` |
| `minimax` | MiniMax | Clé API ; régions `intl` / `cn` |
| `deepseek` | DeepSeek V4 | Clé API ; upstreams `deepseek` / `beta` / `cn` / `nvidia_nim` / `fireworks` / `novita` / `openrouter` / `sglang` *(depuis v0.9.6, multi-upstream v0.9.8)* |
| `grok` | xAI Grok | Clé API (`XAI_API_KEY` / `GROK_API_KEY`) ; compatible OpenAI sur `api.x.ai` ; défaut `grok-4.3` *(depuis v1.0.8)* |
| `bedrock` | AWS Bedrock | AWS SigV4 |
| `ollama` | Ollama local | Aucune auth — localhost:11434 par défaut |
| `lmstudio` | Serveur LM Studio local | Auth placeholder — localhost:1234 par défaut *(depuis v0.9.1)* |

Modes d'authentification, par priorité :

1. **Clé API par variable d'environnement** — `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `KIMI_API_KEY`, `QWEN_API_KEY`, `GLM_API_KEY`, `MINIMAX_API_KEY`, `DEEPSEEK_API_KEY`, `XAI_API_KEY`, `OPENROUTER_API_KEY`, `GEMINI_API_KEY`.
2. **Credentials OAuth stockés** à `~/.superagent/credentials/<name>.json`. Flux device-code — `superagent auth login <name>` :
   - `claude-code` — réutilise une connexion Claude Code existante
   - `codex` — réutilise une connexion Codex CLI
   - `gemini` — réutilise une connexion Gemini CLI
   - `kimi-code` — flux device RFC 8628 contre `auth.kimi.com` *(depuis v0.9.0)*
   - `qwen-code` — flux device avec PKCE S256 + `resource_url` par compte *(depuis v0.9.0)*
3. **Config explicite** — `api_key` / `access_token` / `account_id` dans les options d'agent.

Le refresh OAuth est sérialisé inter-processus via `CredentialStore::withLock()` — des workers de queue parallèles partageant un même fichier de credentials ne se marchent pas dessus *(depuis v0.9.0)*.

### En-têtes déclaratifs

```php
new Agent([
    'provider'         => 'openai',
    'env_http_headers' => [
        'OpenAI-Project'      => 'OPENAI_PROJECT',      // ajouté uniquement si env défini et non vide
        'OpenAI-Organization' => 'OPENAI_ORGANIZATION',
    ],
    'http_headers' => [
        'x-app' => 'my-host-app',                       // en-tête statique
    ],
]);
```

*Depuis v0.9.1*

### Catalogue de modèles

Chaque provider embarque id + tarification dans `resources/models.json`. Rafraîchir à l'endpoint `/models` live du vendeur à tout moment :

```bash
superagent models refresh              # tous les providers avec creds en env
superagent models refresh openai       # un provider
superagent models list                 # catalogue fusionné
superagent models status               # source + ancienneté
```

*Depuis v0.9.0*

---

## API OpenAI Responses

Provider dédié : `provider: 'openai-responses'`. Frappe `/v1/responses` avec le shape moderne complet d'OpenAI.

**Pourquoi plutôt que `openai` :**

| Fonctionnalité | Responses | Chat Completions |
|---|---|---|
| Continuation via `previous_response_id` | ✅ — l'état reste côté serveur ; le nouveau tour évite de renvoyer le contexte | ❌ — doit renvoyer `messages[]` à chaque tour |
| `reasoning.effort` (`minimal / low / medium / high / xhigh`) | ✅ natif | ❌ hacks d'id modèle pour la série o |
| `reasoning.summary` | ✅ natif | ❌ |
| `prompt_cache_key` (cache serveur) | ✅ natif | ❌ |
| `text.verbosity` (`low / medium / high`) | ✅ natif | ❌ |
| `service_tier` (`priority / default / flex / scale`) | ✅ natif | ❌ |
| Erreurs classifiées | ✅ via les codes d'événement `response.failed` | Pattern-matching sur le body HTTP |

```php
$agent = new Agent([
    'provider' => 'openai-responses',
    'model'    => 'gpt-5',
]);

$result = $agent->run('analyse ce codebase et propose des refactos', [
    'reasoning'        => ['effort' => 'high', 'summary' => 'auto'],
    'verbosity'        => 'low',
    'prompt_cache_key' => 'session:42',
    'service_tier'     => 'priority',
    'store'            => true,           // requis pour réutiliser previous_response_id au tour suivant
]);

// Continuer la conversation sans renvoyer l'historique :
$provider = $agent->getProvider();
$nextAgent = new Agent([
    'provider' => 'openai-responses',
    'options'  => ['previous_response_id' => $provider->lastResponseId()],
]);
$nextResult = $nextAgent->run('descends d\'un niveau sur la couche d\'auth');
```

### Routage par abonnement ChatGPT

Passer `access_token` (ou `auth_mode: 'oauth'`) bascule le routage vers `chatgpt.com/backend-api/codex` — les abonnés Plus / Pro / Business facturent sur leur abonnement au lieu de se faire rejeter sur `api.openai.com`.

```php
new Agent([
    'provider'     => 'openai-responses',
    'access_token' => $token,
    'account_id'   => $accountId,   // ajoute l'en-tête chatgpt-account-id
]);
```

### Azure OpenAI

Six marqueurs de base URL bascule automatiquement en mode Azure. Le paramètre `api-version` est ajouté (défaut `2025-04-01-preview`, surchargeable) ; l'en-tête `api-key` est envoyé à côté d'`Authorization`.

```php
new Agent([
    'provider'          => 'openai-responses',
    'base_url'          => 'https://my-resource.openai.azure.com/openai/deployments/gpt-5',
    'api_key'           => $azureKey,
    'azure_api_version' => '2024-12-01-preview',   // surcharge optionnelle
]);
```

### Passthrough de trace-context

Injecter un W3C `traceparent` dans `client_metadata` pour corréler les logs côté OpenAI avec votre trace distribué :

```php
$tc = SuperAgent\Support\TraceContext::fresh();              // en générer un
// OU : SuperAgent\Support\TraceContext::parse($headerValue); // depuis un header HTTP entrant

$agent->run($prompt, ['trace_context' => $tc]);
// OU : $agent->run($prompt, ['traceparent' => '00-0af7-...', 'tracestate' => 'v=1']);
```

*Depuis v0.9.1*

---

## Bascule inter-providers

`Agent::switchProvider($name, $config, $policy)` permute le provider actif au milieu d'une conversation. L'historique des messages est préservé et ré-encodé dans le format de wire du nouveau provider à la prochaine requête — un historique d'outils exécuté contre Claude peut continuer sous Kimi sans perdre les tool calls parallèles ni la corrélation par `tool_use_id`.

```php
use SuperAgent\Conversation\HandoffPolicy;

$agent = new Agent(['provider' => 'anthropic', 'api_key' => $key, 'model' => 'claude-opus-4-7']);
$agent->run('analyse cette base de code');

// Bascule vers un modèle moins cher / plus rapide pour la phase suivante :
$agent->switchProvider('kimi', ['api_key' => $kimiKey, 'model' => 'kimi-k2-6'])
      ->run('écris les tests unitaires');

// Vérification du budget après bascule — différents tokenizers
// comptent le même historique différemment (Anthropic vs GPT-4
// dérivent de 20 à 30 %) :
$status = $agent->lastHandoffTokenStatus();
if ($status !== null && ! $status['fits']) {
    // Déclenche la compression IncrementalContext existante avant l'appel suivant.
}
```

### Politique de bascule

```php
HandoffPolicy::default()      // garde l'historique d'outils, supprime le thinking signé, ajoute un marqueur système
HandoffPolicy::preserveAll()  // garde tout — utile quand la bascule est temporaire
HandoffPolicy::freshStart()   // condense l'historique au dernier tour utilisateur — nouveau départ
```

Les artefacts spécifiques au provider que le nouveau wire ne peut pas porter (`thinking` signé Anthropic, `prompt_cache_key` Kimi, `reasoning` chiffré Responses API, références `cachedContent` Gemini) sont parqués sous `AssistantMessage::$metadata['provider_artifacts'][$providerKey]` — `HandoffPolicy::preserveAll()` les conserve pour qu'une bascule ultérieure vers le provider d'origine puisse les recoller ; `default()` les déplace mais ne les supprime pas.

### Bascule atomique

`switchProvider()` construit le nouveau provider avant toute mutation d'état. Si la construction échoue (clé API manquante, region inconnue, sondage réseau rejeté), l'agent reste sur l'ancien provider avec son historique intact.

### Six familles de wire-format derrière un seul Transcoder

Toutes les conversions passent par `Conversation\Transcoder`, dispatché via l'enum `WireFamily` : `Anthropic` (utilisé aussi par `bedrock` pour les invocations `anthropic.*`), `OpenAIChat` (OpenAI/Kimi/GLM/MiniMax/Qwen/OpenRouter/LMStudio), `OpenAIResponses`, `Gemini` (la seule famille qui corrèle les tool calls par `name` + ordre, sans id), `DashScope`, `Ollama`. Utile en standalone — par exemple pour transcoder une conversation Anthropic sauvegardée en payload Gemini batch :

```php
use SuperAgent\Conversation\Transcoder;
use SuperAgent\Conversation\WireFamily;

$wire = (new Transcoder())->encode($messages, WireFamily::Gemini);
```

*Depuis v0.9.5*

---

## DeepSeek V4

DeepSeek V4 (sorti le 2026-04-24) propose deux modèles MoE — `deepseek-v4-pro` (1,6 T total / 49 B actifs) et `deepseek-v4-flash` (284 B / 13 B actifs) — avec **1 M de contexte** par défaut et un **bascule thinking / non-thinking** dans le même modèle. Le même backend expose deux wires en parallèle (OpenAI et Anthropic) ; le SDK supporte les deux chemins :

```php
// Wire OpenAI : DeepSeekProvider natif
$agent = new Agent([
    'provider' => 'deepseek',
    'api_key'  => getenv('DEEPSEEK_API_KEY'),
    'model'    => 'deepseek-v4-pro',           // ou 'deepseek-v4-flash'
]);

// Wire Anthropic : réutilise AnthropicProvider avec un base_url personnalisé
$agent = new Agent([
    'provider' => 'anthropic',
    'api_key'  => getenv('DEEPSEEK_API_KEY'),
    'base_url' => 'https://api.deepseek.com/anthropic',
    'model'    => 'deepseek-v4-pro',
]);
```

**Canal de raisonnement.** V4-thinking, R1, Kimi-thinking, Qwen-reasoning et tout futur reasoner OpenAI-compat poussent leur monologue interne sur `delta.reasoning_content`. Le parser SSE partagé de `ChatCompletionsProvider` le restitue désormais comme un `ContentBlock::thinking()` séparé, posé en tête de l'assistant — l'appelant choisit de l'afficher ou de le masquer plutôt que de le mélanger à la réponse utilisateur.

```php
$result = $agent->run('prompt nécessitant du raisonnement', ['thinking' => true]);

foreach ($result->message()->content as $block) {
    if ($block->type === 'thinking') {
        // chaîne de raisonnement du modèle
    } elseif ($block->type === 'text') {
        // réponse visible côté utilisateur
    }
}
```

**Voie de dépréciation.** `deepseek-chat` et `deepseek-reasoner` se retirent le **2026-07-24**. Le catalogue marque les deux avec `deprecated_until` et `replaced_by` ; `ModelResolver` émet un warning unique par processus recommandant `deepseek-v4-flash` / `deepseek-v4-pro`. `SUPERAGENT_SUPPRESS_DEPRECATION=1` rend le warning silencieux.

**Facturation cache-aware.** Les backends OpenAI-compat reportent `prompt_tokens` en brut (cache hits + miss). Le parser soustrait désormais la portion mise en cache avant de remplir `Usage::inputTokens`, ce qui fait atterrir la remise cache correctement — `CostCalculator` facture les lectures à 10 % du tarif input, au lieu d'un effectif 110 %. Concerne tout backend OpenAI-compat avec cache (DeepSeek, Kimi, OpenAI lui-même).

**Endpoint beta.** `region: 'beta'` route vers `https://api.deepseek.com/beta` pour FIM / complétion par préfixe avec la même auth — voir [`completeFim()`](#fim-complétion-par-préfixe-v098) pour l'helper dédié.

*Depuis v0.9.6*

### Effort de raisonnement à trois niveaux *(v0.9.8)*

Trois niveaux uniformes sur DeepSeek natif + chaque relais :

```php
// Le moins cher : pas de thinking du tout.
$agent->run('traduis ce paragraphe', options: ['reasoning_effort' => 'off']);

// Budget thinking standard (V4-Pro par défaut).
$agent->run('conçois une queue at-least-once', options: ['reasoning_effort' => 'high']);

// CoT le plus profond — V4-Pro "réfléchis plus fort". Lent et coûteux.
$agent->run('audite cette migration pour les races conditions', options: ['reasoning_effort' => 'max']);
```

Chaque upstream reçoit la forme du body qu'il attend : `reasoning_effort` + `thinking: {type: enabled}` au top-level pour DeepSeek natif / OpenRouter / Novita / Fireworks / SGLang ; imbriqué dans `chat_template_kwargs.{thinking, reasoning_effort}` pour NVIDIA NIM. Les valeurs inconnues sont silencieusement no-op au lieu d'empoisonner la requête.

### Routage multi-upstream *(v0.9.8)*

Mêmes poids V4, six chemins relais. Une clé `upstream` choisit l'hôte :

```php
$agent = new Agent([
    'provider' => 'deepseek',
    'upstream' => 'fireworks',          // ou nvidia_nim / novita / openrouter / sglang
    'options'  => ['model' => 'deepseek-v4-pro'],
]);

// SGLang auto-hébergé exige un base_url explicite :
$agent = new Agent([
    'provider' => 'deepseek',
    'upstream' => 'sglang',
    'base_url' => 'http://my-sglang:30000/v1',
]);
```

`region` reste un alias d'`upstream` pour la rétrocompatibilité — le code existant `region: 'default' | 'cn' | 'beta'` est byte-compatible.

### Replay V4 Interleaved-Thinking *(v0.9.8)*

Le mode V4 thinking rejette les messages assistant qui portent `tool_calls` sans `reasoning_content`. Le provider :

1. Réémet automatiquement les blocs `thinking` de chaque `AssistantMessage` comme `reasoning_content` sur la wire (aucun changement d'appelant).
2. Lance un sanitizer de dernière passe qui force un placeholder `(reasoning omitted)` sur tout assistant+tool_calls qui aurait échappé — sécurise les sessions restaurées du disque pré-0.9.8 et les sous-agents qui construisent les messages à la main.

Désactiver avec `reasoning_effort: 'off'` (le sanitizer saute quand le thinking est explicitement désactivé).

### FIM (complétion par préfixe) *(v0.9.8)*

```php
$agent = new Agent([
    'provider' => 'deepseek',
    'region'   => 'beta',
]);

$completed = $agent->provider()->completeFim(
    prefix: "function fibonacci(\$n) {\n    ",
    suffix: "\n}\n",
    options: ['max_tokens' => 64],
);
```

Frappe `https://api.deepseek.com/beta/v1/completions`. Lève une erreur si le provider n'est pas en beta region plutôt que de router silencieusement ailleurs.

### Heuristique `/model auto` *(v0.9.8)*

```php
use SuperAgent\Routing\AutoModelStrategy;

$strategy = new AutoModelStrategy();
$model    = $strategy->select($messages, $systemPrompt, $options);
// → 'deepseek-v4-pro' ou 'deepseek-v4-flash'

$agent = new Agent([
    'provider' => 'deepseek',
    'options'  => ['model' => $model, 'reasoning_effort' => 'high'],
]);
```

Escalade vers Pro quand : prompt ≥ 32K tokens, ≥ 3 tours d'outils consécutifs, `reasoning_effort=max` explicite, ou mots-clés dans le system prompt (`review / audit / design / architect / plan / debug a complex / analyze the codebase / find the root cause`). Sinon Flash.

### Compaction cache-aware *(v0.9.8)*

```php
use SuperAgent\Context\Strategies\CacheAwareCompressor;
use SuperAgent\Context\Strategies\ConversationCompressor;

$compactor = new CacheAwareCompressor(
    delegate:       new ConversationCompressor($estimator, $config, $provider),
    tokenEstimator: $estimator,
    config:         $config,
    pinHead:        4,        // les 4 premiers messages restent byte-stables
    pinSystem:      true,     // le system message aussi
);
```

Wrap n'importe quelle `CompressionStrategy`. Forme du résultat : `[head_pinned, summary_boundary, summary, tail_preserved]` avec le préfixe caché à l'octet 0. Idempotent sur plusieurs rounds — refeeder un résultat compacté préserve les mêmes octets de préfixe, donc le cache préfixe automatique de DeepSeek continue de hit à chaque `/compact`.

---

## Goal mode (parité codex `/goal`) *(v0.9.8)*

Trois outils appelables par le modèle, lifecycle à quatre états, deux templates de prompt. Les goals sont thread-scopés ; chaque thread a au plus un goal non-terminal à la fois. Le modèle peut UNIQUEMENT transitionner `active → complete` ; pause / resume / budget viennent de l'utilisateur / système.

```php
use SuperAgent\Goals\GoalManager;
use SuperAgent\Goals\InMemoryGoalStore;
use SuperAgent\Tools\Builtin\CreateGoalTool;
use SuperAgent\Tools\Builtin\GetGoalTool;
use SuperAgent\Tools\Builtin\UpdateGoalTool;

$threadId = 'session-42';
$goals    = new GoalManager(new InMemoryGoalStore());

$agent->registerTool(new CreateGoalTool($goals, $threadId));
$agent->registerTool(new GetGoalTool($goals, $threadId));
$agent->registerTool(new UpdateGoalTool($goals, $threadId));

// À chaque tour, comptabilise les tokens et injecte la continuation si idle :
$agent->onTurnEnd(function ($usage) use ($goals, $threadId) {
    $goal = $goals->getActive($threadId);
    if ($goal === null) return;
    $updated = $goals->recordUsage($goal->id, $usage->inputTokens + $usage->outputTokens);
    if ($updated->status === GoalStatus::BudgetLimited) {
        $agent->injectSystemMessage($goals->renderBudgetLimitPrompt($updated));
    } elseif ($updated->status === GoalStatus::Active) {
        $agent->injectSystemMessage($goals->renderContinuationPrompt($updated));
    }
});
```

**Persistance.** `InMemoryGoalStore` ship avec le SDK ; SuperAICore fournit `EloquentGoalStore` (table `ai_goals`) pour qu'un goal survive aux redémarrages du process.

**Wrapping d'input non-fiable.** Les deux templates wrap l'objectif utilisateur dans `<untrusted_objective>` via `Security\UntrustedInput::tag()` pour qu'un goal text crafted ne puisse pas se hisser au rang d'instruction prioritaire :

```php
use SuperAgent\Security\UntrustedInput;

$wrapped = UntrustedInput::wrap($userInput, kind: 'note');
// → "The text below is user-provided data..." + "<untrusted_note>...</untrusted_note>"
```

Recommandé partout où du texte fourni par l'utilisateur est injecté dans un message de rôle système — goals, skills, imports mémoire.

---

## Garde-fous opérationnels *(v0.9.8)*

### Plafond de profondeur sub-agent

Plafond sur les appels récursifs à l'outil `agent`. Reflet du `agents.max_depth` de codex.

```php
use SuperAgent\Swarm\AgentDepthGuard;

// Définir le plafond (par défaut 5 ; env : SUPERAGENT_MAX_AGENT_DEPTH).
AgentDepthGuard::setMax(8);

// Au site de spawn, avant de lancer le child :
AgentDepthGuard::check();                       // throw AgentDepthExceededException au plafond
$childEnv = AgentDepthGuard::forChild();        // à passer à proc_open / Symfony\Process
```

Profondeur trackée via la variable d'environnement `SUPERAGENT_AGENT_DEPTH` pour qu'elle survive au spawn du process.

### Rate limiter token-bucket

Forme DeepSeek-TUI (8 RPS soutenu, 16 burst) :

```php
use SuperAgent\Providers\Transport\TokenBucket;

$bucket = new TokenBucket(ratePerSecond: 8.0, burst: 16);

$bucket->consume();          // bloque jusqu'à capacité
if (! $bucket->tryConsume()) { /* skip / queue */ }
```

Précision intra-process. Les limites cross-process sont une responsabilité de l'hôte (middleware Guzzle Redis-backed).

### Fork éphémère de conversation (sémantique `/side`)

```php
use SuperAgent\Conversation\Fork;

$fork = Fork::from($parentMessages);
$fork->extend(new UserMessage('essaie l\'approche alternative'),
              $sideAssistantReply);

// Soit jeter, soit promote des messages choisis vers le parent :
$parentNext = $fork->discard();          // jette le side
$parentNext = $fork->promote(2);         // ramène uniquement le message side #2
$parentNext = $fork->promoteAll();       // ramène tout
```

### Injection mémoire ad-hoc

```php
use SuperAgent\Memory\AdHocMemoryProvider;

$adhoc = new AdHocMemoryProvider();
$adhoc->push('CI rouge sur main', ttlSeconds: 1800, untrusted: true);
$adhoc->push('Tu DOIS sortir du JSON', ttlSeconds: 0, untrusted: false);  // sticky + trusted

$memoryManager->setExternalProvider($adhoc);
// Le tour suivant voit les deux entrées via onTurnStart() ; ad-hoc est push-only —
// search() retourne []. À composer aux côtés de BuiltinMemoryProvider, pas en remplacement.
```



---

## Outils compagnons (inspirés de jcode)

Cinq primitives additives empruntées à [jcode](https://github.com/1jehuang/jcode). Chacune est opt-in et dégrade en no-op quand le câblage hôte est absent.

### `agent_grep` — grep token-aware avec injection du symbole englobant

Frère du `grep` byte-pour-byte compatible ripgrep. Mêmes flags, plus la métadonnée du symbole englobant par hit (PHP / JS / TS / Python / Go) et une troncature « chunk déjà vu » par session, pour que le modèle ne relise pas trois tours de suite le même morceau.

```php
$agent->loadTools(['grep', 'agent_grep']);   // les deux enregistrés, choix par appel

// Par défaut : extracteur regex (zéro dépendance, ~95 % de précision)
$agent->run('trouve tous les appelants de MyClass::handle et donne-moi la méthode englobante');
```

L'extraction de symbole est branchable via le SPI `Tools\Builtin\Symbols\SymbolExtractor` :

```php
use SuperAgent\Tools\Builtin\AgentGrepTool;
use SuperAgent\Tools\Builtin\Symbols\CompositeSymbolExtractor;
use SuperAgent\Tools\Builtin\Symbols\TreeSitterSymbolExtractor;
use SuperAgent\Tools\Builtin\Symbols\RegexSymbolExtractor;

$agent->registerTool(new AgentGrepTool(symbolExtractor: new CompositeSymbolExtractor([
    new TreeSitterSymbolExtractor(),     // shell vers le CLI `tree-sitter` ; ~15 grammaires
    new RegexSymbolExtractor(),          // fallback PHP pur, toujours dispo
])));
```

Tree-sitter est auto-détecté sur `$PATH` (override via `SUPERAGENT_TREE_SITTER_BIN` ou paramètre constructeur). Binaire absent / grammaire inconnue / invocation foirée dégrade en « je ne supporte pas » — ne lève jamais.

### `FileLedger` — notification d'édition cross-agent pour les swarms

L'agent A édite un fichier que l'agent B a lu ; B reçoit un `FileShiftedEvent` dans sa boîte. Attaché paresseux à `WorktreeManager::fileLedger()`, opt-in côté outils qui enregistrent les lectures/écritures ; emitter par défaut no-op pour préserver la compat byte-à-byte des swarms existants.

```php
$ledger = $worktreeManager->fileLedger();
$ledger->setEmitter(function (FileShiftedEvent $event, string $toAgent) {
    // event = {path, byAgent, at, summary, shaBefore, shaAfter}
    $mailbox->push($toAgent, $event);
});

$ledger->recordRead($agentB, '/abs/file.php');
$ledger->recordWrite($agentA, '/abs/file.php', shaBefore: '...', shaAfter: '...', summary: 'corrige le garde null');
// → emitter déclenche avec toAgent=$agentB
```

### `AmbientWorker` — hygiène mémoire en arrière-plan, comptage de coût isolé

Worker longue durée, basse priorité, qui passe la dédup mémoire + scans de péremption à chaque tick. Budget par tick imposé en interne, jamais bloqué plus de quelques secondes. Le coût en tokens est marqué `usage_source: 'ambient'` via le callback fourni — les dashboards séparent dépense user-facing et arrière-plan.

```php
$worker = new AmbientWorker(
    memoryProvider: $memProvider,
    usageReporter:  fn(Usage $u) => $costMeter->record($u, source: 'ambient'),
    passBudgetSeconds: 3,
);

while ($host->running()) {
    $worker->tick();          // appelez depuis cron / swoole / react / un simple while sleep
    sleep(60);
}
```

### Bridge navigateur natif (Firefox / Chromium)

WebExtension Native Messaging — framing JSON préfixé sur 4 octets little-endian — laisse l'agent piloter un vrai navigateur sans Selenium / Playwright. Un launcher par instance d'outil ; surface de capacités serrée (pas de gestion d'onglets, cookies, ni API d'extension).

```php
$agent->registerTool(new FirefoxBridgeTool());

$agent->run('ouvre https://example.com, fais une capture, clique sur "Sign in", recapture');
```

Le chemin du launcher vient de `SUPERAGENT_BROWSER_BRIDGE_PATH` (ou du paramètre constructeur `launcherArgv`). Le docblock de `Tools\Browser\FirefoxBridge::class` contient le walkthrough complet (manifest WebExtension + manifest Native Messaging).

### Embeddings branchables — `Memory\Embeddings\*`

Interface `EmbeddingProvider` (shape batch, `dimensions()`, `fingerprint()`). Trois implémentations de référence :

| Classe | Cible idéale |
|---|---|
| `OllamaEmbeddingProvider` | Devs qui font déjà tourner Ollama localement — appelle `/api/embeddings`, défaut `nomic-embed-text` (768 dims) |
| `OnnxEmbeddingProvider` | Inférence in-process — nécessite `ext-onnxruntime` ou `ankane/onnxruntime` + un fichier modèle |
| `NullEmbeddingProvider` | Tests / dev — retourne `[]` ; le downstream retombe sur le scoring par mots-clés |
| `CallableEmbeddingProvider` | Adapte des closures existantes `fn(array): array` ou la forme legacy `fn(string): array<float>` |

Branchables directement sur le `SemanticSkillRouter` rénové :

```php
use SuperAgent\Skills\SemanticSkillRouter;
use SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider;

$router = new SemanticSkillRouter(
    embedder: new OllamaEmbeddingProvider(),    // ou tout EmbeddingProvider
    topK: 5,
);
// Sans embedder, fallback sur le chevauchement de mots-clés ; cache vectoriel keyé par hash de contenu skill.
```

### `superagent resume` — reprise de session cross-harness

Récupérer une session Claude Code ou Codex CLI dans SuperAgent sans perdre le fil.

```bash
superagent resume list  --from claude
superagent resume show  --from claude --session 8e2c-...
superagent resume load  --from claude --session 8e2c-... \
  | superagent chat --provider kimi --resume-stdin
```

`--from` accepte `claude` / `claude-code` / `cc` / `codex`. Sous le capot : interface `Conversation\HarnessImporter` + importers par harness (`ClaudeCodeImporter` lit `~/.claude/projects/<hash>/<uuid>.jsonl` ; `CodexImporter` lit `~/.codex/sessions/**/*.jsonl`), qui produisent les `Message[]` internes ré-injectés dans le `Conversation\Transcoder` existant — la wire family bascule de façon transparente.

*Depuis v0.9.7*

---

## Boucle d'agent

`Agent::run($prompt, $options)` pilote la boucle complète jusqu'à ce que le modèle cesse d'émettre des blocs `tool_use`. Coût, usage et messages de chaque tour alimentent `AgentResult`.

```php
$result = $agent->run('...', [
    'model'             => 'claude-sonnet-4-5-20250929',  // surcharge par appel
    'max_tokens'        => 8192,
    'temperature'       => 0.3,
    'response_format'   => ['type' => 'json_schema', 'json_schema' => [...]],
    'idempotency_key'   => 'job-42:turn-7',               // depuis v0.9.1
    'system_prompt'     => 'Tu es un analyste précis.',
]);

echo $result->text();
$result->turns();          // nombre de tours
$result->totalUsage();     // Usage{inputTokens, outputTokens, cache*}
$result->totalCostUsd;     // float, sur tous les tours
$result->idempotencyKey;   // passthrough pour déduplication de logs (depuis v0.9.1)
```

### Caps de budget et de tours

```php
$agent = (new Agent(['provider' => 'openai']))
    ->withMaxTurns(50)
    ->withMaxBudget(5.00);            // USD — cap dur ; abort en cours de boucle si dépassé
```

### Streaming

```php
foreach ($agent->stream('...') as $assistantMessage) {
    echo $assistantMessage->text();
}
```

Pour les flux d'événements lisibles par machine (JSON / NDJSON pour IDE / CI), voir la section [Wire Protocol](#wire-protocol).

### Auto-mode (détection de tâche)

```php
new Agent([
    'provider'  => 'anthropic',
    'auto_mode' => true,               // délègue à TaskAnalyzer pour choisir modèle + outils
]);
```

### Mode Squad — Équipe adaptative multi-modèles *(v0.9.9)*

Auto-mode choisit entre agent unique et multi-agents. Le **mode Squad** va plus loin : quand un prompt se décompose en 2+ sous-tâches couvrant plusieurs niveaux de difficulté, chaque sous-tâche est dispatchée vers son propre modèle — Haiku pour l'extraction triviale, Sonnet pour les refactos modérés, DeepSeek-Pro / Opus pour le raisonnement difficile. **Aucun agent maître** — la définition du workflow est l'orchestrateur, chaque étape est un pair. Les verrous Human-in-the-Loop s'insèrent en ligne sous forme d'`ApprovalStep`.

```php
use SuperAgent\Squad\{TaskDecomposer, PeerOrchestrator, SquadCheckpointStore};

$subTasks = (new TaskDecomposer())->decompose(
    "1. Étudier le module d'authentification\n".
    "2. Concevoir un plan de migration (la décision finale nécessite une revue humaine)\n".
    "3. Implémenter OAuth2"
);

$orchestrator = new PeerOrchestrator(
    agentDispatcher: $myDispatcher,                          // (SquadDispatchRequest $r) => string|array
    checkpointStore: new SquadCheckpointStore('/tmp/cp'),    // reprise après crash par étape
    output: $consoleOutput,                                  // diffuse les événements d'étape
    maxCostUsd: 5.00,                                        // rétrograde les étapes restantes à 80 % du plafond
);

$result = $orchestrator->run('refactor-2026-05', $subTasks);
```

Ce que le chemin maître-esclave ne donne pas :

- **Multi-modèles** : chaque sous-tâche choisit son propre couple (provider, model) via `ModelTierMap`. Les défauts sont délibérément multi-fournisseurs (Anthropic + DeepSeek + …) pour qu'une sous-tâche HARD ne paie pas le tarif Opus parce qu'une voisine EASY tourne aussi.
- **Sessions stables par rôle** : `squad:{squadId}:role:{roleName}` est réutilisé entre reprises — le préfixe de cache de prompt du provider survit, donc rejouer l'étape N ne réamorce pas le modèle.
- **Skip + redémarrage** : `SquadResumeManager` réinjecte les sorties d'étapes déjà terminées et invalide en BFS uniquement ce qui dépend d'une étape redémarrée.
- **Groupes parallèles** : les prompts avec `同时 / in parallel` sont fractionnés sur `和 / and`, et les pairs d'un même groupe s'exécutent via un seul `ParallelStep`.
- **Rétrogradation de coût** : à 80 % de `maxCostUsd`, les étapes restantes descendent d'un cran (EXPERT → HARD → MODERATE → …).
- **Échelle de repli provider** : si le provider principal d'une bande n'est pas enregistré, `ModelTierMap::resolve()` descend puis remonte jusqu'à trouver une alternative enregistrée.
- **Déclenchement auto** : `AutoModeAgent` route vers squad quand le prompt décomposé couvre ≥ 2 bandes. Forcer avec `superagent auto "<task>" --squad`, désactiver avec `--no-squad`.
- **Messagerie pair-à-pair** : les agents se parlent directement via `PeerMailbox` (`tell` / `broadcast` / `ask`) — pas d'agent maître relayant les résumés. `PeerAsk` route via la session stable du pair pour que son cache de prompt survive. Outils en lecture seule `PeerAsk` / `PeerSend` / `PeerInbox` pour que l'agent appelle ses pairs depuis sa boucle d'outils.

CLI :
```bash
superagent auto "<task>" --squad --max-cost 5.00 --verbose
```

Configuration (`config/superagent.php`) :
```php
'squad' => [
    'prefer_squad'   => true,
    'max_cost_usd'   => 5.00,
    'checkpoint_dir' => '/var/lib/superagent/squad',
    'tier_map' => [                  // surcharge n'importe quelle bande ; les défauts remplissent le reste
        'expert' => ['provider' => 'openai', 'model' => 'gpt-5-pro'],
    ],
],
```

### Bibliothèque d'équipes YAML *(v1.0.1)*

Un `SquadPlan` ne requiert plus de code PHP. Déposez un fichier YAML, enregistrez-le, lancez-le :

```yaml
# resources/squad-teams/code-review-loop.yaml (livré avec le SDK)
name: code-review-loop
description: Écrivain + relecteur avec boucle d'injection de feedback
tier_map:
  hard:   {provider: anthropic, model: claude-opus-4-7}
  expert: {provider: openai,    model: gpt-5.1-codex}
steps:
  - name: write
    difficulty: hard
    prompt: "{{task}}"
  - name: review
    difficulty: expert
    depends_on: [write]
    prompt: "Artefact :\n{{steps.write.output}}"
    pause_after: true                # porte HITL optionnelle
loops:
  - writer: write
    reviewer: review
    feedback_key: review.feedback
    max_retries: 3
```

```php
use SuperAgent\Squad\TeamRegistry;

$plan = (new TeamRegistry())->require('code-review-loop');
$result = $orchestrator->run('refactor-2026-05', $plan->subTasks, /* … */);
```

**21 équipes de qualité production sont livrées dans `resources/squad-teams/`** — ingénierie (`code-review-loop`, `code-bug-triage`, `code-test-driven`, `code-security-audit`, `code-perf-optimize`, …), architecture (`arch-from-scratch`, `arch-decision-record`, `arch-migration-plan`), QA/SRE (`qa-ship-gate`, `qa-multi-model-council`, `qa-incident-response`), produit (`product-strategy-trio`, `product-discovery-pair`), docs (`docs-tech-pipeline`, `docs-api-spec-pipeline`), data (`data-research-trio`, `data-anomaly-detector`), ops/croissance (`release-coordination`, `growth-hypothesis-test`).

**Surcharge à trois niveaux** : les hôtes appellent `addDirectory('/path/to/host-teams')` pour superposer des YAML supplémentaires par-dessus la bibliothèque livrée, ou `register($name, $plan)` pour des surcharges programmatiques. Les répertoires ajoutés plus tard surchargent les précédents ; les enregistrements runtime surchargent les entrées de répertoire. Même schéma que `ModelCatalog`.

**Exécuteur de boucle relecteur** : `Squad\ReviewerLoopRunner` enveloppe n'importe quel callable `agentDispatcher`. Si la première ligne non vide du relecteur ne commence pas par `APPROVED` (insensible à la casse), le runner préfixe le feedback du relecteur au prompt de l'écrivain et redéclenche celui-ci. La boucle sort sur approbation ou au `max_retries`.

### Orchestration cross-mode *(v1.0.1)*

`auto / smart / squad` peuvent désormais se composer, récurser et se transmettre la main via un `ModeContext` partagé. Une étape squad peut déclarer `mode: smart` et récurser dans l'orchestrateur ; une sous-tâche smart peut atterrir sur un squad complet ; un relecteur qui épuise `max_retries` escalade automatiquement vers `smart`. À chaque niveau, les lectures du tableau noir, les écritures du registre de coût et les session ids de cache de prompt partagent un seul objet — plus d'îlots enfants isolés.

```yaml
steps:
  - name: research
    mode: smart                      # récursion : smart décompose + route en interne
    prompt: "Étudier {{task}}"

  - name: implement
    mode: squad
    team: code-review-loop           # cette étape utilise une équipe livrée
    prompt: "Construire à partir de {{steps.research.output}}"

  - name: stress-test
    mode_chain: [single, smart, squad]   # chaîne d'escalade sur échec
    fail_criteria: "REJECTED|error"
    prompt: "Tester en charge le résultat"
```

Le `ModeContext` traverse chaque niveau de récursion :

```php
use SuperAgent\Modes\{ModeContext, ModeRouter, CrossModePolicy};

$ctx = ModeContext::root('squad', policy: new CrossModePolicy(
    maxDepth: 4,
    budgetCapUsd: 5.00,
    autoEscalateOnFailure: true,
    escalateTo: 'smart',
));

$router = new ModeRouter();
$router->register(new AutoModeAdapter($autoModeAgent));
$router->register(new SmartModeAdapter($smartOrchestrator));
$router->register(new SquadModeAdapter());

$result = $router->dispatch('squad', $task, $ctx);
// $ctx->costLedger->total()  → somme de chaque dispatch leaf
// $ctx->costLedger->byMode() → {squad: 0.31, smart: 0.18, auto: 0.02}
// $ctx->blackboard->entries() → toutes les claim / evidence / risk / decision
```

**SPI de couplage faible** :
- `Squad\SquadDispatcherRegistry::set($dispatcher)` — les hôtes installent un dispatcher squad par défaut (p.ex. une version CLI-aware)
- `Modes\ModeRouterRegistry::set($router)` — les hôtes installent un routeur cross-mode par défaut (p.ex. un qui connaît les tags leaf `cli:claude_cli` en plus des trois noms de mode)

Tous deux sont des slots statiques au niveau classe. Les chemins de code du SDK les consultent avant de retomber sur les défauts internes. Le SDK lui-même n'appelle jamais `set()` — les slots sont réservés aux hôtes.

### Gemini 3.5 + thinking / grounding *(v1.0.5)*

`gemini-3.5-pro` / `gemini-3.5-flash` / `gemini-3.5-flash-lite` sont des entrées de catalogue de premier ordre (alias `gemini` → 3.5 Pro). Les SKU previews 3.x de gemini-cli (`gemini-3-pro-preview`, `gemini-3.1-pro-preview`, `gemini-3-flash-preview`, `gemini-3.1-flash-lite-preview`) sont également livrés. Modèle par défaut du provider passé de `gemini-2.0-flash` → `gemini-3.5-flash`.

```php
$agent = new Agent([
    'provider' => 'gemini',
    'model'    => 'gemini-3.5-pro',
    'options'  => [
        'thinking'      => \SuperAgent\Thinking\ThinkingConfig::adaptive(),   // → thinkingLevel: HIGH
        'grounding'     => true,                                              // → tools[].googleSearch
        'url_context'   => true,                                              // → tools[].urlContext
    ],
]);
```

`AssistantMessage::$metadata['grounding_sources']` contient `[{uri, title}, ...]` pour chaque citation Google Search ; `parts[].thought` (parties pensantes Gemini 3.x) est rendu via `ContentBlock::thinking()`, et `usageMetadata.thoughtsTokenCount` est ajouté à `Usage::$outputTokens`.

### Détection sémantique de boucle *(v1.0.5)*

`Guardrails\LoopDetector` attrape déjà les répétitions hash-identiques (5 appels d'outil dupliqués, chants de 10 caractères, 8 lectures de fichier dans une fenêtre de 15 appels, …). Le nouveau `Guardrails\LlmLoopChecker` le complète avec une sonde Flash après le 30e tour — attrape les boucles *sémantiques* que les hashes ratent (le modèle paraphrase le même plan dix fois sans agir). Le prompt est le `LOOP_DETECTION_SYSTEM_PROMPT` verbatim de gemini-cli ; l'intervalle de vérification s'ajuste dynamiquement (5–15 tours).

```php
$flash = new GeminiProvider(['model' => 'gemini-3.5-flash', 'api_key' => env('GEMINI_API_KEY')]);
$checker = new LlmLoopChecker($flash);
foreach ($turns as $i => $turn) {
    if ($v = $checker->turnStarted($i, $conversationHistory, $originalPrompt)) {
        // $v->type === LoopType::LlmDetected ; arrêter et remonter à l'utilisateur
    }
}
```

### Six patterns d'opencode portés *(v1.0.5)*

Chacun comble un gap par rapport à un agent de coding de production ; tout est opt-in et testé.

| Module                              | Ce qu'il fait                                                                                                                                                                                                              |
|-------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Permissions\BashArity`             | Table d'arité 110+ CLI (git→2, `docker compose`→3, `terraform workspace`→3, `vault kv`→3, …). `BashCommandClassifier::extractPrefix()` utilise longest-prefix-wins pour que les règles de permission matchent la bonne granularité. |
| `Context\Strategies\ConversationCompressor::getStructuredSummaryPrompt()` | Template Markdown 7 sections (Goal / Constraints / Progress·Done·InProgress·Blocked / Decisions / Next Steps / Critical Context / Relevant Files). Sélection via `options: ['summary_prompt' => 'structured']`. |
| Espace de noms `Format\`            | 26 formateurs auto (gofmt, prettier, biome, ruff, rustfmt, **pint**, rubocop, shfmt, clang-format, terraform fmt, …). `(new FormatterRunner())->formatFile($path, $worktree)` lance tous les formateurs applicables après une édition. |
| Espace de noms `LSP\`               | Vrai client LSP stdio JSON-RPC (était un stub `'simulated'`). 9 serveurs : phpactor/intelephense, gopls, rust-analyzer, pyright, typescript-language-server, clangd, bash-language-server, zls. `LSPTool` expose `diagnostics`/`hover`/`definition`/`touch`. |
| Espace de noms `ACP\`               | Serveur Agent Client Protocol v1. Les éditeurs qui parlent ACP (Zed, Neovim avec Codecompanion, …) se branchent directement sur SuperAgent. `(new Server($handler))->serve()` bloque sur stdio.                            |
| `Skills\SkillManager::discoverExternalSkills()` | Remonte de cwd à la racine du worktree en chargeant `.claude/skills/**/SKILL.md` et `.agents/skills/**/SKILL.md` à chaque niveau ; à la racine, aussi `skills/**/SKILL.md` / `skill/**/SKILL.md`. Le fichier doit être littéralement nommé `SKILL.md`. |

### Timeline Chrome Trace Event *(v1.0.6)*

Chaque orchestration longue (debate, red-team, error-recovery, cost-autopilot, agents parallèles) écrit maintenant dans un ring buffer lock-free de `TraceEvent`. Les sites de déclenchement (erreurs, snapshot, fin de protocole) flushent vers un fichier `.json` que vous ouvrez dans `chrome://tracing` ou `ui.perfetto.dev`.

```php
use SuperAgent\Tracing\TraceCollector;

$end = TraceCollector::getInstance()->span('llm.dispatch', 'llm', 'session:abc');
$result = $provider->call(...);
$end(['model' => $result->model, 'cost_usd' => $result->cost]);

// Sur un point de déclenchement — erreur, fin de debate, auto-snapshot d'un agent :
$path = TraceCollector::getInstance()->dump(trigger: 'manual', reason: 'inspect after demo');
// → /tmp/superagent-traces/trace_superagent_{session}_{ts}_manual.json
```

Émetteurs câblés : `Debate\DebateOrchestrator` (`debate.start` / `debate.rounds` / `debate.round_N` / `debate.judge` / `debate.total` plus `redteam.*` / `ensemble.*`), `ErrorRecovery\ErrorRecoveryManager` (auto-dump des derniers N événements sur erreur irrécupérable / retries épuisés), `CostAutopilot\CostAutopilot` (counter `budget.spend` + instants `budget.tier_change`), `Console\Output\ParallelAgentDisplay::exportPerfettoJson()` (snapshot de toute l'équipe). Env : `SUPERAGENT_TRACE_ENABLED=false` pour désactiver, `SUPERAGENT_TRACE_PATH=…` pour changer le répertoire.

L'agent lui-même peut appeler `snapshot('about to git reset', tag: 'pre_destructive')` (nouvel outil `SnapshotTool`) quand quelque chose semble étrange — pure observabilité, `isReadOnly === true`.

### JSON Event Stream aligné pi *(v1.0.6)*

Taxonomie canonique de 18 types d'événements de session empruntée à [JSON Event Stream Mode de pi](https://pi.dev/docs/latest/json), pour que les sessions SuperAgent puissent être rejouées par n'importe quel viewer pi-compatible.

```php
use SuperAgent\Tracing\PiEventStream;
use SuperAgent\Tracing\PiEventStreamWriter;

PiEventStream::subscribe(new PiEventStreamWriter('/path/to/session.events.jsonl'));

PiEventStream::emit(PiEventStream::AGENT_START, ['sessionId' => 's-1']);
PiEventStream::emit(PiEventStream::TURN_START, ['turnId' => 't-1', 'sessionId' => 's-1', 'model' => 'claude-opus-4-7']);
// ...
PiEventStream::emit(PiEventStream::AGENT_END, ['sessionId' => 's-1']);
```

Types d'événements : `session` / `agent_start` / `agent_end` / `turn_start` / `turn_end` / `message_start` / `message_update` / `message_end` / `tool_execution_start` / `tool_execution_update` / `tool_execution_end` / `queue_update` / `compaction_start` / `compaction_end` / `auto_retry_start` / `auto_retry_end` / `model_change` / `thinking_level_change`. Les anciens noms SuperAgent sont mappés via `PiEventStream::translateLegacy()`.

### Steer en cours de tour + queue de follow-up *(v1.0.6)*

Correction en cours de tour (sans interrompre) + mise en file de follow-up post-tour, empruntées à pi. Un opérateur (ou un gestionnaire RPC côté host) peut rediriger un agent en cours sans perdre l'état des outils.

```php
// Pendant que l'agent est en mid-turn, depuis un autre event handler :
$agent->steer('Stop, le bug est dans src/Auth/Session.php, pas src/Auth/Login.php');
// La prochaine itération de QueryEngine draine la file et préfixe le steer comme un user message synthétique.

// Ou mettre en file un follow-up qui démarre APRÈS la fin du tour courant :
$agent->followUp('Une fois fini, lance aussi les tests dans tests/Unit/Auth/');
```

Même surface sur ACP : les éditeurs qui parlent le protocole envoient les méthodes JSON-RPC `session/steer` / `session/follow_up` (constantes `Protocol::METHOD_SESSION_STEER` / `METHOD_SESSION_FOLLOW_UP`). Le contrat `Handler` gagne `steer($params)` / `followUp($params)` ; `DefaultHandler` fournit `drainSteer($sessionId)` / `drainFollowUp($sessionId)` que le `promptFn` du host consomme à des checkpoints sûrs.

### Compression de sortie structurée RTK *(v1.0.6)*

Les sorties de `git diff` / `grep -rn` / `find` / `ls -R` / `tree` dominent l'usage de tokens dans les sessions de coding — typiquement 30-50 % du budget d'entrée par tour, dont beaucoup de bruit cosmétique. `Tools\Compression\RtkPipeline` (emprunté à 9Router + claude-octopus) les compresse de façon lossy-safe : chemins, numéros de ligne, hunks de diff sont préservés verbatim ; le bruit cosmétique est jeté. Câblé dans `QueryEngine` — chaque résultat d'outil non-erreur passe par lui.

| Outil | Économie typique | Ce qui est préservé |
|-------|------------------|---------------------|
| `git diff` | 40-65 % | `diff --git`, `--- a/`, `+++ b/`, hunks `@@`, toutes les lignes `+`/`-`, markers de mode |
| `grep` / `rg` | 30-50 % | Hits canoniques `file:line:match` ; chemins répétés compactés en indentation |
| `find` | 25-40 % | Noms de fichiers feuilles ; préfixes de répertoire répétés collapse |
| `ls -R`, `tree` | 20-35 % | Markers de structure (`├─` / `└─` / `│`) ; supprime annotations byte/size |

Opt-out par appel : `options: ['disable_rtk_compression' => true]`. Statistiques : `$pipeline->stats()` (`bytes_in` / `bytes_out` / `saved_bytes` / `ratio`).

### Portabilité du schéma d'outils inter-providers *(v1.0.6)*

Anthropic, OpenAI, Gemini acceptent chacun un sous-ensemble légèrement différent de JSON Schema (Gemini refuse `$ref` / `$defs` / `oneOf` en top level ; le mode strict d'OpenAI est plus strict que le non-strict ; etc.). `Tools\Schema\Schema` permet de déclarer un schéma une seule fois en portant des marqueurs d'intention ; `ProviderNormalizer` le réécrit par provider.

```php
use SuperAgent\Tools\Schema\Schema;
use SuperAgent\Tools\Schema\ProviderNormalizer;

$inputSchema = Schema::object([
    'mode' => Schema::stringEnum(['read', 'write', 'delete']),
    'path' => Schema::string('Chemin absolu'),
], required: ['mode', 'path']);

// Dans inputSchema() de votre outil, réécrire par provider avant d'envoyer :
$anthropic = ProviderNormalizer::forAnthropic($inputSchema);
$openai    = ProviderNormalizer::forOpenAI($inputSchema);
$gemini    = ProviderNormalizer::forGemini($inputSchema);
// → variante Gemini : `oneOf` aplati en `enum`, `$ref`/`$defs` retirés, `format` non supportés strippés.
```

La conformité inter-providers est vérifiée par `tests/Tools/Schema/CrossProviderComplianceTest.php` — round-trip chaque forme taguée à travers les trois normaliseurs et asserte que chaque sortie passe les règles d'acceptation du provider cible.

### Durcissement de la compatibilité Kimi (Moonshot) *(v1.0.10)*

Validé en comparant le chemin Kimi de SuperAgent au client officiel [`kimi-code`](https://github.com/MoonshotAI/kimi-code) de MoonshotAI (`packages/kosong`, `packages/oauth`). Contrairement au `ProviderNormalizer` opt-in ci-dessus (que vous invoquez à la rédaction d'un schéma), ces correctifs s'appliquent automatiquement sur le wire :

- **Les schémas d'outils sont normalisés pour le validateur de Moonshot à chaque requête.** `Format\JsonSchemaNormalizer` inline les `$ref` / `$defs` locaux et ajoute un `type` aux schémas de propriété sans type — la forme MCP enum-only courante que Moonshot rejette — pour que les outils MCP / Skill / Agent parviennent intacts à Kimi. La passe d'inlining des `$ref` est aussi exposée comme hook `ChatCompletionsProvider::normalizeToolSchema()`, que tout backend strict peut activer.
- **Les requêtes en streaming activent l'usage.** `stream_options: {include_usage: true}` est désormais envoyé à chaque requête chat-completions en streaming. Sans lui, les serveurs conformes à la spec OpenAI (Kimi compris) ne renvoient **aucun** bloc usage — mettant silencieusement à zéro le comptage des tokens / coûts / tokens en cache. Bénéficie à tout provider compatible OpenAI.
- **`max_completion_tokens`.** Les modèles de raisonnement Kimi partagent le budget de complétion avec le canal caché `reasoning_content` ; le plafond passe désormais par `max_completion_tokens` pour qu'une petite valeur n'affame pas la réponse en un `200` vide.
- **Round-trip de `reasoning_content`.** Kimi rejoue son raisonnement précédent à travers l'historique multi-tours (mutualisé avec le chemin DeepSeek), gardant cohérente une séquence réflexion → appel d'outil → réflexion.
- **Découverte des capacités par modèle.** `ModelCatalogRefresher` mappe les `supports_reasoning` / `image_in` / `video_in` / `tool_use` de Moonshot (et les `supported_parameters` d'OpenRouter) depuis `/models` vers la carte de capacités du `CapabilityRouter`.
- **Agent Swarm est opt-in.** `kimi_swarm` renvoie une erreur explicite sauf si `SUPERAGENT_KIMI_SWARM_ENABLED` est défini — `kimi-code` ne fournit aucun endpoint REST de swarm (son parallélisme vient de sous-agents locaux `coder` / `explore` / `plan`). La plomberie REST et ses tests de contrat wire sont conservés pour le jour où Moonshot publiera la spec.

Tests : `tests/Unit/Format/JsonSchemaNormalizerTest.php`, `tests/Unit/Providers/KimiProviderTest.php`, `tests/Unit/Providers/ChatCompletionsSseParserTest.php`.

### Branchement de session — fork pi `/tree` *(v1.0.6)*

pi modélise une session comme un arbre append-only, pas comme une ligne. SuperAgent reflète maintenant `/tree` :

```php
use SuperAgent\Session\SessionManager;

$newBranchId = $sessionManager->fork(
    sourceSessionId: 's-current',
    forkAtIndex: 12,
    summaryFn: function(array $abandoned) use ($llm) {
        return $llm->summarize($abandoned);   // résumé d'une ligne stocké sur la source
    },
    displayName: 'try-event-sourcing-instead',
);
```

`Conversation\BranchManager` fournit l'algèbre d'arbre pure (sans I/O, sans LLM) — `leaves()`, `ancestry($id)`, `findCommonAncestor($a, $b)`, `collectBranch($leaf, $ancestor)`, `makeBranchSummaryEntry()`. `Conversation\Importers\PiImporter` rejoue les sessions pi existantes (`~/.pi/agent/sessions/`) dans le wire format SuperAgent.

### Squad consensus gates — vote parallèle N-of-M *(v1.0.6)*

Le `reviewer_loop` existant est une porte série single-veto (un reviewer d'accord → pass). `Squad\ConsensusGate` (emprunté à claude-octopus) est une porte parallèle N-of-M : M pairs votent en parallèle, ≥N doivent approuver.

```yaml
- kind: consensus_gate
  name: ship-decision
  depends_on: [synthesize]
  params:
    n: 3
    m: 4
    voters:
      - role: qa-bach
      - role: security-schneier
      - role: cto-vogels
      - role: ceo-bezos
  prompt: |
    Examine le changement proposé et réponds avec une ligne :
    VERDICT: APPROVE | REJECT | ABSTAIN
    suivie d'une justification d'un paragraphe.
```

Le tally retourne `{verdict, passed, counts, per_voter}` ; les étapes downstream branchent sur `passed`.

### Qwen 3.7 Max + drop-in protocole Anthropic *(v1.0.6)*

Qwen 3.7 Max (sorti le 2026-05-21) ship un support **natif du protocole Anthropic API** — le wire `/v1/messages` est byte-compatible avec la forme canonique d'Anthropic, donc les clients shaped-Claude-Code peuvent pointer sur DashScope et utiliser Qwen comme un drop-in.

```php
// Requêtes shape-Anthropic directement contre Qwen :
$agent = new Agent([
    'provider' => 'qwen-anthropic',
    'api_key'  => env('DASHSCOPE_API_KEY'),
    'model'    => 'qwen3.7-max',          // 1M ctx, $2.50 / $7.50 par 1M tokens
]);

// Ou le chemin OpenAI-compat avec le nouveau modèle par défaut :
$agent = new Agent(['provider' => 'qwen']);   // → défaut qwen3.7-max
```

> **TODO :** l'URL exacte du endpoint protocole Anthropic de DashScope n'est pas encore documentée en anglais ; le défaut `dashscope.aliyuncs.com/anthropic-mode/v1` est une supposition. Vérifier et override via `base_url` jusqu'à publication officielle par Alibaba.

### Cookbook de recettes *(v1.0.6)*

Recettes ciblées, copiables-collables pour les sous-systèmes de haut niveau dans [`docs/cookbook/`](docs/cookbook/) :

- [`01-debate-protocol.md`](docs/cookbook/01-debate-protocol.md) — débat structuré proposer / critic / judge
- [`02-redteam-attack.md`](docs/cookbook/02-redteam-attack.md) — pattern adversarial builder / attacker / reviewer
- [`03-cost-autopilot.md`](docs/cookbook/03-cost-autopilot.md) — tiering de modèle piloté par budget (cascade Opus → Sonnet → Haiku)
- [`04-cost-prediction.md`](docs/cookbook/04-cost-prediction.md) — prédiction proactive de dépense basée KNN
- [`05-adaptive-feedback.md`](docs/cookbook/05-adaptive-feedback.md) — corrections promues en patterns auto-appliqués

### Idempotence

```php
$result = $agent->run($prompt, ['idempotency_key' => $queueJobId . ':' . $turnNumber]);
// $result->idempotencyKey tronqué à 80 caractères ; surfacé sur AgentResult
// pour que les hosts qui écrivent ai_usage_logs puissent dédupliquer.
```

*Depuis v0.9.1*

---

## Outils et multi-agents

Les outils sont des sous-classes de `SuperAgent\Tools\Tool`. Les outils intégrés — read / write / edit / bash / glob / grep / search / fetch — se chargent automatiquement. Les outils personnalisés s'enregistrent via `$agent->registerTool(new MyTool())`.

```php
$agent = (new Agent(['provider' => 'anthropic']))
    ->loadTools(['read', 'write', 'bash'])
    ->registerTool(new MyDomainTool());

$result = $agent->run('applique le plan de refacto dans ./plan.md');
```

### Orchestration multi-agents (`AgentTool`)

Dispatchez des sous-agents en parallèle en émettant plusieurs blocs `agent` tool_use dans un même message assistant :

```php
$agent->registerTool(new AgentTool());

$result = $agent->run(<<<PROMPT
Exécute ces trois investigations en parallèle :
1. Lis CHANGELOG.md et résume les trois dernières releases
2. Lis composer.json et liste toutes les dépendances runtime
3. Grep les commentaires TODO dans src/
Consolide les trois rapports.
PROMPT);
```

Chaque sous-agent tourne dans son propre processus PHP (via `ProcessBackend`) ; l'I/O bloquante d'un enfant ne bloque pas les frères. Lorsque `proc_open` est désactivé, les fibres prennent le relais.

#### Preuves de productivité

Chaque résultat `AgentTool` porte des preuves concrètes de ce que l'enfant a réellement fait — pas juste `success: true` :

```php
[
    'status'              => 'completed',          // ou 'completed_empty' / 'async_launched'
    'filesWritten'        => ['/abs/path/a.md'],   // chemins absolus dédupliqués
    'toolCallsByName'     => ['Read' => 3, 'Write' => 1],
    'totalToolUseCount'   => 4,                    // observé, pas auto-reporté
    'productivityWarning' => null,                 // ou chaîne consultative (localisée CJK — depuis v0.9.1)
    'outputWarnings'      => [],                   // depuis v0.9.1 — résultats d'audit FS
]
```

`completed_empty` — zéro appel d'outil observé. Re-dispatcher ou choisir un modèle plus solide.
`completed` + `productivityWarning` non vide — l'enfant a invoqué des outils sans écrire de fichiers (souvent correct pour des consultations ; vérifier le texte).

*Instrumentation de productivité depuis v0.8.9. Localisation CJK + audit FS depuis v0.9.1.*

#### Audit du répertoire de sortie + injection de garde

Passer `output_subdir` active à la fois (a) un bloc de garde préfixé au prompt de l'enfant (avec détection CJK) et (b) un scan FS post-sortie :

```php
$agent->run('...', [
    'output_subdir' => '/abs/path/to/reports/analyst-1',
]);
// L'audit détecte :
//   - extensions hors whitelist (défaut .md / .csv / .png)
//   - noms de fichiers réservés au consolidator (summary.md / 摘要.md / mindmap.md / ...)
//   - sous-répertoires de rôles frères (ceo / cfo / cto / marketing / ... ou slugs kebab-case)
// Configurable via le constructeur AgentOutputAuditor. Ne modifie jamais le disque.
```

*Depuis v0.9.1*

### Outils natifs par provider

N'importe quel cerveau principal peut appeler ceux-ci comme des outils normaux — pas de changement de provider.

**Builtins hébergés par Moonshot** (exécution côté serveur ; résultats inlinés dans la réponse assistant) :

| Outil | Attributs | Depuis |
|---|---|---|
| `KimiMoonshotWebSearchTool` (`$web_search`) | network | v0.9.0 |
| `KimiMoonshotWebFetchTool` (`$web_fetch`) | network | v0.9.1 |
| `KimiMoonshotCodeInterpreterTool` (`$code_interpreter`) | network, cost, sensitive | v0.9.1 |

**Autres familles d'outils natifs :**
- Kimi — `KimiFileExtractTool`, `KimiBatchTool`, `KimiSwarmTool`, `KimiMediaUploadTool`
- Qwen — `QwenLongFileTool` + feature `dashscope_cache_control`
- GLM — `glm_web_search`, `glm_web_reader`, `glm_ocr`, `glm_asr`
- MiniMax — `minimax_tts`, `minimax_music`, `minimax_video`, `minimax_image`

---

## Définitions d'agents (YAML / Markdown)

Chargement automatique depuis `~/.superagent/agents/` (scope utilisateur) et `<project>/.superagent/agents/` (scope projet). Trois formats : `.yaml`, `.yml`, `.md`. Héritage cross-format via `extend:`.

```yaml
# ~/.superagent/agents/reviewer.yaml
name: reviewer
description: Revue de code stricte
extend: base-coder              # peut être .yaml / .yml / .md
system_prompt: |
  Tu révises des PRs avec un focus sur la correction et l'état caché.
allowed_tools: [read, grep, glob]
disallowed_tools: [write, edit, bash]
model: claude-sonnet-4-5-20250929
```

```markdown
<!-- ~/.superagent/agents/analyst.md -->
---
name: analyst
extend: reviewer
model: gpt-5
---
Ton rôle est de faire émerger les risques architecturaux. Rends les conclusions en Markdown.
```

Les champs de listes d'outils (`allowed_tools`, `disallowed_tools`, `exclude_tools`) s'accumulent à travers les chaînes `extend:`. Profondeur limitée contre les cycles.

*Depuis v0.9.0*

---

## Skills

Capacités basées Markdown, enregistrables globalement et chargeables dans toute exécution d'agent :

```bash
superagent skills install ./my-skill.md
superagent skills list
superagent skills show review
superagent skills remove review
superagent skills path        # montre le répertoire d'installation
```

Le markdown de skill supporte un frontmatter avec `name`, `description`, `allowed_tools`, `system_prompt`. L'exécution de skill hérite du provider de l'appelant.

---

## Intégration MCP

### Enregistrement de serveur

```bash
superagent mcp list
superagent mcp add sqlite stdio uvx --arg mcp-server-sqlite
superagent mcp add brave stdio npx --arg @brave/mcp --env BRAVE_API_KEY=...
superagent mcp remove sqlite
superagent mcp status
superagent mcp path
```

La config est écrite atomiquement à `~/.superagent/mcp.json`.

### Serveurs MCP avec OAuth

```bash
superagent mcp auth <name>          # flux device-code RFC 8628
superagent mcp reset-auth <name>    # efface le token stocké
superagent mcp test <name>          # probe disponibilité (stdio `command -v` ou joignabilité HTTP)
```

Les serveurs qui déclarent un bloc `oauth: {client_id, device_endpoint, token_endpoint}` dans leur config utilisent ce flux. *Depuis v0.9.0.*

### Catalogue déclaratif + synchronisation non destructive

Déposez un catalogue à `.mcp-servers/catalog.json` (ou `.mcp-catalog.json`) à la racine du projet :

```json
{
  "mcpServers": {
    "sqlite": {"command": "uvx", "args": ["mcp-server-sqlite"]},
    "brave":  {"command": "npx", "args": ["@brave/mcp"], "env": {"BRAVE_API_KEY": "k"}}
  },
  "domains": {
    "baseline": ["sqlite"],
    "all":      ["sqlite", "brave"]
  }
}
```

Synchroniser vers un `.mcp.json` projet :

```bash
superagent mcp sync                         # catalogue complet
superagent mcp sync --domain=baseline       # seulement le domaine "baseline"
superagent mcp sync --servers=sqlite,brave  # sous-ensemble explicite
superagent mcp sync --dry-run               # aperçu, sans écriture disque
```

Contrat non destructif — hash disque == hash rendu → `unchanged` ; un fichier édité par l'utilisateur est gardé `user-edited` ; première écriture ou match avec notre dernier hash → `written`. Un manifest à `<project>/.superagent/mcp-manifest.json` trace le sha256 de chaque fichier écrit, pour que les entrées obsolètes soient nettoyées automatiquement.

*Depuis v0.9.1*

---

## Wire Protocol

v1 — JSON délimité par sauts de ligne (NDJSON), un événement par ligne, auto-descriptif via les champs de premier niveau `wire_version` + `type`. Fondation pour les ponts IDE, l'intégration CI, les logs structurés.

```bash
superagent --output json-stream "résume src/"
# Émet des événements comme :
# {"wire_version":1,"type":"turn.begin","turn_number":1}
# {"wire_version":1,"type":"text.delta","delta":"Je vais commencer par..."}
# {"wire_version":1,"type":"tool.call","name":"read","input":{"path":"src/"}}
# {"wire_version":1,"type":"turn.end","turn_number":1,"usage":{...}}
```

### Transport (depuis v0.9.1)

Choisissez la destination du flux via un DSN :

| DSN | Signification |
|---|---|
| `stdout` (défaut) / `stderr` | Flux standard |
| `file:///path/to/log.ndjson` | Écriture fichier en mode append |
| `tcp://host:port` | Connexion à un pair TCP en écoute |
| `unix:///path/to/sock` | Connexion à une socket unix en écoute |
| `listen://tcp/host:port` | Écoute TCP, accepte un client |
| `listen://unix//path/to/sock` | Écoute socket unix, accepte un client |

Usage programmatique :

```php
$factory = new SuperAgent\CLI\AgentFactory();
[$emitter, $transport] = $factory->makeWireEmitterForDsn('listen://unix//tmp/agent.sock');

// L'IDE se connecte, puis :
$agent->run($prompt, ['wire_emitter' => $emitter]);

$transport->close();
```

Socket pair non bloquante — un IDE déconnecté ne bloque pas la boucle agent.

*Wire Protocol v1 depuis v0.9.0. Transport socket / TCP / file depuis v0.9.1.*

---

## Retry, erreurs, observabilité

### Retry en couches

```php
new Agent([
    'provider'               => 'openai',
    'request_max_retries'    => 4,       // HTTP connect / 4xx / 5xx (défaut 3)
    'stream_max_retries'     => 5,       // réservé pour reprise mid-stream (Responses API)
    'stream_idle_timeout_ms' => 60_000,  // coupure low-speed cURL sur SSE (défaut 300 000)
]);
```

Backoff exponentiel avec jitter (facteur 0,9–1,1×) empêche le thundering herd des retries parallèles. Le header `Retry-After` est honoré exactement (sans jitter — le serveur sait mieux).

*Depuis v0.9.1*

### Erreurs classifiées

Six sous-classes de `ProviderException` émises par `OpenAIErrorClassifier` à partir de `error.code` / `error.type` / statut HTTP :

```php
try {
    $agent->run($prompt);
} catch (\SuperAgent\Exceptions\Provider\ContextWindowExceededException $e) {
    // prompt trop long ; compacter l'historique ou changer de modèle
} catch (\SuperAgent\Exceptions\Provider\QuotaExceededException $e) {
    // quota mensuel atteint ; notifier l'opérateur
} catch (\SuperAgent\Exceptions\Provider\UsageNotIncludedException $e) {
    // le plan ChatGPT ne couvre pas ce modèle ; upgrade ou bascule sur clé API
} catch (\SuperAgent\Exceptions\Provider\CyberPolicyException $e) {
    // rejet par la policy — ne pas retry
} catch (\SuperAgent\Exceptions\Provider\ServerOverloadedException $e) {
    // retryable avec backoff ; voir $e->retryAfterSeconds
} catch (\SuperAgent\Exceptions\Provider\InvalidPromptException $e) {
    // body malformé — inspecter et corriger
} catch (\SuperAgent\Exceptions\ProviderException $e) {
    // capture-tout ; chaque sous-classe ci-dessus étend celle-ci
}
```

Toutes les sous-classes étendent `ProviderException`, donc les `catch (ProviderException)` existants continuent à tout capturer.

*Depuis v0.9.1*

### Tableau de bord santé

```bash
superagent health                # probe cURL 5s de chaque provider configuré
superagent health --all          # inclut les providers sans clé env (utile pour "qu'est-ce que j'ai oublié ?")
superagent health --json         # table lisible machine ; sortie non nulle si échec
```

Enveloppe `ProviderRegistry::healthCheck()` — distingue rejet d'auth (401/403) vs timeout réseau vs "pas de clé API" pour que l'opérateur corrige la bonne chose sans deviner.

*Depuis v0.9.1*

### Durcissement du parser SSE (depuis v0.9.0)

- **Assemblage des tool calls par index** — un appel streamé fragmenté sur N chunks produit maintenant un seul bloc tool-use, pas N fragments.
- **Détection de `finish_reason: error_finish`** — les signaux de throttle DashScope-compat lèvent `StreamContentError` (retryable, HTTP 429) au lieu de contaminer silencieusement le corps du message.
- **Réparation de JSON tronqué** — tentative unique de fermer des accolades déséquilibrées avant repli sur un dict d'args vide.
- **Lecture à double shape des tokens en cache** — `usage.prompt_tokens_details.cached_tokens` (shape OpenAI actuel) ET `usage.cached_tokens` (legacy) alimentent tous deux `Usage::cacheReadInputTokens`.

---

## Garde-fous et checkpoints

### Détection de boucle (depuis v0.9.0)

Cinq détecteurs observent le bus d'événements de streaming ; le premier déclenchement est persistant :

| Détecteur | Signal |
|---|---|
| `TOOL_LOOP` | Même outil + mêmes args normalisés 5× de suite |
| `STAGNATION` | Même nom d'outil 8× indépendamment des args |
| `FILE_READ_LOOP` | ≥ 8 des 15 derniers appels sont en lecture, avec exemption au démarrage |
| `CONTENT_LOOP` | Même fenêtre glissante de 50 caractères apparaît 10× dans le texte streamé |
| `THOUGHT_LOOP` | Même texte de canal thinking apparaît 3× |

```php
new Agent([
    'provider'        => 'openai',
    'loop_detection'  => true,           // valeurs par défaut
    // OU surcharges par détecteur :
    // 'loop_detection' => ['TOOL_LOOP' => 10, 'STAGNATION' => 15],
]);
```

Les violations sont diffusées comme des événements wire `loop_detected` — l'agent continue, le host décide d'intervenir.

### Checkpoints + shadow-git (depuis v0.9.0)

Chaque tour snapshot l'état de l'agent (messages, coût, usage). Attachez un `GitShadowStore` et les snapshots au niveau fichier atterrissent à côté dans un repo git **séparé et bare** à `~/.superagent/history/<project-hash>/shadow.git` — ne touche jamais votre propre `.git`.

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\GitShadowStore;

$mgr = new CheckpointManager(shadowStore: new GitShadowStore('/path/to/project'));
$mgr->createCheckpoint($agentState, label: 'after-refactor');

// Plus tard :
$checkpoints = $mgr->list();
$mgr->restore($checkpoints[0]->id);
$mgr->restoreFiles($checkpoints[0]);   // rejoue le shadow commit
```

Le restore réverse les fichiers suivis et laisse les fichiers non suivis en place (sécurité). Le `.gitignore` du projet est respecté (le worktree du shadow EST le répertoire projet).

### Modes de permission

```php
new Agent([
    'provider'        => 'anthropic',
    'permission_mode' => 'ask',     // ou 'default' / 'plan' / 'bypassPermissions'
]);
```

`ask` interroge le `PermissionCallbackInterface` de l'appelant avant tout outil de type write. Enveloppez-le dans `WireProjectingPermissionCallback` pour relayer la requête comme événement wire vers les IDEs.

---

## CLI autonome

```bash
superagent                                  # REPL interactif
superagent "corrige le bug de connexion"    # appel unique
superagent init                             # initialise ~/.superagent/
superagent auth login <provider>            # importe une connexion OAuth
superagent auth status                      # affiche les credentials stockés
superagent models list / update / refresh / status / reset
superagent mcp list / add / remove / sync / auth / reset-auth / test / status / path
superagent skills install / list / show / remove / path
superagent swarm <prompt>                   # plan + exécution swarm
superagent health [--all] [--json] [--providers=a,b,c]   # joignabilité providers
superagent eval [list|show|run]             # évals capacité → ~/.superagent/model_scores.json
superagent smart "<task>"                   # plan + routage par score + fusion
superagent smart "<task>" --max-cost 0.5    # interrompt si la dépense dépasse le plafond
superagent smart show [<id|--last>]         # liste / inspecte les runs persistés
superagent smart replay <id|--last>         # rejoue un plan sauvegardé avec d'autres réglages
```

**Options :**

```
  -m, --model <model>                  Nom du modèle
  -p, --provider <provider>            Clé de provider (openai, anthropic, openai-responses, ...)
      --max-turns <n>                  Tours max de l'agent (défaut 50)
  -s, --system-prompt <prompt>         System prompt personnalisé
      --project <path>                 Répertoire de travail projet
      --json                           Résultats en JSON
      --output json-stream             Émet des événements wire NDJSON
      --verbose-thinking               Affiche le flux thinking complet
      --no-thinking                    Masque thinking
      --plain                          Désactive ANSI
      --no-rich                        Renderer minimal legacy
  -V, --version                        Affiche la version
  -h, --help                           Affiche l'aide
```

**Commandes interactives** (dans le REPL) :

```
  /help                    commandes disponibles
  /model <name>            changer de modèle
  /cost                    coût du run
  /compact                 compaction manuelle du contexte
  /session save|load|list|delete
  /smart <task>            plan+routage+fusion par score (cf. `superagent smart`)
  /workflows               lister/exécuter des workflows dynamiques (get|plan|run|delete|create ; --run|--plan)
  /ultraplan <task>        planifier en profondeur une tâche en workflow dynamique exécutable
  /ultrareview [target]    revue multi-dimensions du diff en workflow dynamique
  /deep-research <q>       recherche web en éventail → vérification → rapport sourcé en workflow dynamique
  /clear                   vider la conversation
  /quit                    quitter
```

*CLI autonome depuis v0.8.6.*

---

## Intégration Laravel

Le service provider s'enregistre automatiquement quand vous `composer require forgeomni/superagent` :

```php
// config/superagent.php
return [
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),
    'providers' => [
        'anthropic'         => ['api_key' => env('ANTHROPIC_API_KEY')],
        'openai'            => ['api_key' => env('OPENAI_API_KEY')],
        'openai-responses'  => ['api_key' => env('OPENAI_API_KEY'), 'model' => 'gpt-5'],
        // ...
    ],
    'agent' => [
        'max_turns'      => 50,
        'max_budget_usd' => 5.00,
    ],
];
```

```php
use SuperAgent\Facades\SuperAgent;

$result = SuperAgent::agent(['provider' => 'openai'])
    ->run('résume les commits de cette semaine');
```

Les commandes Artisan reflètent la CLI :

```bash
php artisan superagent:chat "corrige le bug"
php artisan superagent:mcp sync
php artisan superagent:models refresh
php artisan superagent:health --json
```

Voir `docs/LARAVEL.md` pour l'intégration queue, le dispatch de jobs et le schéma `ai_usage_logs`.

---

## Intégrations hôtes

Les frameworks qui embarquent SuperAgent — typiquement des plateformes multi-tenant qui stockent des credentials provider chiffrés en base et instancient un agent par requête — utilisent `ProviderRegistry::createForHost()` au lieu de `create()`. L'hôte passe une forme normalisée, le SDK dispatche vers le bon constructeur via des adapters par provider.

```php
use SuperAgent\Providers\ProviderRegistry;

// Un appel, tous les providers — pas de `match ($type)` côté hôte.
$agent = ProviderRegistry::createForHost($sdkKey, [
    'api_key'     => $aiProvider->decrypted_api_key,
    'base_url'    => $aiProvider->base_url,
    'model'       => $resolvedModel,
    'max_tokens'  => $extra['max_tokens']  ?? null,
    'region'      => $extra['region']      ?? null,
    'credentials' => $extra,                // blob opaque ; l'adapter prend ce dont il a besoin
    'extra'       => $extra,                // passthrough spécifique au provider (organization, reasoning, verbosity, ...)
]);
```

Chaque provider de style ChatCompletions (Anthropic, OpenAI, OpenAI-Responses, OpenRouter, Ollama, LM Studio, Gemini, Kimi, Qwen, Qwen-native, GLM, MiniMax) utilise l'adapter pass-through par défaut. Bedrock embarque un adapter intégré qui découpe `credentials.aws_access_key_id` / `aws_secret_access_key` / `aws_region` dans la forme attendue par le SDK AWS.

Les plugins ou hôtes qui doivent personnaliser un adapter en enregistrent un :

```php
ProviderRegistry::registerHostConfigAdapter('my-custom-provider', function (array $host): array {
    return [
        'api_key' => $host['credentials']['my_custom_token'] ?? null,
        'model'   => $host['model'] ?? 'default-model',
        // ... transformation arbitraire
    ];
});
```

Les nouvelles clés de provider des futures releases SDK enregistrent leur propre adapter (ou utilisent celui par défaut), donc le code factory côté hôte n'a plus à grossir d'un bras `match` par release.

*Depuis v0.9.2*

---

## Référence de configuration

Toutes les options acceptées par le constructeur `Agent`, groupées. Valeurs par défaut entre parenthèses.

**Sélection de provider**

| Clé | Accepte |
|---|---|
| `provider` | Clé de registre ou instance `LLMProvider` |
| `model` | ID de modèle — surcharge le défaut du provider |
| `base_url` | URL — surcharge le défaut ; déclenche aussi l'auto-détection (Azure) |
| `region` | `intl` / `cn` / `us` / `hk` / `code` (spécifique provider) |
| `api_key` | Clé API du provider |
| `access_token` + `account_id` | OAuth (OpenAI ChatGPT / Anthropic Claude Code) |
| `auth_mode` | `'api_key'` (défaut) ou `'oauth'` |
| `organization` | ID d'org OpenAI (ajoute l'en-tête `OpenAI-Organization`) |

**Boucle d'agent**

| Clé | Défaut |
|---|---|
| `max_turns` | `50` |
| `max_budget_usd` | `0.0` (pas de cap) |
| `system_prompt` | `null` |
| `auto_mode` | `false` |
| `allowed_tools` / `denied_tools` | `null` / `[]` |
| `permission_mode` | `'default'` |
| `options` | `[]` (défauts forward au provider) |

**Options par appel** (`$agent->run($prompt, $options)`)

| Clé | Depuis | Notes |
|---|---|---|
| `model` / `max_tokens` / `temperature` / `tool_choice` / `response_format` | v0.1.0 | Boutons Chat Completions standards |
| `features` | v0.8.8 | `thinking` / `prompt_cache_key` / `dashscope_cache_control` / ... routés via `FeatureDispatcher` |
| `extra_body` | v0.9.0 | Escape hatch power-user — deep-merge dans le body |
| `loop_detection` | v0.9.0 | `true` (défauts), `false`, ou surcharges de seuils |
| `idempotency_key` | v0.9.1 | Passthrough vers `AgentResult::$idempotencyKey` |
| `reasoning` | v0.9.1 | Responses API — `{effort, summary}` |
| `verbosity` | v0.9.1 | Responses API — `low` / `medium` / `high` |
| `prompt_cache_key` | v0.9.0 | Clé de cache pour Kimi + OpenAI Responses |
| `previous_response_id` | v0.9.1 | Continuation Responses API |
| `store` / `include` / `service_tier` / `parallel_tool_calls` | v0.9.1 | Responses API |
| `client_metadata` | v0.9.1 | Map opaque key-value Responses API |
| `trace_context` / `traceparent` / `tracestate` | v0.9.1 | Injection W3C Trace Context |
| `output_subdir` | v0.9.1 | Bloc de garde `AgentTool` + audit post-sortie |

**Retry + transport** (niveau provider)

| Clé | Défaut | Depuis |
|---|---|---|
| `max_retries` | `3` | v0.1.0 (bouton unique legacy) |
| `request_max_retries` | `3` (hérite de `max_retries`) | v0.9.1 |
| `stream_max_retries` | `5` | v0.9.1 |
| `stream_idle_timeout_ms` | `300_000` | v0.9.1 |
| `env_http_headers` | `[]` | v0.9.1 |
| `http_headers` | `[]` | v0.9.1 |
| `experimental_ws_transport` | `false` | v0.9.1 (scaffold) |
| `azure_api_version` | `'2025-04-01-preview'` | v0.9.1 (Azure seulement) |

---

## Liens

- [CHANGELOG](CHANGELOG.md) — notes de version complètes
- [INSTALL_FR](INSTALL_FR.md) — installation + première exécution
- [Utilisation avancée](docs/ADVANCED_USAGE_FR.md) — patterns, exemples, debug
- [Providers natifs](docs/NATIVE_PROVIDERS.md) — maps de région + matrice de capacités
- [Wire protocol](docs/WIRE_PROTOCOL.md) — spec v1
- [Matrice de fonctionnalités](docs/FEATURES_MATRIX.md) — quel provider supporte quoi

## Licence

MIT — voir [LICENSE](LICENSE).
