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
     */
    public function stop(): void
    {
        $this->watching = false;
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
        if (!function_exists('pcntl_fork')) {
            // Fallback to simple checking
            return;
        }
        
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            throw new \RuntimeException('Failed to fork watcher process');
        } elseif ($pid == 0) {
            // Child process
            while ($this->watching) {
                $this->check();
                usleep($this->watchInterval * 1000); // Convert to microseconds
            }
            exit(0);
        }
        
        // Parent process continues
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