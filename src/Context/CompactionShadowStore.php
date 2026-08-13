<?php

declare(strict_types=1);

namespace SuperAgent\Context;

/**
 * Records content that compaction removed from model context, so it stays
 * retrievable instead of being destroyed — deepseek-harness's "shadowed
 * surface" idea (compaction there replaces a span of the model-visible
 * projection while citing the shadowed events; session-query can search
 * and re-read them).
 *
 * SuperAgent's compactors (ToolResultCompactor, AutoCompactor::microCompact,
 * ConversationCompressor) call record() before truncating/summarizing.
 * The model retrieves shadowed content via the session_query tool.
 *
 * Storage: one JSONL file per session under <baseDir>, private perms,
 * append-only with flock. Best-effort: any failure returns null and the
 * compactor proceeds exactly as before.
 */
class CompactionShadowStore
{
    /** @var array<string, string> content-identity hash => shadow id (per-process dedup) */
    private array $seen = [];

    private string $sessionKey;

    public function __construct(
        private readonly string $baseDir,
        string $sessionId = 'default',
        private readonly int $minContentBytes = 500,
    ) {
        $this->sessionKey = substr(hash('sha256', $sessionId), 0, 16);
    }

    /**
     * Build from config; returns null when shadow recording is disabled so
     * call sites can keep a nullable reference and skip cleanly.
     */
    public static function fromConfig(?string $sessionId = null): ?self
    {
        try {
            $config = function_exists('config') ? (config('superagent.shadow') ?? []) : [];
        } catch (\Throwable $e) {
            $config = [];
        }

        if (($config['enabled'] ?? true) === false) {
            return null;
        }

        $baseDir = $config['dir'] ?? null;
        if (!is_string($baseDir) || $baseDir === '') {
            $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
            $baseDir = $home . '/.superagent/shadow';
        }

        return new self(
            baseDir: $baseDir,
            sessionId: $sessionId ?? 'default',
            minContentBytes: (int) ($config['min_content_bytes'] ?? 500),
        );
    }

    /**
     * Record content about to be removed from model context.
     *
     * Dedup: the same (toolUseId, content) pair records once per process —
     * important because ToolResultCompactor re-compacts a fresh copy of the
     * message list on every provider call.
     *
     * @return string|null shadow id for retrieval hints, null when skipped/failed
     */
    public function record(
        string $source,
        string $reason,
        string $content,
        ?string $toolUseId = null,
        ?string $toolName = null,
    ): ?string {
        if (strlen($content) < $this->minContentBytes) {
            return null;
        }

        $identity = hash('sha256', ($toolUseId ?? '') . '|' . $content);
        if (isset($this->seen[$identity])) {
            return $this->seen[$identity];
        }

        try {
            if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
                return null;
            }

            $id = bin2hex(random_bytes(8));
            $entry = [
                'id' => $id,
                'time' => date('c'),
                'session_id' => $this->sessionKey,
                'source' => $source,
                'reason' => $reason,
                'tool_use_id' => $toolUseId,
                'tool_name' => $toolName,
                'bytes' => strlen($content),
                'content' => $content,
            ];

            $path = $this->sessionFile();
            $isNew = !file_exists($path);
            $written = @file_put_contents(
                $path,
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
                FILE_APPEND | LOCK_EX,
            );

            if ($written === false) {
                return null;
            }
            if ($isNew) {
                @chmod($path, 0600);
            }

            $this->seen[$identity] = $id;

            return $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Case-insensitive substring search across ALL sessions' shadow files.
     *
     * @return array[] entries without full content: id, session_id, source,
     *                 reason, tool_name, bytes, time, preview
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '' || !is_dir($this->baseDir)) {
            return [];
        }

        $matches = [];
        foreach (glob($this->baseDir . '/session-*.jsonl') ?: [] as $file) {
            foreach ($this->readEntries($file) as $entry) {
                $content = $entry['content'] ?? '';
                $pos = stripos($content, $query);
                if ($pos === false) {
                    continue;
                }

                $start = max(0, $pos - 120);
                $entry['preview'] = ($start > 0 ? '...' : '')
                    . substr($content, $start, 240 + strlen($query))
                    . (($start + 240 + strlen($query)) < strlen($content) ? '...' : '');
                unset($entry['content']);
                $matches[] = $entry;

                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * Retrieve a shadowed entry's content by id, chunked.
     *
     * @return array{id: string, tool_name: ?string, source: string, content: string, offset: int, total: int}|null
     */
    public function get(string $id, int $offset = 0, int $limit = 20000): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id) || !is_dir($this->baseDir)) {
            return null;
        }

        foreach (glob($this->baseDir . '/session-*.jsonl') ?: [] as $file) {
            foreach ($this->readEntries($file) as $entry) {
                if (($entry['id'] ?? null) !== $id) {
                    continue;
                }

                $content = $entry['content'] ?? '';
                $offset = max(0, $offset);
                $limit = max(1, min($limit, 100_000));

                return [
                    'id' => $id,
                    'tool_name' => $entry['tool_name'] ?? null,
                    'source' => $entry['source'] ?? '',
                    'content' => substr($content, $offset, $limit),
                    'offset' => $offset,
                    'total' => strlen($content),
                ];
            }
        }

        return null;
    }

    /**
     * @return iterable<array>
     */
    private function readEntries(string $file): iterable
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    yield $entry;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function sessionFile(): string
    {
        return rtrim($this->baseDir, '/') . '/session-' . $this->sessionKey . '.jsonl';
    }
}
