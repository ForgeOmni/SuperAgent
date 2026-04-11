<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\CredentialPool;
use SuperAgent\Providers\FallbackProvider;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Provider configuration for collaboration pipeline agents.
 *
 * Supports three collaboration patterns:
 *
 * 1. Same provider — all agents share a provider name, credentials are
 *    rotated via CredentialPool to avoid rate-limit collisions.
 *
 * 2. Cross provider — each agent specifies its own provider (e.g. one
 *    uses Anthropic, another uses OpenAI).
 *
 * 3. Fallback chain — ordered list of providers to try on failure,
 *    wrapping them in a FallbackProvider.
 */
class AgentProviderConfig
{
    /** @var array<string, mixed> Raw provider config (api_key, model, base_url, …) */
    private array $config = [];

    /** @var string[] Ordered fallback provider names */
    private array $fallbackProviders = [];

    /** @var array<string, array> Per-fallback-provider config overrides */
    private array $fallbackConfigs = [];

    public function __construct(
        private ?string $providerName = null,
        private ?LLMProvider $providerInstance = null,
        private ?CredentialPool $credentialPool = null,
    ) {}

    // ── Static factories ────────────────────────────────────────

    /**
     * Same-provider config: share one provider, rotate credentials.
     */
    public static function sameProvider(
        string $provider,
        ?CredentialPool $pool = null,
        array $config = [],
    ): self {
        $instance = new self(providerName: $provider, credentialPool: $pool);
        $instance->config = $config;
        return $instance;
    }

    /**
     * Cross-provider config: use a specific provider with explicit config.
     */
    public static function crossProvider(
        string $provider,
        array $config = [],
    ): self {
        $instance = new self(providerName: $provider);
        $instance->config = $config;
        return $instance;
    }

    /**
     * Pre-built provider instance (testing, custom providers).
     */
    public static function fromInstance(LLMProvider $provider): self
    {
        return new self(providerInstance: $provider);
    }

    /**
     * Build a fallback chain: try providers in order on failure.
     *
     * @param array<string|array{name: string, config?: array}> $providers
     */
    public static function withFallbackChain(array $providers, array $baseConfig = []): self
    {
        $instance = new self();
        $instance->config = $baseConfig;

        foreach ($providers as $p) {
            if (is_string($p)) {
                $instance->fallbackProviders[] = $p;
            } elseif (is_array($p) && isset($p['name'])) {
                $instance->fallbackProviders[] = $p['name'];
                if (isset($p['config'])) {
                    $instance->fallbackConfigs[$p['name']] = $p['config'];
                }
            }
        }

        // Use first as primary
        if (!empty($instance->fallbackProviders)) {
            $instance->providerName = $instance->fallbackProviders[0];
        }

        return $instance;
    }

    // ── Fluent setters ──────────────────────────────────────────

    public function withModel(string $model): static
    {
        $this->config['model'] = $model;
        return $this;
    }

    public function withApiKey(string $apiKey): static
    {
        $this->config['api_key'] = $apiKey;
        return $this;
    }

    public function withConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function withCredentialPool(CredentialPool $pool): static
    {
        $this->credentialPool = $pool;
        return $this;
    }

    public function addFallback(string $providerName, array $config = []): static
    {
        $this->fallbackProviders[] = $providerName;
        if (!empty($config)) {
            $this->fallbackConfigs[$providerName] = $config;
        }
        return $this;
    }

    // ── Resolution ──────────────────────────────────────────────

    /**
     * Resolve to a concrete LLMProvider instance.
     *
     * If a credential pool is configured, injects a rotated key.
     * If fallback providers are configured, wraps in FallbackProvider.
     */
    public function resolve(): LLMProvider
    {
        // Pre-built instance
        if ($this->providerInstance !== null) {
            return $this->providerInstance;
        }

        // Fallback chain
        if (count($this->fallbackProviders) > 1) {
            return $this->resolveFallbackChain();
        }

        // Single provider
        return $this->resolveSingleProvider();
    }

    /**
     * Build the provider config array for AgentSpawnConfig.providerConfig.
     */
    public function toSpawnConfig(): array
    {
        $config = $this->config;
        $config['provider'] = $this->providerName;

        // Inject credential from pool
        if ($this->credentialPool !== null && $this->providerName !== null) {
            $key = $this->credentialPool->getKey($this->providerName);
            if ($key !== null) {
                $config['api_key'] = $key;
                $config['_pool_key'] = $key;
            }
        }

        if (!empty($this->fallbackProviders)) {
            $config['_fallback_providers'] = $this->fallbackProviders;
            $config['_fallback_configs'] = $this->fallbackConfigs;
        }

        return $config;
    }

    /**
     * Report a successful call to the credential pool.
     */
    public function reportSuccess(string $apiKey): void
    {
        if ($this->credentialPool !== null && $this->providerName !== null) {
            $this->credentialPool->reportSuccess($this->providerName, $apiKey);
        }
    }

    /**
     * Report a rate limit to the credential pool.
     */
    public function reportRateLimit(string $apiKey): void
    {
        if ($this->credentialPool !== null && $this->providerName !== null) {
            $this->credentialPool->reportRateLimit($this->providerName, $apiKey);
        }
    }

    /**
     * Report an error to the credential pool.
     */
    public function reportError(string $apiKey): void
    {
        if ($this->credentialPool !== null && $this->providerName !== null) {
            $this->credentialPool->reportError($this->providerName, $apiKey);
        }
    }

    // ── Accessors ───────────────────────────────────────────────

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getCredentialPool(): ?CredentialPool
    {
        return $this->credentialPool;
    }

    public function hasFallbackChain(): bool
    {
        return count($this->fallbackProviders) > 1;
    }

    /** @return string[] */
    public function getFallbackProviders(): array
    {
        return $this->fallbackProviders;
    }

    // ── Internals ───────────────────────────────────────────────

    private function resolveSingleProvider(): LLMProvider
    {
        $config = $this->config;

        // Inject credential from pool
        if ($this->credentialPool !== null && $this->providerName !== null) {
            $key = $this->credentialPool->getKey($this->providerName);
            if ($key !== null) {
                $config['api_key'] = $key;
            }
        }

        return ProviderRegistry::create($this->providerName ?? 'anthropic', $config);
    }

    private function resolveFallbackChain(): LLMProvider
    {
        $providers = [];

        foreach ($this->fallbackProviders as $name) {
            $config = array_merge($this->config, $this->fallbackConfigs[$name] ?? []);

            // Inject credential from pool for this provider
            if ($this->credentialPool !== null) {
                $key = $this->credentialPool->getKey($name);
                if ($key !== null) {
                    $config['api_key'] = $key;
                }
            }

            try {
                $providers[] = ProviderRegistry::create($name, $config);
            } catch (\Throwable $e) {
                // Skip unavailable providers in fallback chain
                continue;
            }
        }

        if (empty($providers)) {
            // Fall back to primary
            return $this->resolveSingleProvider();
        }

        return new FallbackProvider($providers, [
            'throw_on_all_failures' => true,
            'return_first_success' => true,
        ]);
    }
}
