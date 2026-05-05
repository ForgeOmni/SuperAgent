<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Transport;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\Transport\TokenBucket;

class TokenBucketTest extends TestCase
{
    public function test_initial_burst_capacity_available(): void
    {
        $clock = 1000.0;
        $bucket = new TokenBucket(
            ratePerSecond: 8.0,
            burst:         16,
            now:           function () use (&$clock) { return $clock; },
            sleep:         function (float $seconds) use (&$clock) { $clock += $seconds; },
        );

        for ($i = 0; $i < 16; $i++) {
            $this->assertSame(0.0, $bucket->consume());
        }
    }

    public function test_seventeenth_call_blocks_for_refill(): void
    {
        $clock = 1000.0;
        $bucket = new TokenBucket(
            ratePerSecond: 8.0,
            burst:         16,
            now:           function () use (&$clock) { return $clock; },
            sleep:         function (float $seconds) use (&$clock) { $clock += $seconds; },
        );

        // Drain.
        for ($i = 0; $i < 16; $i++) $bucket->consume();
        // 17th call must wait — no tokens, refill rate 8/s = 0.125s
        // per token.
        $waited = $bucket->consume();
        $this->assertEqualsWithDelta(0.125, $waited, 0.001);
    }

    public function test_burst_refills_after_idle(): void
    {
        $clock = 1000.0;
        $bucket = new TokenBucket(
            ratePerSecond: 8.0,
            burst:         16,
            now:           function () use (&$clock) { return $clock; },
            sleep:         function (float $seconds) use (&$clock) { $clock += $seconds; },
        );
        for ($i = 0; $i < 16; $i++) $bucket->consume();
        // 5 seconds idle: 8 * 5 = 40 tokens earned, capped at 16.
        $clock += 5;
        for ($i = 0; $i < 16; $i++) {
            $this->assertSame(0.0, $bucket->consume());
        }
    }

    public function test_try_consume_returns_false_when_empty(): void
    {
        $clock = 1000.0;
        $bucket = new TokenBucket(
            ratePerSecond: 8.0,
            burst:         3,
            now:           function () use (&$clock) { return $clock; },
            sleep:         function () { /* never called by tryConsume */ },
        );
        $this->assertTrue($bucket->tryConsume());
        $this->assertTrue($bucket->tryConsume());
        $this->assertTrue($bucket->tryConsume());
        $this->assertFalse($bucket->tryConsume());
    }

    public function test_constructor_validates_inputs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenBucket(ratePerSecond: 0.0, burst: 1);
    }

    public function test_constructor_validates_burst(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenBucket(ratePerSecond: 1.0, burst: 0);
    }

    public function test_consume_with_invalid_cost_raises(): void
    {
        $bucket = new TokenBucket();
        $this->expectException(\InvalidArgumentException::class);
        $bucket->consume(0);
    }

    public function test_consume_multiple_tokens_at_once(): void
    {
        $clock = 1000.0;
        $bucket = new TokenBucket(
            ratePerSecond: 8.0,
            burst:         16,
            now:           function () use (&$clock) { return $clock; },
            sleep:         function (float $seconds) use (&$clock) { $clock += $seconds; },
        );
        $this->assertSame(0.0, $bucket->consume(10));
        // 6 left; consume 7 → blocks for 1 token = 0.125s.
        $waited = $bucket->consume(7);
        $this->assertEqualsWithDelta(0.125, $waited, 0.001);
    }
}
