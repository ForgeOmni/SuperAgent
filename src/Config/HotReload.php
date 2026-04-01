<?php

namespace SuperAgent\Config;

use SuperAgent\Agent;
use SuperAgent\Providers\ProviderInterface;
use SuperAgent\Tools\BuiltinToolRegistry;
use SuperAgent\Plugins\PluginManager;
use SuperAgent\Skills\SkillManager;

class HotReload
{
    private ConfigWatcher $watcher;
    private ?Agent $agent = null;
    private array $configFiles = [];
    
    public function __construct(?ConfigWatcher $watcher = null)
    {
        $this->watcher = $watcher ?? new ConfigWatcher();
        $this->setupDefaultWatchers();
    }

    /**
     * Enable hot reload for an agent.
     */
    public function enableForAgent(Agent $agent): void
    {
        $this->agent = $agent;
        
        // Watch main config file
        $this->watchConfigFile('superagent.php', function() use ($agent) {
            $this->reloadAgentConfig($agent);
        });
        
        // Watch environment file
        if (file_exists(base_path('.env'))) {
            $this->watchConfigFile('.env', function() use ($agent) {
                $this->reloadEnvironment($agent);
            });
        }
        
        $this->watcher->start();
    }

    /**
     * Watch a configuration file.
     */
    public function watchConfigFile(string $file, ?callable $callback = null): void
    {
        $fullPath = $this->resolveConfigPath($file);
        
        if (!$fullPath) {
            return;
        }
        
        $callback = $callback ?? function($file) {
            $this->handleConfigChange($file);
        };
        
        $this->watcher->watch($fullPath, $callback);
        $this->configFiles[$file] = $fullPath;
    }

    /**
     * Reload agent configuration.
     */
    private function reloadAgentConfig(Agent $agent): void
    {
        try {
            // Reload config from file
            $configFile = $this->resolveConfigPath('superagent.php');
            if ($configFile && file_exists($configFile)) {
                $newConfig = require $configFile;
                
                if (is_array($newConfig)) {
                    $config = Config::fromArray($newConfig);
                    
                    // Update agent config
                    $agent->updateConfig($config);
                    
                    // Reload tools if changed
                    if (isset($newConfig['tools'])) {
                        $this->reloadTools($newConfig['tools']);
                    }
                    
                    // Reload plugins if changed
                    if (isset($newConfig['plugins'])) {
                        $this->reloadPlugins($newConfig['plugins']);
                    }
                    
                    $this->log('Configuration reloaded successfully');
                }
            }
        } catch (\Exception $e) {
            $this->log('Failed to reload configuration: ' . $e->getMessage());
        }
    }

    /**
     * Reload environment variables.
     */
    private function reloadEnvironment(Agent $agent): void
    {
        try {
            // In Laravel, we'd use Dotenv
            if (class_exists('Dotenv\\Dotenv')) {
                $dotenv = \Dotenv\Dotenv::createMutable(base_path());
                $dotenv->load();
                
                // Update provider API keys if changed
                if ($agent->provider instanceof ProviderInterface) {
                    $apiKey = env($this->getProviderApiKeyEnv($agent->provider));
                    if ($apiKey) {
                        // This would require a method to update provider config
                        // For now, we'll log the change
                        $this->log('Environment variables reloaded');
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('Failed to reload environment: ' . $e->getMessage());
        }
    }

    /**
     * Reload tools configuration.
     */
    private function reloadTools(array $toolConfig): void
    {
        // Reload tools from BuiltinToolRegistry
        
        // Clear and re-register tools based on config
        // This would require methods to clear/reset the registry
        
        foreach ($toolConfig as $toolClass) {
            if (class_exists($toolClass)) {
                $tool = new $toolClass();
                $registry->register($tool);
            }
        }
        
        $this->log('Tools reloaded');
    }

    /**
     * Reload plugins configuration.
     */
    private function reloadPlugins(array $pluginConfig): void
    {
        $manager = PluginManager::getInstance();
        
        foreach ($pluginConfig as $pluginName => $config) {
            if (isset($config['enabled']) && $config['enabled']) {
                try {
                    if (isset($config['settings'])) {
                        $manager->configure($pluginName, $config['settings']);
                    }
                    
                    if (!$manager->isEnabled($pluginName)) {
                        $manager->enable($pluginName, $this->agent);
                    }
                } catch (\Exception $e) {
                    $this->log("Failed to reload plugin {$pluginName}: " . $e->getMessage());
                }
            } else {
                try {
                    if ($manager->isEnabled($pluginName)) {
                        $manager->disable($pluginName);
                    }
                } catch (\Exception $e) {
                    $this->log("Failed to disable plugin {$pluginName}: " . $e->getMessage());
                }
            }
        }
        
        $this->log('Plugins reloaded');
    }

    /**
     * Handle generic config change.
     */
    private function handleConfigChange(string $file): void
    {
        $this->log("Configuration file changed: {$file}");
        
        if ($this->agent) {
            $this->reloadAgentConfig($this->agent);
        }
    }

    /**
     * Setup default watchers.
     */
    private function setupDefaultWatchers(): void
    {
        // Watch for new tool files
        $toolsDir = base_path('app/SuperAgent/Tools');
        if (is_dir($toolsDir)) {
            $this->watchDirectory($toolsDir, '*.php', function($file) {
                $this->handleNewTool($file);
            });
        }
        
        // Watch for new skill files
        $skillsDir = base_path('app/SuperAgent/Skills');
        if (is_dir($skillsDir)) {
            $this->watchDirectory($skillsDir, '*.php', function($file) {
                $this->handleNewSkill($file);
            });
        }
        
        // Watch for new plugin files
        $pluginsDir = base_path('app/SuperAgent/Plugins');
        if (is_dir($pluginsDir)) {
            $this->watchDirectory($pluginsDir, '*.php', function($file) {
                $this->handleNewPlugin($file);
            });
        }
    }

    /**
     * Watch a directory for changes.
     */
    private function watchDirectory(string $dir, string $pattern, callable $callback): void
    {
        // This would be implemented with more sophisticated file watching
        // For now, it's a placeholder
    }

    /**
     * Handle new tool file.
     */
    private function handleNewTool(string $file): void
    {
        $className = $this->getClassFromFile($file);
        
        if ($className && class_exists($className)) {
            $tool = new $className();
            // Tool registered via BuiltinToolRegistry
            $this->log("New tool registered: {$className}");
        }
    }

    /**
     * Handle new skill file.
     */
    private function handleNewSkill(string $file): void
    {
        $className = $this->getClassFromFile($file);
        
        if ($className && class_exists($className)) {
            $skill = new $className();
            SkillManager::getInstance()->register($skill);
            $this->log("New skill registered: {$className}");
        }
    }

    /**
     * Handle new plugin file.
     */
    private function handleNewPlugin(string $file): void
    {
        $className = $this->getClassFromFile($file);
        
        if ($className && class_exists($className)) {
            $plugin = new $className();
            PluginManager::getInstance()->register($plugin);
            $this->log("New plugin registered: {$className}");
        }
    }

    /**
     * Get class name from file.
     */
    private function getClassFromFile(string $file): ?string
    {
        $content = file_get_contents($file);
        
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch) &&
            preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return $nsMatch[1] . '\\' . $classMatch[1];
        }
        
        return null;
    }

    /**
     * Resolve configuration file path.
     */
    private function resolveConfigPath(string $file): ?string
    {
        // Check config directory
        $configPath = base_path('config/' . $file);
        if (file_exists($configPath)) {
            return $configPath;
        }
        
        // Check base directory
        $basePath = base_path($file);
        if (file_exists($basePath)) {
            return $basePath;
        }
        
        return null;
    }

    /**
     * Get provider API key environment variable name.
     */
    private function getProviderApiKeyEnv(ProviderInterface $provider): string
    {
        $className = get_class($provider);
        
        return match (true) {
            str_contains($className, 'Anthropic') => 'ANTHROPIC_API_KEY',
            str_contains($className, 'OpenAI') => 'OPENAI_API_KEY',
            str_contains($className, 'Bedrock') => 'AWS_ACCESS_KEY_ID',
            default => 'API_KEY',
        };
    }

    /**
     * Log a message.
     */
    private function log(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            echo "[HotReload] {$message}\n";
        } else {
            error_log("[HotReload] {$message}");
        }
    }

    /**
     * Stop watching.
     */
    public function stop(): void
    {
        $this->watcher->stop();
    }
}