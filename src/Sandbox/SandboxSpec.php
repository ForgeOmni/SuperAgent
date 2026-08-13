<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

/**
 * What a sandboxed command is allowed to touch.
 *
 * One spec is shared by every consumer wrapping commands for a session, so
 * bash and any future fs/subprocess consumers cannot confine to different
 * roots (deepseek-harness: "sandboxPolicy is a single home for mode +
 * workspace root").
 */
class SandboxSpec
{
    /**
     * @param string   $workspaceRoot  The directory the command may write to
     * @param string[] $writablePaths  Additional writable paths (temp dirs, caches)
     * @param bool     $allowNetwork   Whether outbound network is permitted
     */
    public function __construct(
        public readonly string $workspaceRoot,
        public readonly array $writablePaths = [],
        public readonly bool $allowNetwork = true,
    ) {
    }

    /**
     * All writable paths (workspace + extras), realpath-resolved where the
     * path exists — important on macOS where /tmp is a symlink into
     * /private/tmp and seatbelt subpath rules match the resolved form.
     *
     * @return string[]
     */
    public function resolvedWritablePaths(): array
    {
        $paths = array_merge([$this->workspaceRoot], $this->writablePaths);
        $resolved = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $real = realpath($path);
            $resolved[] = $real !== false ? $real : $path;
        }

        return array_values(array_unique($resolved));
    }
}
