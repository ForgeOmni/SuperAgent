<?php

declare(strict_types=1);

namespace SuperAgent\Plugins;

/**
 * Represents the parsed contents of a plugin.json manifest file.
 */
class PluginManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $description = null,
        public readonly bool $enabledByDefault = false,
        public readonly string $skillsDir = 'skills',
        public readonly ?string $hooksFile = 'hooks.json',
        public readonly ?string $mcpFile = 'mcp.json',
    ) {}

    /**
     * Create a manifest from an associative array.
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Plugin manifest must include a "name" field.');
        }

        if (empty($data['version'])) {
            throw new \InvalidArgumentException('Plugin manifest must include a "version" field.');
        }

        return new self(
            name: (string) $data['name'],
            version: (string) $data['version'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            enabledByDefault: (bool) ($data['enabled_by_default'] ?? $data['enabledByDefault'] ?? false),
            skillsDir: (string) ($data['skills_dir'] ?? $data['skillsDir'] ?? 'skills'),
            hooksFile: isset($data['hooks_file']) || isset($data['hooksFile'])
                ? (string) ($data['hooks_file'] ?? $data['hooksFile'])
                : 'hooks.json',
            mcpFile: isset($data['mcp_file']) || isset($data['mcpFile'])
                ? (string) ($data['mcp_file'] ?? $data['mcpFile'])
                : 'mcp.json',
        );
    }

    /**
     * Load a manifest from a plugin.json file on disk.
     */
    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Plugin manifest file not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Failed to read plugin manifest: {$path}");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in plugin manifest: {$path}");
        }

        return self::fromArray($data);
    }
}
