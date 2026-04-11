<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Per-agent retry policy for collaboration pipeline execution.
 *
 * Controls how individual agent failures are handled within a phase:
 * - Max attempts and backoff strategy
 * - Credential rotation on rate limits
 * - Provider fallback on persistent failures
 */
class AgentRetryPolicy
{
    /** @var string[] Fallback provider names to try on failure */
    private array $fallbackProviders = [];

    /** @var array<string, array> Config overrides per fallback provider */
    private array $fallbackProviderConfigs = [];

    public function __construct(
        private int $maxAttempts = 3,
        private string $backoffType = 'exponential',
        private int $baseDelayMs = 1000,
        private int $maxDelayMs = 30000,
        private bool $jitter = true,
        private bool $rotateCredentialOnRateLimit = true,
        private bool $switchProviderOnFailure = false,
    ) {}

    // ── Static factories ────────────────────────────────────────

    /**
     * Default policy: 3 retries with exponential backoff, credential rotation.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Aggressive retry: more attempts, longer waits.
     */
    public static function aggressive(): self
    {
        return new self(
            maxAttempts: 5,
            baseDelayMs: 2000,
            maxDelayMs: 60000,
        );
    }

    /**
     * No retries — fail immediately.
     */
    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * Cross-provider retry: switch providers on failure.
     *
     * @param string[] $fallbackProviders Ordered provider names to try
     */
    public static function crossProvider(array $fallbackProviders): self
    {
        $policy = new self(
            maxAttempts: 3,
            switchProviderOnFailure: true,
        );
        $policy->fallbackProviders = $fallbackProviders;
        return $policy;
    }

    // ── Fluent setters ──────────────────────────────────────────

    public function withMaxAttempts(int $attempts): static
    {
        $this->maxAttempts = max(1, $attempts);
        return $this;
    }

    public function withBackoff(string $type, int $baseDelayMs = 1000, int $maxDelayMs = 30000): static
    {
        $this->backoffType = $type;
        $this->baseDelayMs = $baseDelayMs;
        $this->maxDelayMs = $maxDelayMs;
        return $this;
    }

    public function withJitter(bool $jitter = true): static
    {
        $this->jitter = $jitter;
        return $this;
    }

    public function withCredentialRotation(bool $enabled = true): static
    {
        $this->rotateCredentialOnRateLimit = $enabled;
        return $this;
    }

    public function withProviderFallback(string $providerName, array $config = []): static
    {
        $this->switchProviderOnFailure = true;
        $this->fallbackProviders[] = $providerName;
        if (!empty($config)) {
            $this->fallbackProviderConfigs[$providerName] = $config;
        }
        return $this;
    }

    // ── Retry logic ─────────────────────────────────────────────

    /**
     * Check if another attempt should be made.
     */
    public function shouldRetry(int $attempt, \Throwable $error): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        return $this->isRetryable($error);
    }

    /**
     * Calculate delay before next attempt in milliseconds.
     */
    public function getDelayMs(int $attempt): int
    {
        $delay = match ($this->backoffType) {
            'none' => 0,
            'fixed' => $this->baseDelayMs,
            'linear' => $this->baseDelayMs * $attempt,
            'exponential' => (int) ($this->baseDelayMs * pow(2, $attempt - 1)),
            default => (int) ($this->baseDelayMs * pow(2, $attempt - 1)),
        };

        $delay = min($delay, $this->maxDelayMs);

        if ($this->jitter && $delay > 0) {
            // Add 0-25% random jitter
            $jitterRange = (int) ($delay * 0.25);
            $delay += $jitterRange > 0 ? random_int(0, $jitterRange) : 0;
        }

        return $delay;
    }

    /**
     * Check if a rate-limit error should trigger credential rotation.
     */
    public function shouldRotateCredential(\Throwable $error): bool
    {
        if (!$this->rotateCredentialOnRateLimit) {
            return false;
        }

        return $this->isRateLimitError($error);
    }

    /**
     * Check if a failure should trigger provider switch.
     */
    public function shouldSwitchProvider(int $attempt, \Throwable $error): bool
    {
        if (!$this->switchProviderOnFailure || empty($this->fallbackProviders)) {
            return false;
        }

        // Switch after exhausting retries on current provider,
        // or immediately on auth errors
        return $this->isAuthError($error) || $attempt >= 2;
    }

    /**
     * Get the next fallback provider to try.
     *
     * @param int $switchCount Number of provider switches already made
     */
    public function getNextFallbackProvider(int $switchCount): ?string
    {
        return $this->fallbackProviders[$switchCount] ?? null;
    }

    /**
     * Get config for a fallback provider.
     */
    public function getFallbackProviderConfig(string $providerName): array
    {
        return $this->fallbackProviderConfigs[$providerName] ?? [];
    }

    // ── Error classification ────────────────────────────────────

    public function isRetryable(\Throwable $error): bool
    {
        // Never retry programming errors
        if ($error instanceof \InvalidArgumentException
            || $error instanceof \LogicException
            || $error instanceof \TypeError
            || $error instanceof \ParseError
        ) {
            return false;
        }

        $message = strtolower($error->getMessage());
        $code = $error->getCode();

        // Auth errors — not retryable (credential is bad)
        if ($code === 401 || $code === 403) {
            return false;
        }

        // Rate limits — retryable
        if ($this->isRateLimitError($error)) {
            return true;
        }

        // Server errors — retryable
        if ($code >= 500 && $code < 600) {
            return true;
        }

        // Network errors
        if (str_contains($message, 'connection')
            || str_contains($message, 'timeout')
            || str_contains($message, 'network')
            || str_contains($message, 'reset')
            || $code === 0
        ) {
            return true;
        }

        // Overloaded
        if ($code === 529 || str_contains($message, 'overloaded')) {
            return true;
        }

        return false;
    }

    public function isRateLimitError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        $code = $error->getCode();

        return $code === 429
            || str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests');
    }

    public function isAuthError(\Throwable $error): bool
    {
        $code = $error->getCode();
        $message = strtolower($error->getMessage());

        return $code === 401
            || $code === 403
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'invalid api key')
            || str_contains($message, 'forbidden');
    }

    /**
     * Classify an error for logging/metrics.
     */
    public function classifyError(\Throwable $error): string
    {
        if ($this->isAuthError($error)) {
            return 'auth';
        }
        if ($this->isRateLimitError($error)) {
            return 'rate_limit';
        }
        if ($error->getCode() >= 500) {
            return 'server';
        }
        if (str_contains(strtolower($error->getMessage()), 'timeout')) {
            return 'timeout';
        }
        if (str_contains(strtolower($error->getMessage()), 'connection')) {
            return 'network';
        }
        if (!$this->isRetryable($error)) {
            return 'unrecoverable';
        }
        return 'transient';
    }

    // ── Accessors ───────────────────────────────────────────────

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getBackoffType(): string
    {
        return $this->backoffType;
    }

    public function getBaseDelayMs(): int
    {
        return $this->baseDelayMs;
    }

    public function getMaxDelayMs(): int
    {
        return $this->maxDelayMs;
    }

    public function hasJitter(): bool
    {
        return $this->jitter;
    }

    public function isCredentialRotationEnabled(): bool
    {
        return $this->rotateCredentialOnRateLimit;
    }

    public function isProviderFallbackEnabled(): bool
    {
        return $this->switchProviderOnFailure;
    }

    /** @return string[] */
    public function getFallbackProviders(): array
    {
        return $this->fallbackProviders;
    }

    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff_type' => $this->backoffType,
            'base_delay_ms' => $this->baseDelayMs,
            'max_delay_ms' => $this->maxDelayMs,
            'jitter' => $this->jitter,
            'rotate_credential_on_rate_limit' => $this->rotateCredentialOnRateLimit,
            'switch_provider_on_failure' => $this->switchProviderOnFailure,
            'fallback_providers' => $this->fallbackProviders,
        ];
    }
}
