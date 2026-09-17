<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Session\Contracts\SessionStore;
use SuperAgent\Session\SessionManager;
use SuperAgent\Session\SqliteSessionStorage;
use SuperAgent\Support\RuntimeState;
use SuperAgent\Telemetry\CostTracker;

/**
 * Two tenants, one process (1.5.0).
 *
 * Everything static in this SDK was written for a CLI: one process, one
 * person, and the process exits when they are done. A queue worker breaks
 * that assumption, and these tests are the ones that fail when a new static
 * starts remembering the last tenant.
 */
class MultiTenantRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeState::resetPerTenant();
        ProviderRegistry::setMaxCachedInstances(32);
    }

    protected function tearDown(): void
    {
        RuntimeState::resetPerTenant();
        ProviderRegistry::setMaxCachedInstances(32);
    }

    public function test_two_tenants_never_share_a_provider_instance(): void
    {
        $a = ProviderRegistry::get('anthropic', ['api_key' => 'sk-tenant-a']);
        $b = ProviderRegistry::get('anthropic', ['api_key' => 'sk-tenant-b']);

        $this->assertNotSame($a, $b, 'a cached instance carries the key it was built with');
        $this->assertSame($a, ProviderRegistry::get('anthropic', ['api_key' => 'sk-tenant-a']));
    }

    public function test_the_instance_cache_is_bounded(): void
    {
        ProviderRegistry::setMaxCachedInstances(3);

        for ($i = 0; $i < 10; $i++) {
            ProviderRegistry::get('anthropic', ['api_key' => "sk-tenant-{$i}"]);
        }

        $this->assertLessThanOrEqual(3, ProviderRegistry::cachedInstanceCount());
    }

    public function test_reset_clears_every_credential_holding_instance(): void
    {
        ProviderRegistry::get('anthropic', ['api_key' => 'sk-tenant-a']);
        $this->assertGreaterThan(0, ProviderRegistry::cachedInstanceCount());

        RuntimeState::resetPerTenant();

        $this->assertSame(0, ProviderRegistry::cachedInstanceCount());
    }

    public function test_accumulating_singletons_are_emptied_between_tenants(): void
    {
        // The cost tracker is a process-wide singleton whose summary answers
        // for the process, not for the tenant who asked — so whatever one
        // tenant's turn accumulated has to be gone before the next starts.
        // (Tracking itself is off unless a host enables telemetry; what is
        // asserted here is that the accumulator is emptied either way.)
        $tracker = CostTracker::getInstance();
        $tracker->trackLLMUsage('claude-sonnet-4-6', 1000, 1000, 'tenant-a-session');

        $reflection = new \ReflectionProperty(CostTracker::class, 'costs');
        $reflection->getValue($tracker)->push(['cost_usd' => 1.23, 'session_id' => 'tenant-a-session']);

        // Deliberately not an exact count: whether the trackLLMUsage() call
        // above also recorded depends on whether some earlier test in the
        // same process enabled telemetry — which is the very thing this test
        // is about.
        $this->assertGreaterThan(0, count($reflection->getValue($tracker)));

        RuntimeState::resetPerTenant();

        $this->assertCount(0, $reflection->getValue(CostTracker::getInstance()));
    }

    public function test_a_credential_resolver_is_called_once_per_agent_and_not_stored(): void
    {
        $calls = 0;

        $agent = new Agent([
            'provider' => 'anthropic',
            'api_key' => function () use (&$calls): string {
                $calls++;

                return 'sk-from-vault';
            },
            'load_tools' => 'none',
        ]);

        $this->assertSame(1, $calls, 'one vault call per agent, not one per read of the config');
        $this->assertInstanceOf(\SuperAgent\Providers\AnthropicProvider::class, $agent->getProvider());
    }

    public function test_a_resolver_that_returns_nothing_usable_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        new Agent([
            'provider' => 'anthropic',
            'api_key' => fn () => '',
            'load_tools' => 'none',
        ]);
    }

    public function test_the_inventory_lists_what_reset_actually_clears(): void
    {
        $inventory = RuntimeState::inventory();

        $this->assertNotEmpty($inventory['cleared']);
        $this->assertNotEmpty($inventory['kept']);

        // Every class named as cleared must exist — the inventory is a
        // promise a host reads, not a comment.
        foreach ($inventory['cleared'] as $entry) {
            [$class] = explode('::', $entry);
            $this->assertTrue(class_exists($class), "{$class} in the cleared inventory does not exist");
        }

        foreach (array_keys($inventory['kept']) as $class) {
            $this->assertTrue(class_exists($class), "{$class} in the kept inventory does not exist");
        }
    }

    public function test_a_host_store_replaces_the_local_sqlite_file(): void
    {
        $dir = sys_get_temp_dir() . '/superagent_tenant_' . bin2hex(random_bytes(4));

        $store = new class implements SessionStore {
            public array $saved = [];

            public function save(string $sessionId, array $snapshot): void
            {
                $this->saved[$sessionId] = $snapshot;
            }

            public function load(string $sessionId): ?array
            {
                return $this->saved[$sessionId] ?? null;
            }

            public function loadLatest(?string $cwd = null): ?array
            {
                return $this->saved === [] ? null : end($this->saved);
            }

            public function listSessions(int $limit = 20, ?string $cwd = null): array
            {
                return array_values($this->saved);
            }

            public function search(string $query, int $limit = 10): array
            {
                return [];
            }

            public function delete(string $sessionId): bool
            {
                unset($this->saved[$sessionId]);

                return true;
            }

            public function prune(int $maxSessions = 50, int $pruneAfterDays = 90, ?string $cwd = null): int
            {
                return 0;
            }

            public function count(?string $cwd = null): int
            {
                return count($this->saved);
            }
        };

        $manager = new SessionManager($dir, null, 50, 90, $store);

        $this->assertSame($store, $manager->getSessionStore());
        $this->assertNull(
            $manager->getSqliteStorage(),
            'the bundled SQLite file must not be opened when a host brought its own store'
        );
        $this->assertFileDoesNotExist($dir . '/sessions.db');

        // And the default is unchanged for a caller that injects nothing.
        $default = new SessionManager($dir . '-default');
        $this->assertInstanceOf(SqliteSessionStorage::class, $default->getSessionStore());

        $this->removeDir($dir);
        $this->removeDir($dir . '-default');
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
