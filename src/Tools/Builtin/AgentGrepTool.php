<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Builtin\Symbols\RegexSymbolExtractor;
use SuperAgent\Tools\Builtin\Symbols\SymbolExtractor;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\Tools\ToolStateManager;

/**
 * Agent-aware grep that augments raw matches with two pieces of context an
 * LLM uses well:
 *
 *   1. **Symbol scope** — for PHP / JS / TS / Python / Go files, every hit
 *      is annotated with the enclosing class + function/method name so the
 *      agent can tell `function login(...)` from `function logout(...)`
 *      without reading the whole file.
 *   2. **Adaptive truncation** — a per-session "seen chunk" set keyed by
 *      file SHA + line range. Repeat queries to the same chunk get
 *      truncated to a `... (lines N–M previously shown to you in this
 *      session)` marker, so a long-running agent stops re-paying tokens
 *      for the same prefix.
 *
 * Borrowed in spirit from jcode's `agent grep`. The symbol extractor is
 * pure-PHP (regex over the file source) — no tree-sitter dependency, so
 * the tool ships with the SDK and degrades to plain matches on language
 * families it doesn't recognise. PHP / JS / TS / Python / Go cover ~95%
 * of typical agent workloads.
 *
 * Tool name `agent_grep` is intentional — leaves the existing `grep` tool
 * untouched as the byte-for-byte raw alternative for callers that want
 * exactly ripgrep's output.
 */
class AgentGrepTool extends Tool
{
    /** Lines of context joined into the symbol-name lookup window. */
    private const SYMBOL_LOOKBACK = 200;

    /** Max lines of file source we scan for symbols (capped to keep the tool cheap). */
    private const MAX_SYMBOL_SCAN_LINES = 5000;

    /**
     * Symbol-extraction SPI. Default is the always-on regex extractor that
     * shipped with the original tool; hosts that want tree-sitter precision
     * pass a `CompositeSymbolExtractor([new TreeSitterSymbolExtractor(), …])`.
     */
    private readonly SymbolExtractor $symbolExtractor;

    public function __construct(?SymbolExtractor $symbolExtractor = null)
    {
        $this->symbolExtractor = $symbolExtractor ?? new RegexSymbolExtractor();
    }

    public function name(): string
    {
        return 'agent_grep';
    }

    public function description(): string
    {
        return 'Grep that injects enclosing-symbol context (function/class/method) into each hit AND truncates chunks the agent has already seen this session. Prefer this over `grep` for long-running tasks on big repos — same regex syntax, fewer wasted tokens.';
    }

    public function category(): string
    {
        return 'search';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Regular expression pattern. Same flavour as PHP PCRE / ripgrep (Rust regex with PCRE-like syntax).',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'File or directory to search in. Default: current working directory.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Glob filter ("*.php", "**/*.{ts,tsx}").',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Language family — `php` | `js` | `ts` | `python` | `go`. Limits the file walk and enables symbol injection.',
                ],
                'context_before' => [
                    'type' => 'integer',
                    'description' => 'Lines of context before each hit. Default 0.',
                ],
                'context_after' => [
                    'type' => 'integer',
                    'description' => 'Lines of context after each hit. Default 0.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum hits returned. Default 100. Capped at 1000.',
                ],
                'case_insensitive' => [
                    'type' => 'boolean',
                    'description' => 'Case-insensitive matching. Default false.',
                ],
                'symbols' => [
                    'type' => 'boolean',
                    'description' => 'Inject enclosing-symbol context per hit. Default true (the whole point of this tool — disable only for ripgrep parity).',
                ],
                'forget_seen' => [
                    'type' => 'boolean',
                    'description' => 'Reset the per-session seen-chunk ledger before searching. Useful when the agent suspects code has changed under it.',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $pattern = (string) ($input['pattern'] ?? '');
        if ($pattern === '') {
            return ToolResult::error('Pattern cannot be empty.');
        }
        $regexFlags = !empty($input['case_insensitive']) ? 'i' : '';
        if (@preg_match('/' . $pattern . '/' . $regexFlags, '') === false) {
            return ToolResult::error('Invalid regular expression pattern.');
        }

        $path = (string) ($input['path'] ?? getcwd());
        $glob = $input['glob'] ?? null;
        $langType = isset($input['type']) ? (string) $input['type'] : null;
        $contextBefore = max(0, (int) ($input['context_before'] ?? 0));
        $contextAfter = max(0, (int) ($input['context_after'] ?? 0));
        $limit = min(max(1, (int) ($input['limit'] ?? 100)), 1000);
        $injectSymbols = (bool) ($input['symbols'] ?? true);

        if (!empty($input['forget_seen'])) {
            $this->forgetSeen();
        }

        $files = $this->resolveFiles($path, $glob, $langType);
        if ($files === []) {
            return ToolResult::success('No files matched the search root.');
        }

        $hits = [];
        $skippedSeen = 0;
        $truncations = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) continue;
            $lineCount = count($lines);
            if ($lineCount > self::MAX_SYMBOL_SCAN_LINES * 4) {
                // Skip enormous files — most likely lockfiles / minified
                // bundles / generated artefacts. Caller can re-run with
                // type=<lang> to opt back in for a known family.
                continue;
            }

            $symbolsByLine = $injectSymbols
                ? $this->symbolExtractor->extract($file, $lines, $langType)
                : [];

            // Match per-line.
            $matches = [];
            foreach ($lines as $i => $text) {
                if (preg_match('/' . $pattern . '/' . $regexFlags, $text)) {
                    $matches[] = $i;
                    if (count($hits) + count($matches) >= $limit * 2) break;
                }
            }
            if ($matches === []) continue;

            // Group adjacent matches into chunks (so a pattern that hits
            // 5 lines in a row becomes one entry, not five).
            $chunks = $this->groupChunks($matches, $lineCount, $contextBefore, $contextAfter);

            foreach ($chunks as [$chunkStart, $chunkEnd]) {
                if (count($hits) >= $limit) break 2;

                $chunkText = implode("\n", array_slice($lines, $chunkStart, $chunkEnd - $chunkStart + 1));
                $chunkSha  = substr(sha1($chunkText), 0, 12);
                $seenKey   = $file . ':' . $chunkStart . '-' . $chunkEnd . ':' . $chunkSha;

                if ($this->wasSeen($seenKey)) {
                    $skippedSeen++;
                    $truncations[] = [
                        'file' => $this->relPath($file),
                        'lines' => ($chunkStart + 1) . '-' . ($chunkEnd + 1),
                        'note' => 'previously shown to you in this session',
                    ];
                    continue;
                }
                $this->markSeen($seenKey);

                $symbol = $this->resolveEnclosingSymbol($symbolsByLine, $matches[0]);
                $entry = [
                    'file'   => $this->relPath($file),
                    'lines'  => ($chunkStart + 1) . '-' . ($chunkEnd + 1),
                    'symbol' => $symbol,
                    'text'   => $chunkText,
                ];
                $hits[] = $entry;
            }
        }

        return ToolResult::success([
            'pattern'        => $pattern,
            'hit_count'      => count($hits),
            'skipped_seen'   => $skippedSeen,
            'truncated_seen' => $truncations,
            'matches'        => $hits,
        ]);
    }

    // ── Symbol extraction routed through Symbols\SymbolExtractor SPI ─

    /**
     * Walk upward from `$line` and return the nearest preceding symbol.
     * @param array<int, string> $map
     */
    private function resolveEnclosingSymbol(array $map, int $line): ?string
    {
        if ($map === []) return null;
        for ($i = $line; $i >= max(0, $line - self::SYMBOL_LOOKBACK); $i--) {
            if (isset($map[$i])) return $map[$i];
        }
        // Wider scan for languages with very large functions.
        for ($i = $line - self::SYMBOL_LOOKBACK; $i >= 0; $i--) {
            if (isset($map[$i])) return $map[$i];
        }
        return null;
    }

    /**
     * @param  list<int> $matches  match line numbers (0-indexed)
     * @return list<array{0:int,1:int}>  list of [chunkStart, chunkEnd] pairs
     */
    private function groupChunks(array $matches, int $lineCount, int $before, int $after): array
    {
        if ($matches === []) return [];
        sort($matches);
        $chunks = [];
        $chunkStart = max(0, $matches[0] - $before);
        $chunkEnd = min($lineCount - 1, $matches[0] + $after);
        for ($i = 1; $i < count($matches); $i++) {
            $hit = $matches[$i];
            $hitStart = max(0, $hit - $before);
            $hitEnd = min($lineCount - 1, $hit + $after);
            if ($hitStart <= $chunkEnd + 1) {
                $chunkEnd = max($chunkEnd, $hitEnd);
            } else {
                $chunks[] = [$chunkStart, $chunkEnd];
                $chunkStart = $hitStart;
                $chunkEnd = $hitEnd;
            }
        }
        $chunks[] = [$chunkStart, $chunkEnd];
        return $chunks;
    }

    // ── File discovery ────────────────────────────────────────────

    /**
     * Walk the search root, applying the optional glob and language type.
     *
     * @return list<string>  absolute file paths
     */
    private function resolveFiles(string $path, ?string $glob, ?string $langType): array
    {
        if (is_file($path)) return [$path];
        if (!is_dir($path)) return [];

        $exts = $this->extsForLang($langType);

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                static function ($file) {
                    $base = $file->getBasename();
                    if (in_array($base, ['vendor', 'node_modules', '.git', '__pycache__', '.venv', 'dist', 'build', 'target'], true)) {
                        return false;
                    }
                    return true;
                }
            )
        );

        $files = [];
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            $abs = $f->getPathname();
            $ext = strtolower($f->getExtension());
            if ($exts !== null && !in_array($ext, $exts, true)) continue;
            if ($glob !== null && !fnmatch($glob, $f->getBasename())) continue;
            $files[] = $abs;
            if (count($files) > 50000) break; // safety cap
        }
        return $files;
    }

    /** @return list<string>|null  null = accept any extension */
    private function extsForLang(?string $lang): ?array
    {
        return match ($lang) {
            'php'    => ['php'],
            'js'     => ['js', 'mjs', 'cjs', 'jsx'],
            'ts'     => ['ts', 'tsx'],
            'python' => ['py'],
            'go'     => ['go'],
            default  => null,
        };
    }

    private function relPath(string $abs): string
    {
        $cwd = getcwd();
        if ($cwd && str_starts_with($abs, $cwd . DIRECTORY_SEPARATOR)) {
            return substr($abs, strlen($cwd) + 1);
        }
        return $abs;
    }

    // ── Per-session seen-chunk ledger (via ToolStateManager) ──────

    private const STATE_KEY = 'seen_chunks';

    private function wasSeen(string $key): bool
    {
        $set = $this->state()->get($this->name(), self::STATE_KEY, []);
        return is_array($set) && isset($set[$key]);
    }

    private function markSeen(string $key): void
    {
        $set = $this->state()->get($this->name(), self::STATE_KEY, []);
        if (!is_array($set)) $set = [];
        $set[$key] = true;
        // Cap the ledger so a runaway agent that greps thousands of unique
        // chunks doesn't grow the state blob without bound. ~5K entries is
        // plenty for a multi-hour session.
        if (count($set) > 5000) {
            $set = array_slice($set, -4000, null, true);
        }
        $this->state()->set($this->name(), self::STATE_KEY, $set);
    }

    private function forgetSeen(): void
    {
        $this->state()->set($this->name(), self::STATE_KEY, []);
    }
}
