<?php

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\CredentialPool;

class CredentialPoolTest extends TestCase
{
    public function test_get_key_returns_null_for_unknown_provider(): void
    {
        $pool = new CredentialPool();
        $this->assertNull($pool->getKey('unknown'));
    }

    public function test_fill_first_strategy(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1', 'fill_first');
        $pool->addCredential('anthropic', 'key-2', 'fill_first');

        // fill_first always returns the first available key
        $this->assertEquals('key-1', $pool->getKey('anthropic'));
        $this->assertEquals('key-1', $pool->getKey('anthropic'));
    }

    public function test_round_robin_strategy(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1', 'round_robin');
        $pool->addCredential('anthropic', 'key-2', 'round_robin');

        $first = $pool->getKey('anthropic');
        $second = $pool->getKey('anthropic');

        // Round robin should alternate
        $this->assertNotEquals($first, $second);
    }

    public function test_rate_limit_cooldown(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1', 'fill_first', cooldown429: 3600);
        $pool->addCredential('anthropic', 'key-2', 'fill_first', cooldown429: 3600);

        // Rate limit key-1
        $pool->reportRateLimit('anthropic', 'key-1');

        // Should now get key-2
        $key = $pool->getKey('anthropic');
        $this->assertEquals('key-2', $key);
    }

    public function test_exhausted_credential_not_returned(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');
        $pool->addCredential('anthropic', 'key-2');

        $pool->reportExhausted('anthropic', 'key-1');

        $key = $pool->getKey('anthropic');
        $this->assertEquals('key-2', $key);
    }

    public function test_all_exhausted_returns_null(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');

        $pool->reportExhausted('anthropic', 'key-1');

        $this->assertNull($pool->getKey('anthropic'));
    }

    public function test_success_tracking(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');

        $pool->reportSuccess('anthropic', 'key-1');
        $pool->reportSuccess('anthropic', 'key-1');

        $stats = $pool->getStats('anthropic');
        $this->assertEquals(1, $stats['total']);
        $this->assertEquals(1, $stats['ok']);
    }

    public function test_from_config(): void
    {
        $pool = CredentialPool::fromConfig([
            'anthropic' => [
                'strategy' => 'round_robin',
                'keys' => ['key-1', 'key-2', 'key-3'],
                'cooldown_429' => 1800,
            ],
            'openai' => [
                'keys' => ['oai-1'],
            ],
        ]);

        $this->assertContains('anthropic', $pool->getProviders());
        $this->assertContains('openai', $pool->getProviders());

        $stats = $pool->getStats('anthropic');
        $this->assertEquals(3, $stats['total']);
    }

    public function test_get_stats_with_cooldown(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');
        $pool->addCredential('anthropic', 'key-2');

        $pool->reportRateLimit('anthropic', 'key-1');

        $stats = $pool->getStats('anthropic');
        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['ok']);
        $this->assertEquals(1, $stats['cooldown']);
    }

    public function test_least_used_strategy(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1', 'least_used');
        $pool->addCredential('anthropic', 'key-2', 'least_used');

        // Use key-1 multiple times
        $pool->getKey('anthropic'); // key-1 (both at 0, picks first)
        $pool->getKey('anthropic'); // key-2 (key-1 at 1, key-2 at 0)

        $third = $pool->getKey('anthropic'); // Should pick the one with fewer uses
        $this->assertNotNull($third);
    }
}
