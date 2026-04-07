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
    private string $storageDir;
    private LoggerInterface $logger;
    private int $maxSessions;
    private int $pruneAfterDays;

    public function __construct(
        string $storageDir,
        ?LoggerInterface $logger = null,
        int $maxSessions = 50,
        int $pruneAfterDays = 90,
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->logger = $logger ?? new NullLogger();
        $this->maxSessions = $maxSessions;
        $this->pruneAfterDays = $pruneAfterDays;

        $this->ensureDirectory();
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

    /**
     * Get the session directory for a given CWD.
     */
    private function getProjectDir(?string $cwd = null): string
    {
        if ($cwd === null) {
            return $this->storageDir; // fallback for global operations
        }
        $dir = $this->storageDir . '/' . self::projectHash($cwd);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
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
        $projectDir = $this->getProjectDir($cwd);
        $sessionPath = $projectDir . '/session-' . $this->sanitizeId($sessionId) . '.json';
        $this->atomicWrite($sessionPath, $snapshot);

        // Write latest pointer within project dir
        $latestPath = $projectDir . '/latest.json';
        $this->atomicWrite($latestPath, $snapshot);

        $this->logger->debug('Session saved', [
            'session_id' => $sessionId,
            'message_count' => count($messages),
            'project_dir' => $projectDir,
        ]);

        // Auto-prune if over limit
        $this->maybePrune($cwd);
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
            $projectDir = $this->getProjectDir($cwd);
            $latestPath = $projectDir . '/latest.json';

            if (file_exists($latestPath)) {
                $data = $this->readSnapshot($latestPath);
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
        foreach ($this->getProjectSubdirs() as $subdir) {
            $path = $subdir . '/latest.json';
            if (file_exists($path)) {
                $data = $this->readSnapshot($path);
                if ($data !== null && ($data['updated_at'] ?? '') > $latestTime) {
                    $latest = $data;
                    $latestTime = $data['updated_at'] ?? '';
                }
            }
        }

        // Also check global latest.json (backward compat)
        $globalLatestPath = $this->storageDir . '/latest.json';
        if (file_exists($globalLatestPath)) {
            $data = $this->readSnapshot($globalLatestPath);
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
        $safe = $this->sanitizeId($sessionId);
        $filename = 'session-' . $safe . '.json';

        // Search project subdirs first
        foreach ($this->getProjectSubdirs() as $subdir) {
            $path = $subdir . '/' . $filename;
            if (file_exists($path)) {
                return $this->readSnapshot($path);
            }
        }

        // Backward compat: check flat layout in storageDir
        $flatPath = $this->storageDir . '/' . $filename;
        if (file_exists($flatPath)) {
            return $this->readSnapshot($flatPath);
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
            $projectDir = $this->getProjectDir($cwd);
            $sessions = $this->scanSessionFiles($projectDir);

            // Also include backward-compat flat sessions matching this cwd
            foreach ($this->scanSessionFiles($this->storageDir) as $summary) {
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
            foreach ($this->getProjectSubdirs() as $subdir) {
                $sessions = array_merge($sessions, $this->scanSessionFiles($subdir));
            }

            // Also include flat-layout sessions (backward compat)
            $sessions = array_merge($sessions, $this->scanSessionFiles($this->storageDir));

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
        $safe = $this->sanitizeId($sessionId);
        $filename = 'session-' . $safe . '.json';
        $deleted = false;

        // Search project subdirs
        foreach ($this->getProjectSubdirs() as $subdir) {
            $path = $subdir . '/' . $filename;
            if (file_exists($path)) {
                unlink($path);
                $deleted = true;
            }
        }

        // Also check flat layout (backward compat)
        $flatPath = $this->storageDir . '/' . $filename;
        if (file_exists($flatPath)) {
            unlink($flatPath);
            $deleted = true;
        }

        if ($deleted) {
            $this->logger->debug('Session deleted', ['session_id' => $sessionId]);
        }

        return $deleted;
    }

    // ── Pruning ───────────────────────────────────────────────────

    /**
     * Prune old sessions based on maxSessions and pruneAfterDays.
     * When cwd is provided, prune only that project dir.
     * When null, prune each project dir independently plus the flat layout.
     *
     * @return int Number of pruned sessions
     */
    public function prune(?string $cwd = null): int
    {
        $pruned = 0;

        if ($cwd !== null) {
            $pruned += $this->pruneDir($this->getProjectDir($cwd));
        } else {
            // Prune each project subdir
            foreach ($this->getProjectSubdirs() as $subdir) {
                $pruned += $this->pruneDir($subdir);
            }
            // Also prune flat layout (backward compat)
            $pruned += $this->pruneDir($this->storageDir);
        }

        if ($pruned > 0) {
            $this->logger->info('Pruned old sessions', ['count' => $pruned]);
        }

        return $pruned;
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
        return $this->storageDir;
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function sanitizeId(string $sessionId): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
    }

    /**
     * Old flat-layout session path (for backward compatibility reads).
     */
    private function sessionPath(string $sessionId): string
    {
        $safe = $this->sanitizeId($sessionId);
        return $this->storageDir . '/session-' . $safe . '.json';
    }

    private function readSnapshot(string $path): ?array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function atomicWrite(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmpPath = $path . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $json);
        rename($tmpPath, $path);
    }

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

    /**
     * Scan a single directory for session-*.json files and return summaries.
     */
    private function scanSessionFiles(string $dir): array
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

    /**
     * Get all project subdirectories under storageDir.
     */
    private function getProjectSubdirs(): array
    {
        $dirs = [];
        foreach (glob($this->storageDir . '/*', GLOB_ONLYDIR) as $dir) {
            $dirs[] = $dir;
        }
        return $dirs;
    }

    private function findLatestByCwd(string $cwd): ?array
    {
        $projectDir = $this->getProjectDir($cwd);
        $sessions = $this->scanSessionFiles($projectDir);

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
        $sessions = $this->scanSessionFiles($this->storageDir);

        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

        foreach ($sessions as $summary) {
            if (($summary['cwd'] ?? '') === $cwd) {
                $safe = $this->sanitizeId($summary['session_id']);
                $path = $this->storageDir . '/session-' . $safe . '.json';
                if (file_exists($path)) {
                    return $this->readSnapshot($path);
                }
            }
        }

        return null;
    }

    /**
     * Prune sessions in a specific directory.
     */
    private function pruneDir(string $dir): int
    {
        $sessions = $this->scanSessionFiles($dir);

        // Sort newest first
        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

        $pruned = 0;

        // Prune by age
        if ($this->pruneAfterDays > 0) {
            $cutoff = date('c', time() - ($this->pruneAfterDays * 86400));
            foreach ($sessions as $session) {
                if (($session['updated_at'] ?? '') < $cutoff) {
                    $safe = $this->sanitizeId($session['session_id']);
                    $path = $dir . '/session-' . $safe . '.json';
                    if (file_exists($path)) {
                        unlink($path);
                        $pruned++;
                    }
                }
            }
        }

        // Re-fetch after age pruning
        if ($pruned > 0) {
            $sessions = $this->scanSessionFiles($dir);
            usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        }

        // Prune by count (keep newest N)
        if ($this->maxSessions > 0 && count($sessions) > $this->maxSessions) {
            $toRemove = array_slice($sessions, $this->maxSessions);
            foreach ($toRemove as $session) {
                $safe = $this->sanitizeId($session['session_id']);
                $path = $dir . '/session-' . $safe . '.json';
                if (file_exists($path)) {
                    unlink($path);
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    private function maybePrune(?string $cwd = null): void
    {
        // Only check count-based pruning inline (cheap)
        if ($this->maxSessions <= 0) {
            return;
        }

        $dir = $this->getProjectDir($cwd);
        $pattern = $dir . '/session-*.json';
        $files = glob($pattern);

        if (count($files) > $this->maxSessions + 5) {
            // Buffer of 5 before triggering prune
            $this->prune($cwd);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
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
