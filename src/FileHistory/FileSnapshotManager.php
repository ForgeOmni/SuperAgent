<?php

namespace SuperAgent\FileHistory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class FileSnapshotManager
{
    private static ?self $instance = null;
    private string $snapshotDir;
    private Collection $snapshots;
    private Collection $activeFiles;
    private int $maxSnapshotsPerFile;
    private bool $enabled;

    private function __construct()
    {
        $this->snapshotDir = sys_get_temp_dir() . '/superagent_snapshots';
        $this->snapshots = collect();
        $this->activeFiles = collect();
        $this->maxSnapshotsPerFile = 50; // Keep last 50 snapshots per file
        $this->enabled = true;

        $this->ensureSnapshotDirectory();
    }

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

        // Save snapshot to disk
        $this->saveSnapshot($snapshot);

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

    /**
     * Clear all snapshots (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->snapshots = collect();
            self::$instance->activeFiles = collect();
            
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