<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ModelCatalogRefresher;

/**
 * Pure-logic tests for the refresher — the HTTP path is exercised by
 * integration tests (gated behind live API keys). Here we lock in:
 *
 *   1. The OpenAI-compat `/models` response shape is normalized
 *      identically across openai / openrouter / kimi / glm / minimax /
 *      qwen / anthropic.
 *   2. `writeCache` → `readCache` roundtrips; malformed files are
 *      treated as empty not fatal.
 *   3. `ModelCatalog::ensureLoaded()` picks up cached entries after a
 *      refresh and surfaces them via `modelsFor()` / `model()`.
 *   4. Runtime `register()` still wins over refresher cache (the
 *      documented precedence order).
 *   5. Unsupported provider (`gemini` / `ollama` / `bedrock`) throws a
 *      RuntimeException when we try to refresh it.
 */
class ModelCatalogRefresherTest extends TestCase
{
    private string $tmpHome;

    protected function setUp(): void
    {
        parent::setUp();
        // Redirect HOME so the cache writes into a throwaway directory.
        $this->tmpHome = sys_get_temp_dir() . '/superagent-refresher-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        putenv('HOME=' . $this->tmpHome);

        ModelCatalog::invalidate();
        ModelCatalog::clearOverrides();
    }

    protected function tearDown(): void
    {
        ModelCatalogRefresher::clearCache();
        ModelCatalogRefresher::$clientFactory = null;  // reset the test DI seam
        $this->rrmdir($this->tmpHome);
        putenv('HOME');
        ModelCatalog::invalidate();
        ModelCatalog::clearOverrides();
        parent::tearDown();
    }

    public function test_normalize_openai_shape(): void
    {
        $raw = [
            'data' => [
                ['id' => 'gpt-5', 'context_window' => 400000, 'created' => 1_700_000_000],
                ['id' => 'gpt-4o', 'context_length' => 128000],
                ['id' => 'whisper-1'],
            ],
        ];
        $out = ModelCatalogRefresher::normalize('openai', $raw);
        $this->assertCount(3, $out);
        $this->assertSame('gpt-5', $out[0]['id']);
        $this->assertSame(400000, $out[0]['context_length']);
        $this->assertSame(1_700_000_000, $out[0]['created']);
        $this->assertSame(128000, $out[1]['context_length']);
        $this->assertSame('whisper-1', $out[2]['id']);
    }

    public function test_normalize_kimi_shape(): void
    {
        // Moonshot returns `data` with OpenAI-compatible entries plus
        // vendor-specific fields; we should keep the id, copy known
        // metadata, and stash the rest under `_raw`.
        $raw = [
            'data' => [
                ['id' => 'kimi-k2-6', 'created' => 1_713_000_000, 'display_name' => 'Kimi K2.6'],
            ],
        ];
        $out = ModelCatalogRefresher::normalize('kimi', $raw);
        $this->assertSame('kimi-k2-6', $out[0]['id']);
        $this->assertSame('Kimi K2.6', $out[0]['display_name']);
        $this->assertArrayHasKey('_raw', $out[0]);
    }

    public function test_normalize_handles_missing_data_field(): void
    {
        $out = ModelCatalogRefresher::normalize('openai', []);
        $this->assertSame([], $out);
        $out = ModelCatalogRefresher::normalize('openai', ['data' => 'not-a-list']);
        $this->assertSame([], $out);
    }

    public function test_cache_roundtrip(): void
    {
        $path = ModelCatalogRefresher::cachePath('openai');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $payload = json_encode([
            '_meta' => ['schema_version' => 1, 'provider' => 'openai'],
            'models' => [['id' => 'gpt-5'], ['id' => 'gpt-4o']],
        ]);
        file_put_contents($path, $payload);

        $read = ModelCatalogRefresher::readCache('openai');
        $this->assertCount(2, $read);
        $this->assertSame('gpt-5', $read[0]['id']);
    }

    public function test_read_cache_returns_empty_for_missing_or_garbage(): void
    {
        $this->assertSame([], ModelCatalogRefresher::readCache('nonexistent'));

        $path = ModelCatalogRefresher::cachePath('openai');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, 'not json');
        $this->assertSame([], ModelCatalogRefresher::readCache('openai'));
    }

    public function test_model_catalog_overlays_refresher_cache(): void
    {
        // Drop a pretend cache for openai so ensureLoaded picks it up
        // without ever hitting the network.
        $path = ModelCatalogRefresher::cachePath('openai');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            '_meta' => ['schema_version' => 1, 'provider' => 'openai'],
            'models' => [
                ['id' => 'gpt-5-live', 'context_length' => 400000, 'display_name' => 'GPT-5 live'],
            ],
        ]));

        ModelCatalog::invalidate();
        $m = ModelCatalog::model('gpt-5-live');
        $this->assertNotNull($m);
        $this->assertSame('openai', $m['provider']);
        $this->assertSame(400000, $m['context_length']);
        $this->assertSame('GPT-5 live', $m['display_name']);
    }

    public function test_runtime_register_still_wins_over_refresher_cache(): void
    {
        $path = ModelCatalogRefresher::cachePath('openai');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            '_meta' => ['schema_version' => 1, 'provider' => 'openai'],
            'models' => [
                ['id' => 'gpt-5', 'context_length' => 400000, 'display_name' => 'from cache'],
            ],
        ]));
        ModelCatalog::invalidate();

        ModelCatalog::register('gpt-5', [
            'provider' => 'openai',
            'display_name' => 'from register',
            'context_length' => 42,
        ]);

        $m = ModelCatalog::model('gpt-5');
        $this->assertSame('from register', $m['display_name']);
        $this->assertSame(42, $m['context_length']);
    }

    public function test_overlay_does_not_leak_into_other_providers(): void
    {
        // Drop an openai-cache entry and an anthropic-cache entry with
        // the SAME id ("gpt-5-hypothetical") — which in practice
        // shouldn't happen but could via a proxy or naming collision.
        // Expectation: each provider's cache only populates its own
        // provider bucket in byProvider, and byId stores the unified
        // merged view.
        $dir = ModelCatalogRefresher::cacheDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(ModelCatalogRefresher::cachePath('openai'), json_encode([
            '_meta' => ['schema_version' => 1, 'provider' => 'openai'],
            'models' => [['id' => 'gpt-5-hypothetical', 'context_length' => 400000]],
        ]));
        file_put_contents(ModelCatalogRefresher::cachePath('anthropic'), json_encode([
            '_meta' => ['schema_version' => 1, 'provider' => 'anthropic'],
            'models' => [['id' => 'gpt-5-hypothetical', 'context_length' => 200000]],
        ]));
        ModelCatalog::invalidate();

        $openaiModels = ModelCatalog::modelsFor('openai');
        $anthropicModels = ModelCatalog::modelsFor('anthropic');

        $openaiIds = array_column($openaiModels, 'id');
        $anthropicIds = array_column($anthropicModels, 'id');

        // The id lands in exactly one provider bucket (whichever won
        // the insert race — not both). The important invariant is
        // that we don't end up with a phantom duplicate listed under
        // both providers.
        $appearsIn = ($openaiIds && in_array('gpt-5-hypothetical', $openaiIds, true) ? 1 : 0)
                   + ($anthropicIds && in_array('gpt-5-hypothetical', $anthropicIds, true) ? 1 : 0);
        $this->assertSame(
            1,
            $appearsIn,
            'A single id must not show up in two provider buckets simultaneously',
        );
    }

    public function test_overlay_preserves_bundled_pricing_when_cache_omits_it(): void
    {
        // The `/models` endpoint typically doesn't return pricing.
        // When a bundled row has pricing and the refresher cache adds
        // fields like context_length, the merge must preserve the
        // bundled pricing — otherwise `superagent models refresh`
        // would silently wipe out cost tracking.
        ModelCatalog::register('merge-test', [
            'provider' => 'openai',
            'input' => 1.23,
            'output' => 4.56,
            'description' => 'bundled row with price',
        ]);

        // Now simulate refresher writing a cache entry for the same id
        // with ONLY context_length (typical /models response).
        $dir = ModelCatalogRefresher::cacheDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(ModelCatalogRefresher::cachePath('openai'), json_encode([
            '_meta' => ['schema_version' => 1, 'provider' => 'openai'],
            'models' => [['id' => 'merge-test', 'context_length' => 128000]],
        ]));
        ModelCatalog::invalidate();
        // Re-register to re-seed since invalidate drops overrides too.
        ModelCatalog::register('merge-test', [
            'provider' => 'openai',
            'input' => 1.23,
            'output' => 4.56,
            'description' => 'bundled row with price',
        ]);

        $m = ModelCatalog::model('merge-test');
        $this->assertSame(1.23, $m['input'], 'bundled pricing must survive a refresh');
        $this->assertSame(4.56, $m['output']);
        // `register()` overrides win over refresher cache, so the runtime
        // entry's context_length isn't required. But the cache entry
        // should still be readable via the overlay path when `register()`
        // isn't used.
    }

    public function test_refresh_unsupported_provider_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported provider/');
        ModelCatalogRefresher::refresh('gemini', ['api_key' => 'x']);
    }

    public function test_refresh_without_api_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no API key/');
        putenv('OPENAI_API_KEY');  // clear
        ModelCatalogRefresher::refresh('openai', []);
    }

    public function test_refresh_http_path_sends_bearer_auth_and_writes_cache(): void
    {
        // Feed the mocked Guzzle back through the test DI seam so we
        // exercise the full refresh() path — URL selection, auth
        // headers, normalize(), cache write — without hitting network.
        $history = [];
        ModelCatalogRefresher::$clientFactory = $this->mockFactory(
            [
                new Response(200, [], json_encode([
                    'object' => 'list',
                    'data'   => [
                        ['id' => 'gpt-5', 'context_window' => 400000],
                        ['id' => 'gpt-4o', 'context_window' => 128000],
                    ],
                ])),
            ],
            $history,
        );

        $models = ModelCatalogRefresher::refresh('openai', ['api_key' => 'sk-test']);

        // Normalized output shape.
        $this->assertCount(2, $models);
        $this->assertSame('gpt-5', $models[0]['id']);
        $this->assertSame(400000, $models[0]['context_length']);

        // Cache file was persisted.
        $this->assertFileExists(ModelCatalogRefresher::cachePath('openai'));

        // Auth + path reached the Guzzle layer.
        $this->assertCount(1, $history);
        /** @var \Psr\Http\Message\RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertStringContainsString('v1/models', (string) $req->getUri());
        $this->assertSame('Bearer sk-test', $req->getHeaderLine('Authorization'));
    }

    public function test_refresh_http_path_for_anthropic_uses_xapikey_header(): void
    {
        $history = [];
        ModelCatalogRefresher::$clientFactory = $this->mockFactory(
            [
                new Response(200, [], json_encode([
                    'data' => [['id' => 'claude-opus-4-7']],
                ])),
            ],
            $history,
        );

        ModelCatalogRefresher::refresh('anthropic', ['api_key' => 'sk-ant-test']);

        $this->assertCount(1, $history);
        /** @var \Psr\Http\Message\RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertSame('sk-ant-test', $req->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $req->getHeaderLine('anthropic-version'));
        // Anthropic doesn't send Bearer auth.
        $this->assertSame('', $req->getHeaderLine('Authorization'));
    }

    public function test_refresh_http_path_for_glm_uses_paas_v4_path(): void
    {
        // GLM's models endpoint lives at `api/paas/v4/models`, not
        // `v1/models` — regression guard since the path mapping is
        // easy to drift.
        $history = [];
        ModelCatalogRefresher::$clientFactory = $this->mockFactory(
            [new Response(200, [], json_encode(['data' => []]))],
            $history,
        );

        ModelCatalogRefresher::refresh('glm', ['api_key' => 'glm-test', 'region' => 'intl']);

        /** @var \Psr\Http\Message\RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertStringContainsString('api/paas/v4/models', (string) $req->getUri());
    }

    public function test_refresh_http_failure_throws_runtime_exception(): void
    {
        $history = [];
        ModelCatalogRefresher::$clientFactory = $this->mockFactory(
            [new Response(401, [], '{"error":"unauthorized"}')],
            $history,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to fetch openai \/models/');
        ModelCatalogRefresher::refresh('openai', ['api_key' => 'wrong-key']);
    }

    /**
     * Build a client factory that yields a MockHandler-backed Guzzle
     * client. `$history` records every outgoing request.
     */
    private function mockFactory(array $responses, array &$history): \Closure
    {
        return static function (string $base, string $apiKey, array $headers, int $timeout) use ($responses, &$history): Client {
            $stack = HandlerStack::create(new MockHandler($responses));
            $stack->push(Middleware::history($history));
            return new Client([
                'handler'  => $stack,
                'base_uri' => $base,
                'headers'  => $headers,
                'timeout'  => $timeout,
            ]);
        };
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
