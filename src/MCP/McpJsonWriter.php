<?php

declare(strict_types=1);

namespace SuperAgent\MCP;

/**
 * Writes a host project's `.mcp.json` from a name-list subset of a
 * {@see Catalog}. Usage pattern:
 *
 *   $catalog  = new Catalog('<project>/.mcp-servers/catalog.json');
 *   $manifest = new Manifest('<project>/.superagent/mcp-manifest.json');
 *   $writer   = new McpJsonWriter('<project>/.mcp.json', $manifest);
 *
 *   // Write the catalog's "baseline" domain into .mcp.json
 *   $writer->sync($catalog->domainServers('baseline'));
 *
 * Non-destructive contract (inherited from {@see ManifestWriter}):
 *
 *   - On-disk `.mcp.json` hash == rendered hash → `unchanged`
 *   - Hash differs AND manifest says we wrote the previous hash →
 *     user has edited it; we leave it (`user-edited`)
 *   - First-time write or our-last-write matches disk → overwrite
 *     (`written`)
 *
 * Output shape mirrors what {@see MCPManager::loadFromJsonFile()} expects:
 *   {
 *     "mcpServers": {
 *       "<name>": {"type": "stdio", "command": "...", "args": [...], "env": {...}}
 *     }
 *   }
 */
final class McpJsonWriter extends ManifestWriter
{
    public function __construct(
        private readonly string $mcpJsonPath,
        Manifest $manifest,
    ) {
        parent::__construct($manifest);
    }

    /**
     * @param array<string, array{type:string,command:string,args:list<string>,env:array<string,string>}> $servers
     * @return array{status:string, path:string}
     */
    public function sync(array $servers, bool $dryRun = false): array
    {
        return $this->applyOne($this->mcpJsonPath, self::render($servers), $dryRun);
    }

    /**
     * Render the canonical `.mcp.json` text. `args` and `env` are
     * omitted when empty (matches Claude Code / Codex convention;
     * Claude CLI's config parser treats missing keys and empty arrays
     * identically, and the terser form is nicer to diff).
     *
     * Trailing newline on purpose — git diffs without it add `\ No
     * newline at end of file` noise.
     *
     * @param array<string, array{type:string,command:string,args:list<string>,env:array<string,string>}> $servers
     */
    public static function render(array $servers): string
    {
        $mcpServers = [];
        foreach ($servers as $name => $cfg) {
            $entry = [
                'type'    => $cfg['type'] ?? 'stdio',
                'command' => $cfg['command'],
            ];
            if (! empty($cfg['args'])) {
                $entry['args'] = array_values($cfg['args']);
            }
            if (! empty($cfg['env'])) {
                $entry['env'] = $cfg['env'];
            }
            $mcpServers[$name] = $entry;
        }

        return json_encode(
            ['mcpServers' => $mcpServers],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    public function path(): string
    {
        return $this->mcpJsonPath;
    }
}
