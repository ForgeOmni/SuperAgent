<?php

namespace SuperAgent\Tests\Unit\CostAutopilot;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostAutopilot\BudgetTracker;

class BudgetTrackerTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'budget_test_');
        // Remove the temp file so tracker starts fresh
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function test_initial_state(): void
    {
        $tracker = new BudgetTracker($this->tempFile);

        $this->assertSame(0.0, $tracker->getDailySpend());
        $this->assertSame(0.0, $tracker->getMonthlySpend());
        $this->assertSame(0.0, $tracker->getTotalSpend());
    }

    public function test_record_spend_delta(): void
    {
        $tracker = new BudgetTracker($this->tempFile);

        // First call: session cost = 1.00
        $tracker->recordSpend(1.00);
        $this->assertEqualsWithDelta(1.00, $tracker->getDailySpend(), 0.001);

        // Second call: session cost = 2.50 (delta = 1.50)
        $tracker->recordSpend(2.50);
        $this->assertEqualsWithDelta(2.50, $tracker->getDailySpend(), 0.001);
        $this->assertEqualsWithDelta(2.50, $tracker->getMonthlySpend(), 0.001);
        $this->assertEqualsWithDelta(2.50, $tracker->getTotalSpend(), 0.001);
    }

    public function test_zero_delta_ignored(): void
    {
        $tracker = new BudgetTracker($this->tempFile);

        $tracker->recordSpend(1.00);
        $tracker->recordSpend(1.00); // Same value — no delta
        $tracker->recordSpend(0.50); // Lower value — negative delta ignored

        $this->assertEqualsWithDelta(1.00, $tracker->getTotalSpend(), 0.001);
    }

    public function test_persistence(): void
    {
        // First tracker instance records spending
        $tracker1 = new BudgetTracker($this->tempFile);
        $tracker1->recordSpend(5.00);
        unset($tracker1);

        // Second instance loads from file
        $tracker2 = new BudgetTracker($this->tempFile);
        $this->assertEqualsWithDelta(5.00, $tracker2->getTotalSpend(), 0.001);
        $this->assertEqualsWithDelta(5.00, $tracker2->getDailySpend(), 0.001);
    }

    public function test_summary(): void
    {
        $tracker = new BudgetTracker($this->tempFile);
        $tracker->recordSpend(3.50);

        $summary = $tracker->getSummary();
        $this->assertArrayHasKey('today', $summary);
        $this->assertArrayHasKey('this_month', $summary);
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('last_updated', $summary);
        $this->assertEqualsWithDelta(3.50, $summary['total'], 0.001);
        $this->assertNotNull($summary['last_updated']);
    }

    public function test_get_spend_for_specific_date(): void
    {
        $tracker = new BudgetTracker($this->tempFile);
        $tracker->recordSpend(2.00);

        $today = date('Y-m-d');
        $this->assertEqualsWithDelta(2.00, $tracker->getSpendForDate($today), 0.001);
        $this->assertSame(0.0, $tracker->getSpendForDate('1999-01-01'));
    }

    public function test_get_spend_for_specific_month(): void
    {
        $tracker = new BudgetTracker($this->tempFile);
        $tracker->recordSpend(10.00);

        $month = date('Y-m');
        $this->assertEqualsWithDelta(10.00, $tracker->getSpendForMonth($month), 0.001);
        $this->assertSame(0.0, $tracker->getSpendForMonth('1999-01'));
    }

    public function test_reset(): void
    {
        $tracker = new BudgetTracker($this->tempFile);
        $tracker->recordSpend(5.00);
        $tracker->reset();

        $this->assertSame(0.0, $tracker->getTotalSpend());
        $this->assertSame(0.0, $tracker->getDailySpend());
    }

    public function test_in_memory_tracker(): void
    {
        // No storage path — in-memory only
        $tracker = new BudgetTracker(null);
        $tracker->recordSpend(3.00);

        $this->assertEqualsWithDelta(3.00, $tracker->getTotalSpend(), 0.001);
    }

    public function test_prune_daily(): void
    {
        $tracker = new BudgetTracker($this->tempFile);
        $tracker->recordSpend(1.00);

        // Pruning with 90 days should keep today's data
        $tracker->pruneDaily(90);
        $this->assertEqualsWithDelta(1.00, $tracker->getDailySpend(), 0.001);
    }
}
