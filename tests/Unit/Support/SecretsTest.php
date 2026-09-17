<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SuperAgent\Support\Secrets;
use SuperAgent\Swarm\AgentSpawnConfig;

/**
 * Credentials must not ride along in anything that leaves the process (1.5.0).
 */
class SecretsTest extends TestCase
{
    public function test_redacts_secret_looking_keys_at_any_depth(): void
    {
        $redacted = Secrets::redact([
            'provider' => 'anthropic',
            'api_key' => 'sk-live-123',
            'nested' => [
                'access_token' => 'at-456',
                'base_url' => 'https://api.anthropic.com',
                'deeper' => ['X-Api-Key' => 'k'],
            ],
        ]);

        $this->assertSame('anthropic', $redacted['provider']);
        $this->assertSame(Secrets::REDACTED, $redacted['api_key']);
        $this->assertSame(Secrets::REDACTED, $redacted['nested']['access_token']);
        $this->assertSame(Secrets::REDACTED, $redacted['nested']['deeper']['X-Api-Key']);
        $this->assertSame('https://api.anthropic.com', $redacted['nested']['base_url']);
    }

    public function test_vendor_prefixed_key_names_are_covered(): void
    {
        foreach (['ANTHROPIC_API_KEY', 'openai_api_key', 'x-api-key', 'Authorization', 'client_secret'] as $key) {
            $this->assertTrue(Secrets::isSecretKey($key), "{$key} should be treated as a secret");
        }

        foreach (['model', 'base_url', 'max_tokens', 'provider'] as $key) {
            $this->assertFalse(Secrets::isSecretKey($key), "{$key} is not a secret");
        }
    }

    public function test_empty_values_stay_empty_so_missing_and_hidden_are_distinguishable(): void
    {
        $redacted = Secrets::redact(['api_key' => '', 'access_token' => null]);

        $this->assertSame('', $redacted['api_key']);
        $this->assertNull($redacted['access_token']);
    }

    public function test_a_spawn_config_does_not_serialise_the_parent_key(): void
    {
        // This array is what gets logged, traced and sent over a wire.
        $config = new AgentSpawnConfig(
            name: 'child',
            prompt: 'do the thing',
            providerConfig: ['provider' => 'anthropic', 'api_key' => 'sk-live-123', 'model' => 'claude'],
        );

        $serialised = json_encode($config->toArray());

        $this->assertStringNotContainsString('sk-live-123', (string) $serialised);
        $this->assertStringContainsString('anthropic', (string) $serialised);

        // And the one path that has to authenticate a child still can.
        $this->assertSame('sk-live-123', $config->toArrayWithCredentials()['provider_config']['api_key']);
    }

    public function test_fingerprint_shows_only_the_tail(): void
    {
        $this->assertSame('…f123', Secrets::fingerprint('sk-live-abcdef123'));
        $this->assertSame(Secrets::REDACTED, Secrets::fingerprint('abc'));
        $this->assertSame(Secrets::REDACTED, Secrets::fingerprint(null));
    }
}
