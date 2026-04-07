<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HookReloader
{
    private ?int $lastMtime = null;
    private ?HookRegistry $cachedRegistry = null;

    public function __construct(
        private string $configPath,
        private ?string $pluginConfigPath = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Returns the current HookRegistry, reloading if config file changed.
     */
    public function currentRegistry(): HookRegistry
    {
        $currentMtime = $this->getConfigMtime();

        if ($this->cachedRegistry === null || $currentMtime !== $this->lastMtime) {
            $this->reload();
            $this->lastMtime = $currentMtime;
        }

        return $this->cachedRegistry;
    }

    /**
     * Force reload regardless of mtime.
     */
    public function forceReload(): HookRegistry
    {
        $this->reload();
        $this->lastMtime = $this->getConfigMtime();

        return $this->cachedRegistry;
    }

    /**
     * Check if config has changed since last load.
     */
    public function hasChanged(): bool
    {
        return $this->getConfigMtime() !== $this->lastMtime;
    }

    /**
     * Get paths being watched.
     */
    public function getWatchedPaths(): array
    {
        $paths = [$this->configPath];

        if ($this->pluginConfigPath !== null) {
            $paths[] = $this->pluginConfigPath;
        }

        return $paths;
    }

    /**
     * Static factory from standard config locations.
     */
    public static function fromDefaults(): self
    {
        $projectConfig = getcwd() . '/.superagent/hooks.json';
        $homeConfig = ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '') . '/.config/superagent/hooks.json';

        $configPath = file_exists($projectConfig) ? $projectConfig : $homeConfig;
        $pluginConfigPath = file_exists($projectConfig) && file_exists($homeConfig) ? $homeConfig : null;

        return new self($configPath, $pluginConfigPath);
    }

    private function reload(): void
    {
        $this->cachedRegistry = new HookRegistry($this->logger);

        // Load main config
        $mainConfig = $this->loadConfigFile($this->configPath);
        if ($mainConfig !== null) {
            $this->cachedRegistry->loadFromConfig($mainConfig);
        }

        // Load plugin config
        if ($this->pluginConfigPath !== null) {
            $pluginConfig = $this->loadConfigFile($this->pluginConfigPath);
            if ($pluginConfig !== null) {
                $this->cachedRegistry->loadFromConfig($pluginConfig, 'plugin');
            }
        }

        error_log('[SuperAgent] Hook registry reloaded');
    }

    /**
     * Load and parse a config file (JSON or PHP).
     */
    private function loadConfigFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === 'json') {
            $content = file_get_contents($path);
            if ($content === false) {
                $this->logger->warning("Failed to read hook config: {$path}");
                return null;
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning("Invalid JSON in hook config: {$path}: " . json_last_error_msg());
                return null;
            }

            return $data;
        }

        if ($extension === 'php') {
            try {
                $data = require $path;
                return is_array($data) ? $data : null;
            } catch (\Throwable $e) {
                $this->logger->warning("Error loading PHP hook config: {$path}: " . $e->getMessage());
                return null;
            }
        }

        $this->logger->warning("Unsupported hook config format: {$extension}");
        return null;
    }

    private function getConfigMtime(): ?int
    {
        $mtimes = [];

        if (file_exists($this->configPath)) {
            $mtime = filemtime($this->configPath);
            if ($mtime !== false) {
                $mtimes[] = $mtime;
            }
        }

        if ($this->pluginConfigPath !== null && file_exists($this->pluginConfigPath)) {
            $mtime = filemtime($this->pluginConfigPath);
            if ($mtime !== false) {
                $mtimes[] = $mtime;
            }
        }

        if (empty($mtimes)) {
            return null;
        }

        return max($mtimes);
    }
}
