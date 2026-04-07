<?php

declare(strict_types=1);

namespace SuperAgent\Session;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-backed session persistence for conversation history.
 *
 * Directory layout (under $storageDir):
 *   {project_hash}/session-{id}.json   — project-scoped session snapshots
 *   {project_hash}/latest.json         — symlink/copy to most recently saved session per project
 *
 * Legacy flat layout (backward compat):
 *   session-{id}.json                  — old flat-layout sessions still readable
 *   latest.json                        — old global latest pointer
 *
 * Each snapshot contains:
 *   session_id, cwd, model, system_prompt, messages (serialized),
 *   usage, created_at, updated_at, summary, message_count, total_cost_usd
 */
class SessionManager
{
    private SessionStorage $storage;
    private SessionPruner $pruner;
    private LoggerInterface $logger;

    public function __construct(
        string $storageDir,
        ?LoggerInterface $logger = null,
        int $maxSessions = 50,
        int $pruneAfterDays = 90,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->storage = new SessionStorage($storageDir);
        $this->pruner = new SessionPruner($this->storage, $maxSessions, $pruneAfterDays, $this->logger);
    }

    /**
     * Build from config, with optional parameter overrides.
     *
     * Priority: $overrides > config > defaults.
     * Returns null only when the resolved 'enabled' is false.
     *
     * @param array $overrides  Keys: enabled, storage_path, max_sessions, prune_after_days
     */
    public static function fromConfig(?LoggerInterface $logger = null, array $overrides = []): ?self
    {
        $config = self::resolveConfig();
        $sessionsConfig = $config['sessions'] ?? [];

        // Resolve enabled: override > sessions.enabled > persistence.enabled > default(false)
        $enabled = $overrides['enabled']
            ?? $sessionsConfig['enabled']
            ?? $config['enabled']
            ?? false;

        if (!$enabled) {
            return null;
        }

        $storageDir = $overrides['storage_path']
            ?? self::resolveStorageDir($config);

        return new self(
            storageDir: rtrim($storageDir, '/') . '/sessions',
            logger: $logger,
            maxSessions: (int) ($overrides['max_sessions'] ?? $sessionsConfig['max_sessions'] ?? 50),
            pruneAfterDays: (int) ($overrides['prune_after_days'] ?? $sessionsConfig['prune_after_days'] ?? 90),
        );
    }

    // ── Project isolation ────────────────────────────────────────

    /**
     * Compute project-scoped subdirectory from CWD.
     * Format: {basename}-{sha1(cwd)[:12]}
     */
    public static function projectHash(string $cwd): string
    {
        $basename = basename($cwd);
        $hash = substr(sha1($cwd), 0, 12);
        return $basename . '-' . $hash;
    }

    // ── Save ──────────────────────────────────────────────────────

    /**
     * Save a session snapshot.
     *
     * @param string $sessionId  Unique session identifier
     * @param array  $messages   Array of serialized messages (each from Message::toArray())
     * @param array  $meta       Metadata: model, cwd, system_prompt, usage, total_cost_usd, summary
     */
    public function save(string $sessionId, array $messages, array $meta = []): void
    {
        $now = date('c');
        $cwd = $meta['cwd'] ?? (getcwd() ?: '.');

        $snapshot = [
            'session_id' => $sessionId,
            'cwd' => $cwd,
            'model' => $meta['model'] ?? null,
            'system_prompt' => $meta['system_prompt'] ?? null,
            'messages' => $messages,
            'usage' => $meta['usage'] ?? null,
            'total_cost_usd' => $meta['total_cost_usd'] ?? 0.0,
            'created_at' => $meta['created_at'] ?? $now,
            'updated_at' => $now,
            'summary' => $meta['summary'] ?? $this->extractSummary($messages),
            'message_count' => count($messages),
        ];

        // Write session file to project-scoped directory (atomic)
        $projectDir = $this->storage->getProjectDir($cwd);
        $sessionPath = $this->storage->sessionFilePath($projectDir, $sessionId);
        $this->storage->atomicWrite($sessionPath, $snapshot);

        // Write latest pointer within project dir
        $latestPath = $projectDir . '/latest.json';
        $this->storage->atomicWrite($latestPath, $snapshot);

        $this->logger->debug('Session saved', [
            'session_id' => $sessionId,
            'message_count' => count($messages),
            'project_dir' => $projectDir,
        ]);

        // Auto-prune if over limit
        $this->pruner->maybePrune($cwd);
    }

    // ── Load ──────────────────────────────────────────────────────

    /**
     * Load the most recent session snapshot.
     *
     * @return array|null The snapshot data, or null if none exists
     */
    public function loadLatest(?string $cwd = null): ?array
    {
        // If cwd provided, check project-scoped latest first
        if ($cwd !== null) {
            $projectDir = $this->storage->getProjectDir($cwd);
            $latestPath = $projectDir . '/latest.json';

            if (file_exists($latestPath)) {
                $data = $this->storage->readSnapshot($latestPath);
                if ($data !== null) {
                    return $data;
                }
            }

            // Fall back to searching project-scoped sessions
            $found = $this->findLatestByCwd($cwd);
            if ($found !== null) {
                return $found;
            }

            // Backward compat: search flat layout
            return $this->findLatestByCwdFlat($cwd);
        }

        // No cwd: find the most recently updated session across all project dirs
        $latest = null;
        $latestTime = '';

        // Check project subdirs
        foreach ($this->storage->getProjectSubdirs() as $subdir) {
            $path = $subdir . '/latest.json';
            if (file_exists($path)) {
                $data = $this->storage->readSnapshot($path);
                if ($data !== null && ($data['updated_at'] ?? '') > $latestTime) {
                    $latest = $data;
                    $latestTime = $data['updated_at'] ?? '';
                }
            }
        }

        // Also check global latest.json (backward compat)
        $globalLatestPath = $this->storage->getStorageDir() . '/latest.json';
        if (file_exists($globalLatestPath)) {
            $data = $this->storage->readSnapshot($globalLatestPath);
            if ($data !== null && ($data['updated_at'] ?? '') > $latestTime) {
                $latest = $data;
            }
        }

        return $latest;
    }

    /**
     * Load a specific session by ID.
     */
    public function loadById(string $sessionId): ?array
    {
        $safe = $this->storage->sanitizeId($sessionId);
        $filename = 'session-' . $safe . '.json';

        // Search project subdirs first
        foreach ($this->storage->getProjectSubdirs() as $subdir) {
            $path = $subdir . '/' . $filename;
            if (file_exists($path)) {
                return $this->storage->readSnapshot($path);
            }
        }

        // Backward compat: check flat layout in storageDir
        $flatPath = $this->storage->getStorageDir() . '/' . $filename;
        if (file_exists($flatPath)) {
            return $this->storage->readSnapshot($flatPath);
        }

        return null;
    }

    // ── List ──────────────────────────────────────────────────────

    /**
     * List all saved sessions, ordered by updated_at descending.
     *
     * @param int         $limit  Max number of sessions to return (0 = all)
     * @param string|null $cwd    When provided, only list sessions for this project
     * @return array[]  Array of session summaries (no messages included)
     */
    public function listSessions(int $limit = 20, ?string $cwd = null): array
    {
        $sessions = [];

        if ($cwd !== null) {
            // Scan project-scoped directory only
            $projectDir = $this->storage->getProjectDir($cwd);
            $sessions = $this->storage->scanSessionFiles($projectDir);

            // Also include backward-compat flat sessions matching this cwd
            foreach ($this->storage->scanSessionFiles($this->storage->getStorageDir()) as $summary) {
                if (($summary['cwd'] ?? '') === $cwd) {
                    // Avoid duplicates
                    $dominated = false;
                    foreach ($sessions as $existing) {
                        if ($existing['session_id'] === $summary['session_id']) {
                            $dominated = true;
                            break;
                        }
                    }
                    if (!$dominated) {
                        $sessions[] = $summary;
                    }
                }
            }
        } else {
            // Scan all project subdirs
            foreach ($this->storage->getProjectSubdirs() as $subdir) {
                $sessions = array_merge($sessions, $this->storage->scanSessionFiles($subdir));
            }

            // Also include flat-layout sessions (backward compat)
            $sessions = array_merge($sessions, $this->storage->scanSessionFiles($this->storage->getStorageDir()));

            // Deduplicate by session_id (project-scoped takes priority)
            $seen = [];
            $unique = [];
            foreach ($sessions as $s) {
                $id = $s['session_id'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $unique[] = $s;
                }
            }
            $sessions = $unique;
        }

        // Sort by updated_at descending
        usort($sessions, function ($a, $b) {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });

        if ($limit > 0 && count($sessions) > $limit) {
            $sessions = array_slice($sessions, 0, $limit);
        }

        return $sessions;
    }

    // ── Delete ─────────────────────────────────────────────────────

    /**
     * Delete a specific session. Searches across all project dirs and flat layout.
     */
    public function delete(string $sessionId): bool
    {
        $safe = $this->storage->sanitizeId($sessionId);
        $filename = 'session-' . $safe . '.json';
        $deleted = false;

        // Search project subdirs
        foreach ($this->storage->getProjectSubdirs() as $subdir) {
            $path = $subdir . '/' . $filename;
            if (file_exists($path)) {
                unlink($path);
                $deleted = true;
            }
        }

        // Also check flat layout (backward compat)
        $flatPath = $this->storage->getStorageDir() . '/' . $filename;
        if (file_exists($flatPath)) {
            unlink($flatPath);
            $deleted = true;
        }

        if ($deleted) {
            $this->logger->debug('Session deleted', ['session_id' => $sessionId]);
        }

        return $deleted;
    }

    // ── Pruning (delegated) ──────────────────────────────────────

    /**
     * Prune old sessions based on maxSessions and pruneAfterDays.
     *
     * @return int Number of pruned sessions
     */
    public function prune(?string $cwd = null): int
    {
        return $this->pruner->prune($cwd);
    }

    /**
     * Check if session exists.
     */
    public function exists(string $sessionId): bool
    {
        return $this->loadById($sessionId) !== null;
    }

    /**
     * Get the storage directory path.
     */
    public function getStorageDir(): string
    {
        return $this->storage->getStorageDir();
    }

    /**
     * Get the underlying SessionStorage instance.
     */
    public function getStorage(): SessionStorage
    {
        return $this->storage;
    }

    /**
     * Get the underlying SessionPruner instance.
     */
    public function getPruner(): SessionPruner
    {
        return $this->pruner;
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function extractSummary(array $messages): string
    {
        // Use the first user message text as summary
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $content = $msg['content'] ?? '';
                if (is_string($content)) {
                    return mb_substr($content, 0, 120);
                }
                if (is_array($content)) {
                    foreach ($content as $block) {
                        if (is_string($block)) {
                            return mb_substr($block, 0, 120);
                        }
                        if (isset($block['text'])) {
                            return mb_substr($block['text'], 0, 120);
                        }
                    }
                }
            }
        }

        return '';
    }

    private function findLatestByCwd(string $cwd): ?array
    {
        $projectDir = $this->storage->getProjectDir($cwd);
        $sessions = $this->storage->scanSessionFiles($projectDir);

        // Sort by updated_at descending
        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

        foreach ($sessions as $summary) {
            if (($summary['cwd'] ?? '') === $cwd) {
                return $this->loadById($summary['session_id']);
            }
        }

        return null;
    }

    /**
     * Backward compat: search flat layout for sessions matching CWD.
     */
    private function findLatestByCwdFlat(string $cwd): ?array
    {
        $sessions = $this->storage->scanSessionFiles($this->storage->getStorageDir());

        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

        foreach ($sessions as $summary) {
            if (($summary['cwd'] ?? '') === $cwd) {
                $safe = $this->storage->sanitizeId($summary['session_id']);
                $path = $this->storage->getStorageDir() . '/session-' . $safe . '.json';
                if (file_exists($path)) {
                    return $this->storage->readSnapshot($path);
                }
            }
        }

        return null;
    }

    private static function resolveConfig(): array
    {
        $defaults = [
            'enabled' => false,
            'storage_path' => null,
            'tasks' => ['enabled' => true, 'max_output_read_bytes' => 12000, 'prune_after_days' => 30],
            'sessions' => ['enabled' => true, 'max_sessions' => 50, 'prune_after_days' => 90],
        ];

        try {
            if (function_exists('config')) {
                $config = config('superagent.persistence', []);
                return array_replace_recursive($defaults, is_array($config) ? $config : []);
            }
        } catch (\Throwable $e) {
            // No Laravel — use defaults
        }

        return $defaults;
    }

    private static function resolveStorageDir(array $config): string
    {
        if (!empty($config['storage_path'])) {
            return rtrim($config['storage_path'], '/');
        }

        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME') ?: '/tmp';
        return $home . '/.superagent';
    }
}
