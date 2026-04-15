<?php

namespace SuperAgent\Auth;

/**
 * File-based credential storage for API keys and OAuth tokens.
 *
 * Files live under ~/.superagent/credentials/ with mode 0600 (owner-read+write only).
 * Since v0.8.7, each file is encrypted at rest with AES-256-GCM via CredentialCipher.
 * Plaintext files left over from older versions are read transparently and
 * auto-migrated to encrypted form on the next write.
 *
 * Set SUPERAGENT_CREDENTIAL_KEY in the environment to override the on-disk key.
 */
class CredentialStore
{
    private string $baseDir;
    private ?CredentialCipher $cipher = null;
    private bool $encryptionEnabled;

    public function __construct(?string $baseDir = null, ?CredentialCipher $cipher = null, ?bool $encryptionEnabled = null)
    {
        if ($baseDir !== null) {
            $this->baseDir = $baseDir;
        } else {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
            if (! $home && PHP_OS_FAMILY === 'Windows') {
                $home = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? '');
            }
            $this->baseDir = rtrim((string) $home, "\\/") . '/.superagent/credentials';
        }

        // Allow opting out via env (tests, debugging) but default ON.
        // NB: use `!== false` instead of `?: ''` because '0' is falsy in PHP.
        if ($encryptionEnabled !== null) {
            $this->encryptionEnabled = $encryptionEnabled;
        } else {
            $envValue = getenv('SUPERAGENT_CREDENTIAL_ENCRYPTION');
            $this->encryptionEnabled = ! ($envValue !== false
                && in_array(strtolower($envValue), ['0', 'false', 'off'], true));
        }

        if ($this->encryptionEnabled) {
            $this->cipher = $cipher ?? new CredentialCipher($this->baseDir . '/.key');
        }
    }

    public function store(string $provider, string $key, string $value): void
    {
        $dir = $this->baseDir;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $path = "{$dir}/{$provider}.json";
        $data = $this->loadFile($path);
        $data[$key] = $value;
        $this->writeFile($path, $data);
    }

    public function get(string $provider, string $key): ?string
    {
        $path = "{$this->baseDir}/{$provider}.json";
        $data = $this->loadFile($path);
        return $data[$key] ?? null;
    }

    public function delete(string $provider, ?string $key = null): void
    {
        $path = "{$this->baseDir}/{$provider}.json";
        if ($key === null) {
            @unlink($path);
            return;
        }
        $data = $this->loadFile($path);
        unset($data[$key]);
        $this->writeFile($path, $data);
    }

    public function has(string $provider, string $key): bool
    {
        return $this->get($provider, $key) !== null;
    }

    public function listProviders(): array
    {
        if (!is_dir($this->baseDir)) return [];
        $providers = [];
        foreach (glob("{$this->baseDir}/*.json") as $file) {
            $providers[] = basename($file, '.json');
        }
        return $providers;
    }

    private function loadFile(string $path): array
    {
        if (!file_exists($path)) return [];
        $raw = file_get_contents($path);
        if ($raw === false) return [];

        if ($this->cipher !== null && CredentialCipher::isEncrypted($raw)) {
            try {
                $raw = $this->cipher->decrypt($raw);
            } catch (AuthenticationException $e) {
                // Surface decryption errors instead of silently returning empty data —
                // that would look like "logged out" but the user's credentials still
                // sit on disk. Prompt them to re-login or fix the key.
                throw $e;
            }
        }
        // else: treat as plaintext JSON (legacy file or encryption disabled).

        return json_decode($raw, true) ?: [];
    }

    private function writeFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $blob = $this->cipher !== null ? $this->cipher->encrypt($json) : $json;

        // Atomic write with restrictive permissions
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, $blob);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
