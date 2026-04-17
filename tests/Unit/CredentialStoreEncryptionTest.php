<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\AuthenticationException;
use SuperAgent\Auth\CredentialStore;

/**
 * Integration tests for the encrypted `CredentialStore` write + read path,
 * legacy-plaintext migration, tamper detection, and the encryption-disabled
 * escape hatch used by tests / debugging.
 */
class CredentialStoreEncryptionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/superagent_store_' . getmypid() . '_' . mt_rand();
        mkdir($this->dir, 0700, true);
        putenv('SUPERAGENT_CREDENTIAL_ENCRYPTION');
    }

    protected function tearDown(): void
    {
        putenv('SUPERAGENT_CREDENTIAL_ENCRYPTION');
        $this->rmrf($this->dir);
    }

    public function test_store_writes_encrypted_blob(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'access_token', 'sk-ant-oat01-SECRET');

        $raw = (string) file_get_contents($this->dir . '/anthropic.json');
        $this->assertStringStartsWith('SAENC1:', $raw);
        $this->assertStringNotContainsString('sk-ant-oat01-SECRET', $raw);
    }

    public function test_round_trip(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'access_token', 'sk-ant-oat01-TOKEN');
        $store->store('anthropic', 'refresh_token', 'sk-ant-ort01-REFRESH');
        $store->store('anthropic', 'auth_mode', 'oauth');

        $this->assertSame('sk-ant-oat01-TOKEN', $store->get('anthropic', 'access_token'));
        $this->assertSame('sk-ant-ort01-REFRESH', $store->get('anthropic', 'refresh_token'));
        $this->assertSame('oauth', $store->get('anthropic', 'auth_mode'));
    }

    public function test_legacy_plaintext_file_is_read_transparently(): void
    {
        // Simulate a file left over from v0.8.6 (pre-encryption).
        file_put_contents(
            $this->dir . '/anthropic.json',
            json_encode(['access_token' => 'legacy-token', 'auth_mode' => 'oauth'], JSON_PRETTY_PRINT),
        );

        $store = new CredentialStore($this->dir);
        $this->assertSame('legacy-token', $store->get('anthropic', 'access_token'));
        $this->assertSame('oauth', $store->get('anthropic', 'auth_mode'));
    }

    public function test_legacy_file_is_migrated_to_ciphertext_on_next_write(): void
    {
        file_put_contents(
            $this->dir . '/anthropic.json',
            json_encode(['access_token' => 'legacy-token'], JSON_PRETTY_PRINT),
        );

        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'refresh_token', 'new-refresh');

        $raw = (string) file_get_contents($this->dir . '/anthropic.json');
        $this->assertStringStartsWith('SAENC1:', $raw, 'legacy plaintext should be migrated on write');
        $this->assertStringNotContainsString('legacy-token', $raw);

        $reopen = new CredentialStore($this->dir);
        $this->assertSame('legacy-token', $reopen->get('anthropic', 'access_token'));
        $this->assertSame('new-refresh', $reopen->get('anthropic', 'refresh_token'));
    }

    public function test_tampered_file_raises_authentication_exception(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'access_token', 'secret');

        // Flip last byte of ciphertext.
        $path = $this->dir . '/anthropic.json';
        $raw = (string) file_get_contents($path);
        file_put_contents($path, substr($raw, 0, -2) . 'AA');

        $fresh = new CredentialStore($this->dir);
        $this->expectException(AuthenticationException::class);
        $fresh->get('anthropic', 'access_token');
    }

    public function test_delete_provider_removes_file(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'access_token', 'x');
        $this->assertFileExists($this->dir . '/anthropic.json');

        $store->delete('anthropic');
        $this->assertFileDoesNotExist($this->dir . '/anthropic.json');
    }

    public function test_delete_single_key_preserves_others(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'access_token', 'a');
        $store->store('anthropic', 'refresh_token', 'b');

        $store->delete('anthropic', 'access_token');
        $this->assertNull($store->get('anthropic', 'access_token'));
        $this->assertSame('b', $store->get('anthropic', 'refresh_token'));
    }

    public function test_list_providers_returns_file_stems(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'k', 'v');
        $store->store('openai', 'k', 'v');

        $providers = $store->listProviders();
        sort($providers);
        $this->assertSame(['anthropic', 'openai'], $providers);
    }

    public function test_encryption_disabled_writes_plaintext(): void
    {
        $store = new CredentialStore($this->dir, null, encryptionEnabled: false);
        $store->store('anthropic', 'access_token', 'visible');

        $raw = (string) file_get_contents($this->dir . '/anthropic.json');
        $this->assertStringContainsString('"access_token"', $raw);
        $this->assertStringContainsString('visible', $raw);
        $this->assertStringNotContainsString('SAENC1:', $raw);
    }

    public function test_env_var_disables_encryption(): void
    {
        putenv('SUPERAGENT_CREDENTIAL_ENCRYPTION=0');
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'k', 'plain');
        $raw = (string) file_get_contents($this->dir . '/anthropic.json');
        $this->assertStringNotContainsString('SAENC1:', $raw);
    }

    public function test_missing_provider_returns_null(): void
    {
        $store = new CredentialStore($this->dir);
        $this->assertNull($store->get('anthropic', 'missing'));
        $this->assertFalse($store->has('anthropic', 'missing'));
    }

    public function test_has_returns_true_after_store(): void
    {
        $store = new CredentialStore($this->dir);
        $store->store('anthropic', 'access_token', 'x');
        $this->assertTrue($store->has('anthropic', 'access_token'));
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (glob($path . '/*') as $f) @unlink($f);
        foreach (glob($path . '/.*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($path);
    }
}
