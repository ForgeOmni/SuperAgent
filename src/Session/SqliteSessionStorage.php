<?php

declare(strict_types=1);

namespace SuperAgent\Session;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SQLite-backed session storage with WAL mode and FTS5 full-text search.
 *
 * Inspired by hermes-agent's hermes_state.py — provides:
 *   - WAL mode for concurrent read/write safety
 *   - FTS5 full-text search across all session messages
 *   - Random-jitter retry for lock contention avoidance
 *   - Passive WAL checkpointing to prevent unbounded growth
 *   - Schema versioning with forward migrations
 */
class SqliteSessionStorage
{
    private const SCHEMA_VERSION = 1;
    private const WAL_CHECKPOINT_THRESHOLD = 50;

    private PDO $db;
    private LoggerInterface $logger;
    private int $writeCount = 0;

    public function __construct(
        private string $dbPath,
        ?LoggerInterface $logger = null,
        private ?string $encryptionKey = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->initializeDatabase();
    }

    /**
     * Get the database file path.
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    // ── Write ────────────────────────────────────────────────────

    /**
     * Save a session snapshot (upsert).
     */
    public function save(string $sessionId, array $snapshot): void
    {
        $this->withRetry(function () use ($sessionId, $snapshot) {
            $messagesJson = json_encode($snapshot['messages'] ?? [], JSON_UNESCAPED_UNICODE);
            $now = date('c');

            $sql = <<<'SQL'
                INSERT INTO sessions (
                    session_id, cwd, model, system_prompt, messages,
                    usage, total_cost_usd, created_at, updated_at,
                    summary, message_count
                ) VALUES (
                    :session_id, :cwd, :model, :system_prompt, :messages,
                    :usage, :total_cost_usd, :created_at, :updated_at,
                    :summary, :message_count
                )
                ON CONFLICT(session_id) DO UPDATE SET
                    messages = excluded.messages,
                    usage = excluded.usage,
                    total_cost_usd = excluded.total_cost_usd,
                    updated_at = excluded.updated_at,
                    summary = excluded.summary,
                    message_count = excluded.message_count,
                    model = excluded.model
            SQL;

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':session_id' => $sessionId,
                ':cwd' => $snapshot['cwd'] ?? '',
                ':model' => $snapshot['model'] ?? null,
                ':system_prompt' => $snapshot['system_prompt'] ?? null,
                ':messages' => $messagesJson,
                ':usage' => json_encode($snapshot['usage'] ?? null),
                ':total_cost_usd' => $snapshot['total_cost_usd'] ?? 0.0,
                ':created_at' => $snapshot['created_at'] ?? $now,
                ':updated_at' => $now,
                ':summary' => $snapshot['summary'] ?? '',
                ':message_count' => $snapshot['message_count'] ?? count($snapshot['messages'] ?? []),
            ]);

            // Update FTS index
            $this->indexMessages($sessionId, $snapshot['messages'] ?? []);
        });

        $this->maybeCheckpoint();
    }

    // ── Read ─────────────────────────────────────────────────────

    /**
     * Load a session by ID.
     */
    public function load(string $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sessions WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateRow($row) : null;
    }

    /**
     * Load the most recent session, optionally filtered by CWD.
     */
    public function loadLatest(?string $cwd = null): ?array
    {
        if ($cwd !== null) {
            $projectHash = SessionManager::projectHash($cwd);
            $stmt = $this->db->prepare(
                'SELECT * FROM sessions WHERE cwd = :cwd OR cwd LIKE :hash ORDER BY updated_at DESC LIMIT 1'
            );
            $stmt->execute([':cwd' => $cwd, ':hash' => '%' . $projectHash . '%']);
        } else {
            $stmt = $this->db->query('SELECT * FROM sessions ORDER BY updated_at DESC LIMIT 1');
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRow($row) : null;
    }

    /**
     * List sessions with optional CWD filter.
     *
     * @return array[] Session summaries (no messages)
     */
    public function listSessions(int $limit = 20, ?string $cwd = null): array
    {
        $sql = 'SELECT session_id, cwd, model, summary, message_count, total_cost_usd, created_at, updated_at FROM sessions';
        $params = [];

        if ($cwd !== null) {
            $sql .= ' WHERE cwd = :cwd';
            $params[':cwd'] = $cwd;
        }

        $sql .= ' ORDER BY updated_at DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
            $params[':limit'] = $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Search ───────────────────────────────────────────────────

    /**
     * Full-text search across all session messages using FTS5.
     *
     * @return array[] Matching sessions with relevance rank
     */
    public function search(string $query, int $limit = 10): array
    {
        if (empty(trim($query))) {
            return [];
        }

        // Escape FTS5 special characters
        $safeQuery = $this->escapeFts5Query($query);

        $sql = <<<'SQL'
            SELECT
                f.session_id,
                s.cwd,
                s.model,
                s.summary,
                s.message_count,
                s.total_cost_usd,
                s.updated_at,
                snippet(messages_fts, 0, '<mark>', '</mark>', '...', 32) AS snippet,
                rank
            FROM messages_fts f
            JOIN sessions s ON f.session_id = s.session_id
            WHERE messages_fts MATCH :query
            ORDER BY rank
            LIMIT :limit
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':query' => $safeQuery, ':limit' => $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Delete ───────────────────────────────────────────────────

    /**
     * Delete a session by ID.
     */
    public function delete(string $sessionId): bool
    {
        $this->withRetry(function () use ($sessionId) {
            $this->db->beginTransaction();
            try {
                $this->db->prepare('DELETE FROM messages_fts WHERE session_id = :id')
                    ->execute([':id' => $sessionId]);
                $stmt = $this->db->prepare('DELETE FROM sessions WHERE session_id = :id');
                $stmt->execute([':id' => $sessionId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        });

        return true;
    }

    /**
     * Prune old sessions by age and count.
     *
     * @return int Number of pruned sessions
     */
    public function prune(int $maxSessions = 50, int $pruneAfterDays = 90, ?string $cwd = null): int
    {
        $pruned = 0;

        $this->withRetry(function () use (&$pruned, $maxSessions, $pruneAfterDays, $cwd) {
            $this->db->beginTransaction();
            try {
                // Prune by age
                if ($pruneAfterDays > 0) {
                    $cutoff = date('c', time() - ($pruneAfterDays * 86400));
                    $sql = 'DELETE FROM sessions WHERE updated_at < :cutoff';
                    $params = [':cutoff' => $cutoff];

                    if ($cwd !== null) {
                        $sql .= ' AND cwd = :cwd';
                        $params[':cwd'] = $cwd;
                    }

                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                    $pruned += $stmt->rowCount();
                }

                // Prune by count (keep newest N)
                if ($maxSessions > 0) {
                    $countSql = 'SELECT COUNT(*) FROM sessions';
                    $countParams = [];
                    if ($cwd !== null) {
                        $countSql .= ' WHERE cwd = :cwd';
                        $countParams[':cwd'] = $cwd;
                    }

                    $total = (int) $this->db->prepare($countSql)->execute($countParams)
                        ? (int) $this->db->query($countSql)->fetchColumn()
                        : 0;

                    if ($total > $maxSessions) {
                        $excess = $total - $maxSessions;
                        $deleteSql = 'DELETE FROM sessions WHERE session_id IN (
                            SELECT session_id FROM sessions';
                        $deleteParams = [];

                        if ($cwd !== null) {
                            $deleteSql .= ' WHERE cwd = :cwd';
                            $deleteParams[':cwd'] = $cwd;
                        }

                        $deleteSql .= ' ORDER BY updated_at ASC LIMIT :excess)';
                        $deleteParams[':excess'] = $excess;

                        $stmt = $this->db->prepare($deleteSql);
                        $stmt->execute($deleteParams);
                        $pruned += $stmt->rowCount();
                    }
                }

                // Clean orphaned FTS entries
                $this->db->exec(
                    'DELETE FROM messages_fts WHERE session_id NOT IN (SELECT session_id FROM sessions)'
                );

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        });

        if ($pruned > 0) {
            $this->logger->info('Pruned sessions from SQLite', ['count' => $pruned]);
        }

        return $pruned;
    }

    /**
     * Get the total number of sessions.
     */
    public function count(?string $cwd = null): int
    {
        $sql = 'SELECT COUNT(*) FROM sessions';
        $params = [];

        if ($cwd !== null) {
            $sql .= ' WHERE cwd = :cwd';
            $params[':cwd'] = $cwd;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ── Internals ────────────────────────────────────────────────

    private function initializeDatabase(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new PDO('sqlite:' . $this->dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // Apply encryption key if configured (requires SQLCipher extension)
        if ($this->encryptionKey !== null) {
            $this->db->exec("PRAGMA key = " . $this->db->quote($this->encryptionKey));
        }

        // Enable WAL mode for concurrent read/write safety
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout=3000');
        $this->db->exec('PRAGMA synchronous=NORMAL');

        $this->migrate();
    }

    private function migrate(): void
    {
        $currentVersion = (int) $this->db->query('PRAGMA user_version')->fetchColumn();

        if ($currentVersion >= self::SCHEMA_VERSION) {
            return;
        }

        if ($currentVersion < 1) {
            $this->db->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS sessions (
                    session_id TEXT PRIMARY KEY,
                    cwd TEXT NOT NULL DEFAULT '',
                    model TEXT,
                    system_prompt TEXT,
                    messages TEXT NOT NULL DEFAULT '[]',
                    usage TEXT,
                    total_cost_usd REAL NOT NULL DEFAULT 0.0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    summary TEXT NOT NULL DEFAULT '',
                    message_count INTEGER NOT NULL DEFAULT 0
                );

                CREATE INDEX IF NOT EXISTS idx_sessions_cwd ON sessions(cwd);
                CREATE INDEX IF NOT EXISTS idx_sessions_updated ON sessions(updated_at DESC);

                CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
                    session_id UNINDEXED,
                    content,
                    tokenize='porter unicode61'
                );
            SQL);
        }

        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /**
     * Index message text content into the FTS table.
     */
    private function indexMessages(string $sessionId, array $messages): void
    {
        // Remove old FTS entries for this session
        $this->db->prepare('DELETE FROM messages_fts WHERE session_id = :id')
            ->execute([':id' => $sessionId]);

        // Extract searchable text from messages
        $textParts = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            if (is_string($content) && !empty($content)) {
                $textParts[] = "[{$role}] {$content}";
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (is_string($block)) {
                        $textParts[] = "[{$role}] {$block}";
                    } elseif (isset($block['text'])) {
                        $textParts[] = "[{$role}] {$block['text']}";
                    }
                }
            }
        }

        if (!empty($textParts)) {
            $fullText = implode("\n", $textParts);
            $this->db->prepare('INSERT INTO messages_fts (session_id, content) VALUES (:id, :content)')
                ->execute([':id' => $sessionId, ':content' => $fullText]);
        }
    }

    private function hydrateRow(array $row): array
    {
        $row['messages'] = json_decode($row['messages'] ?? '[]', true) ?: [];
        $row['usage'] = json_decode($row['usage'] ?? 'null', true);
        $row['total_cost_usd'] = (float) ($row['total_cost_usd'] ?? 0.0);
        $row['message_count'] = (int) ($row['message_count'] ?? 0);
        return $row;
    }

    /**
     * Escape a query string for FTS5 MATCH syntax.
     */
    private function escapeFts5Query(string $query): string
    {
        // Wrap individual terms in double quotes to treat as literals
        $terms = preg_split('/\s+/', trim($query));
        $escaped = array_map(function ($term) {
            // Remove FTS5 special chars
            $term = preg_replace('/[{}()\[\]^~*:"]/', '', $term);
            return '"' . $term . '"';
        }, array_filter($terms));

        return implode(' ', $escaped);
    }

    /**
     * Execute a callback with random-jitter retry on lock contention.
     *
     * Inspired by hermes-agent's convoy-breaking strategy:
     * short SQLite timeout + application-level retry with random jitter.
     */
    private function withRetry(callable $fn, int $maxAttempts = 5): void
    {
        $attempt = 0;
        while (true) {
            try {
                $fn();
                $this->writeCount++;
                return;
            } catch (PDOException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts || !$this->isLockError($e)) {
                    throw $e;
                }

                // Random jitter between 20-150ms to break convoy effect
                $jitterMs = random_int(20, 150);
                $this->logger->debug('SQLite lock contention, retrying', [
                    'attempt' => $attempt,
                    'jitter_ms' => $jitterMs,
                ]);
                usleep($jitterMs * 1000);
            }
        }
    }

    private function isLockError(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'database is locked')
            || str_contains($msg, 'busy');
    }

    /**
     * Passive WAL checkpoint to prevent unbounded growth.
     */
    private function maybeCheckpoint(): void
    {
        if ($this->writeCount % self::WAL_CHECKPOINT_THRESHOLD === 0) {
            try {
                $this->db->exec('PRAGMA wal_checkpoint(PASSIVE)');
            } catch (PDOException $e) {
                $this->logger->debug('WAL checkpoint skipped', ['error' => $e->getMessage()]);
            }
        }
    }
}
