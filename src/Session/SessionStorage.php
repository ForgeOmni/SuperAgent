<?php

declare(strict_types=1);

namespace SuperAgent\Session;

/**
 * Low-level file I/O for session snapshots.
 *
 * Handles atomic writes, reading/decoding JSON snapshots,
 * scanning directories for session files, and path resolution.
 */
class SessionStorage
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
        $this->ensureDirectory($this->storageDir);
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    // ── Project isolation ────────────────────────────────────────

    /**
     * Get the session directory for a given CWD.
     */
    public function getProjectDir(?string $cwd = null): string
    {
        if ($cwd === null) {
            return $this->storageDir;
        }
        $dir = $this->storageDir . '/' . SessionManager::projectHash($cwd);
        $this->ensureDirectory($dir);
        return $dir;
    }

    /**
     * Get all project subdirectories under storageDir.
     */
    public function getProjectSubdirs(): array
    {
        $dirs = [];
        foreach (glob($this->storageDir . '/*', GLOB_ONLYDIR) as $dir) {
            $dirs[] = $dir;
        }
        return $dirs;
    }

    // ── Read / Write ─────────────────────────────────────────────

    /**
     * Atomically write a snapshot to disk (temp + rename).
     */
    public function atomicWrite(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmpPath = $path . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $json);
        rename($tmpPath, $path);
    }

    /**
     * Read and decode a JSON snapshot file.
     */
    public function readSnapshot(string $path): ?array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Scan a single directory for session-*.json files and return summaries.
     */
    public function scanSessionFiles(string $dir): array
    {
        $sessions = [];
        $pattern = $dir . '/session-*.json';

        foreach (glob($pattern) as $path) {
            $data = $this->readSnapshot($path);
            if ($data === null || !isset($data['session_id'])) {
                continue;
            }

            $sessions[] = [
                'session_id' => $data['session_id'],
                'cwd' => $data['cwd'] ?? null,
                'model' => $data['model'] ?? null,
                'summary' => $data['summary'] ?? null,
                'message_count' => $data['message_count'] ?? 0,
                'total_cost_usd' => $data['total_cost_usd'] ?? 0.0,
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ];
        }

        return $sessions;
    }

    // ── Path helpers ─────────────────────────────────────────────

    /**
     * Sanitize a session ID for use in filenames.
     */
    public function sanitizeId(string $sessionId): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
    }

    /**
     * Build the session file path within a directory.
     */
    public function sessionFilePath(string $dir, string $sessionId): string
    {
        return $dir . '/session-' . $this->sanitizeId($sessionId) . '.json';
    }

    /**
     * Ensure a directory exists.
     */
    public function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
