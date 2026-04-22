<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Helpers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Tests\Helpers\ProviderMockHelper;

/**
 * Smoke test for the shared `ProviderMockHelper`. The helper is meant
 * to replace the hand-rolled MockHandler boilerplate in every provider
 * test — this test pins its contract (history-by-reference works, the
 * injected client actually handles responses) so the helper stays
 * trustworthy as more tests depend on it.
 */
class ProviderMockHelperTest extends TestCase
{
    public function test_injects_mock_client_and_captures_history(): void
    {
        $provider = new KimiProvider(['api_key' => 'sk-test']);
        $history = [];

        ProviderMockHelper::injectMockClient(
            $provider,
            [new Response(200, [], json_encode(['id' => 'file_xyz']))],
            $history,
            'https://api.moonshot.ai/',
        );

        // Drive a simple POST to verify both: (a) the injected client is
        // what the provider uses, (b) the history middleware captured it.
        $client = $this->getClient($provider);
        $response = $client->post('v1/files', ['json' => ['purpose' => 'batch']]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $history);
        $this->assertStringEndsWith('v1/files', $history[0]['request']->getUri()->getPath());
    }

    public function test_throws_when_provider_has_no_client_property(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/client.*property/');

        $obj = new class {
            public string $name = 'x';
        };
        $history = [];
        ProviderMockHelper::injectMockClient($obj, [], $history, 'https://example.test/');
    }

    private function getClient(object $provider): \GuzzleHttp\Client
    {
        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        return $prop->getValue($provider);
    }
}
