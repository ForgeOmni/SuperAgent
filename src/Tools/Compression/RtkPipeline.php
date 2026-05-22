<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression;

/**
 * RTK structured-output compression pipeline.
 *
 * Borrowed from 9Router's RTK Token Saver + claude-octopus' `octo-compress`
 * PostToolUse hook. Both projects observed that tool outputs from
 * `git diff`, `grep -rn`, `find`, `ls -R`, `tree` etc. dominate token
 * usage in coding sessions — typically 30-50% of input budget per turn.
 * Most of those tokens are repeated paths, color codes, blank lines,
 * and unchanged context.
 *
 * This class is a registry of per-tool compressors. Each compressor is
 * lossy-safe in the technical sense the model needs: file paths,
 * line numbers, diff hunks, match locations are preserved verbatim;
 * cosmetic noise is dropped. If a compressor enlarges output (rare —
 * usually because the input was already short) the original wins.
 *
 * Usage:
 *
 *   $rtk = new RtkPipeline();
 *   $compressed = $rtk->compress($toolName, $rawOutput);
 *
 * The shared compressors live in `Compressors/` and each implement
 * {@see CompressorInterface}. Adding a new tool is a one-file change.
 */
final class RtkPipeline
{
    /** @var array<string, CompressorInterface> Tool-name → compressor. */
    private array $byTool = [];

    /** @var list<callable(string, string): ?string> Fallback heuristics. */
    private array $heuristics = [];

    private int $totalBytesIn = 0;
    private int $totalBytesOut = 0;

    public function __construct()
    {
        $this->registerBuiltins();
    }

    public function register(string $toolName, CompressorInterface $compressor): void
    {
        $this->byTool[$this->normalize($toolName)] = $compressor;
    }

    /**
     * Compress a tool's output. Returns the compressed text, or the
     * original if compression would enlarge / break it. Always safe —
     * never throws on malformed input, just degrades to the original.
     */
    public function compress(string $toolName, string $output): string
    {
        if ($output === '') return $output;
        $this->totalBytesIn += strlen($output);

        $compressor = $this->byTool[$this->normalize($toolName)] ?? null;
        if ($compressor === null) {
            // Try heuristic match against output content (e.g., diff
            // header / grep "file:line:" pattern) so the pipeline still
            // saves tokens for unrecognised tool aliases.
            $compressor = $this->matchHeuristic($toolName, $output);
        }
        if ($compressor === null) {
            $this->totalBytesOut += strlen($output);
            return $output;
        }

        try {
            $compressed = $compressor->compress($output);
        } catch (\Throwable) {
            $this->totalBytesOut += strlen($output);
            return $output;
        }

        // Never enlarge — degrade silently if compression failed to help.
        if ($compressed === null || strlen($compressed) >= strlen($output)) {
            $this->totalBytesOut += strlen($output);
            return $output;
        }

        $this->totalBytesOut += strlen($compressed);
        return $compressed;
    }

    /** Cumulative byte savings since construction. */
    public function stats(): array
    {
        $savedBytes = max(0, $this->totalBytesIn - $this->totalBytesOut);
        $ratio = $this->totalBytesIn > 0 ? $savedBytes / $this->totalBytesIn : 0.0;
        return [
            'bytes_in' => $this->totalBytesIn,
            'bytes_out' => $this->totalBytesOut,
            'saved_bytes' => $savedBytes,
            'ratio' => round($ratio, 3),
        ];
    }

    private function registerBuiltins(): void
    {
        $this->register('git_diff', new Compressors\GitDiffCompressor());
        $this->register('git diff', new Compressors\GitDiffCompressor());
        $this->register('grep', new Compressors\GrepCompressor());
        $this->register('rg', new Compressors\GrepCompressor());
        $this->register('ripgrep', new Compressors\GrepCompressor());
        $this->register('find', new Compressors\FindCompressor());
        $this->register('ls', new Compressors\LsCompressor());
        $this->register('tree', new Compressors\TreeCompressor());
        // Aliases for SuperAgent / Claude Code tool names
        $this->register('Bash', new Compressors\BashOutputCompressor($this));
        $this->register('bash', new Compressors\BashOutputCompressor($this));
        $this->register('Grep', new Compressors\GrepCompressor());
        $this->register('Glob', new Compressors\FindCompressor());
    }

    private function matchHeuristic(string $toolName, string $output): ?CompressorInterface
    {
        // Heuristic order: diff header is most distinctive
        $head = substr($output, 0, 2048);
        if (preg_match('/^diff --git |^---\s+a\/|^\+\+\+\s+b\//m', $head)) {
            return new Compressors\GitDiffCompressor();
        }
        if (preg_match('/^[^:]+:\d+:/m', $head)) {
            return new Compressors\GrepCompressor();
        }
        if (preg_match('/^\.\/[\w\-.\/]+$/m', $head)) {
            return new Compressors\FindCompressor();
        }
        return null;
    }

    private function normalize(string $toolName): string
    {
        return strtolower(trim($toolName));
    }
}
