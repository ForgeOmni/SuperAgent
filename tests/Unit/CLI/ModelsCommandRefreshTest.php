<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\Commands\ModelsCommand;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ModelCatalogRefresher;

/**
 * Pins the CLI glue between `superagent models refresh [<provider>]`
 * and `ModelCatalogRefresher`. The library itself has dedicated
 * tests — this file focuses on the command dispatch + exit codes +
 * user-facing output so a regression in the CLI wrapper doesn't
 * silently break the feature.
 */
class ModelsCommandRefreshTest extends TestCase
{
    private string $tmpHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-models-cli-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        putenv('HOME=' . $this->tmpHome);

        // Scrub ambient provider keys so "refresh without env credentials"
        // tests actually see an empty environment. The dev machine or CI
        // may have some of these set.
        foreach ([
            'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENROUTER_API_KEY',
            'KIMI_API_KEY', 'MOONSHOT_API_KEY', 'GLM_API_KEY', 'ZAI_API_KEY',
            'ZHIPU_API_KEY', 'MINIMAX_API_KEY', 'QWEN_API_KEY', 'DASHSCOPE_API_KEY',
        ] as $k) {
            putenv($k);
        }

        ModelCatalog::invalidate();
        ModelCatalog::clearOverrides();
        ModelCatalogRefresher::clearCache();
    }

    protected function tearDown(): void
    {
        ModelCatalogRefresher::$clientFactory = null;
        ModelCatalogRefresher::clearCache();
        putenv('OPENAI_API_KEY');
        $this->rrmdir($this->tmpHome);
        putenv('HOME');
        ModelCatalog::invalidate();
        ModelCatalog::clearOverrides();
        parent::tearDown();
    }

    public function test_refresh_single_provider_writes_cache_and_exits_zero(): void
    {
        putenv('OPENAI_API_KEY=sk-test');
        ModelCatalogRefresher::$clientFactory = $this->mockFactory([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'gpt-5', 'context_window' => 400000],
                    ['id' => 'gpt-4o', 'context_window' => 128000],
                ],
            ])),
        ]);

        ob_start();
        $code = (new ModelsCommand())->execute(['models_args' => ['refresh', 'openai']]);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Cached 2 openai models', $out);
        $this->assertFileExists(ModelCatalogRefresher::cachePath('openai'));
    }

    public function test_refresh_unknown_provider_returns_1(): void
    {
        ob_start();
        $code = (new ModelsCommand())->execute([
            'models_args' => ['refresh', 'gemini'],  // unsupported for live refresh
        ]);
        $out = ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refresh failed', $out);
    }

    public function test_refresh_all_providers_reports_per_provider_status(): void
    {
        // Only OPENAI_API_KEY set — every other provider should be
        // skipped with a "no API key in env" message, and refresh_all
        // returns 0 as long as at least one succeeded.
        putenv('OPENAI_API_KEY=sk-test');
        ModelCatalogRefresher::$clientFactory = $this->mockFactory([
            new Response(200, [], json_encode([
                'data' => [['id' => 'gpt-5']],
            ])),
        ]);

        ob_start();
        $code = (new ModelsCommand())->execute(['models_args' => ['refresh']]);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('openai', $out);
        $this->assertStringContainsString('skipped', $out);  // other providers
        $this->assertStringContainsString('1 ok', $out);
    }

    public function test_refresh_all_with_no_api_keys_anywhere_returns_1(): void
    {
        // Clear every known provider env var — not exhaustive but
        // covers the ones refreshAll() probes.
        foreach ([
            'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENROUTER_API_KEY',
            'KIMI_API_KEY', 'MOONSHOT_API_KEY', 'GLM_API_KEY', 'ZAI_API_KEY',
            'ZHIPU_API_KEY', 'MINIMAX_API_KEY', 'QWEN_API_KEY', 'DASHSCOPE_API_KEY',
        ] as $k) {
            putenv($k);
        }

        ob_start();
        $code = (new ModelsCommand())->execute(['models_args' => ['refresh']]);
        $out = ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('0 ok', $out);
    }

    public function test_usage_mentions_refresh_subcommand(): void
    {
        ob_start();
        $code = (new ModelsCommand())->execute(['models_args' => ['nonexistent']]);
        $out = ob_get_clean();

        $this->assertSame(2, $code);
        $this->assertStringContainsString('models refresh', $out);
    }

    private function mockFactory(array $responses): \Closure
    {
        return static function (string $base, string $apiKey, array $headers, int $timeout) use ($responses): Client {
            $stack = HandlerStack::create(new MockHandler($responses));
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
