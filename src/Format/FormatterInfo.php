<?php

declare(strict_types=1);

namespace SuperAgent\Format;

/**
 * Declarative description of a code formatter: name, supported extensions, and
 * a "probe" callable that decides whether the formatter is available + returns
 * the command to run for a given file.
 *
 * The probe is a `\Closure(FormatterContext): array|false` that:
 *   - returns `false` when the formatter is unavailable / not configured for
 *     this project, or
 *   - returns `[$bin, ...$args]` where the literal placeholder `$FILE` is
 *     substituted with the absolute target path by {@see FormatterRunner}.
 *
 * Probes are cheap (typically `which` + a filesystem `findUp` for the config
 * file) but may execute I/O; the registry memoises results per project root.
 */
final class FormatterInfo
{
    /**
     * @param array<int, string>      $extensions Lowercased extensions including the leading dot.
     * @param array<string, string>   $environment Extra env vars to inject into the subprocess.
     * @param \Closure(FormatterContext): array<int, string>|false $probe
     */
    public function __construct(
        public readonly string $name,
        public readonly array $extensions,
        public readonly \Closure $probe,
        public readonly array $environment = [],
    ) {
    }
}
