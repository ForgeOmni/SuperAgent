<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Transport;

/**
 * In-process token-bucket rate limiter — the same shape DeepSeek-TUI
 * runs in front of its `/chat/completions` client (8 RPS sustained,
 * 16-token burst). Cheap to construct, cheap to consume; the
 * `consume()` call blocks when the bucket is empty until enough
 * capacity has refilled.
 *
 * Why a token bucket and not a fixed-window counter:
 *
 *   Fixed windows give terrible burst behaviour at boundaries — a
 *   client doing one call/second can issue 60 calls in a half-second
 *   if the boundary lands wrong. The token bucket is rate-faithful
 *   AND tolerates legitimate bursts (e.g. a parallel fan-out of N
 *   sub-agents starting at once). DeepSeek's documented limits
 *   match this shape — they apply per-account RPS and accept
 *   short bursts above the sustained rate.
 *
 * Process-local — there is no cross-process coordination, so two
 * SuperAgent processes hitting the same API key will each get their
 * own bucket. That's the same fidelity codex-rs's `RateLimiter`
 * gives, and it's the right tradeoff: the alternative is a
 * cross-process counter (Redis, file lock) and the latency overhead
 * usually outweighs the rate-fidelity gain. If a host needs strict
 * cross-process limits, wire its own Redis-backed limiter into
 * Guzzle middleware.
 *
 * Time source is injectable so tests don't sleep — pass a `\Closure`
 * returning float seconds (or rely on `microtime(true)` by default).
 */
final class TokenBucket
{
    private float $tokens;
    private float $lastRefillAt;

    /**
     * @param float    $ratePerSecond  Sustained refill rate (e.g. 8.0
     *                                 = 8 tokens per second).
     * @param int      $burst          Bucket capacity (e.g. 16 = up to
     *                                 16 simultaneous calls before we
     *                                 throttle).
     * @param \Closure|null $now       Time source returning float
     *                                 seconds. Defaults to
     *                                 `microtime(true)`.
     * @param \Closure|null $sleep     Sleeper invoked with float
     *                                 seconds when blocking. Defaults
     *                                 to `usleep`.
     */
    public function __construct(
        private float $ratePerSecond = 8.0,
        private int $burst = 16,
        private ?\Closure $now = null,
        private ?\Closure $sleep = null,
    ) {
        if ($ratePerSecond <= 0.0) {
            throw new \InvalidArgumentException('ratePerSecond must be > 0');
        }
        if ($burst <= 0) {
            throw new \InvalidArgumentException('burst must be > 0');
        }
        $this->now ??= static fn (): float => microtime(true);
        $this->sleep ??= static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
        $this->tokens = (float) $burst;
        $this->lastRefillAt = ($this->now)();
    }

    /**
     * Block until at least one token is available, then deduct it.
     * Returns the number of seconds the call had to wait (0.0 when
     * the bucket had immediate capacity).
     */
    public function consume(int $cost = 1): float
    {
        if ($cost < 1) {
            throw new \InvalidArgumentException('cost must be >= 1');
        }
        $waited = 0.0;
        while (true) {
            $this->refill();
            if ($this->tokens >= (float) $cost) {
                $this->tokens -= (float) $cost;
                return $waited;
            }
            $needed = (float) $cost - $this->tokens;
            $waitSeconds = $needed / $this->ratePerSecond;
            ($this->sleep)($waitSeconds);
            $waited += $waitSeconds;
        }
    }

    /**
     * Non-blocking variant — returns true if a token was available
     * (and consumed), false otherwise. Useful for "skip the API call
     * and try again later" workflows.
     */
    public function tryConsume(int $cost = 1): bool
    {
        if ($cost < 1) {
            throw new \InvalidArgumentException('cost must be >= 1');
        }
        $this->refill();
        if ($this->tokens >= (float) $cost) {
            $this->tokens -= (float) $cost;
            return true;
        }
        return false;
    }

    public function availableTokens(): float
    {
        $this->refill();
        return $this->tokens;
    }

    private function refill(): void
    {
        $now = ($this->now)();
        $delta = max(0.0, $now - $this->lastRefillAt);
        $this->tokens = min(
            (float) $this->burst,
            $this->tokens + $delta * $this->ratePerSecond,
        );
        $this->lastRefillAt = $now;
    }
}
