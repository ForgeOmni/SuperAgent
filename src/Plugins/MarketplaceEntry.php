<?php

declare(strict_types=1);

namespace SuperAgent\Plugins;

/**
 * One row from a marketplace.json `plugins[]` array.
 *
 * `source` is stored verbatim (typically `./plugins/<name>` for monorepo-
 * style marketplaces). `resolvedPath()` joins it against the marketplace
 * root so callers can `loadPlugin()` it directly.
 *
 * Future extension: `source` could also be a git URL or registry name.
 * Those resolutions belong on a separate `MarketplaceResolver` service —
 * this class just records what the manifest declared.
 */
final class MarketplaceEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly ?string $description,
        public readonly string $rootDir,
    ) {}

    /**
     * Absolute path to the plugin directory if the source is a relative
     * filesystem path. Returns the source verbatim for non-path sources
     * (git URLs, etc.) so callers can detect and route differently.
     */
    public function resolvedPath(): string
    {
        $src = $this->source;
        // Heuristic: protocol scheme present? leave alone.
        if (preg_match('#^[a-z]+://#i', $src) === 1) {
            return $src;
        }
        // Absolute path?
        if (preg_match('#^([a-zA-Z]:|/)#', $src) === 1) {
            return $src;
        }
        // Relative — join under marketplace root.
        $joined = rtrim($this->rootDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($src, '/\\');
        $real = realpath($joined);
        return $real !== false ? $real : $joined;
    }
}
