<?php

declare(strict_types=1);

namespace SuperAgent\Plugins;

/**
 * Represents a fully-loaded plugin with its resolved skills, hooks, and MCP configs.
 */
class LoadedPlugin
{
    public function __construct(
        public readonly PluginManifest $manifest,
        public readonly string $path,
        public readonly bool $enabled,
        public readonly array $skills = [],      // Skill definition arrays (parsed from .md files)
        public readonly array $hooks = [],       // Hook definition arrays (parsed from hooks.json)
        public readonly array $mcpServers = [],  // MCP server config arrays (parsed from mcp.json)
    ) {}

    /**
     * Return a copy with a different enabled state.
     */
    public function withEnabled(bool $enabled): self
    {
        return new self(
            manifest: $this->manifest,
            path: $this->path,
            enabled: $enabled,
            skills: $this->skills,
            hooks: $this->hooks,
            mcpServers: $this->mcpServers,
        );
    }
}
