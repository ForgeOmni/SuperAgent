<?php

namespace SuperAgent\Config;

use SuperAgent\Agent;

class ConfigWatcher
{
    private array $watchedFiles = [];
    private array $lastModified = [];
    private array $callbacks = [];
    private bool $watching = false;
    private ?int $watchInterval = null;

    /** PID of the forked CLI watcher child, held by the parent only. */
    private ?int $watcherPid = null;
    
    public function __construct(int $watchInterval = 1000)
    {
        $this->watchInterval = $watchInterval;
    }

    /**
     * Watch a configuration file for changes.
     */
    public function watch(string $file, callable $callback): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("Config file not found: {$file}");
        }
        
        $this->watchedFiles[] = $file;
        $this->lastModified[$file] = filemtime($file);
        
        if (!isset($this->callbacks[$file])) {
            $this->callbacks[$file] = [];
        }
        
        $this->callbacks[$file][] = $callback;
    }

    /**
     * Start watching for changes.
     */
    public function start(): void
    {
        if ($this->watching) {
            return;
        }
        
        $this->watching = true;
        
        // In a real implementation, this would run in a separate thread/process
        // For now, we'll use a simple polling approach
        if (PHP_SAPI === 'cli') {
            $this->startCliWatcher();
        }
    }

    /**
     * Stop watching for changes.
     *
     * The forked child polls a copy-on-write copy of `$this->watching`, so
     * flipping the flag here can never reach it — the child's lifetime is
     * managed by signal instead (and by the parent-death check in its loop).
     */
    public function stop(): void
    {
        $this->watching = false;

        if ($this->watcherPid !== null) {
            if (function_exists('posix_kill')) {
                @posix_kill($this->watcherPid, SIGTERM);
            }
            if (function_exists('pcntl_waitpid')) {
                @pcntl_waitpid($this->watcherPid, $status);
            }
            $this->watcherPid = null;
        }
    }

    /**
     * Last-resort cleanup so a dropped watcher never leaks its child process.
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Check for changes once.
     */
    public function check(): void
    {
        foreach ($this->watchedFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $currentModified = filemtime($file);
            
            if ($currentModified > $this->lastModified[$file]) {
                $this->handleFileChange($file);
                $this->lastModified[$file] = $currentModified;
            }
        }
    }

    /**
     * Handle a file change.
     */
    private function handleFileChange(string $file): void
    {
        if (!isset($this->callbacks[$file])) {
            return;
        }
        
        foreach ($this->callbacks[$file] as $callback) {
            try {
                $callback($file);
            } catch (\Exception $e) {
                // Log error but continue watching
                error_log("Error in config watcher callback: " . $e->getMessage());
            }
        }
    }

    /**
     * Start CLI watcher (for long-running processes).
     */
    private function startCliWatcher(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('posix_getppid')) {
            // Without fork + posix signalling we cannot manage the child's
            // lifetime — fall back to manual check() polling by the caller.
            return;
        }
        
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new \RuntimeException('Failed to fork watcher process');
        } elseif ($pid == 0) {
            // Child process. `$this->watching` is a copy-on-write copy the
            // parent's stop() can never flip, so exit is driven by SIGTERM
            // (sent by stop()) or by the parent dying — reparenting changes
            // our ppid, which the loop condition detects.
            $parentPid = posix_getppid();
            while (posix_getppid() === $parentPid) {
                $this->check();
                usleep($this->watchInterval * 1000); // Convert to microseconds
            }
            exit(0);
        }

        // Parent process continues; remember the child so stop() can reap it.
        $this->watcherPid = $pid;
    }

    /**
     * Get watched files.
     */
    public function getWatchedFiles(): array
    {
        return $this->watchedFiles;
    }

    /**
     * Is watching active?
     */
    public function isWatching(): bool
    {
        return $this->watching;
    }

    /**
     * Clear all watchers.
     */
    public function clear(): void
    {
        $this->stop();
        $this->watchedFiles = [];
        $this->lastModified = [];
        $this->callbacks = [];
    }
}