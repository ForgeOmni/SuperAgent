<?php

declare(strict_types=1);

namespace SuperAgent\MCP;

/**
 * Persists the sha256 of files SuperAgent has written under host-owned
 * directories (e.g. project `.mcp.json`) so subsequent syncs can
 * distinguish:
 *
 *   1. "We wrote this; it's still our output" → safe to overwrite.
 *   2. "We wrote this; the user has since edited it" → leave alone.
 *   3. "We never wrote this; it's the user's file" → leave alone.
 *   4. "We wrote this; the source spec no longer references it" → clean up,
 *      unless the user edited it (then retain as stale-kept).
 *
 * Manifest default path: `<project-root>/.superagent/mcp-manifest.json`.
 * Callers pass any path they want — keeping the manifest under
 * `.superagent/` lets users `.gitignore` it without hiding the real
 * `.mcp.json`.
 *
 * Shape:
 *   {
 *     "version": 1,
 *     "generated_at": "2026-04-23T12:34:56+00:00",
 *     "entries": { "<absolute path>": "<sha256 hex>" }
 *   }
 *
 * Lifted from SuperAICore's `Sync\Manifest` — identical semantics,
 * different default path (SuperAICore assumed a Gemini commands dir).
 */
final class Manifest
{
    public const VERSION = 1;

    public function __construct(private readonly string $path) {}

    /** @return array<string,string> path => sha256 hex */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['entries']) || ! is_array($data['entries'])) {
            return [];
        }

        $out = [];
        foreach ($data['entries'] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,string> $entries path => sha256 hex */
    public function write(array $entries): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->path,
            json_encode(
                [
                    'version'      => self::VERSION,
                    'generated_at' => date('c'),
                    'entries'      => $entries,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n"
        );
    }

    public function path(): string
    {
        return $this->path;
    }
}
