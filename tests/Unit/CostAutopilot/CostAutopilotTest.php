<?php

namespace SuperAgent\Tests\Unit\CostAutopilot;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostAutopilot\AutopilotDecision;
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\CostAction;
use SuperAgent\CostAutopilot\CostAutopilot;
use SuperAgent\CostAutopilot\ModelTier;

class CostAutopilotTest extends TestCase
{
    private function makeAutopilot(array $config = [], array $tiers = []): CostAutopilot
    {
        $budgetConfig = BudgetConfig::fromArray(array_merge([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 50, 'action' => 'warn', 'message' => 'Half budget used'],
                ['at_pct' => 70, 'action' => 'compact_context', 'message' => 'Compacting context'],
                ['at_pct' => 80, 'action' => 'downgrade_model', 'message' => 'Downgrading model'],
                ['at_pct' => 95, 'action' => 'halt', 'message' => 'Budget exhausted'],
            ],
        ], $config));

        if (!empty($tiers)) {
            $budgetConfig->setTiers($tiers);
        } else {
            $budgetConfig->setTiers(ModelTier::anthropicTiers());
        }

        return new CostAutopilot($budgetConfig);
    }

    // ── No Action (Budget Healthy) ─────────────────────────────────

    public function test_no_action_when_under_threshold(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        $decision = $autopilot->evaluate(2.0); // 20% of $10

        $this->assertFalse($decision->requiresAction());
        $this->assertSame(20.0, $decision->budgetUsedPct);
        $this->assertSame(2.0, $decision->sessionCostUsd);
    }

    public function test_no_action_when_no_budget(): void
    {
        $budgetConfig = BudgetConfig::fromArray([]);
        $autopilot = new CostAutopilot($budgetConfig);

        $decision = $autopilot->evaluate(100.0);

        $this->assertFalse($decision->requiresAction());
    }

    // ── Warn ───────────────────────────────────────────────────────

    public function test_warn_at_50_percent(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        $decision = $autopilot->evaluate(5.0); // 50%

        $this->assertTrue($decision->requiresAction());
        $this->assertTrue($decision->isWarning());
        $this->assertNull($decision->newModel);
        $this->assertStringContainsString('Half budget', $decision->message);
    }

    public function test_warn_does_not_re_trigger(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        $autopilot->evaluate(5.0); // First trigger at 50%
        $decision = $autopilot->evaluate(5.5); // Still above 50%

        // Should not trigger again — already fired
        $this->assertFalse($decision->requiresAction());
    }

    // ── Compact Context ────────────────────────────────────────────

    public function test_compact_at_70_percent(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        // Skip past warn threshold first
        $autopilot->evaluate(5.0);

        $decision = $autopilot->evaluate(7.0); // 70%

        $this->assertTrue($decision->requiresAction());
        $this->assertTrue($decision->shouldCompact());
        $this->assertFalse($decision->hasDowngrade());
    }

    // ── Model Downgrade ────────────────────────────────────────────

    public function test_downgrade_at_80_percent(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        // Trigger earlier thresholds first
        $autopilot->evaluate(5.0); // 50% warn
        $autopilot->evaluate(7.0); // 70% compact

        $decision = $autopilot->evaluate(8.0); // 80% downgrade

        $this->assertTrue($decision->requiresAction());
        $this->assertTrue($decision->hasDowngrade());
        $this->assertSame('claude-sonnet-4-20250514', $decision->newModel);
        $this->assertSame('claude-opus-4-20250514', $decision->previousModel);
        $this->assertSame('sonnet', $decision->tierName);
    }

    public function test_multiple_downgrades(): void
    {
        $autopilot = $this->makeAutopilot([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 50, 'action' => 'downgrade_model'],
                ['at_pct' => 80, 'action' => 'downgrade_model'],
            ],
        ]);
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        // First downgrade: opus → sonnet
        $d1 = $autopilot->evaluate(5.0);
        $this->assertTrue($d1->hasDowngrade());
        $this->assertSame('claude-sonnet-4-20250514', $d1->newModel);

        // Second downgrade: sonnet → haiku
        $d2 = $autopilot->evaluate(8.0);
        $this->assertTrue($d2->hasDowngrade());
        $this->assertSame('claude-haiku-4-5-20251001', $d2->newModel);
    }

    public function test_downgrade_at_cheapest_tier_becomes_warn(): void
    {
        $autopilot = $this->makeAutopilot([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 80, 'action' => 'downgrade_model'],
            ],
        ]);
        $autopilot->setCurrentModel('claude-haiku-4-5-20251001'); // Already cheapest

        $decision = $autopilot->evaluate(8.0);

        // Cannot downgrade further — falls back to warn
        $this->assertTrue($decision->requiresAction());
        $this->assertFalse($decision->hasDowngrade());
        $this->assertStringContainsString('cheapest', $decision->message);
    }

    public function test_downgrade_with_unknown_model(): void
    {
        $autopilot = $this->makeAutopilot([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 80, 'action' => 'downgrade_model'],
            ],
        ]);
        $autopilot->setCurrentModel('unknown-custom-model');

        $decision = $autopilot->evaluate(8.0);

        // Unknown model = not in tier list → picks second tier
        $this->assertTrue($decision->hasDowngrade());
        $this->assertSame('claude-sonnet-4-20250514', $decision->newModel);
    }

    // ── Halt ───────────────────────────────────────────────────────

    public function test_halt_at_95_percent(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        // Fire all previous thresholds
        $autopilot->evaluate(5.0);
        $autopilot->evaluate(7.0);
        $autopilot->evaluate(8.0);

        $decision = $autopilot->evaluate(9.5); // 95%

        $this->assertTrue($decision->shouldHalt());
        $this->assertStringContainsString('exhausted', $decision->message);
    }

    // ── Event Listeners ────────────────────────────────────────────

    public function test_event_listeners(): void
    {
        $events = [];
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        $autopilot->on('autopilot.warn', function ($data) use (&$events) {
            $events[] = 'warn:' . round($data['budget_used_pct']);
        });
        $autopilot->on('autopilot.downgrade', function ($data) use (&$events) {
            $events[] = "downgrade:{$data['from']}→{$data['to']}";
        });
        $autopilot->on('autopilot.halt', function ($data) use (&$events) {
            $events[] = 'halt';
        });

        $autopilot->evaluate(5.0);  // warn
        $autopilot->evaluate(7.0);  // compact (no warn event)
        $autopilot->evaluate(8.0);  // downgrade
        $autopilot->evaluate(9.5);  // halt

        $this->assertContains('warn:50', $events);
        $this->assertContains('downgrade:claude-opus-4-20250514→claude-sonnet-4-20250514', $events);
        $this->assertContains('halt', $events);
    }

    // ── Reset ──────────────────────────────────────────────────────

    public function test_reset_clears_fired_thresholds(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        $autopilot->evaluate(5.0); // Fire 50% warn
        $autopilot->reset();

        $decision = $autopilot->evaluate(5.0); // Should fire again
        $this->assertTrue($decision->requiresAction());
    }

    // ── Statistics ─────────────────────────────────────────────────

    public function test_statistics(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        $stats = $autopilot->getStatistics();
        $this->assertSame('claude-opus-4-20250514', $stats['current_model']);
        $this->assertSame('opus', $stats['current_tier']);
        $this->assertSame(2, $stats['tiers_remaining']);
        $this->assertSame(0, $stats['thresholds_fired']);

        // After firing warn + downgrade
        $autopilot->evaluate(5.0);
        $autopilot->evaluate(7.0);
        $autopilot->evaluate(8.0);

        $stats = $autopilot->getStatistics();
        $this->assertSame('sonnet', $stats['current_tier']);
        $this->assertSame(1, $stats['tiers_remaining']);
        $this->assertSame(3, $stats['thresholds_fired']);
    }

    // ── AutopilotDecision ──────────────────────────────────────────

    public function test_decision_noop(): void
    {
        $decision = AutopilotDecision::noop(25.0, 2.50);

        $this->assertFalse($decision->requiresAction());
        $this->assertFalse($decision->hasDowngrade());
        $this->assertFalse($decision->shouldHalt());
        $this->assertFalse($decision->shouldCompact());
        $this->assertSame(25.0, $decision->budgetUsedPct);
        $this->assertSame(2.50, $decision->sessionCostUsd);
    }

    public function test_decision_with_downgrade(): void
    {
        $decision = new AutopilotDecision(
            actions: [CostAction::DOWNGRADE_MODEL],
            newModel: 'claude-sonnet-4-20250514',
            previousModel: 'claude-opus-4-20250514',
            tierName: 'sonnet',
            budgetUsedPct: 80.0,
            sessionCostUsd: 8.0,
            message: 'Downgrading',
        );

        $this->assertTrue($decision->requiresAction());
        $this->assertTrue($decision->hasDowngrade());
        $this->assertFalse($decision->shouldHalt());
    }

    // ── Effective Budget with Monthly Tracker ──────────────────────

    public function test_effective_budget_with_both_limits(): void
    {
        $autopilot = $this->makeAutopilot([
            'session_budget_usd' => 5.0,
            'monthly_budget_usd' => 100.0,
        ]);

        // Without tracker, session budget is effective
        $this->assertSame(5.0, $autopilot->getEffectiveBudget());
    }

    // ── Custom Tiers ───────────────────────────────────────────────

    public function test_custom_tier_downgrade_path(): void
    {
        $customTiers = [
            new ModelTier('premium', 'custom-large', 10.0, 50.0, 30),
            new ModelTier('standard', 'custom-medium', 2.0, 10.0, 20),
            new ModelTier('budget', 'custom-small', 0.5, 2.0, 10),
        ];

        $autopilot = $this->makeAutopilot([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 50, 'action' => 'downgrade_model'],
                ['at_pct' => 80, 'action' => 'downgrade_model'],
            ],
        ], $customTiers);
        $autopilot->setCurrentModel('custom-large');

        $d1 = $autopilot->evaluate(5.0);
        $this->assertSame('custom-medium', $d1->newModel);
        $this->assertSame('standard', $d1->tierName);

        $d2 = $autopilot->evaluate(8.0);
        $this->assertSame('custom-small', $d2->newModel);
        $this->assertSame('budget', $d2->tierName);
    }

    // ── Threshold Ordering ─────────────────────────────────────────

    public function test_only_highest_threshold_fires_per_evaluation(): void
    {
        $autopilot = $this->makeAutopilot([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 30, 'action' => 'warn'],
                ['at_pct' => 50, 'action' => 'compact_context'],
                ['at_pct' => 90, 'action' => 'halt'],
            ],
        ]);
        $autopilot->setCurrentModel('claude-opus-4-20250514');

        // Jump straight to 95% — only the highest (halt at 90%) fires
        $decision = $autopilot->evaluate(9.5);

        $this->assertTrue($decision->shouldHalt());
        // The lower thresholds should fire on next evaluations at same level
    }

    // ── Get/Set Model ──────────────────────────────────────────────

    public function test_get_current_model(): void
    {
        $autopilot = $this->makeAutopilot();
        $autopilot->setCurrentModel('claude-sonnet-4-20250514');

        $this->assertSame('claude-sonnet-4-20250514', $autopilot->getCurrentModel());
    }
}
