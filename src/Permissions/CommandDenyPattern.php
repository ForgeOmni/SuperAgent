<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Pattern for denying specific shell commands.
 * Uses fnmatch for flexible matching.
 */
class CommandDenyPattern
{
    public function __construct(
        public readonly string $pattern,  // e.g. "rm -rf /", "DROP TABLE*", "chmod 777*"
    ) {}

    /**
     * Check if a command matches this deny pattern.
     */
    public function matches(string $command): bool
    {
        return fnmatch($this->pattern, $command)
            || fnmatch($this->pattern, trim($command));
    }

    /**
     * @param string[] $patterns
     * @return self[]
     */
    public static function fromArray(array $patterns): array
    {
        return array_map(fn(string $p) => new self($p), $patterns);
    }
}
