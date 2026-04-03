<?php

namespace SuperAgent\Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\Context\RateTracker;

class RateTrackerTest extends TestCase
{
    public function test_empty_tracker_returns_zero(): void
    {
        $tracker = new RateTracker();
        $this->assertSame(0, $tracker->countInWindow('Bash', 60));
        $this->assertFalse($tracker->exceedsRate('Bash', 60, 5));
    }

    public function test_records_and_counts(): void
    {
        $tracker = new RateTracker();
        $tracker->record('Bash');
        $tracker->record('Bash');
        $tracker->record('Bash');

        $this->assertSame(3, $tracker->countInWindow('Bash', 60));
        $this->assertSame(0, $tracker->countInWindow('Read', 60));
    }

    public function test_exceeds_rate(): void
    {
        $tracker = new RateTracker();
        $tracker->record('Bash');
        $tracker->record('Bash');

        $this->assertFalse($tracker->exceedsRate('Bash', 60, 3));
        $tracker->record('Bash');
        $this->assertTrue($tracker->exceedsRate('Bash', 60, 3));
    }

    public function test_reset(): void
    {
        $tracker = new RateTracker();
        $tracker->record('Bash');
        $tracker->record('Bash');
        $tracker->reset();

        $this->assertSame(0, $tracker->countInWindow('Bash', 60));
    }

    public function test_reset_key(): void
    {
        $tracker = new RateTracker();
        $tracker->record('Bash');
        $tracker->record('Read');
        $tracker->resetKey('Bash');

        $this->assertSame(0, $tracker->countInWindow('Bash', 60));
        $this->assertSame(1, $tracker->countInWindow('Read', 60));
    }

    public function test_different_keys_are_independent(): void
    {
        $tracker = new RateTracker();
        $tracker->record('Bash');
        $tracker->record('Bash');
        $tracker->record('Read');

        $this->assertSame(2, $tracker->countInWindow('Bash', 60));
        $this->assertSame(1, $tracker->countInWindow('Read', 60));
    }
}
