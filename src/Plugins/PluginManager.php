<?php

namespace SuperAgent\Plugins;

use SuperAgent\Agent;
use SuperAgent\Tools\ToolRegistry;

class PluginManager
{
    private static ?self $instance = null;
    
    private array $plugins = [];
    private array $enabled = [];
    private array $config = [];
    
    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->loadConfiguration();
    }

    /**
     * Register a plugin.
     */
    public function register(PluginInterface $plugin): void
    {
        $name = $plugin->name();
        
        if (isset($this->plugins[$name])) {
            throw new \RuntimeException("Plugin already registered: {$name}");
        }
        
        // Check dependencies
        foreach ($plugin->dependencies() as $dependency) {
            if (!isset($this->plugins[$dependency])) {
                throw new \RuntimeException("Missing dependency for {$name}: {$dependency}");
            }
        }
        
        $this->plugins[$name] = $plugin;
        
        // Apply configuration if available
        if (isset($this->config[$name])) {
            $plugin->setConfig($this->config[$name]);
        }
        
        // Register plugin services
        $plugin->register();
    }

    /**
     * Enable a plugin.
     */
    public function enable(string $name, ?Agent $agent = null): void
    {
        if (!isset($this->plugins[$name])) {
            throw new \RuntimeException("Plugin not found: {$name}");
        }
        
        if (in_array($name, $this->enabled)) {
            return; // Already enabled
        }
        
        $plugin = $this->plugins[$name];
        
        // Check compatibility if agent provided
        if ($agent && !$plugin->isCompatible($agent)) {
            throw new \RuntimeException("Plugin not compatible: {$name}");
        }
        
        // Enable dependencies first
        foreach ($plugin->dependencies() as $dependency) {
            $this->enable($dependency, $agent);
        }
        
        // Boot and enable the plugin
        $plugin->boot();
        $plugin->enable();
        
        $this->enabled[] = $name;
    }

    /**
     * Disable a plugin.
     */
    public function disable(string $name): void
    {
        if (!in_array($name, $this->enabled)) {
            return; // Not enabled
        }
        
        // Check if other enabled plugins depend on this one
        foreach ($this->enabled as $enabledName) {
            if ($enabledName === $name) {
                continue;
            }
            
            $plugin = $this->plugins[$enabledName];
            if (in_array($name, $plugin->dependencies())) {
                throw new \RuntimeException("Cannot disable {$name}: {$enabledName} depends on it");
            }
        }
        
        $plugin = $this->plugins[$name];
        $plugin->disable();
        
        $this->enabled = array_diff($this->enabled, [$name]);
    }

    /**
     * Get all registered plugins.
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Get enabled plugins.
     */
    public function getEnabled(): array
    {
        return array_intersect_key($this->plugins, array_flip($this->enabled));
    }

    /**
     * Get a plugin by name.
     */
    public function get(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Check if a plugin is enabled.
     */
    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabled);
    }

    /**
     * Load plugins from a directory.
     */
    public function loadFromDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = glob($directory . '/*Plugin.php');
        
        foreach ($files as $file) {
            $className = 'App\\SuperAgent\\Plugins\\' . basename($file, '.php');
            
            if (class_exists($className)) {
                $plugin = new $className();
                
                if ($plugin instanceof PluginInterface) {
                    $this->register($plugin);
                }
            }
        }
    }

    /**
     * Auto-discover and load plugins.
     */
    public function discover(): void
    {
        // Load from app plugins directory
        $appPluginsDir = base_path('app/SuperAgent/Plugins');
        $this->loadFromDirectory($appPluginsDir);
        
        // Load from vendor plugins (if using Composer)
        $vendorPlugins = $this->discoverComposerPlugins();
        foreach ($vendorPlugins as $pluginClass) {
            if (class_exists($pluginClass)) {
                $plugin = new $pluginClass();
                if ($plugin instanceof PluginInterface) {
                    $this->register($plugin);
                }
            }
        }
    }

    /**
     * Discover plugins from Composer packages.
     */
    private function discoverComposerPlugins(): array
    {
        $plugins = [];
        $composerFile = base_path('composer.json');
        
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            
            // Look for superagent plugins in extra section
            if (isset($composer['extra']['superagent']['plugins'])) {
                $plugins = $composer['extra']['superagent']['plugins'];
            }
        }
        
        return $plugins;
    }

    /**
     * Load plugin configuration.
     */
    private function loadConfiguration(): void
    {
        try {
            if (function_exists('config')) {
                $this->config = config('superagent.plugins', []);
            }
        } catch (\Throwable $e) {
            // No Laravel config available (e.g., unit tests without full app)
            $this->config = [];
        }
    }

    /**
     * Set configuration for a plugin.
     */
    public function configure(string $name, array $config): void
    {
        $this->config[$name] = $config;
        
        if (isset($this->plugins[$name])) {
            $this->plugins[$name]->setConfig($config);
        }
    }

    /**
     * Reset the singleton instance (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}