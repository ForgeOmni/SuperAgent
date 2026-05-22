# 04 — CostPredictor (predict spend before running)

Goal: feed historical agent runs into the predictor, ask it "how much will
this task cost?", and verify accuracy against actual.

## When to use

You're about to run an expensive agent loop and want a price tag before
authorizing. Different from CostAutopilot (reactive) — predictor is proactive.

## Code

```php
use SuperAgent\CostPrediction\CostPredictor;
use SuperAgent\CostPrediction\CostHistoryStore;
use SuperAgent\CostPrediction\CostEstimate;

$store = new CostHistoryStore(storagePath: __DIR__ . '/storage/cost-history');
$predictor = new CostPredictor($store);

// Predict: what will this task cost?
$estimate = $predictor->predict([
    'task_type'      => 'code_review',
    'estimated_loc'  => 1200,
    'estimated_files'=> 8,
    'model_tier'     => 'sonnet',
]);

printf("Predicted: \$%.4f (±\$%.4f)  confidence=%s  sample_size=%d\n",
    $estimate->expectedUsd,
    $estimate->stdDevUsd,
    $estimate->confidence,
    $estimate->sampleSize,
);

// Run the actual task
$agent = new Agent(...);
$result = $agent->run('Review this PR…');

// Record the actual for next time
$store->record([
    'task_type'      => 'code_review',
    'estimated_loc'  => 1200,
    'estimated_files'=> 8,
    'model_tier'     => 'sonnet',
    'actual_cost_usd'=> $result->totalCostUsd,
    'actual_turns'   => $result->turnCount,
    'recorded_at'    => date('c'),
]);

// Validate
$accuracy = $predictor->validate($estimate, $result->totalCostUsd);
printf("Predicted \$%.4f, actual \$%.4f → error %.1f%%\n",
    $estimate->expectedUsd,
    $result->totalCostUsd,
    $accuracy->errorPercent,
);
```

## How accuracy improves

The predictor uses simple K-nearest-neighbors over the features in
`predict()` against rows in CostHistoryStore. Cold-start estimates are wide
(±50% std dev); accuracy tightens as the store accumulates 20+ matching rows.

You can inspect the prediction confidence histogram:

```bash
php artisan superagent:cost-prediction-report --task-type=code_review
# →
# task_type=code_review  n=47  avg_error=12.4%  p50_error=9.1%  p95_error=31.2%
```

## Use in a guard

Refuse to start the task if predicted cost exceeds the operator's budget:

```php
if ($estimate->expectedUsd > $userBudget) {
    abort(402, sprintf(
        'Predicted cost $%.2f exceeds budget $%.2f (sample=%d, confidence=%s)',
        $estimate->expectedUsd, $userBudget, $estimate->sampleSize, $estimate->confidence,
    ));
}
```

## See also

- 03 — CostAutopilot (reactive ceiling vs. proactive prediction)
- 01 — Debate protocol (where CostPredictor + Autopilot pair well)
