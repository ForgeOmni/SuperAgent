<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\KimiCodeCredentials;

class KimiCodeCredentialsTest extends TestCase
{
    private string $tmpHome;
    private CredentialStore $store;
    private KimiCodeCredentials $creds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-kimi-code-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        // Target the test home instead of the real user's.
        $this->store = new CredentialStore(
            baseDir: $this->tmpHome . '/.superagent/credentials',
            encryptionEnabled: false,   // don't leak the user's encryption key into the test
        );
        $this->creds = new KimiCodeCredentials($this->store);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpHome);
        parent::tearDown();
    }

    public function test_load_when_no_credentials_returns_null(): void
    {
        $this->assertNull($this->creds->load());
    }

    public function test_save_then_load_roundtrip(): void
    {
        $this->creds->save([
            'access_token'  => 'tok-abc',
            'refresh_token' => 'ref-def',
            'expires_at'    => 1_800_000_000,
            'scopes'        => ['read', 'write'],
        ]);

        $loaded = $this->creds->load();
        $this->assertNotNull($loaded);
        $this->assertSame('tok-abc', $loaded['access_token']);
        $this->assertSame('ref-def', $loaded['refresh_token']);
        $this->assertSame(1_800_000_000, $loaded['expires_at']);
        $this->assertSame(['read', 'write'], $loaded['scopes']);
    }

    public function test_is_expired_returns_true_when_no_credentials(): void
    {
        $this->assertTrue($this->creds->isExpired());
    }

    public function test_is_expired_returns_false_when_no_expiry_known(): void
    {
        // Match ClaudeCodeCredentials: missing expiry = "assume valid".
        $this->creds->save(['access_token' => 'tok', 'expires_at' => null]);
        $this->assertFalse($this->creds->isExpired());
    }

    public function test_is_expired_within_skew_window(): void
    {
        // Token that expires in 30 seconds, skew=60s → treat as expired.
        $this->creds->save(['access_token' => 'tok', 'expires_at' => time() + 30]);
        $this->assertTrue($this->creds->isExpired(60));
        // But not expired under a 10s skew.
        $this->assertFalse($this->creds->isExpired(10));
    }

    public function test_delete_clears_credentials(): void
    {
        $this->creds->save(['access_token' => 'tok', 'refresh_token' => 'ref']);
        $this->assertNotNull($this->creds->load());
        $this->creds->delete();
        $this->assertNull($this->creds->load());
    }

    public function test_current_access_token_returns_live_token_when_not_expired(): void
    {
        $this->creds->save(['access_token' => 'fresh-tok', 'expires_at' => time() + 3600]);
        $this->assertSame('fresh-tok', $this->creds->currentAccessToken());
    }

    public function test_current_access_token_returns_null_when_no_credentials(): void
    {
        $this->assertNull($this->creds->currentAccessToken());
    }

    public function test_host_defaults_to_auth_kimi_com(): void
    {
        putenv('KIMI_CODE_OAUTH_HOST');
        putenv('KIMI_OAUTH_HOST');
        $this->assertSame('https://auth.kimi.com', $this->creds->host());
    }

    public function test_host_respects_env_override(): void
    {
        putenv('KIMI_CODE_OAUTH_HOST=https://auth.test.example');
        try {
            $this->assertSame('https://auth.test.example', $this->creds->host());
        } finally {
            putenv('KIMI_CODE_OAUTH_HOST');
        }
    }

    public function test_client_id_matches_moonshot_public_id(): void
    {
        // Sanity: keep the constant in sync with Moonshot's published
        // Kimi Code client id. If they rotate it, the login flow will
        // break silently; this test makes that regression loud.
        $this->assertSame(
            '17e5f671-d194-4dfb-9706-5516cb48c098',
            KimiCodeCredentials::CLIENT_ID,
        );
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
