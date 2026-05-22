# 01 — Debate protocol (proposer / critic / judge)

Goal: run a structured 3-round debate between two LLMs adjudicated by a third,
inspect the result, and visualize the timeline.

## When to use

You have a question with no obvious right answer and want a defensible decision
artifact. The debate is the artifact — readers see exactly which arguments and
counter-arguments the judge weighed.

Typical:
- Architecture decisions ("monolith vs. modular monolith vs. microservices?")
- Vendor choice with non-obvious trade-offs
- Investment thesis stress-test
- Code review on a controversial PR

## Code

```php
use SuperAgent\Agent;
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\Debate\DebateConfig;

$agentRunner = function (string $prompt, string $model, ?string $system, int $maxTurns, float $maxBudget): array {
    $agent = new Agent(...); // wire your provider here
    $agent->setModel($model);
    if ($system) $agent->setSystemPrompt($system);
    $result = $agent->run($prompt, ['max_turns' => $maxTurns, 'max_cost_usd' => $maxBudget]);
    return [
        'content' => $result->finalResponse,
        'cost'    => $result->totalCostUsd,
        'turns'   => $result->turnCount,
    ];
};

$orchestrator = new DebateOrchestrator($agentRunner);

$config = new DebateConfig(
    proposerModel: 'claude-sonnet-4-6',
    criticModel:   'claude-sonnet-4-6',
    judgeModel:    'claude-opus-4-7',
    rounds: 3,
    maxBudget: 2.50,
    judgeSystemPrompt: 'You are an impartial judge. Weigh both sides on rigor, evidence, and acknowledgement of trade-offs.',
    judgingCriteria: 'Pick the side with the stronger acknowledged trade-offs. State the recommendation clearly.',
);

$result = $orchestrator->debate(
    $config,
    'Should we adopt event sourcing for the order-management service? Order volume is ~50k/day, team is 3 engineers.',
);

echo "Verdict:\n{$result->finalVerdict}\n";
echo "\nRecommendation: {$result->recommendation}\n";
echo "Cost: \${$result->totalCost} across {$result->totalTurns} turns.\n";
```

## Inspect the timeline

Every debate emits trace events into the SuperAgent trace ring (Wave 1):

```
debate.start            (instant)
debate.rounds           (X, duration)
  debate.round_1        (instant per round)
  debate.round_2        (instant per round)
  debate.round_3        (instant per round)
debate.judge            (X, duration)
debate.total            (X, total duration)
```

Force a dump and open the timeline:

```bash
php -r 'require "vendor/autoload.php"; SuperAgent\Tracing\TraceCollector::getInstance()->dump("manual", "post-debate inspection");'

# Trace file lands in $SUPERAGENT_TRACE_PATH (default: sys_get_temp_dir()/superagent-traces/)
ls -lt $(getconf DARWIN_USER_TEMP_DIR 2>/dev/null || echo /tmp)/superagent-traces | head -5

# Open in chrome://tracing or ui.perfetto.dev
```

## Variations

Quick check (1 round, cheap):
```php
new DebateConfig(rounds: 1, maxBudget: 0.30, ...)
```

Deep dive (5 rounds, separate judge):
```php
new DebateConfig(rounds: 5, judgeModel: 'claude-opus-4-7', maxBudget: 5.00, ...)
```

## See also

- 02 — Red-team (asymmetric attack pattern, vs. symmetric debate)
- 03 — Ensemble (N agents independent + merger, no critique)
- SuperAICore cookbook 05 — Tracing quickstart
