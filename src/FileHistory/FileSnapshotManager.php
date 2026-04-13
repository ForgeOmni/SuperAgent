<?php

namespace SuperAgent\FileHistory;

use Illuminate\Support\Collection;
use SuperAgent\Support\DateTime as Carbon;

class FileSnapshotManager
{
    private static ?self $instance = null;
    private string $snapshotDir;
    private Collection $snapshots;
    private Collection $activeFiles;
    private int $maxSnapshotsPerFile;
    private bool $enabled;

    /**
     * Per-message snapshots: messageId => MessageSnapshot
     * LRU eviction at MAX_MESSAGE_SNAPSHOTS
     */
    private Collection $messageSnapshots;
    private int $snapshotSequence = 0;

    /** LRU limit for message-level snapshots */
    private const MAX_MESSAGE_SNAPSHOTS = 100;

    /** Batch size for disk writes (flush every N snapshots) */
    private int $batchSize = 5;
    private int $pendingWrites = 0;
    private array $pendingSnapshots = [];

    /** Set of tracked file paths (relative for space efficiency) */
    private Collection $trackedFiles;

    public function __construct()
    {
        $this->snapshotDir = sys_get_temp_dir() . '/superagent_snapshots';
        $this->snapshots = collect();
        $this->activeFiles = collect();
        $this->messageSnapshots = collect();
        $this->trackedFiles = collect();
        $this->maxSnapshotsPerFile = 50; // Keep last 50 snapshots per file
        $this->enabled = true;

        $this->ensureSnapshotDirectory();
    }

    /**
     * @deprecated Use constructor injection instead
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a snapshot before modifying a file.
     */
    public function createSnapshot(string $filePath): ?string
    {
        if (!$this->enabled || !file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $hash = sha1($content);

        // Check if content has changed since last snapshot
        $lastSnapshot = $this->getLastSnapshot($filePath);
        if ($lastSnapshot && $lastSnapshot->hash === $hash) {
            return $lastSnapshot->id; // No changes, reuse last snapshot
        }

        $snapshotId = $this->generateSnapshotId();
        $snapshot = new FileSnapshot(
            id: $snapshotId,
            filePath: $filePath,
            content: $content,
            hash: $hash,
            timestamp: Carbon::now(),
            metadata: [
                'size' => strlen($content),
                'permissions' => fileperms($filePath),
                'owner' => fileowner($filePath),
                'group' => filegroup($filePath),
            ]
        );

        // Batch disk writes for performance
        $this->pendingSnapshots[] = $snapshot;
        $this->pendingWrites++;

        if ($this->pendingWrites >= $this->batchSize) {
            $this->flushPendingSnapshots();
        }

        // Track in memory
        if (!$this->snapshots->has($filePath)) {
            $this->snapshots->put($filePath, collect());
        }
        $this->snapshots->get($filePath)->push($snapshot);

        // Track active file
        $this->activeFiles->put($filePath, [
            'current_snapshot' => $snapshotId,
            'modified' => false,
        ]);

        // Cleanup old snapshots
        $this->cleanupOldSnapshots($filePath);

        return $snapshotId;
    }

    /**
     * Flush any pending snapshots to disk.
     * Called automatically when batch size is reached or on destruction.
     */
    public function flushPendingSnapshots(): void
    {
        foreach ($this->pendingSnapshots as $snapshot) {
            $this->saveSnapshot($snapshot);
        }
        $this->pendingSnapshots = [];
        $this->pendingWrites = 0;
    }

    /**
     * Set the batch size for disk writes.
     * Higher values improve performance but risk data loss on crash.
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, $size);
    }

    public function __destruct()
    {
        $this->flushPendingSnapshots();
    }

    /**
     * Restore a file from a snapshot.
     */
    public function restoreSnapshot(string $snapshotId): bool
    {
        $snapshot = $this->loadSnapshot($snapshotId);
        if (!$snapshot) {
            return false;
        }

        // Create a backup of current state before restoring
        $this->createSnapshot($snapshot->filePath);

        // Restore the file
        $result = file_put_contents($snapshot->filePath, $snapshot->content);
        
        if ($result === false) {
            return false;
        }

        // Restore permissions if possible
        @chmod($snapshot->filePath, $snapshot->metadata['permissions']);

        // Update tracking
        $this->activeFiles->put($snapshot->filePath, [
            'current_snapshot' => $snapshotId,
            'modified' => false,
            'restored_at' => Carbon::now(),
        ]);

        return true;
    }

    /**
     * Get diff between two snapshots or current file and a snapshot.
     */
    public function getDiff(string $filePath, ?string $fromSnapshotId = null, ?string $toSnapshotId = null): ?array
    {
        $fromContent = '';
        $toContent = '';

        if ($fromSnapshotId) {
            $fromSnapshot = $this->loadSnapshot($fromSnapshotId);
            if (!$fromSnapshot) {
                return null;
            }
            $fromContent = $fromSnapshot->content;
        } else {
            // Use earliest snapshot as base
            $snapshots = $this->getFileSnapshots($filePath);
            if ($snapshots->isEmpty()) {
                return null;
            }
            $fromContent = $snapshots->first()->content;
        }

        if ($toSnapshotId) {
            $toSnapshot = $this->loadSnapshot($toSnapshotId);
            if (!$toSnapshot) {
                return null;
            }
            $toContent = $toSnapshot->content;
        } else {
            // Use current file content
            if (!file_exists($filePath)) {
                return null;
            }
            $toContent = file_get_contents($filePath);
        }

        return $this->generateDiff($fromContent, $toContent);
    }

    /**
     * Generate a unified diff between two contents.
     */
    private function generateDiff(string $from, string $to): array
    {
        $fromLines = explode("\n", $from);
        $toLines = explode("\n", $to);
        
        $diff = [];
        $maxLines = max(count($fromLines), count($toLines));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $fromLine = $fromLines[$i] ?? null;
            $toLine = $toLines[$i] ?? null;
            
            if ($fromLine === $toLine) {
                $diff[] = [
                    'type' => 'unchanged',
                    'line' => $i + 1,
                    'content' => $fromLine,
                ];
            } elseif ($fromLine === null) {
                $diff[] = [
                    'type' => 'added',
                    'line' => $i + 1,
                    'content' => $toLine,
                ];
            } elseif ($toLine === null) {
                $diff[] = [
                    'type' => 'deleted',
                    'line' => $i + 1,
                    'content' => $fromLine,
                ];
            } else {
                $diff[] = [
                    'type' => 'modified',
                    'line' => $i + 1,
                    'from' => $fromLine,
                    'to' => $toLine,
                ];
            }
        }

        // Calculate statistics
        $stats = [
            'added' => count(array_filter($diff, fn($d) => $d['type'] === 'added')),
            'deleted' => count(array_filter($diff, fn($d) => $d['type'] === 'deleted')),
            'modified' => count(array_filter($diff, fn($d) => $d['type'] === 'modified')),
            'unchanged' => count(array_filter($diff, fn($d) => $d['type'] === 'unchanged')),
        ];

        return [
            'diff' => $diff,
            'stats' => $stats,
        ];
    }

    /**
     * Get all snapshots for a file.
     */
    public function getFileSnapshots(string $filePath): Collection
    {
        if (!$this->snapshots->has($filePath)) {
            // Load from disk if not in memory
            $this->loadFileSnapshots($filePath);
        }
        
        return $this->snapshots->get($filePath, collect());
    }

    /**
     * Get the last snapshot for a file.
     */
    public function getLastSnapshot(string $filePath): ?FileSnapshot
    {
        $snapshots = $this->getFileSnapshots($filePath);
        return $snapshots->last();
    }

    /**
     * Save snapshot to disk.
     */
    private function saveSnapshot(FileSnapshot $snapshot): void
    {
        $path = $this->getSnapshotPath($snapshot->id);
        $directory = dirname($path);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, serialize($snapshot));
    }

    /**
     * Load snapshot from disk.
     */
    private function loadSnapshot(string $snapshotId): ?FileSnapshot
    {
        // Ensure pending writes are flushed before reading from disk
        if (!empty($this->pendingSnapshots)) {
            $this->flushPendingSnapshots();
        }

        $path = $this->getSnapshotPath($snapshotId);

        if (!file_exists($path)) {
            return null;
        }

        return unserialize(file_get_contents($path));
    }

    /**
     * Load all snapshots for a file from disk.
     */
    private function loadFileSnapshots(string $filePath): void
    {
        $fileHash = md5($filePath);
        $pattern = $this->snapshotDir . '/' . substr($fileHash, 0, 2) . '/' . $fileHash . '_*.snapshot';
        $files = glob($pattern);
        
        $snapshots = collect();
        foreach ($files as $file) {
            $snapshot = unserialize(file_get_contents($file));
            if ($snapshot && $snapshot->filePath === $filePath) {
                $snapshots->push($snapshot);
            }
        }
        
        $this->snapshots->put($filePath, $snapshots->sortBy('timestamp'));
    }

    /**
     * Cleanup old snapshots beyond the limit.
     */
    private function cleanupOldSnapshots(string $filePath): void
    {
        $snapshots = $this->getFileSnapshots($filePath);
        
        if ($snapshots->count() <= $this->maxSnapshotsPerFile) {
            return;
        }

        // Keep only the most recent snapshots
        $toDelete = $snapshots->take($snapshots->count() - $this->maxSnapshotsPerFile);
        
        foreach ($toDelete as $snapshot) {
            $path = $this->getSnapshotPath($snapshot->id);
            @unlink($path);
            $snapshots = $snapshots->reject(fn($s) => $s->id === $snapshot->id);
        }
        
        $this->snapshots->put($filePath, $snapshots->values());
    }

    /**
     * Get snapshot file path.
     */
    private function getSnapshotPath(string $snapshotId): string
    {
        $prefix = substr($snapshotId, 0, 2);
        return $this->snapshotDir . '/' . $prefix . '/' . $snapshotId . '.snapshot';
    }

    /**
     * Generate a unique snapshot ID.
     */
    private function generateSnapshotId(): string
    {
        return uniqid('snap_', true);
    }

    /**
     * Ensure snapshot directory exists.
     */
    private function ensureSnapshotDirectory(): void
    {
        if (!is_dir($this->snapshotDir)) {
            mkdir($this->snapshotDir, 0755, true);
        }
    }

    /**
     * Get snapshot count for a file.
     */
    public function getSnapshotCount(string $filePath): int
    {
        return $this->getFileSnapshots($filePath)->count();
    }

    // ================================================================
    // Per-message snapshots (LRU, ported from CC fileHistory.ts)
    // ================================================================

    /**
     * Track a file edit for the given message.
     * Creates a backup (v1) of the file before it is modified.
     */
    public function trackEdit(string $filePath, string $messageId): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        // Add to tracked files
        $relativePath = $this->shortenPath($filePath);
        $this->trackedFiles->put($relativePath, true);

        // Create snapshot for this file
        return $this->createSnapshot($filePath);
    }

    /**
     * Make a message-level snapshot capturing all tracked files.
     * Associates the snapshot with a message ID for later rewind.
     *
     * Uses inheritance: unchanged files reuse the previous snapshot's backup.
     */
    public function makeMessageSnapshot(string $messageId): MessageSnapshot
    {
        $fileBackups = [];
        $previousSnapshot = $this->messageSnapshots->last();

        foreach ($this->trackedFiles->keys() as $relativePath) {
            $absolutePath = $this->expandPath($relativePath);

            // Check if file changed since last snapshot
            $previousBackup = $previousSnapshot?->fileBackups[$relativePath] ?? null;

            if ($previousBackup !== null && !$this->fileChangedSince($absolutePath, $previousBackup)) {
                // Inherit unchanged backup
                $fileBackups[$relativePath] = $previousBackup;
            } else {
                // Create new backup
                $snapshotId = $this->createSnapshot($absolutePath);
                $fileBackups[$relativePath] = new FileBackup(
                    snapshotId: $snapshotId,
                    version: ($previousBackup?->version ?? 0) + 1,
                    backupTime: Carbon::now(),
                );
            }
        }

        $snapshot = new MessageSnapshot(
            messageId: $messageId,
            fileBackups: $fileBackups,
            timestamp: Carbon::now(),
            sequence: ++$this->snapshotSequence,
        );

        $this->messageSnapshots->put($messageId, $snapshot);

        // LRU eviction
        if ($this->messageSnapshots->count() > self::MAX_MESSAGE_SNAPSHOTS) {
            $this->messageSnapshots->shift();
        }

        return $snapshot;
    }

    /**
     * Rewind all tracked files to the state at a given message.
     *
     * @return string[] Paths of files that were changed
     */
    public function rewindToMessage(string $messageId): array
    {
        $snapshot = $this->messageSnapshots->get($messageId);
        if ($snapshot === null) {
            throw new \RuntimeException("No snapshot found for message {$messageId}");
        }

        $changedPaths = [];

        foreach ($snapshot->fileBackups as $relativePath => $backup) {
            $absolutePath = $this->expandPath($relativePath);

            if ($backup->snapshotId === null) {
                // File didn't exist at this point — delete it
                if (file_exists($absolutePath)) {
                    @unlink($absolutePath);
                    $changedPaths[] = $absolutePath;
                }
                continue;
            }

            // Check if file differs from backup
            if ($this->fileChangedSince($absolutePath, $backup)) {
                $restored = $this->restoreSnapshot($backup->snapshotId);
                if ($restored) {
                    $changedPaths[] = $absolutePath;
                }
            }
        }

        return $changedPaths;
    }

    /**
     * Check if a rewind is possible for the given message.
     */
    public function canRewindToMessage(string $messageId): bool
    {
        return $this->messageSnapshots->has($messageId);
    }

    /**
     * Get diff statistics between current state and a target message snapshot.
     *
     * @return DiffStats|null
     */
    public function getDiffStats(?string $targetMessageId = null): ?DiffStats
    {
        if (!$this->enabled) {
            return null;
        }

        $targetSnapshot = $targetMessageId !== null
            ? $this->messageSnapshots->get($targetMessageId)
            : $this->messageSnapshots->first(); // Use earliest as baseline

        if ($targetSnapshot === null) {
            return null;
        }

        $filesChanged = [];
        $totalInsertions = 0;
        $totalDeletions = 0;

        foreach ($targetSnapshot->fileBackups as $relativePath => $backup) {
            $absolutePath = $this->expandPath($relativePath);

            try {
                // Get baseline content
                $baseContent = '';
                if ($backup->snapshotId !== null) {
                    $baseSnapshot = $this->loadSnapshot($backup->snapshotId);
                    if ($baseSnapshot !== null) {
                        $baseContent = $baseSnapshot->content;
                    }
                }

                // Get current content
                $currentContent = file_exists($absolutePath) ? file_get_contents($absolutePath) : '';

                if ($baseContent === $currentContent) {
                    continue;
                }

                $filesChanged[] = $absolutePath;

                // Compute line-level diff
                $baseLines = $baseContent !== '' ? explode("\n", $baseContent) : [];
                $currentLines = $currentContent !== '' ? explode("\n", $currentContent) : [];

                // Simple diff: count added/removed lines
                $diff = $this->computeLineDiff($baseLines, $currentLines);
                $totalInsertions += $diff['insertions'];
                $totalDeletions += $diff['deletions'];
            } catch (\Throwable $e) {
                // Per-file error handling: continue on failure
                continue;
            }
        }

        return new DiffStats(
            filesChanged: $filesChanged,
            insertions: $totalInsertions,
            deletions: $totalDeletions,
        );
    }

    /**
     * Check if any files have changes relative to the earliest snapshot.
     * Lightweight boolean-only check (early exit on first change).
     */
    public function hasAnyChanges(): bool
    {
        $firstSnapshot = $this->messageSnapshots->first();
        if ($firstSnapshot === null) {
            return false;
        }

        foreach ($firstSnapshot->fileBackups as $relativePath => $backup) {
            $absolutePath = $this->expandPath($relativePath);
            if ($this->fileChangedSince($absolutePath, $backup)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the snapshot sequence counter (activity signal).
     */
    public function getSnapshotSequence(): int
    {
        return $this->snapshotSequence;
    }

    /**
     * Get number of message snapshots.
     */
    public function getMessageSnapshotCount(): int
    {
        return $this->messageSnapshots->count();
    }

    // ================================================================
    // Private helpers for message snapshots
    // ================================================================

    private function fileChangedSince(string $filePath, FileBackup $backup): bool
    {
        if (!file_exists($filePath)) {
            return $backup->snapshotId !== null; // Changed if it used to exist
        }

        if ($backup->snapshotId === null) {
            return true; // Changed if it now exists but didn't before
        }

        // Fast path: mtime check
        $mtime = filemtime($filePath);
        if ($mtime !== false && $mtime < $backup->backupTime->getTimestamp()) {
            return false; // File not modified since backup
        }

        // Slow path: content comparison
        $currentHash = sha1_file($filePath);
        $baseSnapshot = $this->loadSnapshot($backup->snapshotId);
        if ($baseSnapshot === null) {
            return true;
        }

        return $currentHash !== $baseSnapshot->hash;
    }

    private function computeLineDiff(array $baseLines, array $currentLines): array
    {
        $insertions = 0;
        $deletions = 0;

        // Simple LCS-based diff approximation
        $baseSet = array_count_values($baseLines);
        $currentSet = array_count_values($currentLines);

        // Lines in current but not in base = insertions
        foreach ($currentSet as $line => $count) {
            $baseCount = $baseSet[$line] ?? 0;
            if ($count > $baseCount) {
                $insertions += ($count - $baseCount);
            }
        }

        // Lines in base but not in current = deletions
        foreach ($baseSet as $line => $count) {
            $currentCount = $currentSet[$line] ?? 0;
            if ($count > $currentCount) {
                $deletions += ($count - $currentCount);
            }
        }

        return ['insertions' => $insertions, 'deletions' => $deletions];
    }

    private function shortenPath(string $absolutePath): string
    {
        $cwd = getcwd() ?: '';
        if ($cwd !== '' && str_starts_with($absolutePath, $cwd . '/')) {
            return substr($absolutePath, strlen($cwd) + 1);
        }
        return $absolutePath;
    }

    private function expandPath(string $relativePath): string
    {
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }
        $cwd = getcwd() ?: '';
        return $cwd . '/' . $relativePath;
    }

    /**
     * Clear all snapshots (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->snapshots = collect();
            self::$instance->activeFiles = collect();
            self::$instance->messageSnapshots = collect();
            self::$instance->trackedFiles = collect();
            self::$instance->snapshotSequence = 0;

            // Clear disk storage
            if (is_dir(self::$instance->snapshotDir)) {
                $files = glob(self::$instance->snapshotDir . '/*/*');
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Set enabled state.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

class FileSnapshot
{
    public function __construct(
        public readonly string $id,
        public readonly string $filePath,
        public readonly string $content,
        public readonly string $hash,
        public readonly Carbon $timestamp,
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'file_path' => $this->filePath,
            'hash' => $this->hash,
            'timestamp' => $this->timestamp->toIso8601String(),
            'size' => strlen($this->content),
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Per-file backup reference within a MessageSnapshot.
 */
class FileBackup
{
    public function __construct(
        /** Snapshot ID (null = file didn't exist) */
        public readonly ?string $snapshotId,
        /** Version number (incrementing for same file) */
        public readonly int $version,
        /** When the backup was created */
        public readonly Carbon $backupTime,
    ) {}
}

/**
 * A snapshot of all tracked files at a specific message.
 */
class MessageSnapshot
{
    public function __construct(
        /** Associated message ID */
        public readonly string $messageId,
        /** relativePath => FileBackup */
        public readonly array $fileBackups,
        /** When the snapshot was taken */
        public readonly Carbon $timestamp,
        /** Monotonic sequence counter (activity signal) */
        public readonly int $sequence,
    ) {}
}

/**
 * Diff statistics between two states.
 */
class DiffStats
{
    public function __construct(
        /** @var string[] Changed file paths */
        public readonly array $filesChanged = [],
        /** Number of lines added */
        public readonly int $insertions = 0,
        /** Number of lines removed */
        public readonly int $deletions = 0,
    ) {}

    public function hasChanges(): bool
    {
        return !empty($this->filesChanged);
    }

    public function toArray(): array
    {
        return [
            'files_changed' => $this->filesChanged,
            'insertions' => $this->insertions,
            'deletions' => $this->deletions,
        ];
    }
}