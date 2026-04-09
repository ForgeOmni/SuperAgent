<?php

namespace SuperAgent\Plugins;

use SuperAgent\Agent;

interface PluginInterface
{
    /**
     * Get the plugin name.
     */
    public function name(): string;

    /**
     * Get the plugin version.
     */
    public function version(): string;

    /**
     * Get the plugin description.
     */
    public function description(): string;

    /**
     * Bootstrap the plugin.
     * Called when the plugin is loaded.
     */
    public function boot(): void;

    /**
     * Register plugin services.
     * Called before boot to register tools, providers, etc.
     */
    public function register(): void;

    /**
     * Get plugin configuration schema.
     */
    public function configSchema(): array;

    /**
     * Set plugin configuration.
     */
    public function setConfig(array $config): void;

    /**
     * Get plugin dependencies.
     * @return array List of required plugin names
     */
    public function dependencies(): array;

    /**
     * Called when plugin is enabled.
     */
    public function enable(): void;

    /**
     * Called when plugin is disabled.
     */
    public function disable(): void;

    /**
     * Check if the plugin is compatible with the current agent.
     */
    public function isCompatible(Agent $agent): bool;

    /**
     * Get middleware provided by this plugin.
     *
     * @return \SuperAgent\Middleware\MiddlewareInterface[]
     */
    public function middleware(): array;

    /**
     * Get LLM provider drivers provided by this plugin.
     * Keys are provider names, values are fully qualified class names.
     *
     * @return array<string, class-string<\SuperAgent\Contracts\LLMProvider>>
     */
    public function providers(): array;
}