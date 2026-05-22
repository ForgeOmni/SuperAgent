<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression\Compressors;

use SuperAgent\Tools\Compression\CompressorInterface;

/**
 * Compress `find` / `glob` output (one path per line).
 *
 * Strategy: prefix-compress paths against the previous line. When the new
 * line shares a common directory prefix with the previous, emit a tab-indented
 * relative suffix.
 *
 *   src/Foo/Bar.php
 *   src/Foo/Baz.php
 *   src/Quux/Quux.php
 *
 *   becomes:
 *
 *   src/Foo/Bar.php
 *        Baz.php
 *   src/Quux/Quux.php
 *
 * Model can reconstruct full path by joining indented suffix onto the
 * most recent non-indented path's directory. Typical savings: 20-40%.
 */
final class FindCompressor implements CompressorInterface
{
    public function compress(string $output): ?string
    {
        $lines = preg_split("/\r?\n/", $output) ?: [];
        if (count($lines) < 5) return null;

        // Sanity: do most lines look like paths?
        $pathish = 0;
        foreach (array_slice($lines, 0, 20) as $l) {
            if (str_contains($l, '/') || str_contains($l, '\\')) $pathish++;
        }
        if ($pathish < 3) return null;

        $out = [];
        $prevDir = null;
        foreach ($lines as $line) {
            if ($line === '') { $out[] = ''; continue; }
            $parts = explode('/', $line);
            if (count($parts) < 2) {
                $out[] = $line;
                $prevDir = null;
                continue;
            }
            $file = array_pop($parts);
            $dir = implode('/', $parts);
            if ($dir === $prevDir) {
                $out[] = str_repeat(' ', min(8, strlen($dir))) . $file;
            } else {
                $out[] = $line;
                $prevDir = $dir;
            }
        }
        return implode("\n", $out);
    }
}
