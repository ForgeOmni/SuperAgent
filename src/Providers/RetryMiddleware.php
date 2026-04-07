<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use InvalidArgumentException;
use LogicException;
use SuperAgent\Contracts\LLMProvider;
use Throwable;

/**
 * Middleware that wraps API calls with retry logic and exponential backoff.
 *
 * Inspired by the OpenHarness pattern, adapted for PHP LLM provider calls.
 */
class RetryMiddleware
{
    private ?LLMProvider $provider = null;

    /** @var array<int, array{attempt: int, error: string, code: int|string, classification: string, delay: float}> */
    private array $retryLog = [];

    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly float $baseDelay = 1.0,
        private readonly float $maxDelay = 30.0,
    ) {}

    /**
     * Factory method: create a RetryMiddleware wrapping the given provider.
     *
     * @param array{maxRetries?: int, baseDelay?: float, maxDelay?: float} $options
     */
    public static function wrap(LLMProvider $provider, array $options = []): self
    {
        $instance = new self(
            maxRetries: $options['maxRetries'] ?? 3,
            baseDelay: $options['baseDelay'] ?? 1.0,
            maxDelay: $options['maxDelay'] ?? 30.0,
        );
        $instance->provider = $provider;

        return $instance;
    }

    /**
     * Execute an API call with retry logic.
     *
     * The callable receives the wrapped provider (if set) as its first argument.
     */
    public function execute(callable $apiCall): mixed
    {
        $this->retryLog = [];
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $apiCall($this->provider);
            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt >= $this->maxRetries || !$this->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                $delay = $this->calculateDelay($attempt, $e);
                $classification = $this->classifyError($e);

                $this->retryLog[] = [
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'classification' => $classification,
                    'delay' => $delay,
                ];

                error_log(sprintf(
                    '[SuperAgent] API retry attempt %d/%d — %s (code %s, class %s) — waiting %.2fs',
                    $attempt + 1,
                    $this->maxRetries,
                    $e->getMessage(),
                    (string) $e->getCode(),
                    $classification,
                    $delay,
                ));

                $this->sleep($delay);
            }
        }

        // Should not reach here, but just in case:
        throw $lastException ?? new \RuntimeException('Retry loop exhausted with no exception');
    }

    /**
     * Determine whether the given exception is retryable.
     */
    public function shouldRetry(Throwable $e, int $attempt): bool
    {
        // Never retry logic / argument errors
        if ($e instanceof InvalidArgumentException || $e instanceof LogicException) {
            return false;
        }

        // Never retry auth errors
        $code = $e->getCode();
        if (in_array($code, [401, 403], true)) {
            return false;
        }

        // Retry rate-limit and transient server errors
        if (in_array($code, [429, 500, 502, 503, 529], true)) {
            return true;
        }

        // Retry connection / timeout exceptions by class name (works across packages)
        $className = get_class($e);
        if (
            str_contains($className, 'ConnectionException')
            || str_contains($className, 'TimeoutException')
        ) {
            return true;
        }

        // Default: not retryable
        return false;
    }

    /**
     * Calculate the delay before the next retry attempt.
     *
     * Uses exponential backoff with 0-25 % random jitter.
     * Respects a Retry-After header when available in exception metadata.
     */
    public function calculateDelay(int $attempt, ?Throwable $e = null): float
    {
        // Check for Retry-After metadata on the exception
        $retryAfter = $this->extractRetryAfter($e);
        if ($retryAfter !== null) {
            return min($retryAfter, $this->maxDelay);
        }

        $exponential = $this->baseDelay * pow(2, $attempt);
        $capped = min($exponential, $this->maxDelay);

        // Add 0-25 % jitter
        $jitter = $capped * (mt_rand(0, 250) / 1000);

        return $capped + $jitter;
    }

    /**
     * Classify an error into a category.
     *
     * @return string One of 'auth', 'rate_limit', 'transient', 'unrecoverable'
     */
    public function classifyError(Throwable $e): string
    {
        $code = $e->getCode();

        if (in_array($code, [401, 403], true)) {
            return 'auth';
        }

        if ($code === 429 || $code === 529) {
            return 'rate_limit';
        }

        if (in_array($code, [500, 502, 503], true)) {
            return 'transient';
        }

        $className = get_class($e);
        if (
            str_contains($className, 'ConnectionException')
            || str_contains($className, 'TimeoutException')
        ) {
            return 'transient';
        }

        if ($e instanceof InvalidArgumentException || $e instanceof LogicException) {
            return 'unrecoverable';
        }

        return 'unrecoverable';
    }

    /**
     * Return the log of retry attempts from the most recent execute() call.
     *
     * @return array<int, array{attempt: int, error: string, code: int|string, classification: string, delay: float}>
     */
    public function getRetryLog(): array
    {
        return $this->retryLog;
    }

    /**
     * Get the wrapped provider, if any.
     */
    public function getProvider(): ?LLMProvider
    {
        return $this->provider;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Try to extract a Retry-After value from exception metadata.
     *
     * Supports exceptions that expose a `getRetryAfter()` or `getHeaders()` method.
     */
    private function extractRetryAfter(?Throwable $e): ?float
    {
        if ($e === null) {
            return null;
        }

        // Direct method
        if (method_exists($e, 'getRetryAfter')) {
            $value = $e->getRetryAfter();
            return is_numeric($value) ? (float) $value : null;
        }

        // Via headers
        if (method_exists($e, 'getHeaders')) {
            $headers = $e->getHeaders();
            $retryAfter = $headers['Retry-After'] ?? $headers['retry-after'] ?? null;
            if (is_numeric($retryAfter)) {
                return (float) $retryAfter;
            }
        }

        return null;
    }

    /**
     * Sleep for the given number of seconds. Extracted for testability.
     */
    protected function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
