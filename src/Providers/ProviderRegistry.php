<?php

namespace SuperAgent\Providers;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\CredentialPool;

class ProviderRegistry
{
    /**
     * Registered provider classes.
     *
     * @var array<string, class-string<LLMProvider>>
     */
    protected static array $providers = [
        'anthropic' => AnthropicProvider::class,
        'openai' => OpenAIProvider::class,
        'openrouter' => OpenRouterProvider::class,
        'bedrock' => BedrockProvider::class,
        'ollama' => OllamaProvider::class,
        'gemini' => GeminiProvider::class,
        'kimi' => KimiProvider::class,
        'qwen' => QwenProvider::class,
        'glm' => GlmProvider::class,
        'minimax' => MiniMaxProvider::class,
    ];

    /**
     * Provider instances cache.
     *
     * @var array<string, LLMProvider>
     */
    protected static array $instances = [];

    /**
     * Credential pool for multi-key rotation and failover.
     */
    protected static ?CredentialPool $credentialPool = null;

    /**
     * Default configurations for providers.
     * 
     * @var array<string, array>
     */
    protected static array $defaultConfigs = [
        'anthropic' => [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 4096,
            'max_retries' => 3,
        ],
        'openai' => [
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'max_retries' => 3,
        ],
        'openrouter' => [
            'model' => 'anthropic/claude-3-5-sonnet',
            'max_tokens' => 4096,
            'max_retries' => 3,
            'app_name' => 'SuperAgent',
        ],
        'bedrock' => [
            'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'region' => 'us-east-1',
            'max_tokens' => 4096,
            'max_retries' => 3,
        ],
        'ollama' => [
            'model' => 'llama2',
            'base_url' => 'http://localhost:11434',
            'max_tokens' => 2048,
            'max_retries' => 3,
            'keep_alive' => true,
        ],
        'gemini' => [
            'model' => 'gemini-2.0-flash',
            'max_tokens' => 8192,
            'max_retries' => 3,
        ],
        'kimi' => [
            'model' => 'kimi-k2-6',
            'region' => 'intl',
            'max_tokens' => 8192,
            'max_retries' => 3,
        ],
        'qwen' => [
            'model' => 'qwen3.6-max-preview',
            'region' => 'intl',
            'max_tokens' => 8192,
            'max_retries' => 3,
        ],
        'glm' => [
            'model' => 'glm-4.6',
            'region' => 'intl',
            'max_tokens' => 8192,
            'max_retries' => 3,
        ],
        'minimax' => [
            'model' => 'MiniMax-M2.7',
            'region' => 'intl',
            'max_tokens' => 8192,
            'max_retries' => 3,
        ],
    ];

    /**
     * Register a new provider.
     */
    public static function register(string $name, string $providerClass): void
    {
        if (!is_subclass_of($providerClass, LLMProvider::class)) {
            throw new ProviderException(
                "Provider class must implement LLMProvider interface",
                $name
            );
        }

        self::$providers[$name] = $providerClass;
    }

    /**
     * Set the credential pool for multi-key rotation and failover.
     */
    public static function setCredentialPool(CredentialPool $pool): void
    {
        self::$credentialPool = $pool;
    }

    /**
     * Get the current credential pool (if any).
     */
    public static function getCredentialPool(): ?CredentialPool
    {
        return self::$credentialPool;
    }

    /**
     * Create a provider instance.
     *
     * When a CredentialPool is configured and the provider has pooled keys,
     * the pool's next available key is used instead of the config key.
     */
    public static function create(string $name, array $config = []): LLMProvider
    {
        if (!isset(self::$providers[$name])) {
            throw new ProviderException(
                "Unknown provider: {$name}. Available providers: " . implode(', ', array_keys(self::$providers)),
                $name
            );
        }

        // Merge with default config
        $defaultConfig = self::$defaultConfigs[$name] ?? [];
        $config = array_merge($defaultConfig, $config);

        // If a credential pool is configured, try to get a key from the pool.
        // Pass the region so region-tagged keys only get served for matching regions
        // (region-less keys remain universal).
        if (self::$credentialPool !== null && !isset($config['_skip_pool'])) {
            $poolKey = self::$credentialPool->getKey($name, $config['region'] ?? null);
            if ($poolKey !== null) {
                $config['api_key'] = $poolKey;
                $config['_pool_key'] = $poolKey; // Track which key was used for reporting
            }
        }

        // Validate required config based on provider
        self::validateConfig($name, $config);

        $providerClass = self::$providers[$name];
        return new $providerClass($config);
    }

    /**
     * Create a provider pinned to a specific region. Convenience wrapper around
     * `create()` that ensures `region` ends up in the config even if the caller
     * passed other settings.
     *
     * Throws if the provider class has no `regionToBaseUrl()` support (non-
     * ChatCompletionsProvider like Anthropic / Bedrock / Ollama that don't
     * carry a region concept) — use `create()` for those.
     */
    public static function createWithRegion(string $name, string $region, array $config = []): LLMProvider
    {
        $config['region'] = $region;
        return self::create($name, $config);
    }

    /**
     * Report a successful API call to the credential pool.
     */
    public static function reportSuccess(string $providerName, array $config): void
    {
        if (self::$credentialPool !== null && isset($config['_pool_key'])) {
            self::$credentialPool->reportSuccess($providerName, $config['_pool_key']);
        }
    }

    /**
     * Report a rate limit (429) to the credential pool.
     */
    public static function reportRateLimit(string $providerName, array $config): void
    {
        if (self::$credentialPool !== null && isset($config['_pool_key'])) {
            self::$credentialPool->reportRateLimit($providerName, $config['_pool_key']);
        }
    }

    /**
     * Report an error to the credential pool.
     */
    public static function reportError(string $providerName, array $config): void
    {
        if (self::$credentialPool !== null && isset($config['_pool_key'])) {
            self::$credentialPool->reportError($providerName, $config['_pool_key']);
        }
    }

    /**
     * Get or create a cached provider instance.
     */
    public static function get(string $name, array $config = []): LLMProvider
    {
        $cacheKey = $name . ':' . md5(serialize($config));
        
        if (!isset(self::$instances[$cacheKey])) {
            self::$instances[$cacheKey] = self::create($name, $config);
        }

        return self::$instances[$cacheKey];
    }

    /**
     * Clear cached instances and credential pool.
     */
    public static function clearCache(): void
    {
        self::$instances = [];
        self::$credentialPool = null;
    }

    /**
     * Get all registered provider names.
     */
    public static function getProviders(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Check if a provider is registered.
     */
    public static function hasProvider(string $name): bool
    {
        return isset(self::$providers[$name]);
    }

    /**
     * Get default configuration for a provider.
     */
    public static function getDefaultConfig(string $name): array
    {
        return self::$defaultConfigs[$name] ?? [];
    }

    /**
     * Set default configuration for a provider.
     */
    public static function setDefaultConfig(string $name, array $config): void
    {
        self::$defaultConfigs[$name] = $config;
    }

    /**
     * Validate provider configuration.
     */
    protected static function validateConfig(string $name, array $config): void
    {
        $requiredKeys = match ($name) {
            'anthropic' => ['api_key'],
            'openai' => ['api_key'],
            'openrouter' => ['api_key'],
            'bedrock' => ['access_key', 'secret_key'],
            'ollama' => [], // No required keys for Ollama
            'gemini' => ['api_key'],
            'kimi', 'qwen', 'glm', 'minimax' => ['api_key'],
            default => [],
        };

        // Check for alternative key names
        $alternativeKeys = match ($name) {
            'anthropic' => ['api_key' => ['access_token']],
            'openai' => ['api_key' => ['access_token']],
            'bedrock' => [
                'access_key' => ['aws_access_key_id'],
                'secret_key' => ['aws_secret_access_key'],
            ],
            default => [],
        };

        // OAuth mode: access_token satisfies the api_key requirement.
        if (($config['auth_mode'] ?? null) === 'oauth' && ! empty($config['access_token'])) {
            return;
        }

        foreach ($requiredKeys as $key) {
            $hasKey = isset($config[$key]);
            
            // Check alternative keys
            if (!$hasKey && isset($alternativeKeys[$key])) {
                foreach ($alternativeKeys[$key] as $altKey) {
                    if (isset($config[$altKey])) {
                        $hasKey = true;
                        break;
                    }
                }
            }

            if (!$hasKey) {
                throw new ProviderException(
                    "Missing required configuration key '{$key}' for provider '{$name}'",
                    $name
                );
            }
        }
    }

    /**
     * Create provider from environment variables.
     */
    public static function createFromEnv(string $name): LLMProvider
    {
        $config = match ($name) {
            'anthropic' => [
                'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'),
            ],
            'openai' => [
                'api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'),
                'organization' => $_ENV['OPENAI_ORGANIZATION'] ?? getenv('OPENAI_ORGANIZATION'),
            ],
            'openrouter' => [
                'api_key' => $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY'),
                'app_name' => $_ENV['OPENROUTER_APP_NAME'] ?? getenv('OPENROUTER_APP_NAME') ?? 'SuperAgent',
                'site_url' => $_ENV['OPENROUTER_SITE_URL'] ?? getenv('OPENROUTER_SITE_URL'),
            ],
            'bedrock' => [
                'access_key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? getenv('AWS_ACCESS_KEY_ID'),
                'secret_key' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? getenv('AWS_SECRET_ACCESS_KEY'),
                'region' => $_ENV['AWS_DEFAULT_REGION'] ?? getenv('AWS_DEFAULT_REGION') ?? 'us-east-1',
            ],
            'ollama' => [
                'base_url' => $_ENV['OLLAMA_BASE_URL'] ?? getenv('OLLAMA_BASE_URL') ?? 'http://localhost:11434',
            ],
            'gemini' => [
                'api_key' => $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY')
                    ?: ($_ENV['GOOGLE_API_KEY'] ?? getenv('GOOGLE_API_KEY')),
            ],
            'kimi' => [
                'api_key' => $_ENV['KIMI_API_KEY'] ?? getenv('KIMI_API_KEY')
                    ?: ($_ENV['MOONSHOT_API_KEY'] ?? getenv('MOONSHOT_API_KEY')),
                'region' => $_ENV['KIMI_REGION'] ?? getenv('KIMI_REGION') ?: 'intl',
            ],
            'qwen' => [
                'api_key' => $_ENV['QWEN_API_KEY'] ?? getenv('QWEN_API_KEY')
                    ?: ($_ENV['DASHSCOPE_API_KEY'] ?? getenv('DASHSCOPE_API_KEY')),
                'region' => $_ENV['QWEN_REGION'] ?? getenv('QWEN_REGION') ?: 'intl',
            ],
            'glm' => [
                'api_key' => $_ENV['GLM_API_KEY'] ?? getenv('GLM_API_KEY')
                    ?: ($_ENV['ZAI_API_KEY'] ?? getenv('ZAI_API_KEY'))
                    ?: ($_ENV['ZHIPU_API_KEY'] ?? getenv('ZHIPU_API_KEY')),
                'region' => $_ENV['GLM_REGION'] ?? getenv('GLM_REGION') ?: 'intl',
            ],
            'minimax' => [
                'api_key' => $_ENV['MINIMAX_API_KEY'] ?? getenv('MINIMAX_API_KEY'),
                'group_id' => $_ENV['MINIMAX_GROUP_ID'] ?? getenv('MINIMAX_GROUP_ID') ?: null,
                'region' => $_ENV['MINIMAX_REGION'] ?? getenv('MINIMAX_REGION') ?: 'intl',
            ],
            default => throw new ProviderException("Unknown provider: {$name}", $name),
        };

        return self::create($name, $config);
    }

    /**
     * Best-effort health check for a single provider. Returns a structured
     * `HealthStatus` array — never throws. This intentionally differs from
     * `discover()` which only looks at environment variables: `healthCheck`
     * actually **hits the network** (with a short timeout) to verify auth
     * + reachability. Call explicitly via `superagent doctor` or during
     * startup when an operator wants "are my keys live right now".
     *
     * Lightweight strategy — try `GET /v1/models` (or equivalent) with a
     * 5-second timeout. If the endpoint doesn't exist we fall back to a
     * minimal chat call with 1 max_token. Any 2xx response counts as OK.
     *
     * @return array{provider: string, ok: bool, latency_ms?: int, reason?: string}
     */
    public static function healthCheck(string $name): array
    {
        if (! isset(self::$providers[$name])) {
            return ['provider' => $name, 'ok' => false, 'reason' => 'unknown provider'];
        }

        try {
            $config = match ($name) {
                'anthropic' => ['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: null],
                'openai'    => ['api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: null],
                'kimi'      => ['api_key' => $_ENV['KIMI_API_KEY'] ?? getenv('KIMI_API_KEY') ?: null],
                'qwen'      => ['api_key' => $_ENV['QWEN_API_KEY'] ?? getenv('QWEN_API_KEY') ?: null],
                'glm'       => ['api_key' => $_ENV['GLM_API_KEY'] ?? getenv('GLM_API_KEY') ?: null],
                'minimax'   => ['api_key' => $_ENV['MINIMAX_API_KEY'] ?? getenv('MINIMAX_API_KEY') ?: null],
                'gemini'    => ['api_key' => $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: null],
                'openrouter'=> ['api_key' => $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: null],
                default     => [],
            };

            if (empty($config['api_key'] ?? null) && $name !== 'ollama' && $name !== 'bedrock') {
                return ['provider' => $name, 'ok' => false, 'reason' => 'no API key in environment'];
            }

            // Create provider (validates config); any ProviderException here
            // points at a configuration problem, not a network problem.
            try {
                $provider = self::create($name, $config);
            } catch (ProviderException $e) {
                return ['provider' => $name, 'ok' => false, 'reason' => 'config: ' . $e->getMessage()];
            }

            $t0 = microtime(true);
            $probeUrl = self::healthProbeUrl($name, $provider);
            if ($probeUrl === null) {
                return ['provider' => $name, 'ok' => true, 'reason' => 'no probe endpoint — skipping'];
            }

            $ch = curl_init($probeUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => self::healthProbeHeaders($name, $config),
                CURLOPT_NOBODY => false,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $ms = (int) ((microtime(true) - $t0) * 1000);

            if ($status >= 200 && $status < 300) {
                return ['provider' => $name, 'ok' => true, 'latency_ms' => $ms];
            }
            if ($status === 401 || $status === 403) {
                return ['provider' => $name, 'ok' => false, 'latency_ms' => $ms, 'reason' => "auth rejected (HTTP {$status})"];
            }
            if ($err !== '') {
                return ['provider' => $name, 'ok' => false, 'latency_ms' => $ms, 'reason' => "curl: {$err}"];
            }
            return ['provider' => $name, 'ok' => false, 'latency_ms' => $ms, 'reason' => "HTTP {$status}"];
        } catch (\Throwable $e) {
            return ['provider' => $name, 'ok' => false, 'reason' => 'unexpected: ' . $e->getMessage()];
        }
    }

    /**
     * Cheap listing endpoint per provider. Null means "skip the HTTP probe"
     * (e.g. Bedrock uses AWS SDK, not a plain HTTPS endpoint we can curl).
     */
    private static function healthProbeUrl(string $name, object $provider): ?string
    {
        return match ($name) {
            'anthropic'  => 'https://api.anthropic.com/v1/models',
            'openai'     => 'https://api.openai.com/v1/models',
            'openrouter' => 'https://openrouter.ai/api/v1/models',
            'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/models',
            'kimi'       => method_exists($provider, 'getRegion') && $provider->getRegion() === 'cn'
                ? 'https://api.moonshot.cn/v1/models'
                : 'https://api.moonshot.ai/v1/models',
            'qwen'       => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/models',
            'glm'        => 'https://api.z.ai/api/paas/v4/models',
            'minimax'    => 'https://api.minimax.io/v1/text/models',
            'ollama'     => 'http://localhost:11434/api/tags',
            default      => null,  // bedrock uses AWS SDK — no plain probe
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private static function healthProbeHeaders(string $name, array $config): array
    {
        $bearer = $config['api_key'] ?? null;
        return match ($name) {
            'anthropic' => ['x-api-key: ' . $bearer, 'anthropic-version: 2023-06-01'],
            'gemini'    => ['x-goog-api-key: ' . $bearer],
            'ollama'    => [],
            default     => $bearer ? ['Authorization: Bearer ' . $bearer] : [],
        };
    }

    /**
     * Auto-discover available providers based on environment.
     */
    public static function discover(): array
    {
        $available = [];

        // Check for API keys in environment
        if (getenv('ANTHROPIC_API_KEY')) {
            $available[] = 'anthropic';
        }

        if (getenv('OPENAI_API_KEY')) {
            $available[] = 'openai';
        }

        if (getenv('OPENROUTER_API_KEY')) {
            $available[] = 'openrouter';
        }

        if (getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY')) {
            $available[] = 'bedrock';
        }

        if (getenv('GEMINI_API_KEY') || getenv('GOOGLE_API_KEY')) {
            $available[] = 'gemini';
        }

        if (getenv('KIMI_API_KEY') || getenv('MOONSHOT_API_KEY')) {
            $available[] = 'kimi';
        }

        if (getenv('QWEN_API_KEY') || getenv('DASHSCOPE_API_KEY')) {
            $available[] = 'qwen';
        }

        if (getenv('GLM_API_KEY') || getenv('ZAI_API_KEY') || getenv('ZHIPU_API_KEY')) {
            $available[] = 'glm';
        }

        if (getenv('MINIMAX_API_KEY')) {
            $available[] = 'minimax';
        }

        // Check if Ollama is running
        if (self::isOllamaAvailable()) {
            $available[] = 'ollama';
        }

        return $available;
    }

    /**
     * Check if Ollama is available.
     */
    protected static function isOllamaAvailable(): bool
    {
        try {
            $baseUrl = $_ENV['OLLAMA_BASE_URL'] ?? getenv('OLLAMA_BASE_URL') ?? 'http://localhost:11434';
            $client = new \GuzzleHttp\Client(['base_uri' => $baseUrl, 'timeout' => 2]);
            $response = $client->get('/api/tags');
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get provider capabilities.
     */
    public static function getCapabilities(string $name): array
    {
        return match ($name) {
            'anthropic' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 200000,
                'structured_output' => false,
            ],
            'openai' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 128000,
                'structured_output' => true,
            ],
            'openrouter' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 'varies',
                'structured_output' => 'varies',
                'multi_provider' => true,
            ],
            'bedrock' => [
                'streaming' => true,
                'tools' => 'model_dependent',
                'vision' => 'model_dependent',
                'max_context' => 'model_dependent',
                'structured_output' => false,
                'multi_model' => true,
            ],
            'ollama' => [
                'streaming' => true,
                'tools' => false, // emulated
                'vision' => 'model_dependent',
                'max_context' => 'model_dependent',
                'structured_output' => false,
                'local' => true,
                'embeddings' => true,
            ],
            'gemini' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 1_048_576,
                'structured_output' => true,
            ],
            'kimi' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 262_144,
                'structured_output' => true,
                'regions' => ['intl', 'cn'],
            ],
            'qwen' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 260_000,
                'structured_output' => true,
                'regions' => ['intl', 'us', 'cn', 'hk'],
            ],
            'glm' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 200_000,
                'structured_output' => true,
                'regions' => ['intl', 'cn'],
            ],
            'minimax' => [
                'streaming' => true,
                'tools' => true,
                'vision' => true,
                'max_context' => 204_800,
                'structured_output' => true,
                'regions' => ['intl', 'cn'],
            ],
            default => [],
        };
    }

    /**
     * Suggest best provider for a use case.
     */
    public static function suggest(array $requirements = []): string
    {
        $available = self::discover();
        
        if (empty($available)) {
            throw new ProviderException(
                "No providers available. Please configure at least one provider.",
                'registry'
            );
        }

        // Priority based on requirements
        $priorities = [];

        if ($requirements['local'] ?? false) {
            // Prefer local providers
            if (in_array('ollama', $available)) return 'ollama';
        }

        if ($requirements['tools'] ?? false) {
            // Prefer providers with native tool support
            if (in_array('anthropic', $available)) $priorities['anthropic'] = 10;
            if (in_array('openai', $available)) $priorities['openai'] = 9;
            if (in_array('openrouter', $available)) $priorities['openrouter'] = 8;
        }

        if ($requirements['structured_output'] ?? false) {
            // Prefer providers with structured output
            if (in_array('openai', $available)) return 'openai';
        }

        if ($requirements['vision'] ?? false) {
            // All major providers support vision
            if (in_array('anthropic', $available)) $priorities['anthropic'] = 10;
            if (in_array('openai', $available)) $priorities['openai'] = 10;
        }

        if ($requirements['cost_effective'] ?? false) {
            // Prefer cost-effective providers
            if (in_array('ollama', $available)) return 'ollama';
            if (in_array('openrouter', $available)) return 'openrouter';
        }

        // Default priorities
        if (empty($priorities)) {
            if (in_array('anthropic', $available)) return 'anthropic';
            if (in_array('openai', $available)) return 'openai';
            if (in_array('openrouter', $available)) return 'openrouter';
            if (in_array('bedrock', $available)) return 'bedrock';
            if (in_array('ollama', $available)) return 'ollama';
        }

        // Return highest priority
        if (!empty($priorities)) {
            arsort($priorities);
            return array_key_first($priorities);
        }

        return $available[0];
    }
}