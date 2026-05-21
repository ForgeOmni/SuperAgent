<?php

declare(strict_types=1);

namespace SuperAgent\Format;

/**
 * Project context passed to a formatter probe so it can decide whether to fire.
 *
 * `directory` is the working directory the agent is editing in; `worktree` is
 * the highest ancestor the probe should walk up to when looking for project-
 * scoped config files (`package.json`, `pyproject.toml`, `composer.json`,
 * `.clang-format`, ...). Matches opencode's two-arg `findUp`.
 */
final class FormatterContext
{
    public function __construct(
        public readonly string $directory,
        public readonly string $worktree,
    ) {
    }
}
