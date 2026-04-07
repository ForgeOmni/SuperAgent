<?php

declare(strict_types=1);

namespace SuperAgent\Session;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Prunes old session snapshots by age and count.
 */
class SessionPruner
{
    private SessionStorage $storage;
    private LoggerInterface $logger;
    private int $maxSessions;
    private int $pruneAfterDays;

    public function __construct(
        SessionStorage $storage,
        int $maxSessions = 50,
        int $pruneAfterDays = 90,
        ?LoggerInterface $logger = null,
    ) {
        $this->storage = $storage;
        $this->maxSessions = $maxSessions;
        $this->pruneAfterDays = $pruneAfterDays;
        $this->logger = $logger ?? new NullLogger();
    }

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
            $pruned += $this->pruneDir($this->storage->getProjectDir($cwd));
        } else {
            foreach ($this->storage->getProjectSubdirs() as $subdir) {
                $pruned += $this->pruneDir($subdir);
            }
            $pruned += $this->pruneDir($this->storage->getStorageDir());
        }

        if ($pruned > 0) {
            $this->logger->info('Pruned old sessions', ['count' => $pruned]);
        }

        return $pruned;
    }

    /**
     * Lightweight inline check: only triggers a full prune when the
     * session count exceeds maxSessions + 5 (buffer).
     */
    public function maybePrune(?string $cwd = null): void
    {
        if ($this->maxSessions <= 0) {
            return;
        }

        $dir = $this->storage->getProjectDir($cwd);
        $pattern = $dir . '/session-*.json';
        $files = glob($pattern);

        if (count($files) > $this->maxSessions + 5) {
            $this->prune($cwd);
        }
    }

    /**
     * Prune sessions in a specific directory.
     */
    private function pruneDir(string $dir): int
    {
        $sessions = $this->storage->scanSessionFiles($dir);

        // Sort newest first
        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));

        $pruned = 0;

        // Prune by age
        if ($this->pruneAfterDays > 0) {
            $cutoff = date('c', time() - ($this->pruneAfterDays * 86400));
            foreach ($sessions as $session) {
                if (($session['updated_at'] ?? '') < $cutoff) {
                    $path = $this->storage->sessionFilePath($dir, $session['session_id']);
                    if (file_exists($path)) {
                        unlink($path);
                        $pruned++;
                    }
                }
            }
        }

        // Re-fetch after age pruning
        if ($pruned > 0) {
            $sessions = $this->storage->scanSessionFiles($dir);
            usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        }

        // Prune by count (keep newest N)
        if ($this->maxSessions > 0 && count($sessions) > $this->maxSessions) {
            $toRemove = array_slice($sessions, $this->maxSessions);
            foreach ($toRemove as $session) {
                $path = $this->storage->sessionFilePath($dir, $session['session_id']);
                if (file_exists($path)) {
                    unlink($path);
                    $pruned++;
                }
            }
        }

        return $pruned;
    }
}
