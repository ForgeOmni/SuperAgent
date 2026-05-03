<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin\Symbols;

/**
 * Extracts a "line → enclosing-symbol" map for a single source file.
 *
 * Used by `AgentGrepTool` to attach `class::method()` / `function name()`
 * context to each grep hit so the LLM can tell `function login(...)` from
 * `function logout(...)` without re-reading the whole file.
 *
 * The map's shape is intentionally loose — the line key is 0-indexed and
 * the value is a single human-readable label (e.g. `Auth\\Service::login()`,
 * `MyClass`, `format_date()`). Lines without a known symbol are absent;
 * the consumer walks upward to find the nearest preceding entry.
 *
 * Two reference implementations ship today:
 *
 *   - `RegexSymbolExtractor` — pure-PHP, no extra deps, ~95% accuracy on
 *     PHP / JS / TS / Python / Go. Default and always-on.
 *   - `TreeSitterSymbolExtractor` — opt-in, shells out to the
 *     `tree-sitter` CLI for ~20 languages with full AST precision. Hosts
 *     that need exact symbol resolution wire it through `CompositeSymbolExtractor`
 *     so the regex extractor stays as a fallback.
 *
 * Hosts can register their own (LSP-backed, Roslyn-backed, prebuilt
 * symbol-table cache, …) by implementing this interface and passing the
 * instance to `AgentGrepTool::__construct(symbolExtractor: $custom)`.
 */
interface SymbolExtractor
{
    /**
     * @param  string   $file    Absolute file path
     * @param  string[] $lines   File contents pre-split on newline (FILE_IGNORE_NEW_LINES shape)
     * @param  ?string  $langHint  Caller-provided language ('php' | 'js' | 'ts' | 'python' | 'go' | …),
     *                              or null to detect from file extension
     * @return array<int, string>   line index (0-based) → symbol label; lines without a symbol are absent
     */
    public function extract(string $file, array $lines, ?string $langHint = null): array;

    /**
     * Cheap predicate the caller uses to decide whether to even attempt
     * extraction (and whether to fall through to the next extractor in a
     * composite chain). Implementations should return false for languages
     * they have no recognition for so the composite can probe the next
     * one without paying for a full extract().
     */
    public function supports(string $file, ?string $langHint = null): bool;
}
