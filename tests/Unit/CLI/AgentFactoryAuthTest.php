<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\CLI\AgentFactory;

/**
 * Tests `AgentFactory::resolveStoredAuth()` — the glue that reads from
 * CredentialStore and injects OAuth/api_key material into provider config
 * before constructing an Agent.
 */
class AgentFactoryAuthTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/superagent_factory_' . getmypid() . '_' . mt_rand();
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') as $f) @unlink($f);
            foreach (glob($this->dir . '/.*') as $f) {
                if (is_file($f)) @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    public function test_resolve_returns_empty_when_no_store(): void
    {
        $this->assertSame([], $this->invokeResolve('anthropic'));
    }

    public function test_resolve_unknown_provider_returns_empty(): void
    {
        $this->writeCred('anthropic', ['auth_mode' => 'oauth', 'access_token' => 't']);
        $this->assertSame([], $this->invokeResolve('bedrock'));
    }

    public function test_resolve_oauth_for_anthropic(): void
    {
        // Expires well in the future so no refresh is attempted.
        $this->writeCred('anthropic', [
            'auth_mode' => 'oauth',
            'access_token' => 'sk-ant-oat01-FRESH',
            'refresh_token' => 'sk-ant-ort01-R',
            'expires_at' => (string) ((time() + 3600) * 1000),
        ]);

        $resolved = $this->invokeResolve('anthropic');
        $this->assertSame('oauth', $resolved['auth_mode']);
        $this->assertSame('sk-ant-oat01-FRESH', $resolved['access_token']);
    }

    public function test_resolve_api_key_mode(): void
    {
        $this->writeCred('openai', [
            'auth_mode' => 'api_key',
            'api_key' => 'sk-codex-key',
        ]);

        $resolved = $this->invokeResolve('openai');
        $this->assertSame('api_key', $resolved['auth_mode']);
        $this->assertSame('sk-codex-key', $resolved['api_key']);
    }

    public function test_resolve_forwards_openai_account_id(): void
    {
        $this->writeCred('openai', [
            'auth_mode' => 'oauth',
            'access_token' => 'eyJ.X',
            'account_id' => 'acct_123',
        ]);

        $resolved = $this->invokeResolve('openai');
        $this->assertSame('acct_123', $resolved['account_id']);
    }

    public function test_resolve_ignores_entries_without_auth_mode(): void
    {
        // Store a malformed entry with only a leftover stray key.
        $this->writeCred('anthropic', ['source' => 'claude-code']);
        $this->assertSame([], $this->invokeResolve('anthropic'));
    }

    public function test_resolve_ignores_oauth_without_token(): void
    {
        $this->writeCred('anthropic', ['auth_mode' => 'oauth']);
        $this->assertSame([], $this->invokeResolve('anthropic'));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function writeCred(string $provider, array $fields): void
    {
        $store = new CredentialStore($this->dir);
        foreach ($fields as $k => $v) {
            $store->store($provider, $k, (string) $v);
        }
    }

    private function invokeResolve(string $provider): array
    {
        // Drive AgentFactory's resolveStoredAuth against our temp dir. It reads
        // via `new CredentialStore()` so we swap HOME to this test dir's parent.
        $home = dirname($this->dir);
        $fakeHome = $home . '/fakehome_' . mt_rand();
        @mkdir($fakeHome . '/.superagent/credentials', 0700, true);
        // Copy the credential files into the expected location
        foreach (glob($this->dir . '/*.json') as $src) {
            copy($src, $fakeHome . '/.superagent/credentials/' . basename($src));
        }
        foreach (glob($this->dir . '/.key') as $src) {
            copy($src, $fakeHome . '/.superagent/credentials/.key');
        }

        $origHome = getenv('HOME');
        $origUserProfile = getenv('USERPROFILE');
        putenv("HOME={$fakeHome}");
        if (PHP_OS_FAMILY === 'Windows') {
            putenv("USERPROFILE={$fakeHome}");
        }
        // Also override $_SERVER because CredentialStore checks it first.
        $savedServerHome = $_SERVER['HOME'] ?? null;
        $savedServerUser = $_SERVER['USERPROFILE'] ?? null;
        $_SERVER['HOME'] = $fakeHome;
        if (PHP_OS_FAMILY === 'Windows') {
            $_SERVER['USERPROFILE'] = $fakeHome;
        }

        try {
            $factory = new AgentFactory();
            $ref = new \ReflectionMethod($factory, 'resolveStoredAuth');
            $ref->setAccessible(true);
            return $ref->invoke($factory, $provider);
        } finally {
            putenv('HOME' . ($origHome === false ? '' : '=' . $origHome));
            putenv('USERPROFILE' . ($origUserProfile === false ? '' : '=' . $origUserProfile));
            if ($savedServerHome !== null) $_SERVER['HOME'] = $savedServerHome; else unset($_SERVER['HOME']);
            if ($savedServerUser !== null) $_SERVER['USERPROFILE'] = $savedServerUser; else unset($_SERVER['USERPROFILE']);

            // Clean up fake home dir.
            $this->rmrf($fakeHome);
        }
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (glob($path . '/*') as $f) {
            is_dir($f) ? $this->rmrf($f) : @unlink($f);
        }
        foreach (glob($path . '/.*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($path);
    }
}
