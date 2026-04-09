<?php

declare(strict_types=1);

namespace SuperAgent\Middleware\Builtin;

use SuperAgent\Middleware\MiddlewareContext;
use SuperAgent\Middleware\MiddlewareInterface;
use SuperAgent\Middleware\MiddlewareResult;
use SuperAgent\Exceptions\SuperAgentException;

/**
 * Automatic retry with exponential backoff and jitter.
 */
class RetryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $maxRetries = 3,
        private int $baseDelayMs = 1000,
        private float $backoffMultiplier = 2.0,
        private int $maxDelayMs = 30000,
    ) {}

    public function name(): string
    {
        return 'retry';
    }

    public function priority(): int
    {
        return 90; // runs early, inside rate limiter
    }

    public function handle(MiddlewareContext $context, callable $next): MiddlewareResult
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $result = $next($context);

                if ($attempt > 0) {
                    $result = $result->withMetadata('retry_attempts', $attempt);
                }

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;

                if (!$this->isRetryable($e) || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                $delay = $this->calculateDelay($attempt);
                usleep($delay * 1000);
            }
        }

        throw $lastException;
    }

    private function isRetryable(\Throwable $e): bool
    {
        // SuperAgentException hierarchy knows if it's retryable
        if ($e instanceof SuperAgentException) {
            return $e->isRetryable();
        }

        // HTTP status codes
        $code = $e->getCode();
        if ($code === 429 || $code === 503 || $code === 502 || $code === 500) {
            return true;
        }

        // Connection errors
        $message = strtolower($e->getMessage());
        $retryablePatterns = ['connection', 'timeout', 'reset', 'overloaded', 'temporarily'];
        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function calculateDelay(int $attempt): int
    {
        $delay = (int) ($this->baseDelayMs * ($this->backoffMultiplier ** $attempt));
        $delay = min($delay, $this->maxDelayMs);

        // Add jitter (±25%)
        $jitter = (int) ($delay * 0.25);
        $delay += random_int(-$jitter, $jitter);

        return max(100, $delay);
    }
}
