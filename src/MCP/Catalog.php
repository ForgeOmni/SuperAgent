<?php

declare(strict_types=1);

namespace SuperAgent\MCP;

/**
 * Runtime-config view of an MCP-server catalog. Loads a user-supplied
 * JSON file whose shape mirrors a project `.mcp.json` — `{mcpServers:
 * {name: {command, args?, env?, type?}}}` — and exposes simple queries.
 *
 * Why separate from {@see MCPManager}: `MCPManager`'s registry is
 * *connection-oriented* (it loads, connects, registers tools, holds
 * Client instances). `Catalog` is the *declarative-config* view — plain
 * records the sync writer can diff against on-disk files without
 * spinning up any transports. Lifted from SuperAICore's `McpCatalog`
 * with the installer-oriented fields dropped.
 *
 * Catalog file shape:
 *   {
 *     "mcpServers": {
 *       "sqlite": {"command": "uvx", "args": ["mcp-server-sqlite"]},
 *       "brave":  {"command": "npx", "args": ["@brave/mcp"], "env": {"BRAVE_API_KEY": "..."}}
 *     },
 *     "domains": { "search": ["brave"] }   // optional — subset by label
 *   }
 */
final class Catalog
{
    /** @var array<string, array{type:string, command:string, args:list<string>, env:array<string,string>}> */
    private array $servers;

    /** @var array<string, list<string>> */
    private array $domains;

    public function __construct(string $catalogPath)
    {
        if (! is_file($catalogPath)) {
            throw new \RuntimeException("MCP catalog not found: {$catalogPath}");
        }
        $raw = (string) @file_get_contents($catalogPath);
        if ($raw === '') {
            throw new \RuntimeException("MCP catalog unreadable or empty: {$catalogPath}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['mcpServers']) || ! is_array($data['mcpServers'])) {
            throw new \RuntimeException("MCP catalog malformed (missing mcpServers): {$catalogPath}");
        }

        $this->servers = [];
        foreach ($data['mcpServers'] as $name => $cfg) {
            if (! is_string($name) || ! is_array($cfg)) continue;
            $this->servers[$name] = [
                'type'    => (string) ($cfg['type'] ?? 'stdio'),
                'command' => (string) ($cfg['command'] ?? ''),
                'args'    => array_values(array_map('strval', (array) ($cfg['args'] ?? []))),
                'env'     => array_map('strval', (array) ($cfg['env'] ?? [])),
            ];
        }

        $this->domains = [];
        foreach ((array) ($data['domains'] ?? []) as $domain => $names) {
            if (! is_string($domain)) continue;
            $this->domains[$domain] = array_values(array_filter(
                array_map('strval', (array) $names),
                static fn(string $s): bool => $s !== '',
            ));
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->servers);
    }

    public function has(string $name): bool
    {
        return isset($this->servers[$name]);
    }

    /** @return array{type:string,command:string,args:list<string>,env:array<string,string>} */
    public function get(string $name): array
    {
        if (! isset($this->servers[$name])) {
            throw new \InvalidArgumentException("Unknown MCP server: {$name}");
        }
        return $this->servers[$name];
    }

    /**
     * Return a sub-catalog containing only the requested names,
     * preserving input order. Unknown names throw — callers treat
     * them as configuration errors rather than silently dropping.
     *
     * @param  list<string> $names
     * @return array<string, array{type:string,command:string,args:list<string>,env:array<string,string>}>
     */
    public function subset(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = $this->get($n);
        }
        return $out;
    }

    /**
     * Members of a domain label. Returns an empty list when the label
     * is unknown — this is an optional feature of the catalog shape.
     *
     * @return list<string>
     */
    public function domain(string $domain): array
    {
        return $this->domains[$domain] ?? [];
    }

    /**
     * Convenience: full sub-catalog for a domain label.
     *
     * @return array<string, array{type:string,command:string,args:list<string>,env:array<string,string>}>
     */
    public function domainServers(string $domain): array
    {
        return $this->subset($this->domain($domain));
    }
}
