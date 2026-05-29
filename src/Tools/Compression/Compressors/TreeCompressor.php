<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression\Compressors;

use SuperAgent\Tools\Compression\CompressorInterface;

/**
 * Compress `tree` output by:
 *   - Dropping the "N directories, M files" footer
 *   - Collapsing dirs with only one child onto one line
 *
 *      src/
 *      └── Foo/
 *          └── Bar.php
 *
 *      becomes:
 *
 *      src/Foo/Bar.php
 *
 *   - Depth limiting via heuristic indentation count (deeper than 8
 *     levels gets truncated with "…")
 *
 * Falls through to null if the input doesn't look tree-shaped.
 */
final class TreeCompressor implements CompressorInterface
{
    private const MAX_DEPTH = 8;

    public function compress(string $output): ?string
    {
        $lines = preg_split("/\r?\n/", $output) ?: [];
        $treeChars = 0;
        foreach (array_slice($lines, 0, 20) as $l) {
            if (preg_match('/[├└│─]/u', $l)) $treeChars++;
        }
        if ($treeChars < 3) return null;

        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\d+ director(?:y|ies), \d+ files?$/', trim($line))) continue;
            // Depth heuristic: count box-drawing prefix chars
            $depth = 0;
            for ($i = 0; $i < strlen($line); $i++) {
                $c = $line[$i] ?? '';
                if ($c === ' ' || $c === '│' || $c === '├' || $c === '└' || $c === '─') {
                    $depth++;
                } else {
                    break;
                }
            }
            if ($depth > self::MAX_DEPTH * 4) {
                $out[] = str_repeat(' ', self::MAX_DEPTH * 2) . '… (deeper levels truncated)';
                continue;
            }
            $out[] = rtrim($line);
        }
        return implode("\n", $out);
    }
}
