<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * Envelope encryption for credential files.
 *
 * Uses AES-256-GCM (authenticated encryption) so tampering is detected. The
 * key is derived in this priority order:
 *
 *   1. SUPERAGENT_CREDENTIAL_KEY env var (hex or base64; ≥32 bytes of entropy)
 *   2. A persistent machine-local key at ~/.superagent/credentials/.key (mode 0600,
 *      generated once from CSPRNG on first use)
 *
 * Choice #2 is the default: at-rest encryption that survives reboots but doesn't
 * require the user to remember a password. An attacker who reads the credential
 * JSON alone cannot decrypt it; they would also need .key. Since .key sits in
 * the same directory, threat model = "stolen credentials.json copy" (email
 * attachment, log, backup), not "full disk compromise" — the latter is out of
 * scope for any local key-storage scheme. For defense-in-depth, set
 * SUPERAGENT_CREDENTIAL_KEY to a value stored outside the FS (keychain, vault).
 *
 * Ciphertext format (single line, base64-wrapped):
 *
 *   SAENC1:<base64(nonce(12) || tag(16) || ciphertext)>
 *
 * Plain-JSON files are still read (auto-migrated on next write).
 */
class CredentialCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const MAGIC = 'SAENC1:';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    public function __construct(
        private readonly string $keyFilePath,
    ) {}

    public static function isEncrypted(string $blob): bool
    {
        return str_starts_with(ltrim($blob), self::MAGIC);
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->resolveKey();
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );
        if ($ct === false) {
            throw new AuthenticationException('Credential encryption failed: ' . openssl_error_string());
        }
        return self::MAGIC . base64_encode($nonce . $tag . $ct);
    }

    public function decrypt(string $blob): string
    {
        $blob = trim($blob);
        if (! self::isEncrypted($blob)) {
            throw new AuthenticationException('Not an encrypted credential blob');
        }
        $raw = base64_decode(substr($blob, strlen(self::MAGIC)), true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN + 1) {
            throw new AuthenticationException('Credential blob truncated or corrupt');
        }
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $ct = substr($raw, self::NONCE_LEN + self::TAG_LEN);

        $key = $this->resolveKey();
        $pt = openssl_decrypt(
            $ct,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
        );
        if ($pt === false) {
            throw new AuthenticationException(
                'Credential decryption failed — wrong key or tampered data. '
                . 'If you moved machines, re-run `superagent auth login`.'
            );
        }
        return $pt;
    }

    /**
     * Load or create the machine-local 32-byte key.
     * Respects SUPERAGENT_CREDENTIAL_KEY as an override.
     */
    private function resolveKey(): string
    {
        $env = getenv('SUPERAGENT_CREDENTIAL_KEY') ?: ($_SERVER['SUPERAGENT_CREDENTIAL_KEY'] ?? '');
        if (is_string($env) && $env !== '') {
            $decoded = $this->decodeUserKey($env);
            if (strlen($decoded) < 32) {
                throw new AuthenticationException(
                    'SUPERAGENT_CREDENTIAL_KEY must decode to at least 32 bytes'
                );
            }
            return substr(hash('sha256', $decoded, true), 0, 32);
        }

        if (is_file($this->keyFilePath)) {
            $hex = @file_get_contents($this->keyFilePath);
            if ($hex !== false) {
                $bin = hex2bin(trim($hex));
                if ($bin !== false && strlen($bin) === 32) {
                    return $bin;
                }
            }
        }

        $key = random_bytes(32);
        $dir = dirname($this->keyFilePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $this->keyFilePath . '.tmp.' . getmypid();
        if (file_put_contents($tmp, bin2hex($key)) === false) {
            throw new AuthenticationException('Unable to persist credential key at ' . $this->keyFilePath);
        }
        @chmod($tmp, 0600);
        if (! @rename($tmp, $this->keyFilePath)) {
            @unlink($tmp);
            throw new AuthenticationException('Unable to finalize credential key at ' . $this->keyFilePath);
        }
        return $key;
    }

    private function decodeUserKey(string $raw): string
    {
        $raw = trim($raw);
        // hex?
        if (preg_match('/^[0-9a-fA-F]+$/', $raw) && strlen($raw) % 2 === 0) {
            $bin = @hex2bin($raw);
            if ($bin !== false) {
                return $bin;
            }
        }
        // base64?
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
        return $raw;
    }
}
