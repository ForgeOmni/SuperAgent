<?php

declare(strict_types=1);

namespace SuperAgent\LSP;

/**
 * Extension → LSP `languageId` table. Subset of opencode's `lsp/language.ts`
 * covering the languages we ship server adapters for; new entries can be added
 * here without code changes to the client. Keys are lowercase dotted extensions.
 *
 * The LSP spec requires a `languageId` on every `textDocument/didOpen` —
 * servers use it to disambiguate (e.g. `.ts` could be Deno or Node).
 */
final class LanguageExtensions
{
    public const MAP = [
        '.php'    => 'php',
        '.go'     => 'go',
        '.rs'     => 'rust',
        '.py'     => 'python',
        '.pyi'    => 'python',
        '.ts'     => 'typescript',
        '.tsx'    => 'typescriptreact',
        '.mts'    => 'typescript',
        '.cts'    => 'typescript',
        '.js'     => 'javascript',
        '.jsx'    => 'javascriptreact',
        '.mjs'    => 'javascript',
        '.cjs'    => 'javascript',
        '.c'      => 'c',
        '.h'      => 'c',
        '.cpp'    => 'cpp',
        '.cxx'    => 'cpp',
        '.cc'     => 'cpp',
        '.hpp'    => 'cpp',
        '.java'   => 'java',
        '.kt'     => 'kotlin',
        '.kts'    => 'kotlin',
        '.rb'     => 'ruby',
        '.cs'     => 'csharp',
        '.swift'  => 'swift',
        '.lua'    => 'lua',
        '.dart'   => 'dart',
        '.elm'    => 'elm',
        '.ex'     => 'elixir',
        '.exs'    => 'elixir',
        '.hs'     => 'haskell',
        '.json'   => 'json',
        '.jsonc'  => 'jsonc',
        '.yaml'   => 'yaml',
        '.yml'    => 'yaml',
        '.toml'   => 'toml',
        '.md'     => 'markdown',
        '.html'   => 'html',
        '.css'    => 'css',
        '.scss'   => 'scss',
        '.zig'    => 'zig',
        '.sh'     => 'shellscript',
        '.bash'   => 'shellscript',
        '.tf'     => 'terraform',
        '.tfvars' => 'terraform',
        '.vue'    => 'vue',
        '.svelte' => 'svelte',
    ];

    public static function forPath(string $path): ?string
    {
        $ext = '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::MAP[$ext] ?? null;
    }
}
