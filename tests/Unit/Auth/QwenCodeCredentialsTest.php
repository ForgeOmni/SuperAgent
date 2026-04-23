<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\QwenCodeCredentials;

class QwenCodeCredentialsTest extends TestCase
{
    private string $tmpHome;
    private CredentialStore $store;
    private QwenCodeCredentials $creds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-qwen-code-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        $this->store = new CredentialStore(
            baseDir: $this->tmpHome . '/.superagent/credentials',
            encryptionEnabled: false,
        );
        $this->creds = new QwenCodeCredentials($this->store);
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

    public function test_save_then_load_roundtrip_including_resource_url(): void
    {
        $this->creds->save([
            'access_token'  => 'tok-abc',
            'refresh_token' => 'ref-def',
            'expires_at'    => 1_800_000_000,
            'resource_url'  => 'https://portal.qwen.ai/v1',
            'scopes'        => ['openid', 'profile'],
        ]);

        $loaded = $this->creds->load();
        $this->assertNotNull($loaded);
        $this->assertSame('tok-abc', $loaded['access_token']);
        $this->assertSame('ref-def', $loaded['refresh_token']);
        $this->assertSame(1_800_000_000, $loaded['expires_at']);
        $this->assertSame('https://portal.qwen.ai/v1', $loaded['resource_url']);
        $this->assertSame(['openid', 'profile'], $loaded['scopes']);
    }

    public function test_is_expired_true_when_no_credentials(): void
    {
        $this->assertTrue($this->creds->isExpired());
    }

    public function test_is_expired_false_when_expiry_unknown(): void
    {
        $this->creds->save(['access_token' => 'tok', 'expires_at' => null]);
        $this->assertFalse($this->creds->isExpired());
    }

    public function test_is_expired_within_skew_window(): void
    {
        $this->creds->save(['access_token' => 'tok', 'expires_at' => time() + 30]);
        $this->assertTrue($this->creds->isExpired(60));
        $this->assertFalse($this->creds->isExpired(10));
    }

    public function test_delete_clears_credentials(): void
    {
        $this->creds->save(['access_token' => 'tok']);
        $this->assertNotNull($this->creds->load());
        $this->creds->delete();
        $this->assertNull($this->creds->load());
    }

    public function test_current_access_token_returns_live_token_when_not_expired(): void
    {
        $this->creds->save(['access_token' => 'fresh', 'expires_at' => time() + 3600]);
        $this->assertSame('fresh', $this->creds->currentAccessToken());
    }

    public function test_current_access_token_returns_null_when_no_credentials(): void
    {
        $this->assertNull($this->creds->currentAccessToken());
    }

    public function test_host_defaults_to_chat_qwen_ai(): void
    {
        putenv('QWEN_OAUTH_HOST');
        putenv('QWEN_CODE_OAUTH_HOST');
        $this->assertSame('https://chat.qwen.ai', $this->creds->host());
    }

    public function test_host_respects_env_override(): void
    {
        putenv('QWEN_OAUTH_HOST=https://auth.test.example');
        try {
            $this->assertSame('https://auth.test.example', $this->creds->host());
        } finally {
            putenv('QWEN_OAUTH_HOST');
        }
    }

    public function test_client_id_matches_alibaba_public_id(): void
    {
        // Source: qwen-code packages/core/src/qwen/qwenOAuth2.ts.
        // If Alibaba rotates it, the login flow breaks silently — this
        // test makes that regression loud.
        $this->assertSame(
            'f0304373b74a44d2b584a3fb70ca9e56',
            QwenCodeCredentials::CLIENT_ID,
        );
    }

    public function test_default_scope_matches_qwen_code(): void
    {
        // qwen-code's QwenOAuth2Client.requestDeviceAuthorization uses
        // this exact scope string. Reproducing here so we're unlikely
        // to drift.
        $this->assertSame(
            'openid profile email model.completion',
            QwenCodeCredentials::DEFAULT_SCOPE,
        );
    }

    public function test_resource_url_returns_normalized_https(): void
    {
        // Alibaba's token response may return the URL without a scheme.
        // QwenProvider needs an absolute URL it can feed Guzzle.
        $this->creds->save([
            'access_token' => 'tok',
            'resource_url' => 'portal.qwen.ai/v1/compatible-mode/v1/',
        ]);
        $this->assertSame('https://portal.qwen.ai/v1/compatible-mode/v1', $this->creds->resourceUrl());
    }

    public function test_resource_url_returns_null_when_absent(): void
    {
        $this->creds->save(['access_token' => 'tok']);
        $this->assertNull($this->creds->resourceUrl());
    }

    public function test_resource_url_preserves_existing_scheme(): void
    {
        $this->creds->save([
            'access_token' => 'tok',
            'resource_url' => 'https://account.example/v1/',
        ]);
        $this->assertSame('https://account.example/v1', $this->creds->resourceUrl());
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
