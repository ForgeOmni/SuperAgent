# 03 — CostAutopilot (budget-driven model tiering)

Goal: hand the autopilot a $10 budget and watch it cascade from Opus → Sonnet
→ Haiku as spend accumulates, without your code branching on it.

## When to use

Long-running agents where you have a hard budget ceiling but want best-quality
output until you actually start burning through. The autopilot enforces the
ceiling without requiring per-call model selection logic in the caller.

## Code

```php
use SuperAgent\Agent;
use SuperAgent\CostAutopilot\CostAutopilot;
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\ModelTier;
use SuperAgent\CostAutopilot\ThresholdRule;

$autopilot = new CostAutopilot(
    new BudgetConfig(maxUsd: 10.00),
    [
        // Default tier
        new ModelTier(name: 'premium',  model: 'claude-opus-4-7',    inputPer1k: 0.015, outputPer1k: 0.075),
        new ModelTier(name: 'mid',      model: 'claude-sonnet-4-6',  inputPer1k: 0.003, outputPer1k: 0.015),
        new ModelTier(name: 'budget',   model: 'claude-haiku-4-5',   inputPer1k: 0.001, outputPer1k: 0.005),
    ],
    [
        // At 30% of budget, downshift to mid tier
        new ThresholdRule(spentPercent: 0.30, tier: 'mid', label: '30% threshold → Sonnet'),
        // At 70% of budget, downshift to budget tier
        new ThresholdRule(spentPercent: 0.70, tier: 'budget', label: '70% threshold → Haiku'),
    ],
);

$agent = new Agent(...);

for ($i = 1; $i <= 20; $i++) {
    $decision = $autopilot->decide();
    if ($decision->shouldStop) {
        echo "Autopilot: budget exhausted at turn {$i}, stopping.\n";
        break;
    }

    $agent->setModel($decision->tier->model);
    $result = $agent->run("Round {$i}: <task>");

    $autopilot->recordSpend($result->totalCostUsd);
    printf(
        "Turn %2d  tier=%s  spent=$%.4f / $%.2f  (%.0f%%)\n",
        $i,
        $decision->tier->name,
        $autopilot->spent(),
        $autopilot->budget(),
        ($autopilot->spent() / $autopilot->budget()) * 100,
    );
}
```

Expected output:

```
Turn  1  tier=premium  spent=$0.0450 / $10.00  (0%)
Turn  2  tier=premium  spent=$0.0900 / $10.00  (1%)
...
Turn 67  tier=premium  spent=$3.0150 / $10.00  (30%)
Turn 68  tier=mid      spent=$3.0250 / $10.00  (30%)  [downshift fired]
...
Turn 95  tier=mid      spent=$7.0010 / $10.00  (70%)
Turn 96  tier=budget   spent=$7.0050 / $10.00  (70%)  [downshift fired]
```

## Inspect the decisions

Every `decide()` / `recordSpend()` cycle emits trace events (since Wave 1):

```
budget.threshold       — when a ThresholdRule fired
budget.tier_change     — when active tier flipped
budget.autopilot_decision — every decide() call
```

Open `~/superagent-traces/trace_superagent_*.json` in Perfetto and the budget
lane shows tier flips as a step chart.

## Variations

Stop instead of downshift:
```php
new ThresholdRule(spentPercent: 0.95, tier: 'budget', shouldStop: true)
```

Multi-objective (cost AND wall time):
```php
new BudgetConfig(maxUsd: 10.00, maxDurationSec: 600)
```

## See also

- 04 — CostPredictor (predict spend BEFORE running)
- SuperAICore cookbook 05 — Tracing quickstart
