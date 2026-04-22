<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Compat;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\GeminiProvider;
use SuperAgent\Providers\OllamaProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\OpenRouterProvider;

/**
 * Lockdown: each shipped provider, when constructed without an explicit
 * `base_url`, must point at its historical default host. If future work
 * (region routing, capability router) accidentally changes the default,
 * every user's existing config would silently start hitting a different
 * endpoint — this test catches that.
 */
class ProviderDefaultsLockdownTest extends TestCase
{
    /**
     * @dataProvider providerDefaultHostProvider
     */
    public function test_provider_default_base_uri_is_fixed(string $class, array $config, string $expectedHost): void
    {
        $provider = new $class($config);
        $host = $this->extractBaseHost($provider);
        $this->assertSame($expectedHost, $host);
    }

    /**
     * @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string}>
     */
    public static function providerDefaultHostProvider(): array
    {
        return [
            'anthropic' => [
                AnthropicProvider::class,
                ['api_key' => 'sk-test'],
                'api.anthropic.com',
            ],
            'openai' => [
                OpenAIProvider::class,
                ['api_key' => 'sk-test'],
                'api.openai.com',
            ],
            'openrouter' => [
                OpenRouterProvider::class,
                ['api_key' => 'sk-test'],
                'openrouter.ai',
            ],
            'ollama' => [
                OllamaProvider::class,
                [],
                'localhost',
            ],
            'gemini' => [
                GeminiProvider::class,
                ['api_key' => 'key-test'],
                'generativelanguage.googleapis.com',
            ],
        ];
    }

    /**
     * Pull the Guzzle client's `base_uri` host out of the provider.
     *
     * Providers store the Guzzle Client on a protected `$client` property.
     * We reflect in so this compat test never has to modify production code
     * to expose internals.
     */
    private function extractBaseHost(object $provider): string
    {
        $ref = new \ReflectionObject($provider);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        /** @var Client $client */
        $client = $prop->getValue($provider);

        // Guzzle 7 deprecated getConfig() but still returns the base_uri.
        // We accept the deprecation here because this is a test-only path.
        $uri = $client->getConfig('base_uri');
        $this->assertNotNull($uri, 'Client must have a base_uri configured');

        $host = parse_url((string) $uri, PHP_URL_HOST);
        $this->assertIsString($host);
        return $host;
    }
}
