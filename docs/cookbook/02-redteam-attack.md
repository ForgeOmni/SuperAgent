# 02 — Red-team (builder / attacker / reviewer)

Goal: pit an attacker against a builder for N rounds, with a senior reviewer
synthesizing the result.

## When to use

You need an artifact (proposal, plan, code change, threat model) to survive
adversarial pressure before shipping. Different from debate: builder and
attacker are not symmetric — the attacker's job is to break the builder's work.

Typical:
- Security review of a new feature
- Pre-launch readiness for high-risk changes
- "What would a determined attacker do with this?" exercises

## Code

```php
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\Debate\RedTeamConfig;

$orchestrator = new DebateOrchestrator($agentRunner);  // same agentRunner as cookbook 01

$config = new RedTeamConfig(
    builderModel: 'claude-sonnet-4-6',
    attackerModel: 'claude-sonnet-4-6',
    reviewerModel: 'claude-opus-4-7',
    rounds: 3,
    maxBudget: 3.00,
);

$result = $orchestrator->redTeam(
    $config,
    "Threat-model this AWS IAM policy: <policy JSON>. Find every privilege escalation path.",
);

echo "Final assessment:\n{$result->finalVerdict}\n";
echo "Recommendation: {$result->recommendation}\n";
```

Per round the protocol runs:

1. Builder produces (or refines) a solution
2. Attacker reviews it adversarially — finds gaps, edge cases, attack paths
3. Builder takes the attacker's findings and refines

After N rounds the reviewer synthesizes: "the builder addressed X / Y / Z;
issues A and B remain; ship-ready / not ship-ready".

## Inspect the timeline

Same trace pattern as `debate` but with `redteam.` prefix:

```
redteam.start    (instant)
redteam.rounds   (X, duration of all rounds)
redteam.total    (X, total)
```

The instant events for each round are absent on red-team (the DebateProtocol
internal structure handles them) — to see per-round granularity, instrument
`DebateProtocol::runRedTeamRounds` further.

## Cost discipline

`maxBudget` is enforced. When budget runs low, the protocol degrades
gracefully — reviewer always gets at least $0.50 to produce a verdict so the
operator never gets an empty result.

## Variations

Quick paranoia check:
```php
new RedTeamConfig(rounds: 1, attackerModel: 'claude-opus-4-7', maxBudget: 1.00, ...)
```

Deep adversarial exercise:
```php
new RedTeamConfig(rounds: 5, reviewerModel: 'claude-opus-4-7', maxBudget: 8.00, ...)
```

## See also

- 01 — Debate protocol (symmetric debate vs. asymmetric red-team)
- 03 — Ensemble (no adversary, just N independent attempts)
