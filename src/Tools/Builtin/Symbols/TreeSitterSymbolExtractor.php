<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin\Symbols;

/**
 * Optional, opt-in tree-sitter-backed extractor.
 *
 * jcode bundles ~20 tree-sitter grammars natively (Rust binary). PHP has
 * no equally cross-platform binding, so this extractor takes the pragmatic
 * path: shell out to the `tree-sitter` CLI binary the host has already
 * installed for jcode (or any other reason), parse the s-expression query
 * output, and return the same `array<int, string>` shape that
 * `RegexSymbolExtractor` produces.
 *
 * The extractor degrades to "I don't support this" when:
 *   - The CLI binary isn't found on PATH (or the configured custom path).
 *   - No grammar is registered for the file's language.
 *   - The CLI invocation fails for any reason.
 *
 * In any of those cases `supports()` returns false; callers using a
 * `CompositeSymbolExtractor` fall through to the next extractor (typically
 * `RegexSymbolExtractor`) without observing an exception.
 *
 * **Wiring example (host-side, e.g. Laravel service provider):**
 * ```php
 * $this->app->bind(SymbolExtractor::class, fn () =>
 *     new CompositeSymbolExtractor([
 *         new TreeSitterSymbolExtractor(binPath: '/opt/tree-sitter/bin/tree-sitter'),
 *         new RegexSymbolExtractor(),
 *     ])
 * );
 * ```
 *
 * Then `new AgentGrepTool($state, $extractor)` will hit tree-sitter first
 * and silently fall back to regex per-file.
 */
final class TreeSitterSymbolExtractor implements SymbolExtractor
{
    /** Languages this extractor can address when the CLI is present. Maps SuperAgent's lang hint → tree-sitter grammar name. */
    private const GRAMMAR_MAP = [
        'php'    => 'php',
        'js'     => 'javascript',
        'ts'     => 'typescript',
        'python' => 'python',
        'go'     => 'go',
        'rust'   => 'rust',
        'ruby'   => 'ruby',
        'java'   => 'java',
        'c'      => 'c',
        'cpp'    => 'cpp',
        'csharp' => 'c-sharp',
        'swift'  => 'swift',
        'kotlin' => 'kotlin',
        'scala'  => 'scala',
        'lua'    => 'lua',
    ];

    private readonly ?string $binPath;
    private readonly int $timeoutMs;

    /** @var array<string,bool> negative cache so we don't re-probe the binary for each file in a sweep */
    private static array $probeCache = [];

    public function __construct(?string $binPath = null, int $timeoutMs = 1500)
    {
        // Allow either an explicit absolute path, an env override, or
        // PATH-based lookup (cheap on first use, cached after that).
        $this->binPath = $binPath
            ?? (getenv('SUPERAGENT_TREE_SITTER_BIN') ?: null)
            ?? $this->discoverOnPath();
        $this->timeoutMs = max(100, $timeoutMs);
    }

    public function supports(string $file, ?string $langHint = null): bool
    {
        if ($this->binPath === null) return false;
        $lang = $this->resolveLang($file, $langHint);
        if ($lang === null) return false;
        if (!isset(self::GRAMMAR_MAP[$lang])) return false;
        return $this->binaryRuns();
    }

    public function extract(string $file, array $lines, ?string $langHint = null): array
    {
        if (!$this->supports($file, $langHint)) return [];

        $lang = $this->resolveLang($file, $langHint);
        $grammar = self::GRAMMAR_MAP[$lang] ?? null;
        if ($grammar === null) return [];

        // We capture the parse output via `tree-sitter parse --quiet`, which
        // writes an s-expression of (node_kind [start_row, start_col] - [end_row, end_col]).
        // We don't need a full AST — only definition nodes — so we filter
        // line by line for the kinds the SDK cares about. Cross-grammar the
        // canonical names align: function_declaration / method_declaration /
        // class_declaration / interface_declaration. Outliers are mapped
        // per-grammar in pickSymbolName().
        $cmd = [
            $this->binPath,
            'parse',
            '--quiet',
            '--scope', 'source.' . $grammar,
            $file,
        ];
        $out = $this->runCmd($cmd);
        if ($out === null) return [];

        return $this->parseSExprToSymbolMap($out, $lines);
    }

    /**
     * Walk the tree-sitter parse s-expression and convert any def-shaped
     * line into the same `array<int, string>` shape RegexSymbolExtractor
     * returns. Heuristic but cross-grammar — keeps `pickSymbolName()` short.
     */
    private function parseSExprToSymbolMap(string $sexpr, array $lines): array
    {
        $map = [];
        $currentClass = null;

        // Each interesting line of the parse output looks like:
        //   (function_declaration [12, 0] - [25, 1]
        // We only care about the kind + the start row.
        foreach (preg_split('/\R/u', $sexpr) ?: [] as $row) {
            if (!preg_match('/\(([a-z_]+)\s+\[(\d+),\s*\d+\]/', $row, $m)) continue;
            $kind = $m[1];
            $line = (int) $m[2];

            if (in_array($kind, ['class_declaration', 'class_definition', 'interface_declaration', 'struct_type', 'type_declaration'], true)) {
                $name = $this->pickSymbolName($lines[$line] ?? '', 'class');
                if ($name !== null) {
                    $currentClass = $name;
                    $map[$line] = $name;
                }
            } elseif (in_array($kind, ['function_declaration', 'function_definition', 'method_declaration', 'method_definition', 'function_item'], true)) {
                $name = $this->pickSymbolName($lines[$line] ?? '', 'function');
                if ($name !== null) {
                    $map[$line] = ($currentClass !== null && $kind !== 'function_declaration' ? $currentClass . '::' : '')
                                . $name . '()';
                }
            }
        }
        return $map;
    }

    /**
     * Tree-sitter only gives us the node kind + position. The symbol name
     * itself is a child node we'd need a query (.scm) file to extract; to
     * stay dependency-free we fish the identifier off the originating
     * source line with the same regex shape RegexSymbolExtractor uses.
     * Returns null when no plausible identifier is on the line.
     */
    private function pickSymbolName(string $line, string $shape): ?string
    {
        if ($shape === 'class') {
            if (preg_match('/(?:class|interface|trait|enum|struct|type)\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
                return $m[1];
            }
        } else { // function
            if (preg_match('/(?:def|fn|func|function)\s+\*?\s*([A-Za-z_$][A-Za-z0-9_$]*)/', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function resolveLang(string $file, ?string $langHint): ?string
    {
        if ($langHint !== null && $langHint !== '') return $langHint;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'php'                       => 'php',
            'js', 'mjs', 'cjs', 'jsx'   => 'js',
            'ts', 'tsx'                 => 'ts',
            'py'                        => 'python',
            'go'                        => 'go',
            'rs'                        => 'rust',
            'rb'                        => 'ruby',
            'java'                      => 'java',
            'c', 'h'                    => 'c',
            'cc', 'cpp', 'hpp', 'cxx'   => 'cpp',
            'cs'                        => 'csharp',
            'swift'                     => 'swift',
            'kt', 'kts'                 => 'kotlin',
            'scala', 'sc'               => 'scala',
            'lua'                       => 'lua',
            default                     => null,
        };
    }

    private function discoverOnPath(): ?string
    {
        // Cross-platform PATH walk — shell-free so we don't pay the cost
        // of `which`/`where` and we work the same on Windows / nix.
        $name = stripos(PHP_OS, 'WIN') === 0 ? 'tree-sitter.exe' : 'tree-sitter';
        $path = (string) getenv('PATH');
        $sep  = stripos(PHP_OS, 'WIN') === 0 ? ';' : ':';
        foreach (explode($sep, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') continue;
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
        return null;
    }

    private function binaryRuns(): bool
    {
        $key = (string) $this->binPath;
        if (isset(self::$probeCache[$key])) return self::$probeCache[$key];
        $out = $this->runCmd([$this->binPath, '--version']);
        return self::$probeCache[$key] = ($out !== null);
    }

    /**
     * Run a CLI command with stdout capture and a hard timeout. Returns
     * null on any failure (non-zero exit, timeout, missing binary).
     */
    private function runCmd(array $argv): ?string
    {
        if ($this->binPath === null) return null;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) return null;

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + ($this->timeoutMs / 1000.0);
        while (true) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') $stdout .= $chunk;

            $status = proc_get_status($proc);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                @proc_terminate($proc, 9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
                return null;
            }
            usleep(20_000);
        }
        // Drain any remaining bytes after the process has exited.
        $tail = stream_get_contents($pipes[1]);
        if (is_string($tail)) $stdout .= $tail;
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) return null;
        return $stdout;
    }
}
