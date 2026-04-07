<?php

namespace SuperAgent\Auth;

/**
 * File-based credential storage for API keys and OAuth tokens.
 * Stores in ~/.superagent/credentials/
 */
class CredentialStore
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? ($_SERVER['HOME'] ?? getenv('HOME')) . '/.superagent/credentials';
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
        $json = file_get_contents($path);
        return json_decode($json, true) ?: [];
    }

    private function writeFile(string $path, array $data): void
    {
        // Atomic write with restrictive permissions
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
