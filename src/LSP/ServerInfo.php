<?php

declare(strict_types=1);

namespace SuperAgent\LSP;

/**
 * Declarative description of an LSP server: id, supported extensions, a
 * "root finder" closure that locates the project root for a given file, and
 * a "spawn" closure that builds the subprocess command line.
 *
 * The root finder walks upward from the edited file looking for marker files
 * (`package.json`, `go.mod`, `Cargo.toml`, `composer.json`, ...) and returns
 * the parent directory of the first hit. When no marker exists, it falls back
 * to the worktree root so the server still gets a sane cwd.
 *
 * Spawn closures receive `(rootDir): array|false` and either return a command
 * `[bin, ...args]` or `false` when the server binary isn't installed. The
 * manager subprocess-spawns the returned command with stdio pipes.
 */
final class ServerInfo
{
    /**
     * @param array<int, string>                                 $extensions Lowercased extensions including the dot.
     * @param \Closure(string $filePath, string $worktree): ?string $rootFinder
     * @param \Closure(string $root): (array<int, string>|false)  $spawn
     * @param array<string, mixed>                                $initializationOptions
     */
    public function __construct(
        public readonly string $id,
        public readonly array $extensions,
        public readonly \Closure $rootFinder,
        public readonly \Closure $spawn,
        public readonly array $initializationOptions = [],
    ) {
    }
}
