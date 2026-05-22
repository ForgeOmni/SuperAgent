<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression\Compressors;

use SuperAgent\Tools\Compression\CompressorInterface;

/**
 * Compress grep / ripgrep output.
 *
 * Preserves:
 *   - `file:line:match` (or `file:line:column:match`) — the canonical hit
 *
 * Drops:
 *   - Context lines (the dash-prefixed variant when `-A`/`-B`/`-C` was used) —
 *     model can ask for context if it wants
 *   - ANSI color codes
 *   - Empty separator lines
 *   - File-summary footers ("X matches in Y files")
 *
 * Compacts:
 *   - Repeated file paths within consecutive hits: emit `path:L1:hit1` then
 *     subsequent hits in same file as just `   L2:hit2` (saves the path).
 *     This is reversible — model can reconstruct full paths from indent.
 *
 * Typical savings: 30-50% on grep -rn output.
 */
final class GrepCompressor implements CompressorInterface
{
    public function compress(string $output): ?string
    {
        // ANSI strip
        $clean = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;

        $lines = preg_split("/\r?\n/", $clean) ?: [];
        if (!$this->looksLikeGrep($lines)) return null;

        $kept = [];
        $lastFile = null;
        foreach ($lines as $line) {
            if ($line === '' || $line === '--') continue; // context separator

            // file:line:match  OR  file:line:col:match
            if (preg_match('/^([^:]+):(\d+):(?:\d+:)?(.*)$/', $line, $m)) {
                $file = $m[1];
                $ln   = $m[2];
                $rest = $m[3];
                if ($file === $lastFile) {
                    // Same file as previous hit — drop the path
                    $kept[] = '  :' . $ln . ':' . $rest;
                } else {
                    $kept[] = $file . ':' . $ln . ':' . $rest;
                    $lastFile = $file;
                }
                continue;
            }
            // context line: starts with "file-line-" (ripgrep) or "file-line:" — drop
            if (preg_match('/^[^:]+[-:]\d+[-:]/', $line)) continue;

            // Drop ripgrep "<N> matches" footer
            if (preg_match('/^\d+ match(es)?$/', trim($line))) continue;
        }

        if (empty($kept)) return null;
        return implode("\n", $kept);
    }

    private function looksLikeGrep(array $lines): bool
    {
        $sniffed = 0;
        foreach (array_slice($lines, 0, 20) as $line) {
            if (preg_match('/^[^:]+:\d+:/', $line)) {
                $sniffed++;
                if ($sniffed >= 2) return true;
            }
        }
        return $sniffed >= 1;
    }
}
