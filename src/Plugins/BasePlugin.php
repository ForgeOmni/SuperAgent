<?php

namespace SuperAgent\Plugins;

use SuperAgent\Agent;

abstract class BasePlugin implements PluginInterface
{
    protected array $config = [];
    
    public function version(): string
    {
        return '1.0.0';
    }
    
    public function boot(): void
    {
        // Override in subclass if needed
    }
    
    public function register(): void
    {
        // Override in subclass if needed
    }
    
    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }
    
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
    
    public function getConfig(): array
    {
        return $this->config;
    }
    
    public function dependencies(): array
    {
        return [];
    }
    
    public function enable(): void
    {
        // Override in subclass if needed
    }
    
    public function disable(): void
    {
        // Override in subclass if needed
    }
    
    public function isCompatible(Agent $agent): bool
    {
        return true;
    }

    public function middleware(): array
    {
        return [];
    }

    public function providers(): array
    {
        return [];
    }
}