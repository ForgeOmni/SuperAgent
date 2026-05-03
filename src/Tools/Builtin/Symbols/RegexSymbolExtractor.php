<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin\Symbols;

/**
 * Pure-PHP, regex-based extraction for PHP / JS / TS / Python / Go.
 *
 * This is the original `AgentGrepTool::extractSymbolMap()` body lifted
 * verbatim into a standalone class so hosts can compose it with other
 * extractors via `CompositeSymbolExtractor` without subclassing the tool.
 *
 * Performance / correctness profile (see also AgentGrepTool docblock):
 *   - O(lines) — single pass per file, no compilation step.
 *   - ~95% accuracy on idiomatic code in the supported families.
 *   - Misses: heavily nested anonymous functions, complex destructuring,
 *     macro-driven definitions (Go generics, TS template literal types).
 *   - For tree-sitter precision, layer `TreeSitterSymbolExtractor` in
 *     front via `new CompositeSymbolExtractor([$ts, new RegexSymbolExtractor()])`.
 */
final class RegexSymbolExtractor implements SymbolExtractor
{
    /** Max lines of file source we scan for symbols (capped to keep the tool cheap). */
    public const MAX_SYMBOL_SCAN_LINES = 5000;

    public function supports(string $file, ?string $langHint = null): bool
    {
        return $this->resolveLang($file, $langHint) !== null;
    }

    public function extract(string $file, array $lines, ?string $langHint = null): array
    {
        $lang = $this->resolveLang($file, $langHint);
        if ($lang === null) return [];

        $count = min(count($lines), self::MAX_SYMBOL_SCAN_LINES);
        $map = [];
        $currentClass = null;

        for ($i = 0; $i < $count; $i++) {
            $text = $lines[$i];

            switch ($lang) {
                case 'php':
                    if (preg_match('/^\s*(?:abstract\s+|final\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $m)) {
                        $currentClass = $m[1];
                        $map[$i] = $currentClass;
                    }
                    if (preg_match('/^\s*(?:public|protected|private|static|final|abstract|\s)*\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $m)) {
                        $map[$i] = ($currentClass !== null ? $currentClass . '::' : '') . $m[1] . '()';
                    }
                    break;

                case 'js':
                case 'ts':
                    if (preg_match('/^\s*(?:export\s+(?:default\s+)?)?(?:abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $m)) {
                        $currentClass = $m[1];
                        $map[$i] = $currentClass;
                    } elseif (preg_match('/^\s*(?:export\s+(?:default\s+)?)?(?:async\s+)?function\s*\*?\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $text, $m)) {
                        $map[$i] = ($currentClass !== null ? $currentClass . '::' : '') . $m[1] . '()';
                    } elseif (preg_match('/^\s*(?:public|private|protected|static|async)?\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*{/', $text, $m)
                              && $currentClass !== null
                              && !in_array($m[1], ['if', 'for', 'while', 'switch', 'catch'], true)) {
                        $map[$i] = $currentClass . '::' . $m[1] . '()';
                    } elseif (preg_match('/^\s*(?:export\s+)?const\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s+)?\(?[^)]*\)?\s*=>/', $text, $m)) {
                        $map[$i] = $m[1] . '()';
                    }
                    break;

                case 'python':
                    if (preg_match('/^class\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $m)) {
                        $currentClass = $m[1];
                        $map[$i] = $currentClass;
                    } elseif (preg_match('/^(\s*)(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $m)) {
                        $isMethod = strlen($m[1]) > 0 && $currentClass !== null;
                        $map[$i] = ($isMethod ? $currentClass . '.' : '') . $m[2] . '()';
                    }
                    break;

                case 'go':
                    if (preg_match('/^func\s+(?:\([^)]*\*?([A-Za-z_][A-Za-z0-9_]*)\)\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $m)) {
                        $map[$i] = ($m[1] ? $m[1] . '.' : '') . $m[2] . '()';
                    } elseif (preg_match('/^type\s+([A-Za-z_][A-Za-z0-9_]*)\s+(?:struct|interface)/', $text, $m)) {
                        $currentClass = $m[1];
                        $map[$i] = $currentClass;
                    }
                    break;
            }
        }
        return $map;
    }

    private function resolveLang(string $file, ?string $langHint): ?string
    {
        if ($langHint !== null && $langHint !== '') return $langHint;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'php'                            => 'php',
            'js', 'mjs', 'cjs', 'jsx'        => 'js',
            'ts', 'tsx'                      => 'ts',
            'py'                             => 'python',
            'go'                             => 'go',
            default                          => null,
        };
    }
}
