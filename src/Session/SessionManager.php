<?php

declare(strict_types=1);

namespace SuperAgent\Session;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-backed session persistence for conversation history.
 *
 * Directory layout (under $storageDir):
 *   latest.json              — symlink/copy to most recently saved session
 *   session-{id}.json        — immutable snapshot per session
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
        $snapshot = [
            'session_id' => $sessionId,
            'cwd' => $meta['cwd'] ?? (getcwd() ?: '.'),
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

        // Write session file (atomic)
        $sessionPath = $this->sessionPath($sessionId);
        $this->atomicWrite($sessionPath, $snapshot);

        // Write latest pointer
        $latestPath = $this->storageDir . '/latest.json';
        $this->atomicWrite($latestPath, $snapshot);

        $this->logger->debug('Session saved', [
            'session_id' => $sessionId,
            'message_count' => count($messages),
        ]);

        // Auto-prune if over limit
        $this->maybePrune();
    }

    // ── Load ──────────────────────────────────────────────────────

    /**
     * Load the most recent session snapshot.
     *
     * @return array|null The snapshot data, or null if none exists
     */
    public function loadLatest(?string $cwd = null): ?array
    {
        $latestPath = $this->storageDir . '/latest.json';

        if (!file_exists($latestPath)) {
            return null;
        }

        $data = $this->readSnapshot($latestPath);

        // If cwd filter is specified, latest must match
        if ($data !== null && $cwd !== null && ($data['cwd'] ?? '') !== $cwd) {
            // Search through sessions for one matching cwd
            return $this->findLatestByCwd($cwd);
        }

        return $data;
    }

    /**
     * Load a specific session by ID.
     */
    public function loadById(string $sessionId): ?array
    {
        $path = $this->sessionPath($sessionId);

        if (!file_exists($path)) {
            return null;
        }

        return $this->readSnapshot($path);
    }

    // ── List ──────────────────────────────────────────────────────

    /**
     * List all saved sessions, ordered by updated_at descending.
     *
     * @param int $limit  Max number of sessions to return (0 = all)
     * @return array[]  Array of session summaries (no messages included)
     */
    public function listSessions(int $limit = 20): array
    {
        $sessions = [];
        $pattern = $this->storageDir . '/session-*.json';

        foreach (glob($pattern) as $path) {
            $data = $this->readSnapshot($path);
            if ($data === null) {
                continue;
            }

            // Return summary without full message history
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
     * Delete a specific session.
     */
    public function delete(string $sessionId): bool
    {
        $path = $this->sessionPath($sessionId);

        if (!file_exists($path)) {
            return false;
        }

        unlink($path);

        $this->logger->debug('Session deleted', ['session_id' => $sessionId]);

        return true;
    }

    // ── Pruning ───────────────────────────────────────────────────

    /**
     * Prune old sessions based on maxSessions and pruneAfterDays.
     *
     * @return int Number of pruned sessions
     */
    public function prune(): int
    {
        $sessions = $this->listSessions(0); // all, sorted newest first
        $pruned = 0;

        // Prune by age
        if ($this->pruneAfterDays > 0) {
            $cutoff = date('c', time() - ($this->pruneAfterDays * 86400));
            foreach ($sessions as $session) {
                if (($session['updated_at'] ?? '') < $cutoff) {
                    $this->delete($session['session_id']);
                    $pruned++;
                }
            }
        }

        // Re-fetch after age pruning
        if ($pruned > 0) {
            $sessions = $this->listSessions(0);
        }

        // Prune by count (keep newest N)
        if ($this->maxSessions > 0 && count($sessions) > $this->maxSessions) {
            $toRemove = array_slice($sessions, $this->maxSessions);
            foreach ($toRemove as $session) {
                $this->delete($session['session_id']);
                $pruned++;
            }
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
        return file_exists($this->sessionPath($sessionId));
    }

    /**
     * Get the storage directory path.
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function sessionPath(string $sessionId): string
    {
        // Sanitize to prevent directory traversal
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
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

    private function findLatestByCwd(string $cwd): ?array
    {
        $sessions = $this->listSessions(0);

        foreach ($sessions as $summary) {
            if (($summary['cwd'] ?? '') === $cwd) {
                return $this->loadById($summary['session_id']);
            }
        }

        return null;
    }

    private function maybePrune(): void
    {
        // Only check count-based pruning inline (cheap)
        if ($this->maxSessions <= 0) {
            return;
        }

        $pattern = $this->storageDir . '/session-*.json';
        $files = glob($pattern);

        if (count($files) > $this->maxSessions + 5) {
            // Buffer of 5 before triggering prune
            $this->prune();
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
