<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression\Compressors;

use SuperAgent\Tools\Compression\CompressorInterface;
use SuperAgent\Tools\Compression\RtkPipeline;

/**
 * Bash tool output is heterogeneous — could contain git diff, grep, find,
 * ls, tree, or arbitrary command output. This compressor sniffs the
 * content and delegates to the matching specialised compressor.
 *
 * For arbitrary command output we still apply two universal cleanups:
 *   - Strip ANSI color codes (saves ~5-10% on colored shells)
 *   - Drop trailing whitespace
 *   - Cap repeated identical lines (e.g. "Waiting…" loops)
 */
final class BashOutputCompressor implements CompressorInterface
{
    public function __construct(private RtkPipeline $pipeline) {}

    public function compress(string $output): ?string
    {
        // ANSI strip first — every specialised compressor benefits.
        $clean = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;

        // Sniff: does this look like a known structured format?
        $head = substr($clean, 0, 2048);
        if (preg_match('/^diff --git /m', $head)) {
            return $this->pipeline->compress('git_diff', $clean);
        }
        if (preg_match('/^[^:]+:\d+:/m', $head)) {
            return $this->pipeline->compress('grep', $clean);
        }
        if (preg_match('/[├└│─]/u', $head)) {
            return $this->pipeline->compress('tree', $clean);
        }

        // Generic cleanup: trailing whitespace + repeated-line cap.
        $lines = preg_split("/\r?\n/", $clean) ?: [];
        $out = [];
        $prevLine = null;
        $repeatCount = 0;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === $prevLine) {
                $repeatCount++;
                if ($repeatCount === 3) {
                    $out[] = '  … (repeated lines truncated)';
                }
                if ($repeatCount >= 3) continue;
            } else {
                $repeatCount = 0;
            }
            $out[] = $line;
            $prevLine = $line;
        }
        $result = implode("\n", $out);

        // Only return if we actually shrank it (otherwise pipeline keeps original)
        return strlen($result) < strlen($output) ? $result : null;
    }
}
