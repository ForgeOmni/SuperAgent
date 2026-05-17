<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Modes;

use PHPUnit\Framework\TestCase;
use SuperAgent\Modes\CrossModePolicy;
use SuperAgent\Modes\ModeBudgetExceededException;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeCycleException;
use SuperAgent\Modes\ModeDepthExceededException;

/**
 * Pin the cross-mode context contract: descend produces a child with
 * shared collaborators + incremented depth + appended mode_stack;
 * policy violations (depth / cycle / budget) throw at descend time,
 * not later; ledger and blackboard are shared by reference across
 * parent and children.
 */
final class ModeContextTest extends TestCase
{
    public function test_root_starts_at_depth_zero(): void
    {
        $ctx = ModeContext::root('squad');
        $this->assertSame(0, $ctx->depth);
        $this->assertSame(['squad'], $ctx->modeStack);
        $this->assertSame('squad', $ctx->currentMode());
    }

    public function test_descend_increments_depth_and_appends_stack(): void
    {
        $ctx = ModeContext::root('squad');
        $child = $ctx->descend('smart');
        $this->assertSame(1, $child->depth);
        $this->assertSame(['squad', 'smart'], $child->modeStack);
        $this->assertSame('smart', $child->currentMode());
    }

    public function test_shared_ledger_accumulates_across_descend(): void
    {
        $ctx = ModeContext::root('squad');
        $child = $ctx->descend('smart');
        $ctx->costLedger->record('squad', 0.05);
        $child->costLedger->record('smart', 0.10);
        $grand = $child->descend('auto');
        $grand->costLedger->record('auto', 0.02);
        $this->assertEqualsWithDelta(0.17, $ctx->costLedger->total(), 0.0001);
        $this->assertEqualsWithDelta(0.17, $grand->costLedger->total(), 0.0001);
    }

    public function test_shared_blackboard_is_visible_at_every_depth(): void
    {
        $ctx = ModeContext::root('squad');
        $child = $ctx->descend('smart');
        $child->blackboard->claim('researcher', 'finding-1', 'X is true');
        $this->assertCount(1, $ctx->blackboard->entries());
        $this->assertSame('claim', $ctx->blackboard->entries()[0]['kind']);
    }

    public function test_descend_blocks_at_max_depth(): void
    {
        $policy = new CrossModePolicy(maxDepth: 2);
        $ctx = ModeContext::root('squad', null, $policy);
        $a = $ctx->descend('smart');     // depth 1
        $b = $a->descend('auto');        // depth 2 — OK (== maxDepth)
        $this->expectException(ModeDepthExceededException::class);
        $b->descend('squad');            // depth 3 — fail
    }

    public function test_cycle_detection_blocks_repeated_same_mode(): void
    {
        $ctx = ModeContext::root('squad');
        $this->expectException(ModeCycleException::class);
        $ctx->descend('squad');  // would produce stack ['squad', 'squad']
    }

    public function test_cycle_detection_can_be_disabled(): void
    {
        $policy = new CrossModePolicy(detectCycles: false);
        $ctx = ModeContext::root('squad', null, $policy);
        $child = $ctx->descend('squad'); // would normally throw
        $this->assertSame(['squad', 'squad'], $child->modeStack);
    }

    public function test_budget_cap_blocks_descend(): void
    {
        $policy = new CrossModePolicy(budgetCapUsd: 0.05);
        $ctx = ModeContext::root('squad', null, $policy);
        $ctx->costLedger->record('squad', 0.10);
        $this->expectException(ModeBudgetExceededException::class);
        $ctx->descend('smart');
    }

    public function test_with_metadata_does_not_advance_depth(): void
    {
        $ctx = ModeContext::root('squad');
        $ctx2 = $ctx->withMetadata(['tag' => 'a']);
        $this->assertSame(0, $ctx2->depth);
        $this->assertSame(['tag' => 'a'], $ctx2->metadata);
    }
}
