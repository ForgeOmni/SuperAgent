<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Multi-credential pool with failover, rotation, and cooldown tracking.
 *
 * Inspired by hermes-agent's credential_pool.py — provides:
 *   - Multiple API keys per provider for load distribution
 *   - Per-credential status tracking (ok, exhausted, cooldown)
 *   - Configurable rotation strategies (fill_first, round_robin, random, least_used)
 *   - Automatic cooldown on rate limits (429) and errors
 *   - Failover to next available credential on failure
 */
class CredentialPool
{
    /** @var array<string, CredentialEntry[]> Keyed by provider name */
    private array $pools = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create from configuration array.
     *
     * Expected format:
     *   [
     *     'anthropic' => [
     *       'strategy' => 'round_robin',
     *       'keys' => ['sk-ant-1', 'sk-ant-2'],
     *       'cooldown_429' => 3600,
     *       'cooldown_error' => 86400,
     *     ],
     *   ]
     */
    public static function fromConfig(array $config = [], ?LoggerInterface $logger = null): self
    {
        $pool = new self($logger);

        foreach ($config as $provider => $providerConfig) {
            $keys = $providerConfig['keys'] ?? [];
            $strategy = $providerConfig['strategy'] ?? 'fill_first';
            $cooldown429 = (int) ($providerConfig['cooldown_429'] ?? 3600);
            $cooldownError = (int) ($providerConfig['cooldown_error'] ?? 86400);

            foreach ($keys as $key) {
                $pool->addCredential($provider, $key, $strategy, $cooldown429, $cooldownError);
            }
        }

        return $pool;
    }

    /**
     * Add a credential to the pool.
     */
    public function addCredential(
        string $provider,
        string $apiKey,
        string $strategy = 'fill_first',
        int $cooldown429 = 3600,
        int $cooldownError = 86400,
    ): void {
        $this->pools[$provider][] = new CredentialEntry(
            apiKey: $apiKey,
            strategy: $strategy,
            cooldown429: $cooldown429,
            cooldownError: $cooldownError,
        );
    }

    /**
     * Get the next available API key for a provider.
     *
     * @return string|null API key, or null if all credentials are exhausted/cooling down
     */
    public function getKey(string $provider): ?string
    {
        if (!isset($this->pools[$provider]) || empty($this->pools[$provider])) {
            return null;
        }

        $entries = &$this->pools[$provider];
        $strategy = $entries[0]->strategy;

        // Filter to available credentials
        $available = [];
        foreach ($entries as $i => $entry) {
            if ($entry->isAvailable()) {
                $available[$i] = $entry;
            }
        }

        if (empty($available)) {
            $this->logger->warning('All credentials exhausted/cooling for provider', [
                'provider' => $provider,
                'total' => count($entries),
            ]);
            return null;
        }

        $selected = match ($strategy) {
            'round_robin' => $this->selectRoundRobin($available),
            'random' => $this->selectRandom($available),
            'least_used' => $this->selectLeastUsed($available),
            default => $this->selectFillFirst($available), // fill_first
        };

        if ($selected !== null) {
            $selected->useCount++;
            $selected->lastUsedAt = time();
        }

        return $selected?->apiKey;
    }

    /**
     * Report a successful API call for a credential.
     */
    public function reportSuccess(string $provider, string $apiKey): void
    {
        $entry = $this->findEntry($provider, $apiKey);
        if ($entry !== null) {
            $entry->status = 'ok';
            $entry->successCount++;
        }
    }

    /**
     * Report a rate limit (429) for a credential.
     */
    public function reportRateLimit(string $provider, string $apiKey): void
    {
        $entry = $this->findEntry($provider, $apiKey);
        if ($entry !== null) {
            $entry->status = 'cooldown';
            $entry->cooldownUntil = time() + $entry->cooldown429;
            $entry->errorCount++;

            $this->logger->info('Credential rate-limited, cooling down', [
                'provider' => $provider,
                'cooldown_seconds' => $entry->cooldown429,
                'key_suffix' => substr($apiKey, -4),
            ]);
        }
    }

    /**
     * Report an error for a credential.
     */
    public function reportError(string $provider, string $apiKey): void
    {
        $entry = $this->findEntry($provider, $apiKey);
        if ($entry !== null) {
            $entry->status = 'cooldown';
            $entry->cooldownUntil = time() + $entry->cooldownError;
            $entry->errorCount++;

            $this->logger->info('Credential error, cooling down', [
                'provider' => $provider,
                'cooldown_seconds' => $entry->cooldownError,
                'key_suffix' => substr($apiKey, -4),
            ]);
        }
    }

    /**
     * Mark a credential as permanently exhausted (e.g., invalid key).
     */
    public function reportExhausted(string $provider, string $apiKey): void
    {
        $entry = $this->findEntry($provider, $apiKey);
        if ($entry !== null) {
            $entry->status = 'exhausted';
        }
    }

    /**
     * Check if a provider has any available credentials.
     */
    public function hasAvailable(string $provider): bool
    {
        return $this->getKey($provider) !== null;
    }

    /**
     * Get pool statistics for a provider.
     */
    public function getStats(string $provider): array
    {
        $entries = $this->pools[$provider] ?? [];
        $stats = ['total' => count($entries), 'ok' => 0, 'cooldown' => 0, 'exhausted' => 0];

        foreach ($entries as $entry) {
            if ($entry->isAvailable()) {
                $stats['ok']++;
            } elseif ($entry->status === 'exhausted') {
                $stats['exhausted']++;
            } else {
                $stats['cooldown']++;
            }
        }

        return $stats;
    }

    /**
     * Get all registered provider names.
     */
    public function getProviders(): array
    {
        return array_keys($this->pools);
    }

    // ── Selection strategies ─────────────────────────────────────

    private function selectFillFirst(array $available): ?CredentialEntry
    {
        return reset($available) ?: null;
    }

    private function selectRoundRobin(array $available): ?CredentialEntry
    {
        // Pick the one used least recently
        $oldest = null;
        foreach ($available as $entry) {
            if ($oldest === null || $entry->lastUsedAt < $oldest->lastUsedAt) {
                $oldest = $entry;
            }
        }
        return $oldest;
    }

    private function selectRandom(array $available): ?CredentialEntry
    {
        $keys = array_keys($available);
        return $available[$keys[array_rand($keys)]];
    }

    private function selectLeastUsed(array $available): ?CredentialEntry
    {
        $min = null;
        foreach ($available as $entry) {
            if ($min === null || $entry->useCount < $min->useCount) {
                $min = $entry;
            }
        }
        return $min;
    }

    private function findEntry(string $provider, string $apiKey): ?CredentialEntry
    {
        foreach ($this->pools[$provider] ?? [] as $entry) {
            if ($entry->apiKey === $apiKey) {
                return $entry;
            }
        }
        return null;
    }
}

/**
 * Internal credential entry with state tracking.
 */
class CredentialEntry
{
    public string $status = 'ok';
    public int $useCount = 0;
    public int $successCount = 0;
    public int $errorCount = 0;
    public int $lastUsedAt = 0;
    public int $cooldownUntil = 0;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $strategy = 'fill_first',
        public readonly int $cooldown429 = 3600,
        public readonly int $cooldownError = 86400,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->status === 'exhausted') {
            return false;
        }

        if ($this->status === 'cooldown' && time() < $this->cooldownUntil) {
            return false;
        }

        // Cooldown expired — reset status
        if ($this->status === 'cooldown' && time() >= $this->cooldownUntil) {
            $this->status = 'ok';
        }

        return true;
    }
}
