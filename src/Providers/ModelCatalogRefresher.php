<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Per-provider live model catalog refresh.
 *
 * Pulls the authoritative model list directly from each provider's
 * `/models` endpoint so `resources/models.json` stops being the source
 * of truth for model ids and capabilities — it becomes an offline
 * fallback instead.
 *
 * Background — why we need this:
 *   - Moonshot/Anthropic/OpenAI/Google all ship new models faster than
 *     we can cut releases. A static JSON catalog is guaranteed stale.
 *   - Every major provider exposes a `/models` endpoint that lists the
 *     ids their account can currently call, plus (usually) a
 *     `context_length` and some capability flags.
 *   - kimi-cli (MoonshotAI) refreshes on login and after /usage; we
 *     follow the same pattern but keep it CLI-triggered for now rather
 *     than automatic, because a broken refresh shouldn't break chat.
 *
 * Per-provider cache: `~/.superagent/models-cache/<provider>.json`
 *   - Atomic write (temp + rename), chmod 0644.
 *   - Loaded by ModelCatalog::ensureLoaded() as an overlay above the
 *     user override and bundled baseline. Runtime register() still wins.
 *
 * Supported providers: OpenAI, OpenRouter, Kimi, GLM, MiniMax, Qwen,
 * Anthropic. Gemini (different `/models` shape) / Ollama (different
 * auth) / Bedrock (SDK-only) are unsupported for now — the method
 * throws a RuntimeException with an actionable message when called on
 * one of them.
 *
 * Design note: this class intentionally re-implements HTTP against
 * each provider's base URL rather than reusing the provider's Guzzle
 * client. We want `refresh()` to work with just (provider, api_key,
 * base_url) — no agent-loop dependencies. That also means this method
 * is safe to call before any provider instance has been constructed.
 */
class ModelCatalogRefresher
{
    /**
     * Test-only client factory hook. When set, overrides the default
     * Guzzle client construction inside `httpGetModels()`. Production
     * code never assigns this; tests use `ProviderMockHelper`-style
     * injected responses:
     *
     *   ModelCatalogRefresher::$clientFactory =
     *       fn(string $base, string $key) => new GuzzleHttp\Client([...]);
     *
     * Always reset to null in tearDown() or it leaks into neighbouring
     * test cases.
     *
     * @var \Closure|null (fn(string $baseUrl, string $apiKey, array<string,string> $headers, int $timeout) : \GuzzleHttp\Client)
     */
    public static ?\Closure $clientFactory = null;

    /**
     * Fetch the live model list for `$provider` and persist it to the
     * per-provider cache.
     *
     * @param array{api_key?:string, base_url?:string, region?:string, timeout?:int} $config
     * @return list<array<string,mixed>> normalized model entries
     * @throws \RuntimeException on network / parse / unsupported provider
     */
    public static function refresh(string $provider, array $config = []): array
    {
        $apiKey = $config['api_key'] ?? self::apiKeyFromEnv($provider);
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException(
                "Cannot refresh {$provider}: no API key in config or env"
            );
        }

        $baseUrl = $config['base_url'] ?? self::baseUrlFor($provider, $config['region'] ?? null);
        $timeout = (int) ($config['timeout'] ?? 20);

        $raw = self::httpGetModels($provider, $baseUrl, $apiKey, $timeout);
        $normalized = self::normalize($provider, $raw);

        self::writeCache($provider, $normalized);

        return $normalized;
    }

    /**
     * Refresh every provider for which env credentials are available,
     * skipping ones without keys and ones that aren't supported.
     *
     * @return array<string, array{ok:bool, count?:int, error?:string}>
     */
    public static function refreshAll(int $timeout = 20): array
    {
        $results = [];
        foreach (self::supportedProviders() as $p) {
            $key = self::apiKeyFromEnv($p);
            if ($key === null || $key === '') {
                $results[$p] = ['ok' => false, 'error' => 'no API key in env'];
                continue;
            }
            try {
                $models = self::refresh($p, ['api_key' => $key, 'timeout' => $timeout]);
                $results[$p] = ['ok' => true, 'count' => count($models)];
            } catch (\Throwable $e) {
                $results[$p] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Read the cache for `$provider`, if any. Used by ModelCatalog on load.
     *
     * @return list<array<string,mixed>>
     */
    public static function readCache(string $provider): array
    {
        $path = self::cachePath($provider);
        if (! is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['models']) || ! is_array($decoded['models'])) {
            return [];
        }
        return array_values($decoded['models']);
    }

    /**
     * Delete the cache file for one or all providers.
     */
    public static function clearCache(?string $provider = null): void
    {
        if ($provider !== null) {
            @unlink(self::cachePath($provider));
            return;
        }
        $dir = self::cacheDir();
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public static function cacheDir(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/models-cache';
    }

    public static function cachePath(string $provider): string
    {
        return self::cacheDir() . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $provider) . '.json';
    }

    /**
     * @return list<string>
     */
    public static function supportedProviders(): array
    {
        return ['openai', 'openrouter', 'kimi', 'glm', 'minimax', 'qwen', 'anthropic'];
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function httpGetModels(
        string $provider,
        string $baseUrl,
        string $apiKey,
        int $timeout,
    ): array {
        $base = rtrim($baseUrl, '/') . '/';
        $headers = self::authHeadersFor($provider, $apiKey);

        $client = self::$clientFactory !== null
            ? (self::$clientFactory)($base, $apiKey, $headers, $timeout)
            : new Client([
                'base_uri' => $base,
                'timeout'  => $timeout,
                'headers'  => $headers,
            ]);

        try {
            $resp = $client->get(self::modelsPathFor($provider));
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Failed to fetch {$provider} /models: " . $e->getMessage(),
                0,
                $e,
            );
        }

        $decoded = json_decode((string) $resp->getBody(), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$provider} /models: response is not JSON");
        }
        return $decoded;
    }

    /**
     * Normalize each provider's response into a canonical shape:
     *   { id, context_length?, display_name?, capabilities?: array, _raw?: array }
     *
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function normalize(string $provider, array $raw): array
    {
        // OpenAI-compat shape (openai, openrouter, kimi, glm, minimax, qwen, anthropic):
        //   { "data": [ {id, ...}, ... ] }
        $list = $raw['data'] ?? null;
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $entry) {
            if (! is_array($entry) || ! isset($entry['id'])) {
                continue;
            }
            $id = (string) $entry['id'];
            $row = ['id' => $id];

            // Common capability / metadata fields found across providers.
            foreach ([
                'context_length'    => 'context_length',
                'context_window'    => 'context_length',
                'max_context_length'=> 'context_length',
                'display_name'      => 'display_name',
                'description'       => 'description',
                'created'           => 'created',
            ] as $src => $dst) {
                if (isset($entry[$src])) {
                    $row[$dst] = $entry[$src];
                }
            }

            // Per-provider extras. OpenRouter carries a rich `pricing`
            // object and a `supported_parameters` list we can use to
            // derive capabilities; keep them under `_raw` for now and
            // let the catalog map them on ingest.
            $row['_raw'] = $entry;

            $out[] = $row;
        }
        return $out;
    }

    private static function writeCache(string $provider, array $models): void
    {
        $dir = self::cacheDir();
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Failed to create cache dir: {$dir}");
            }
        }

        $path = self::cachePath($provider);
        $payload = json_encode([
            '_meta' => [
                'schema_version' => 1,
                'provider' => $provider,
                'refreshed_at' => gmdate('c'),
            ],
            'models' => array_values($models),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new \RuntimeException('JSON encode failed for provider cache');
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $payload) === false) {
            throw new \RuntimeException("Failed to write temp cache: {$tmp}");
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to promote cache: {$path}");
        }
        @chmod($path, 0644);
    }

    /**
     * @return array<string, string>
     */
    private static function authHeadersFor(string $provider, string $apiKey): array
    {
        return match ($provider) {
            'anthropic' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'accept' => 'application/json',
            ],
            default => [
                'Authorization' => 'Bearer ' . $apiKey,
                'accept' => 'application/json',
            ],
        };
    }

    private static function modelsPathFor(string $provider): string
    {
        return match ($provider) {
            'glm'      => 'api/paas/v4/models',
            'minimax'  => 'v1/models',
            'qwen'     => 'compatible-mode/v1/models',
            'openrouter' => 'api/v1/models',
            default    => 'v1/models',  // openai, anthropic, kimi
        };
    }

    private static function baseUrlFor(string $provider, ?string $region): string
    {
        $region ??= self::regionFromEnv($provider);
        return match ($provider) {
            'openai'     => 'https://api.openai.com',
            'anthropic'  => 'https://api.anthropic.com',
            'openrouter' => 'https://openrouter.ai',
            'kimi' => match ($region) {
                'cn' => 'https://api.moonshot.cn',
                default => 'https://api.moonshot.ai',
            },
            'glm' => match ($region) {
                'cn' => 'https://open.bigmodel.cn',
                default => 'https://api.z.ai',
            },
            'minimax' => match ($region) {
                'cn' => 'https://api.minimaxi.com',
                default => 'https://api.minimax.io',
            },
            'qwen' => match ($region) {
                'cn' => 'https://dashscope.aliyuncs.com',
                'hk' => 'https://dashscope-hk.aliyuncs.com',
                'us' => 'https://dashscope-us.aliyuncs.com',
                default => 'https://dashscope-intl.aliyuncs.com',
            },
            default => throw new \RuntimeException(
                "Unsupported provider for live catalog refresh: {$provider}"
            ),
        };
    }

    private static function apiKeyFromEnv(string $provider): ?string
    {
        $key = match ($provider) {
            'openai'     => getenv('OPENAI_API_KEY'),
            'anthropic'  => getenv('ANTHROPIC_API_KEY'),
            'openrouter' => getenv('OPENROUTER_API_KEY'),
            'kimi'       => getenv('KIMI_API_KEY') ?: getenv('MOONSHOT_API_KEY'),
            'glm'        => getenv('GLM_API_KEY') ?: getenv('ZAI_API_KEY') ?: getenv('ZHIPU_API_KEY'),
            'minimax'    => getenv('MINIMAX_API_KEY'),
            'qwen'       => getenv('QWEN_API_KEY') ?: getenv('DASHSCOPE_API_KEY'),
            default      => false,
        };
        return $key === false || $key === '' ? null : $key;
    }

    private static function regionFromEnv(string $provider): ?string
    {
        $env = match ($provider) {
            'kimi'    => getenv('KIMI_REGION'),
            'glm'     => getenv('GLM_REGION'),
            'minimax' => getenv('MINIMAX_REGION'),
            'qwen'    => getenv('QWEN_REGION'),
            default   => false,
        };
        return $env === false || $env === '' ? null : $env;
    }
}
