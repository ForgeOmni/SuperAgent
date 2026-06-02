# SmartFlow — cross-model / cross-API dynamic flows

SmartFlow is SuperAgent's **multi-ai dynamic-flow engine**: a PHP port of the
Claude Code `Workflow` engine, made **cross-model and cross-API**. The same
primitives — `agent()`, `parallel()`, `pipeline()`, `gate()`, `budget`,
`schema`/`SKIP` — drive any of SuperAgent's 15 providers. *One set of primitives,
many brains.*

Beyond the built-in engine it adds:

- a **3-layer structured-output safety net** (native → submitted → extracted),
- reusable **roles / personas**,
- **gates** with fallback/relay and explicit acceptance,
- a **call-ledger + signature** giving **checkpoint resume** that doesn't burn
  tokens,
- **11 prebuilt static flows**, and
- a **`MULTI_AI_FAKE_PROVIDER=1` zero-cost rehearsal mode**.

---

## Quick start (CLI)

```bash
# list the built-in flows
superagent flow list

# rehearse one end-to-end at ZERO token cost (deterministic fake provider)
superagent flow run dev-from-scratch --args goal="a todo CLI" --rehearse

# run for real (uses your configured providers)
superagent flow run product-trio --args idea="a habit tracker"

# resume: replay the unchanged prefix of a prior run, rerun only what changed
superagent flow run dev-from-scratch --args goal="a todo CLI" --resume <run-id>

# inspect a flow
superagent flow show stock-trio
```

### Run options

| Flag | Meaning |
|------|---------|
| `--args k=v` | set one arg (repeatable) |
| `--json '{...}'` | set args from a JSON object |
| `--rehearse` / `--fake` | use the deterministic zero-cost fake provider |
| `--dry-run` | rehearse without writing a ledger file |
| `--resume <runId>` | replay the unchanged prefix of a prior run |
| `--concurrency <n>` | max parallel workers (process pool) |
| `--budget-usd <x>` | hard USD ceiling |
| `--provider <p>` / `--model <m>` | default provider/model for calls that don't pin one |
| `--out-json` | print the full result as JSON |

---

## The primitives (PHP fluent DSL)

A flow body is `callable(Flow $flow): mixed`. The `$flow` context exposes the
primitives:

```php
use SuperAgent\SmartFlow\FlowEngine;
use SuperAgent\SmartFlow\FlowDefinition;
use SuperAgent\SmartFlow\FlowOptions;
use SuperAgent\SmartFlow\Flow;

$def = FlowDefinition::make('review', 'Review a diff', function (Flow $flow) {
    $flow->phase('Review');

    // One cross-model call. With a schema you get a validated array back,
    // otherwise the raw string; on schema failure you get $flow->SKIP.
    $summary = $flow->agent("Summarize this diff:\n" . $flow->args['diff'], [
        'role'   => 'reviewer',
        'schema' => [
            'type' => 'object',
            'required' => ['risk', 'notes'],
            'properties' => [
                'risk'  => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
    ]);

    // Fan out concurrently (true process-pool parallelism). Each item is a
    // *deferred* call built with $flow->call().
    $reviews = $flow->parallel([
        $flow->call('Review for correctness:'  . $flow->args['diff'], ['role' => 'reviewer']),
        $flow->call('Review for security:'     . $flow->args['diff'], ['role' => 'reviewer', 'provider' => 'openai']),
    ]);

    // Per-item, per-stage pipeline.
    $drafts = $flow->pipeline(
        ['intro', 'body', 'outro'],
        fn ($prev, $item) => $flow->call("Write the {$item} section", ['role' => 'writer'])
    );

    // Perspective-diverse verification (majority vote).
    $vote = $flow->council('The diff is safe to merge.', ['correctness', 'security', 'style']);

    // Acceptance gate with a fallback/relay branch.
    $gate = $flow->gate('nonempty', fn () => $summary !== $flow->SKIP, [
        'fallback' => fn () => 'manual review required',
        'required' => false,
    ]);

    return compact('summary', 'reviews', 'drafts', 'vote', 'gate');
});

$result = (new FlowEngine())->run($def, ['diff' => $diff], new FlowOptions(rehearse: true));
echo $result->costUsd();         // 0.0 in rehearsal
print_r($result->ledger);        // calls, cached, skips, cost, tokens, layers
```

| Primitive | Purpose |
|-----------|---------|
| `agent($prompt, $opts)` | one cross-model call (+ structured output) |
| `call($prompt, $opts)` | a **deferred** call for `parallel()`/`pipeline()` |
| `parallel([$a, $b, …])` | barrier; deferred calls run concurrently via the process pool |
| `pipeline($items, …$stages)` | each item through each stage; calls in a stage run concurrently |
| `gate($name, $check, $opts)` | acceptance checkpoint with `fallback`/`relay`/`required` |
| `council($claim, $lenses)` | judge a claim through several lenses, majority vote |
| `log()`, `phase()` | narration |
| `$flow->budget`, `$flow->args`, `$flow->SKIP`, `$flow->keep($arr)` | state + helpers |

`agent()` opts: `role`, `provider`, `model`, `system`, `schema`, `temperature`,
`max_tokens`, `label`, `phase`.

---

## The 3-layer structured-output safety net

When you pass a `schema`, SmartFlow recovers a valid value in three escalating
ways and records which rung won (visible in the ledger `layers`):

1. **native** — the provider was asked for JSON (`response_format` / `json_schema`)
   and the whole reply parsed cleanly.
2. **submitted** — the model fenced its JSON in a ```` ```json ```` block.
3. **extracted** — last-ditch: the first `{...}`/`[...]` is sniffed out of prose.

If none yields a schema-valid value, `agent()` returns the **`SKIP`** sentinel
(`$flow->SKIP`) instead of crashing. Filter it (and `null` from failed parallel
thunks) with `$flow->keep($values)`.

---

## Roles / personas

A persona bundles a system prompt with optional default provider/model. Built-ins:
`planner`, `builder`, `reviewer`, `researcher`, `writer`, `critic`, `chair`.
More ship in `resources/flows/personas/personas.yaml` (`pm`, `designer`,
`engineer`, `editor`, `translator`, `fundamental-analyst`, …). Override or add
your own in `config('superagent.smartflow.personas')`:

```php
'personas' => [
    'analyst' => ['system' => '…', 'provider' => 'deepseek', 'model' => 'deepseek-v4-pro'],
],
```

Pinning a provider/model per persona is how you make a flow **truly cross-model**
(e.g. fundamentals on one model, technicals on another).

---

## Budget, gates, resume

- **Budget** — set `--budget-usd` (or `config superagent.smartflow.budget.*`, or a
  flow's `defaults`). Once spent, the next call throws and the flow fails cleanly.
- **Gates** — `gate()` records acceptance to the ledger; a `required` gate that
  fails with no fallback fails the flow ("做完了到验收了").
- **Resume** — every run writes a JSONL **call-ledger** under
  `~/.superagent/flows/`. Re-running with `--resume <runId>` replays the longest
  **unchanged prefix** from cache (zero tokens) and reruns from the first call
  whose signature changed. Signatures are content-addressed from the *declared*
  call (label + prompt + schema + role + pinned provider/model), so cosmetic
  whitespace edits don't bust the cache but real changes do.

---

## Rehearsal (zero-cost)

`--rehearse` (or `MULTI_AI_FAKE_PROVIDER=1`) routes every call to a deterministic
`fake` provider that returns schema-conforming stubs and reports **zero tokens /
zero cost**. Every shipped flow is guaranteed to rehearse green — exercise the
whole orchestration, wiring, and structured-output paths without spending a cent.

---

## Authoring static flows in YAML

Static flows live in `resources/flows/*.yaml` (and your `./flows` or
`./.superagent/flows`). They compile to the same engine.

```yaml
name: my-flow
description: …
phases: [{title: Plan}, {title: Build}]
schemas:
  plan: {type: object, required: [steps], properties: {steps: {type: array, items: {type: string}}}}
steps:
  - name: plan
    role: planner
    phase: Plan
    prompt: "Plan: {{args.goal}}"
    schema: plan
  - name: reviews
    strategy: parallel        # run `agents` concurrently
    agents:
      - {role: reviewer, prompt: "Review A:\n{{steps.plan.output}}"}
      - {role: reviewer, prompt: "Review B"}
  - name: drafts
    strategy: pipeline        # each `over` item through each `stage`
    over: "{{args.topics}}"
    stages:
      - {role: writer, prompt: "Write about {{item}}"}
  - name: accept
    strategy: gate
    check: "nonempty:{{steps.plan.output}}"
    required: true
return: plan                  # which step's output to return (default: all)
```

Templating: `{{args.x}}`, `{{steps.name.output}}`, dotted paths into structured
outputs (`{{steps.plan.output.title}}`), and `{{item}}` inside pipeline stages.
Step strategies: `solo` (default), `parallel`, `pipeline`, `gate`.

---

## The 11 built-in flows

| Flow | What it does |
|------|--------------|
| `dev-from-scratch` | goal → plan → build → parallel reviews → accepted result |
| `product-trio` | PM + designer + engineer → one aligned product brief |
| `research-trio` | multi-angle research → synthesized brief → adversarial verify |
| `code-review-council` | multi-lens diff review → consolidated verdict |
| `doc-writer` | outline → draft → edited technical docs |
| `translate-localize` | translate → localize → QA |
| `mp-article` | WeChat 公众号 article: angle → outline → draft → polish → titles |
| `video-creator` | short-video script → storyboard → caption |
| `stock-trio` | fundamental + technical + sentiment → chair's balanced view |
| `stock-monthly-style` | monthly market review in a chosen voice |
| `stock-veggie` | plain-language beginner stock explainer |

> The domain flows (`stock-*`, `mp-article`, `video-creator`) ship with
> **best-effort prompts** flagged in-file — tune the personas/prompts to your
> voice. The stock flows are **educational only, not investment advice**.

---

## Config

```php
// config/superagent.php → 'smartflow'
'smartflow' => [
    'enabled'     => true,
    'concurrency' => 4,            // SUPERAGENT_FLOW_CONCURRENCY
    'ledger_dir'  => null,         // SUPERAGENT_FLOW_DIR (default ~/.superagent/flows)
    'flows_dir'   => null,         // SUPERAGENT_FLOWS_DIR — extra flow dirs
    'budget'      => ['usd' => null, 'tokens' => null],
    'personas'    => [],
],
```

Environment: `MULTI_AI_FAKE_PROVIDER=1` forces rehearsal everywhere;
`SUPERAGENT_FLOW_*` mirror the config keys above.
