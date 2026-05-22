# 05 — AdaptiveFeedback (corrections promote into reusable patterns)

Goal: feed the AdaptiveFeedbackEngine a handful of user corrections, watch it
detect the repeating pattern, and have future agent runs auto-apply the fix.

## When to use

You've corrected the same agent mistake 3+ times — "stop using emoji as feature
icons", "always pass --no-edit to git rebase", "prefer ripgrep over grep".
You want the agent to internalize the rule, not learn it for the 4th time.

## Code

```php
use SuperAgent\AdaptiveFeedback\AdaptiveFeedbackEngine;
use SuperAgent\AdaptiveFeedback\CorrectionCollector;
use SuperAgent\AdaptiveFeedback\CorrectionStore;

$store = new CorrectionStore(storagePath: __DIR__ . '/storage/corrections');
$engine = new AdaptiveFeedbackEngine(
    store: $store,
    promotionThreshold: 3,   // promote after this many similar corrections
);

// User correction during agent run #1
$engine->record([
    'session_id' => 's-001',
    'category'   => 'visual_design',
    'agent_output' => 'Here are the features:\n✨ Fast onboarding\n🚀 Easy setup',
    'correction'   => 'Do not use emoji as feature icons — anti-AI-slop rule #3.',
    'rule_hint'    => 'no_emoji_feature_icons',
]);

// Correction during run #2 (similar)
$engine->record([
    'session_id' => 's-002',
    'category'   => 'visual_design',
    'agent_output' => '⚡ Lightning-fast\n🔥 Hot features',
    'correction'   => 'No emojis on feature list.',
    'rule_hint'    => 'no_emoji_feature_icons',
]);

// Correction during run #3 — threshold reached
$promotion = $engine->record([
    'session_id' => 's-003',
    'category'   => 'visual_design',
    'agent_output' => '🎯 Target acquisition\n💡 Smart insights',
    'correction'   => 'Remove emojis from feature icons.',
    'rule_hint'    => 'no_emoji_feature_icons',
]);

if ($promotion?->promoted) {
    echo "✅ Promoted: {$promotion->pattern->ruleHint}\n";
    echo "   Confidence: {$promotion->pattern->confidence}\n";
    echo "   Auto-applies to future runs in category: {$promotion->pattern->category}\n";
}
```

## How promotion works

When `n` corrections land in the same `category` with the same `rule_hint`
(or sufficiently similar via embedding cosine when configured), the engine
promotes the rule into the agent's persistent system-prompt block. Future runs
in that category receive it automatically — no caller code change required.

## Inspect

```bash
php artisan superagent:feedback list
# → shows all promoted patterns

php artisan superagent:feedback show no_emoji_feature_icons
# → full pattern with confidence, sample corrections, applied count
```

## Revoke a pattern

If the auto-applied rule turns out wrong:

```bash
php artisan superagent:feedback revoke no_emoji_feature_icons --reason="user wants emoji on lifestyle deck"
```

The pattern is moved to the revoked bucket and won't auto-apply, but stays
visible for audit.

## Integration with traces

When a pattern auto-applies during an agent run, the SuperAgent trace ring
records:

```
adaptive.pattern_applied  (instant, cat=marker)
   args: { rule_hint, category, applied_count }
```

Useful for "why did the agent refuse emoji on this run?" post-mortems.

## See also

- 03 — CostAutopilot (operator-facing budget rules vs. agent-facing behavior rules)
- SuperTeam CONVENTIONS.md §14 — the universal anti-AI-slop rules that
  trigger most early corrections
