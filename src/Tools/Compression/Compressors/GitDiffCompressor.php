<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression\Compressors;

use SuperAgent\Tools\Compression\CompressorInterface;

/**
 * Compress `git diff` / `git show` output.
 *
 * Preserves:
 *   - `diff --git a/X b/Y` headers
 *   - `--- a/X` / `+++ b/Y` file markers
 *   - `@@ -L,N +L,M @@` hunk headers
 *   - All `+` / `-` lines (the actual change)
 *   - File mode change markers (`new file mode`, `deleted file mode`,
 *     `rename from`, `rename to`)
 *
 * Drops:
 *   - Unchanged context lines (3 above + 3 below by default in git) —
 *     the +/- lines + hunk headers are enough for the model
 *   - `index <sha>..<sha>` lines (rarely useful for code review)
 *   - Trailing whitespace
 *
 * Typical savings: 40-65% on real-world diffs with normal context.
 */
final class GitDiffCompressor implements CompressorInterface
{
    public function compress(string $output): ?string
    {
        // Quick reject: must look like a git diff
        if (!preg_match('/^diff --git |^---\s+a\/|^\+\+\+\s+b\//m', substr($output, 0, 2048))) {
            return null;
        }

        $lines = preg_split("/\r?\n/", $output) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            // Drop index lines — they're cosmetic
            if (preg_match('/^index [0-9a-f]+\.\.[0-9a-f]+/', $line)) continue;

            // Drop trailing whitespace
            $line = rtrim($line);

            // Keep structural markers
            if (
                $line === ''
                || $line[0] === '+'
                || $line[0] === '-'
                || str_starts_with($line, 'diff --git')
                || str_starts_with($line, '@@')
                || str_starts_with($line, 'new file mode')
                || str_starts_with($line, 'deleted file mode')
                || str_starts_with($line, 'rename from')
                || str_starts_with($line, 'rename to')
                || str_starts_with($line, 'similarity index')
                || str_starts_with($line, 'copy from')
                || str_starts_with($line, 'copy to')
                || str_starts_with($line, 'old mode')
                || str_starts_with($line, 'new mode')
                || preg_match('/^Binary files /', $line)
            ) {
                $kept[] = $line;
                continue;
            }
            // Everything else is a context line (starts with ' ') — drop.
        }

        // Collapse runs of empty lines to single blanks.
        $compact = [];
        $prevBlank = false;
        foreach ($kept as $line) {
            if ($line === '') {
                if ($prevBlank) continue;
                $prevBlank = true;
            } else {
                $prevBlank = false;
            }
            $compact[] = $line;
        }

        return implode("\n", $compact);
    }
}
