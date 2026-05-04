<?php

declare(strict_types=1);

namespace SuperAgent\Plugins;

/**
 * Parsed `marketplace.json` — the index file shipped at the root of a
 * plugin marketplace (see `ruflo/.claude-plugin/marketplace.json` for the
 * canonical example).
 *
 * Shape:
 *   {
 *     "name": "ruflo",
 *     "description": "...",
 *     "owner": { "name": "ruvnet", "url": "https://github.com/ruvnet" },
 *     "plugins": [
 *       { "name": "ruflo-sparc", "source": "./plugins/ruflo-sparc", "description": "..." },
 *       ...
 *     ]
 *   }
 *
 * Each `plugins[].source` is resolved relative to the marketplace.json's
 * containing directory — `MarketplaceEntry::resolvedPath()` handles that.
 *
 * Same value class works for both SuperAgent and SuperAICore — schema is
 * intentionally minimal.
 */
final class MarketplaceManifest
{
    /**
     * @param MarketplaceEntry[] $plugins
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $ownerName,
        public readonly ?string $ownerUrl,
        public readonly array $plugins,
        public readonly string $rootDir,
    ) {}

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Marketplace manifest not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read marketplace manifest: {$path}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in marketplace manifest: {$path}");
        }

        return self::fromArray($data, self::resolveRootDir($path));
    }

    /**
     * Claude Code's convention: marketplace.json lives at
     * `<repo>/.claude-plugin/marketplace.json` and `plugins[].source`
     * paths are relative to `<repo>/`. Detect that layout and walk up
     * one level so relative sources resolve correctly. For non-ruflo
     * layouts (marketplace.json at the actual root), `dirname()` is fine.
     */
    private static function resolveRootDir(string $manifestPath): string
    {
        $dir = dirname($manifestPath);
        if (basename($dir) === '.claude-plugin') {
            $parent = dirname($dir);
            if ($parent !== $dir) {
                return $parent;
            }
        }
        return $dir;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $rootDir): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Marketplace manifest must include a "name" field.');
        }

        $owner = $data['owner'] ?? null;
        $ownerName = null;
        $ownerUrl = null;
        if (is_string($owner)) {
            $ownerName = $owner;
        } elseif (is_array($owner)) {
            $ownerName = isset($owner['name']) ? (string) $owner['name'] : null;
            $ownerUrl  = isset($owner['url'])  ? (string) $owner['url']  : null;
        }

        $entries = [];
        foreach (($data['plugins'] ?? []) as $row) {
            if (!is_array($row) || empty($row['name']) || empty($row['source'])) {
                continue;
            }
            $entries[] = new MarketplaceEntry(
                name: (string) $row['name'],
                source: (string) $row['source'],
                description: isset($row['description']) ? (string) $row['description'] : null,
                rootDir: $rootDir,
            );
        }

        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            ownerName: $ownerName,
            ownerUrl: $ownerUrl,
            plugins: $entries,
            rootDir: $rootDir,
        );
    }
}
