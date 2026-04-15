<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\AuthenticationException;
use SuperAgent\Auth\CredentialCipher;

/**
 * Unit tests for CredentialCipher — AES-256-GCM envelope encryption
 * used to protect ~/.superagent/credentials/*.json at rest.
 */
class CredentialCipherTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/superagent_cipher_' . getmypid() . '_' . mt_rand();
        mkdir($this->tmpDir, 0700, true);
        $this->keyPath = $this->tmpDir . '/.key';
    }

    protected function tearDown(): void
    {
        putenv('SUPERAGENT_CREDENTIAL_KEY');
        unset($_SERVER['SUPERAGENT_CREDENTIAL_KEY']);
        if (is_dir($this->tmpDir)) {
            foreach (glob("{$this->tmpDir}/*") as $f) @unlink($f);
            foreach (glob("{$this->tmpDir}/.*") as $f) {
                if (is_file($f)) @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function test_round_trip_restores_plaintext(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $blob = $c->encrypt('{"access_token":"secret"}');
        $this->assertStringStartsWith('SAENC1:', $blob);
        $this->assertSame('{"access_token":"secret"}', $c->decrypt($blob));
    }

    public function test_ciphertext_hides_plaintext_bytes(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $plain = 'sk-ant-oat01-CONFIDENTIAL-TOKEN-MATERIAL-0123456789';
        $blob = $c->encrypt($plain);
        $this->assertStringNotContainsString($plain, $blob);
        $this->assertStringNotContainsString('sk-ant', $blob);
    }

    public function test_is_encrypted_detects_magic(): void
    {
        $this->assertTrue(CredentialCipher::isEncrypted('SAENC1:abc=='));
        $this->assertTrue(CredentialCipher::isEncrypted("  SAENC1:abc==\n"));
        $this->assertFalse(CredentialCipher::isEncrypted('{"access_token":"x"}'));
        $this->assertFalse(CredentialCipher::isEncrypted(''));
    }

    public function test_decrypting_plaintext_throws(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $this->expectException(AuthenticationException::class);
        $c->decrypt('{"foo":"bar"}');
    }

    public function test_tampered_ciphertext_fails(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $blob = $c->encrypt('sensitive');
        // Flip last two base64 chars to corrupt the tag/ciphertext.
        $bad = substr($blob, 0, -2) . 'AA';

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/wrong key or tampered/');
        $c->decrypt($bad);
    }

    public function test_truncated_blob_fails(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $blob = $c->encrypt('hello');
        $short = substr($blob, 0, 12);

        $this->expectException(AuthenticationException::class);
        $c->decrypt($short);
    }

    public function test_key_is_persisted_and_reused(): void
    {
        $a = new CredentialCipher($this->keyPath);
        $blob = $a->encrypt('same-key');
        $this->assertFileExists($this->keyPath);

        $b = new CredentialCipher($this->keyPath);
        $this->assertSame('same-key', $b->decrypt($blob));
    }

    public function test_key_file_is_0600_on_unix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod semantics differ on Windows');
        }
        $c = new CredentialCipher($this->keyPath);
        $c->encrypt('data');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->keyPath)), -4));
    }

    public function test_key_file_contains_64_hex_chars(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $c->encrypt('data');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim((string) file_get_contents($this->keyPath)));
    }

    public function test_env_key_overrides_disk_key_hex(): void
    {
        $envKeyHex = str_repeat('ab', 32); // 64 hex chars = 32 bytes
        putenv("SUPERAGENT_CREDENTIAL_KEY={$envKeyHex}");

        $c = new CredentialCipher($this->keyPath);
        $blob = $c->encrypt('payload');
        $this->assertFileDoesNotExist($this->keyPath, 'env key should bypass on-disk key creation');
        $this->assertSame('payload', $c->decrypt($blob));
    }

    public function test_env_key_overrides_disk_key_base64(): void
    {
        $raw = random_bytes(32);
        $b64 = base64_encode($raw);
        putenv("SUPERAGENT_CREDENTIAL_KEY={$b64}");

        $c = new CredentialCipher($this->keyPath);
        $blob = $c->encrypt('payload');
        $this->assertSame('payload', $c->decrypt($blob));
    }

    public function test_env_key_too_short_throws(): void
    {
        putenv('SUPERAGENT_CREDENTIAL_KEY=aabbcc'); // 3 bytes decoded

        $c = new CredentialCipher($this->keyPath);
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/32 bytes/');
        $c->encrypt('x');
    }

    public function test_different_nonces_for_same_plaintext(): void
    {
        $c = new CredentialCipher($this->keyPath);
        $a = $c->encrypt('same');
        $b = $c->encrypt('same');
        $this->assertNotSame($a, $b, 'AES-GCM must use a unique nonce per message');
    }
}
