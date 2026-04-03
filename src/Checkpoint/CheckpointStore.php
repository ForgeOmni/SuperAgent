<?php

declare(strict_types=1);

namespace SuperAgent\Checkpoint;

/**
 * Persistent storage for agent checkpoints.
 *
 * Each checkpoint is stored as a separate JSON file in a directory,
 * named by checkpoint ID. This avoids large single-file I/O and
 * allows concurrent checkpoint writes from different sessions.
 *
 * Directory structure:
 *   {storagePath}/
 *     {checkpoint_id}.json
 *     {checkpoint_id}.json
 *     ...
 */
class CheckpointStore
{
    public function __construct(private readonly ?string $storagePath = null)
    {
        if ($this->storagePath !== null && !is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Save a checkpoint.
     */
    public function save(Checkpoint $checkpoint): void
    {
        if ($this->storagePath === null) {
            return;
        }

        $path = $this->getFilePath($checkpoint->id);
        file_put_contents(
            $path,
            json_encode($checkpoint->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    /**
     * Load a checkpoint by ID.
     */
    public function load(string $id): ?Checkpoint
    {
        if ($this->storagePath === null) {
            return null;
        }

        $path = $this->getFilePath($id);
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }

        return Checkpoint::fromArray($data);
    }

    /**
     * List all checkpoints, optionally filtered by session.
     *
     * @return Checkpoint[]
     */
    public function list(?string $sessionId = null): array
    {
        if ($this->storagePath === null || !is_dir($this->storagePath)) {
            return [];
        }

        $checkpoints = [];
        foreach (glob("{$this->storagePath}/*.json") as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $checkpoint = Checkpoint::fromArray($data);

            if ($sessionId !== null && $checkpoint->sessionId !== $sessionId) {
                continue;
            }

            $checkpoints[] = $checkpoint;
        }

        // Sort by creation time descending, then by turn count descending
        usort($checkpoints, function (Checkpoint $a, Checkpoint $b) {
            $timeCmp = strcmp($b->createdAt, $a->createdAt);

            return $timeCmp !== 0 ? $timeCmp : $b->turnCount <=> $a->turnCount;
        });

        return $checkpoints;
    }

    /**
     * Get the latest checkpoint for a session.
     */
    public function getLatest(?string $sessionId = null): ?Checkpoint
    {
        $all = $this->list($sessionId);

        return $all[0] ?? null;
    }

    /**
     * Delete a checkpoint by ID.
     */
    public function delete(string $id): bool
    {
        if ($this->storagePath === null) {
            return false;
        }

        $path = $this->getFilePath($id);
        if (!file_exists($path)) {
            return false;
        }

        return unlink($path);
    }

    /**
     * Clear all checkpoints, optionally for a specific session.
     */
    public function clear(?string $sessionId = null): int
    {
        if ($this->storagePath === null || !is_dir($this->storagePath)) {
            return 0;
        }

        $count = 0;
        foreach (glob("{$this->storagePath}/*.json") as $file) {
            if ($sessionId !== null) {
                $data = json_decode(file_get_contents($file), true);
                if (!is_array($data) || ($data['session_id'] ?? '') !== $sessionId) {
                    continue;
                }
            }

            if (unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Prune old checkpoints, keeping only the latest N per session.
     */
    public function prune(int $keepPerSession = 3): int
    {
        $all = $this->list();
        $bySess = [];

        foreach ($all as $cp) {
            $bySess[$cp->sessionId][] = $cp;
        }

        $pruned = 0;
        foreach ($bySess as $checkpoints) {
            // Already sorted newest-first from list()
            $toRemove = array_slice($checkpoints, $keepPerSession);
            foreach ($toRemove as $cp) {
                if ($this->delete($cp->id)) {
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    /**
     * Get statistics.
     */
    public function getStatistics(): array
    {
        $all = $this->list();
        $sessions = [];
        $totalSize = 0;

        foreach ($all as $cp) {
            $sessions[$cp->sessionId] = true;
            $path = $this->getFilePath($cp->id);
            if (file_exists($path)) {
                $totalSize += filesize($path);
            }
        }

        return [
            'total_checkpoints' => count($all),
            'total_sessions' => count($sessions),
            'total_size_bytes' => $totalSize,
        ];
    }

    private function getFilePath(string $id): string
    {
        return "{$this->storagePath}/{$id}.json";
    }
}
