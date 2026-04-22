<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SuperAgent\Security\CostLimiter;

class CostLimiterTest extends TestCase
{
    private string $ledger;

    protected function setUp(): void
    {
        $this->ledger = sys_get_temp_dir() . '/superagent_cost_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledger)) @unlink($this->ledger);
        if (is_file($this->ledger . '.tmp')) @unlink($this->ledger . '.tmp');
    }

    public function test_unmetered_tool_always_allowed(): void
    {
        $cl = new CostLimiter(['ledger_path' => $this->ledger, 'per_call_usd' => 0.0]);
        $decision = $cl->check('readonly_tool', ['network'], 99.99);
        $this->assertTrue($decision->isAllow());
    }

    public function test_per_call_cap_denies_over_budget_call(): void
    {
        $cl = new CostLimiter(['ledger_path' => $this->ledger, 'per_call_usd' => 2.00]);
        $d = $cl->check('minimax_video', ['network', 'cost'], 5.00);
        $this->assertTrue($d->isDeny());
        $this->assertStringContainsString('per-call', $d->reason);
    }

    public function test_per_tool_daily_cap_denies_cumulative_overrun(): void
    {
        $cl = new CostLimiter([
            'ledger_path' => $this->ledger,
            'per_tool_daily_usd' => ['minimax_video' => 3.00],
        ]);
        $cl->record('minimax_video', 2.50);

        // Same-tool additional 1.00 → would hit 3.50 → DENIED.
        $d = $cl->check('minimax_video', ['cost'], 1.00);
        $this->assertTrue($d->isDeny());
        $this->assertStringContainsString('daily cap', $d->reason);

        // A cheaper call still fits (2.50 + 0.25 = 2.75 < 3.00).
        $ok = $cl->check('minimax_video', ['cost'], 0.25);
        $this->assertTrue($ok->isAllow());
    }

    public function test_global_daily_cap_denies_cross_tool_overrun(): void
    {
        $cl = new CostLimiter([
            'ledger_path' => $this->ledger,
            'global_daily_usd' => 5.00,
        ]);
        $cl->record('minimax_video', 3.00);
        $cl->record('minimax_music', 1.50);
        // Total 4.50; next call of 1.00 → would hit 5.50 → DENIED.
        $d = $cl->check('minimax_tts', ['cost'], 1.00);
        $this->assertTrue($d->isDeny());
        $this->assertStringContainsString('global daily', $d->reason);
    }

    public function test_ask_threshold_surfaces_ask_verdict(): void
    {
        $cl = new CostLimiter([
            'ledger_path' => $this->ledger,
            'ask_threshold_usd' => 0.50,
        ]);
        $this->assertTrue($cl->check('x', ['cost'], 0.25)->isAllow());
        $ask = $cl->check('x', ['cost'], 1.00);
        $this->assertTrue($ask->isAsk());
    }

    public function test_record_persists_across_instances(): void
    {
        $a = new CostLimiter(['ledger_path' => $this->ledger]);
        $a->record('tool_a', 1.23);

        $b = new CostLimiter(['ledger_path' => $this->ledger]);
        $snap = $b->snapshot();
        $this->assertSame(1.23, $snap['spend']['tool_a']);
    }

    public function test_reset_zeroes_ledger(): void
    {
        $cl = new CostLimiter(['ledger_path' => $this->ledger]);
        $cl->record('t', 1.0);
        $cl->reset();
        $this->assertSame(0.0, (float) ($cl->snapshot()['spend']['t'] ?? 0.0));
    }

    public function test_ledger_auto_rolls_over_on_new_day(): void
    {
        // Seed ledger with yesterday's date — the limiter should ignore stale spend.
        @mkdir(dirname($this->ledger), 0755, true);
        file_put_contents($this->ledger, json_encode([
            'schema' => 1,
            'date'   => gmdate('Y-m-d', strtotime('-2 days')),
            'spend'  => ['minimax_video' => 9.99],
        ]));

        $cl = new CostLimiter([
            'ledger_path' => $this->ledger,
            'per_tool_daily_usd' => ['minimax_video' => 3.00],
        ]);

        // Despite the stale 9.99 in the file, a fresh 2.00 should be allowed today.
        $this->assertTrue($cl->check('minimax_video', ['cost'], 2.00)->isAllow());
    }

    public function test_negative_or_zero_record_is_noop(): void
    {
        $cl = new CostLimiter(['ledger_path' => $this->ledger]);
        $cl->record('t', 0.0);
        $cl->record('t', -1.0);
        $this->assertSame([], $cl->snapshot()['spend']);
    }
}
