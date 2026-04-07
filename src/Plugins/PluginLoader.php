<?php

declare(strict_types=1);

namespace SuperAgent\Plugins;

/**
 * Discovers, loads, and manages file-based plugins from disk.
 *
 * Discovery paths (in order):
 *   1. ~/.superagent/plugins/   (user-global)
 *   2. .superagent/plugins/     (project-local, relative to cwd)
 *
 * Each plugin directory must contain a plugin.json manifest.
 * Skills are .md files inside the manifest's skills_dir.
 * Hooks are parsed from the manifest's hooks_file (hooks.json).
 * MCP configs are parsed from the manifest's mcp_file (mcp.json).
 */
class PluginLoader
{
    /** @var array<string, LoadedPlugin> keyed by plugin name */
    private array $plugins = [];

    /**
     * @param array<string, bool> $enabledPlugins  Explicit overrides: name => enabled
     */
    public function __construct(private array $enabledPlugins = []) {}

    // ── Discovery ────────────────────────────────────────────────

    /**
     * Discover plugins in the given directory paths.
     *
     * Each path is scanned for immediate sub-directories containing a plugin.json.
     */
    public function discover(array $paths): void
    {
        foreach ($paths as $path) {
            $resolved = $this->resolvePath($path);
            if ($resolved === null || !is_dir($resolved)) {
                continue;
            }

            $entries = scandir($resolved);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $pluginDir = $resolved . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($pluginDir)) {
                    $loaded = $this->loadPlugin($pluginDir);
                    if ($loaded !== null) {
                        $this->plugins[$loaded->manifest->name] = $loaded;
                    }
                }
            }
        }
    }

    /**
     * Load a single plugin from a directory path.
     *
     * Returns null if the directory does not contain a valid plugin.json.
     */
    public function loadPlugin(string $path): ?LoadedPlugin
    {
        $manifestPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin.json';

        if (!is_file($manifestPath)) {
            return null;
        }

        try {
            $manifest = PluginManifest::fromJsonFile($manifestPath);
        } catch (\Throwable) {
            return null;
        }

        $enabled = $this->resolveEnabled($manifest);
        $skills = $this->loadSkills($path, $manifest->skillsDir);
        $hooks = $this->loadHooks($path, $manifest->hooksFile);
        $mcpServers = $this->loadMcpConfigs($path, $manifest->mcpFile);

        $plugin = new LoadedPlugin(
            manifest: $manifest,
            path: realpath($path) ?: $path,
            enabled: $enabled,
            skills: $skills,
            hooks: $hooks,
            mcpServers: $mcpServers,
        );

        $this->plugins[$manifest->name] = $plugin;

        return $plugin;
    }

    // ── Accessors ────────────────────────────────────────────────

    /**
     * Get all loaded plugins (enabled and disabled).
     *
     * @return array<string, LoadedPlugin>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Get only enabled plugins.
     *
     * @return array<string, LoadedPlugin>
     */
    public function getEnabledPlugins(): array
    {
        return array_filter($this->plugins, fn(LoadedPlugin $p) => $p->enabled);
    }

    // ── Enable / Disable ─────────────────────────────────────────

    /**
     * Enable or disable a plugin by name.
     */
    public function setEnabled(string $name, bool $enabled): void
    {
        $this->enabledPlugins[$name] = $enabled;

        if (isset($this->plugins[$name])) {
            $this->plugins[$name] = $this->plugins[$name]->withEnabled($enabled);
        }
    }

    // ── Install / Uninstall ──────────────────────────────────────

    /**
     * Install a plugin by copying its source directory into the target plugins dir.
     *
     * Returns true on success, false if the source is invalid or copy fails.
     */
    public function install(string $sourcePath, string $targetDir): bool
    {
        $manifestPath = rtrim($sourcePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($manifestPath)) {
            return false;
        }

        try {
            $manifest = PluginManifest::fromJsonFile($manifestPath);
        } catch (\Throwable) {
            return false;
        }

        $dest = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $manifest->name;

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                return false;
            }
        }

        if (!$this->copyDirectory($sourcePath, $dest)) {
            return false;
        }

        // Load the newly installed plugin
        $loaded = $this->loadPlugin($dest);

        return $loaded !== null;
    }

    /**
     * Uninstall a plugin by removing its directory from the plugins dir.
     *
     * Returns true if successfully removed, false otherwise.
     */
    public function uninstall(string $name, string $pluginsDir): bool
    {
        $pluginDir = rtrim($pluginsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($pluginDir)) {
            return false;
        }

        if (!$this->removeDirectory($pluginDir)) {
            return false;
        }

        unset($this->plugins[$name]);
        unset($this->enabledPlugins[$name]);

        return true;
    }

    // ── Aggregation ──────────────────────────────────────────────

    /**
     * Collect skills from all enabled plugins.
     *
     * @return array<int, array{name: string, content: string, plugin: string}>
     */
    public function collectSkills(): array
    {
        $skills = [];
        foreach ($this->getEnabledPlugins() as $plugin) {
            foreach ($plugin->skills as $skill) {
                $skills[] = array_merge($skill, ['plugin' => $plugin->manifest->name]);
            }
        }
        return $skills;
    }

    /**
     * Collect hooks from all enabled plugins.
     *
     * @return array<int, array>
     */
    public function collectHooks(): array
    {
        $hooks = [];
        foreach ($this->getEnabledPlugins() as $plugin) {
            foreach ($plugin->hooks as $hook) {
                $hooks[] = array_merge($hook, ['plugin' => $plugin->manifest->name]);
            }
        }
        return $hooks;
    }

    /**
     * Collect MCP server configs from all enabled plugins.
     *
     * @return array<string, array>
     */
    public function collectMcpConfigs(): array
    {
        $configs = [];
        foreach ($this->getEnabledPlugins() as $plugin) {
            foreach ($plugin->mcpServers as $serverName => $serverConfig) {
                $configs[$plugin->manifest->name . '/' . $serverName] = $serverConfig;
            }
        }
        return $configs;
    }

    // ── Static Factory ───────────────────────────────────────────

    /**
     * Create a PluginLoader from config, with optional overrides, and
     * auto-discover from default paths.
     *
     * @param array<string, bool> $overrides  Plugin name => enabled
     */
    public static function fromConfig(array $overrides = []): self
    {
        $enabledPlugins = $overrides;

        try {
            if (function_exists('config')) {
                $configured = config('superagent.plugins.enabled', []);
                if (is_array($configured)) {
                    $enabledPlugins = array_merge($configured, $enabledPlugins);
                }
            }
        } catch (\Throwable) {
            // No Laravel
        }

        $loader = new self($enabledPlugins);

        $loader->discover(self::defaultDiscoveryPaths());

        return $loader;
    }

    /**
     * Return the default plugin discovery paths.
     *
     * @return string[]
     */
    public static function defaultDiscoveryPaths(): array
    {
        $paths = [];

        // User-global: ~/.superagent/plugins/
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
        if ($home !== false && $home !== '') {
            $paths[] = $home . DIRECTORY_SEPARATOR . '.superagent' . DIRECTORY_SEPARATOR . 'plugins';
        }

        // Project-local: .superagent/plugins/ (relative to cwd)
        $paths[] = getcwd() . DIRECTORY_SEPARATOR . '.superagent' . DIRECTORY_SEPARATOR . 'plugins';

        return $paths;
    }

    // ── Internal helpers ─────────────────────────────────────────

    private function resolveEnabled(PluginManifest $manifest): bool
    {
        if (array_key_exists($manifest->name, $this->enabledPlugins)) {
            return (bool) $this->enabledPlugins[$manifest->name];
        }

        return $manifest->enabledByDefault;
    }

    /**
     * Load skill definitions from .md files in the skills directory.
     *
     * @return array<int, array{name: string, content: string}>
     */
    private function loadSkills(string $pluginPath, string $skillsDir): array
    {
        $dir = rtrim($pluginPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $skillsDir;

        if (!is_dir($dir)) {
            return [];
        }

        $skills = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.md');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $skills[] = [
                'name' => pathinfo($file, PATHINFO_FILENAME),
                'content' => $content,
            ];
        }

        return $skills;
    }

    /**
     * Load hook definitions from the hooks JSON file.
     *
     * @return array<int, array>
     */
    private function loadHooks(string $pluginPath, ?string $hooksFile): array
    {
        if ($hooksFile === null) {
            return [];
        }

        $path = rtrim($pluginPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hooksFile;

        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        // Support both { "hooks": [...] } and plain [...]
        return isset($data['hooks']) && is_array($data['hooks']) ? $data['hooks'] : (array_is_list($data) ? $data : []);
    }

    /**
     * Load MCP server configs from the mcp JSON file.
     *
     * @return array<string, array>
     */
    private function loadMcpConfigs(string $pluginPath, ?string $mcpFile): array
    {
        if ($mcpFile === null) {
            return [];
        }

        $path = rtrim($pluginPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $mcpFile;

        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        // Support { "mcpServers": {...} } or { "servers": {...} } or plain {...}
        if (isset($data['mcpServers']) && is_array($data['mcpServers'])) {
            return $data['mcpServers'];
        }
        if (isset($data['servers']) && is_array($data['servers'])) {
            return $data['servers'];
        }

        return $data;
    }

    private function resolvePath(string $path): ?string
    {
        // Expand ~ at the start
        if (str_starts_with($path, '~')) {
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
            if ($home === false || $home === '') {
                return null;
            }
            $path = $home . substr($path, 1);
        }

        return $path;
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDirectory(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }

        if (!is_dir($dst)) {
            if (!mkdir($dst, 0755, true) && !is_dir($dst)) {
                return false;
            }
        }

        $entries = scandir($src);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $entry;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                if (!$this->removeDirectory($path)) {
                    return false;
                }
            } else {
                if (!unlink($path)) {
                    return false;
                }
            }
        }

        return rmdir($dir);
    }
}
