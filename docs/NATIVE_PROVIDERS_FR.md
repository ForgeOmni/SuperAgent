# Fournisseurs natifs — Kimi / Qwen / GLM / MiniMax

> Depuis la v0.8.8, Moonshot Kimi, Alibaba Qwen, Z.AI GLM et MiniMax sont
> des **fournisseurs natifs** de premier ordre — chacun possède sa classe,
> sa configuration de région et ses points d'entrée de fonctionnalités
> natives. Il n'est plus nécessaire de détourner `OpenAIProvider` en
> modifiant sa `base_url`.
>
> **Langues :** [English](NATIVE_PROVIDERS.md) · [中文](NATIVE_PROVIDERS_CN.md) · [Français](NATIVE_PROVIDERS_FR.md)

---

## 1. Démarrage rapide

### 1.1 Configurer les identifiants

```bash
# Les quatre utilisent par défaut le point d'accès international (région intl)
export KIMI_API_KEY=sk-moonshot-xxx
export QWEN_API_KEY=sk-dashscope-xxx
export GLM_API_KEY=your-zai-key
export MINIMAX_API_KEY=your-minimax-key
export MINIMAX_GROUP_ID=your-group-id   # optionnel
```

Alias de variables d'environnement (reconnus automatiquement) :

| Variable principale | Alias |
|---|---|
| `KIMI_API_KEY` | `MOONSHOT_API_KEY` |
| `QWEN_API_KEY` | `DASHSCOPE_API_KEY` |
| `GLM_API_KEY` | `ZAI_API_KEY` / `ZHIPU_API_KEY` |
| `MINIMAX_API_KEY` | — |

### 1.2 Choisir une région (les clés CN exigent des points d'accès CN)

```bash
export KIMI_REGION=cn          # intl | cn
export QWEN_REGION=cn          # intl | us | cn | hk
export GLM_REGION=cn           # intl | cn
export MINIMAX_REGION=cn       # intl | cn
```

**Important** : les quatre fournisseurs **lient leurs clés API à l'hôte**.
Une clé intl ne peut pas appeler un point d'accès CN et réciproquement.
`CredentialPool` filtre les clés par `region` pour éviter les erreurs de
routage.

### 1.3 Utilisation

```bash
# CLI
superagent chat -p kimi "Écris une fonction Python Fibonacci"

# PHP
use SuperAgent\Providers\ProviderRegistry;

$provider = ProviderRegistry::createFromEnv('kimi');
// ou avec région explicite :
$provider = ProviderRegistry::createWithRegion('qwen', 'us', ['api_key' => '...']);
```

---

## 2. Modèles par défaut + carte des régions

| Fournisseur | Modèle par défaut | Régions prises en charge → point d'accès |
|---|---|---|
| **kimi** | `kimi-k2-6` | `intl` → api.moonshot.ai<br>`cn` → api.moonshot.cn |
| **qwen** | `qwen3.6-max-preview` | `intl` → dashscope-intl.aliyuncs.com (Singapour)<br>`us` → dashscope-us.aliyuncs.com (Virginie)<br>`cn` → dashscope.aliyuncs.com (Pékin)<br>`hk` → cn-hongkong.dashscope.aliyuncs.com |
| **glm** | `glm-4.6` | `intl` → api.z.ai/api/paas/v4<br>`cn` → open.bigmodel.cn/api/paas/v4 |
| **minimax** | `MiniMax-M2.7` | `intl` → api.minimax.io<br>`cn` → api.minimaxi.com |

Liste complète des modèles : `superagent models list` ou `resources/models.json`.

---

## 3. Capacités natives par fournisseur

### 3.1 Kimi K2.6

| Capacité | Utilisation |
|---|---|
| Réflexion (niveau requête) | `$options['features']['thinking']` — définit `reasoning_effort` (low/medium/high, bucketé depuis le `budget` token advisory) + `thinking: {type: "enabled"}` sur le même modèle. Pas de bascule de modèle. |
| Extraction de fichiers (PDF/PPT/Word → texte) | outil `kimi_file_extract` |
| Traitement par lots (JSONL) | outil `kimi_batch` — passer `wait=false` pour dispatch sans attente |
| **Agent Swarm** (300 sous-agents / 4000 étapes) | outil `kimi_swarm` — tout cerveau principal peut l'appeler ; le schéma REST est **provisoire** (Moonshot n'a pas encore publié la spécification) |
| Mise en cache de contexte | Côté serveur, automatique (pas de balisage côté client) |

### 3.2 Qwen3.6-Max-Preview

| Capacité | Utilisation |
|---|---|
| **Thinking** (`enable_thinking` + `thinking_budget`) | `$options['features']['thinking']` ou `$options['enable_thinking'] = true` |
| **Interpréteur de code** (bac à sable côté serveur) | `$options['features']['code_interpreter']` ou `$options['enable_code_interpreter'] = true` |
| **Qwen-Long** (10M tokens via référence de fichier) | l'outil `qwen_long_file` renvoie `fileid://xxx` ; à coller dans un message système pour donner accès au fichier à Qwen-Long. **Seulement pris en charge sur la région `cn`.** |
| Multimodal (VL / Omni) | Utiliser les ids `qwen3-vl-plus` / `qwen3-omni` |
| OCR | Utiliser le modèle `qwen-vl-ocr` |

### 3.3 GLM (Z.AI / BigModel)

Caractéristique distinctive de GLM : **les outils sont exposés comme des
points d'accès REST autonomes**, donc votre LLM principal n'a pas besoin
d'être GLM pour les utiliser.

| Capacité | Utilisation |
|---|---|
| **Thinking** (`thinking: {type: enabled}`) | `$options['thinking'] = true` |
| **Recherche Web** | outil `glm_web_search` |
| **Lecteur Web** (URL → markdown propre) | outil `glm_web_reader` |
| **OCR / Analyse de mise en page** | outil `glm_ocr` |
| **ASR** (parole vers texte, GLM-ASR-2512) | outil `glm_asr` |
| Meilleur modèle agentique open-weight | Utiliser `glm-5` (744 Mrd / 40 Mrd actifs) — #1 sur MCP-Atlas, τ²-Bench, BrowseComp |

### 3.4 MiniMax M2.7

M2.7 est un **modèle agent auto-évolutif** dont la collaboration
multi-agent est intégrée aux poids (sans ingénierie de prompt).

| Capacité | Utilisation |
|---|---|
| **Agent Teams** (multi-agent natif — frontières de rôle + raisonnement adverse + adhérence au protocole) | `$options['features']['agent_teams']` avec `roles` et `objective` |
| **Skills** (compétences de 2000+ tokens, taux d'adhérence 97 %) | `SkillManager` + `SkillInjector` (pont MiniMax) |
| **Recherche dynamique d'outils** (le modèle trouve ses outils) | Il suffit d'attacher tous les outils pertinents |
| TTS (texte court synchrone, ≤10K caractères) | outil `minimax_tts` |
| Génération musicale (music-2.6) | outil `minimax_music` |
| Vidéo (Hailuo-2.3, T2V + I2V) | outil `minimax_video` (asynchrone, `wait=false` pour task_id) |
| Image (image-01) | outil `minimax_image` |

---

## 4. Invocation mixte — tous les fournisseurs ensemble

C'est la conception centrale de v0.8.8. **Peu importe quel est le
cerveau principal : toutes les spécialités sont appelables depuis lui.**

### 4.1 Exemple : Claude comme cerveau + recherche GLM + TTS MiniMax

```php
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Providers\Glm\GlmWebSearchTool;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxTtsTool;

$claude  = ProviderRegistry::createFromEnv('anthropic');
$glmProv = ProviderRegistry::createFromEnv('glm');
$mmProv  = ProviderRegistry::createFromEnv('minimax');

$tools = [
    new GlmWebSearchTool($glmProv),
    new MiniMaxTtsTool($mmProv),
];

$response = $claude->chat($messages, $tools);
```

Exemple complet : `examples/mixed_agent.php`.

### 4.2 Pourquoi cela fonctionne

- Le contrat `Tool` de SuperAgent est agnostique quant au fournisseur — le LLM principal ne voit que `name` / `description` / `inputSchema` / `execute()`
- Chaque outil de fournisseur (`GlmWebSearchTool` etc.) **réutilise** le client Guzzle de son fournisseur (bearer / base URL / région déjà configurés)
- Quand le cerveau principal invoque un outil, l'appel HTTP réel atteint le fournisseur correspondant et le résultat revient
- Les outils MCP (de tout serveur MCP) et les Skills (injection de prompt système) utilisent le même contrat `Tool`

---

## 5. Fonctionnalités (champ `features`)

`$options['features']` est le point d'entrée unifié inter-fournisseurs
que `FeatureDispatcher` traduit au moment de la requête :

```php
$provider->chat($messages, $tools, $system, [
    'features' => [
        'thinking' => ['budget' => 4000, 'required' => false],
        'agent_teams' => [
            'objective' => 'Produire un rapport de marché de 10 pages',
            'roles' => [
                ['name' => 'chercheur', 'description' => 'Collecter les sources'],
                ['name' => 'rédacteur', 'description' => 'Rédiger'],
                ['name' => 'critique',  'description' => 'Remettre en question'],
            ],
        ],
    ],
]);
```

**Stratégie de dégradation :**
- `required: false` (défaut) → les fournisseurs non pris en charge se replient (prompt CoT / injection de structure)
- `required: true` → les fournisseurs non pris en charge lèvent `FeatureNotSupportedException` (étend `ProviderException`)
- `enabled: false` → no-op strict

Voir `docs/FEATURES_MATRIX.md` pour la grille par fournisseur × par fonctionnalité.

### 5.1 Échappatoire `extra_body` (utilisateurs avancés)

Tous les fournisseurs qui héritent de `ChatCompletionsProvider` (OpenAI,
OpenRouter, Kimi, GLM, MiniMax) acceptent un tableau
`$options['extra_body']` qui est **fusionné en profondeur au niveau racine
du body de la requête APRÈS que tous les autres transforms s'exécutent**
(`customizeRequestBody`, `FeatureDispatcher`). C'est l'équivalent PHP de
la convention `extra_body=` du SDK Python d'OpenAI, pour le cas où un
fournisseur expose un nouveau champ de requête avant qu'on ait livré un
adapter de capacité :

```php
$provider->chat($messages, $tools, $system, [
    // Kimi : activer le prompt cache au niveau session sans adapter
    'extra_body' => ['prompt_cache_key' => $sessionId],
]);

// Écraser le choix d'un adapter : FeatureDispatcher a choisi "medium" —
// on veut "high"
$provider->chat($messages, $tools, $system, [
    'features'   => ['thinking' => ['budget' => 4000]],
    'extra_body' => ['reasoning_effort' => 'high'],
]);
```

Sémantique de fusion : les scalaires écrasent ; les sous-objets
associatifs sont fusionnés en profondeur (leaf-wins) ; les listes
indexées sont remplacées en bloc.

---

## 6. MCP (intégration unifiée d'outils inter-fournisseurs)

```bash
# Configuration utilisateur
superagent mcp add filesystem stdio npx --arg -y --arg @modelcontextprotocol/server-filesystem --arg /tmp
superagent mcp add search http https://mcp.example.com/search --header "Authorization: Bearer x"
superagent mcp list
superagent mcp remove filesystem
superagent mcp path   # imprime ~/.superagent/mcp.json
```

**Configurer une fois, fonctionne avec tous les cerveaux principaux**
(les quatre nouveaux fournisseurs + les six préexistants). Les outils
MCP s'enveloppent automatiquement en `Tool` SuperAgent et passent par
le chemin standard `formatTools()`.

---

## 7. Skills

SuperAgent encapsule instructions / styles / règles dans des fichiers
markdown, chargés depuis :
- `~/.superagent/skills/` (niveau utilisateur)
- `<projet>/.superagent/skills/` (niveau projet)

```bash
superagent skills install my-skill.md
superagent skills list
superagent skills show my-skill
superagent skills remove my-skill
```

**Format de fichier Skill** (frontmatter + corps) :
```markdown
---
name: code-review
description: Relecture de PR selon les conventions projet
category: engineering
---
Analyser le diff. Se concentrer sur : ...
```

`SkillInjector` fusionne le corps du skill dans
`$options['system_prompt']` (avec un en-tête idempotent `## Skill: <name>`).
Quand Kimi / MiniMax publieront leurs API natives de Skills,
l'enregistrement d'un pont basculera ces fournisseurs vers le chemin
d'upload natif sans modification côté appelant.

---

## 8. Couche de sécurité

Chaque outil déclare `attributes()`, et `ToolSecurityValidator` décide :

| attribute | Signification | Comportement par défaut |
|---|---|---|
| `network` | Atteint Internet public | refusé quand `SUPERAGENT_OFFLINE=1` |
| `cost` | Facturé par le fournisseur | passe par `CostLimiter` (par appel / par outil / quotidien global) |
| `sensitive` | Téléverse des données utilisateur | défaut `ask` (configurable en `allow` / `deny`) |
| (outils Bash) | — | délégué à `BashSecurityValidator` existant (23 vérifications, inchangé) |

**Exemple de configuration :**
```php
use SuperAgent\Security\ToolSecurityValidator;

$validator = new ToolSecurityValidator([
    'sensitive_default' => 'ask',
    'cost' => [
        'global_daily_usd' => 10.00,
        'per_tool_daily_usd' => ['minimax_video' => 5.00],
        'ask_threshold_usd' => 0.50,
    ],
]);

$decision = $validator->validate($tool, $input, /*estimated_cost=*/0.20);
// $decision->verdict ∈ {'allow', 'ask', 'deny'}
```

Chemin du registre : `~/.superagent/cost_ledger.json` (bascule UTC automatique).

---

## 9. Orchestration Agent Team / Swarm

Trois stratégies, choisies automatiquement par `SwarmRouter` ou forcées manuellement :

```bash
# Plan (pas encore d'exécution)
superagent swarm "Analyse ce dépôt et génère un deck" --max-sub-agents 100
# → native_swarm (Kimi)

superagent swarm "Rédige une analyse de marché" --role chercheur:collecter --role rédacteur:écrire
# → agent_teams (MiniMax M2.7)

superagent swarm "Tâche parallèle simple"
# → local_swarm (existant src/Swarm/)

superagent swarm ... --json   # émet le plan en JSON
```

Le câblage d'exécution arrive dans la prochaine mineure ; aujourd'hui la
CLI ne fait que **planifier**, vous pouvez donc prendre `strategy` +
`provider` du plan et dispatcher manuellement.

---

## 10. FAQ

**Q : J'utilisais `OpenAIProvider` pointé sur la `base_url` de Kimi — dois-je migrer ?**
R : Recommandé. Passer à `KimiProvider` débloque le routage par région, les fonctionnalités spécifiques natives (Swarm etc.), et des tags d'erreur plus clairs. Voir `docs/MIGRATION_NATIVE.md`.

**Q : Je n'utilise aucun des quatre — suis-je impacté ?**
R : Non. Ils sont tous opt-in. Sans variables d'environnement, `discover()` ne les fait pas apparaître et `superagent models list` n'affiche que ce que vous avez configuré.

**Q : Kimi Agent Swarm est-il réellement utilisable aujourd'hui ?**
R : **Le côté SuperAgent est architecturalement complet** (interface `SupportsSwarm` + `KimiSwarmTool` + `SwarmRouter`). Mais la spécification REST Swarm de Moonshot **n'est pas encore publiquement documentée**. Nous implémentons contre la structure la plus plausible et marquons cela `provisional` ; quand la spec officielle arrive, c'est un fix de 30 lignes pour s'aligner.

**Q : Pourquoi Qwen a 4 régions mais les autres seulement 2 ?**
R : Qwen (DashScope) propose réellement quatre géographies : Singapour / Virginie / Pékin / Hong Kong. Les trois autres fournisseurs ne proposent actuellement que international + continental.

**Q : MCP / Skills ont-ils changé ?**
R : Les MCP existants (`MCPManager`, 1200+ lignes) et Skills (`SkillManager` + intégrés) sont **intacts**. Cette version a seulement standardisé `~/.superagent/mcp.json` et `~/.superagent/skills/` comme répertoires de premier ordre, et ajouté des commandes CLI.

---

**Version :** v0.8.8 · **Mise à jour :** 2026-04-21 · **Document de conception :** `design/NATIVE_PROVIDERS_CN.md`
