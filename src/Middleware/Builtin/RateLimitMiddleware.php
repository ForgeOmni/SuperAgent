<?php

declare(strict_types=1);

namespace SuperAgent\Middleware\Builtin;

use SuperAgent\Middleware\MiddlewareContext;
use SuperAgent\Middleware\MiddlewareInterface;
use SuperAgent\Middleware\MiddlewareResult;
use SuperAgent\Exceptions\RateLimitException;

/**
 * Token-bucket rate limiter for LLM requests.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private float $tokens;
    private float $lastRefill;

    public function __construct(
        private float $maxTokens = 10.0,
        private float $refillRate = 1.0, // tokens per second
        private float $maxWaitSeconds = 30.0,
    ) {
        $this->tokens = $this->maxTokens;
        $this->lastRefill = microtime(true);
    }

    public function name(): string
    {
        return 'rate_limit';
    }

    public function priority(): int
    {
        return 100; // runs first (outermost)
    }

    public function handle(MiddlewareContext $context, callable $next): MiddlewareResult
    {
        $this->refill();

        if ($this->tokens < 1.0) {
            $waitTime = (1.0 - $this->tokens) / $this->refillRate;
            if ($waitTime > $this->maxWaitSeconds) {
                throw new RateLimitException(
                    "Rate limit exceeded, would need to wait {$waitTime}s",
                    waitSeconds: $waitTime,
                );
            }
            usleep((int) ($waitTime * 1_000_000));
            $this->refill();
        }

        $this->tokens -= 1.0;

        return $next($context);
    }

    private function refill(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRefill;
        $this->tokens = min($this->maxTokens, $this->tokens + $elapsed * $this->refillRate);
        $this->lastRefill = $now;
    }
}
