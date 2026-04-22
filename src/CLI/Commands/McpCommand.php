<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\MCP\MCPManager;

/**
 * `superagent mcp` — manage user-level MCP server registrations.
 *
 * Subcommands:
 *   list                                               Show configured servers (from ~/.superagent/mcp.json).
 *   add <name> <type> <target> [--arg ...] [--env K=V] [--header H=V]
 *        type is `stdio`, `http`, or `sse`.
 *        For stdio the `target` is the command; `--arg` may repeat for args.
 *        For http/sse the `target` is the URL; `--header` may repeat.
 *   remove <name>                                      Delete a server from the config.
 *   status                                             Where the config lives + summary counts.
 *   path                                               Print the canonical config file path.
 *
 * Changes are atomic (temp + rename); the file is only rewritten when a
 * mutating subcommand succeeds. Claude-Code MCP sources (`.mcp.json` /
 * `~/.claude.json`) are read-only — edit them at their source if you use
 * them.
 */
class McpCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['mcp_args'] ?? [];
        $sub = strtolower((string) ($args[0] ?? 'list'));
        $rest = array_slice($args, 1);

        return match ($sub) {
            'list'   => $this->list($renderer),
            'add'    => $this->add($renderer, $rest),
            'remove' => $this->remove($renderer, $rest),
            'status' => $this->status($renderer),
            'path'   => $this->path($renderer),
            default  => $this->usage($renderer, $sub),
        };
    }

    private function list(Renderer $renderer): int
    {
        $servers = MCPManager::readUserConfig();
        if ($servers === []) {
            $renderer->info('No MCP servers configured.');
            $renderer->hint('Run:  superagent mcp add <name> <stdio|http|sse> <target>');
            return 0;
        }

        $renderer->info(sprintf('%d MCP server(s) configured:', count($servers)));
        foreach ($servers as $name => $config) {
            $type = (string) ($config['type'] ?? 'stdio');
            $target = $this->renderTarget($type, $config);
            $renderer->line(sprintf('  %s  [%s]  %s', $name, $type, $target));
        }
        return 0;
    }

    private function add(Renderer $renderer, array $rest): int
    {
        if (count($rest) < 3) {
            $renderer->error('Usage: superagent mcp add <name> <stdio|http|sse> <target> [--arg ...] [--env K=V] [--header H=V]');
            return 2;
        }

        [$name, $type, $target] = $rest;
        $type = strtolower($type);
        if (! in_array($type, ['stdio', 'http', 'sse'], true)) {
            $renderer->error("Unknown type: {$type} (expected stdio / http / sse)");
            return 2;
        }

        $extraArgs = [];
        $env = [];
        $headers = [];
        $consumed = 3;
        for ($i = $consumed; $i < count($rest); $i++) {
            $flag = $rest[$i];
            $val = $rest[++$i] ?? null;
            if ($val === null) {
                $renderer->error("Flag '{$flag}' is missing a value");
                return 2;
            }
            switch ($flag) {
                case '--arg':
                    $extraArgs[] = $val;
                    break;
                case '--env':
                    [$k, $v] = array_pad(explode('=', $val, 2), 2, '');
                    $env[$k] = $v;
                    break;
                case '--header':
                    [$k, $v] = array_pad(explode(':', $val, 2), 2, '');
                    $headers[trim($k)] = trim($v);
                    break;
                default:
                    $renderer->error("Unknown flag: {$flag}");
                    return 2;
            }
        }

        $servers = MCPManager::readUserConfig();
        if (isset($servers[$name])) {
            $renderer->warning("Overwriting existing server '{$name}'.");
        }

        $entry = match ($type) {
            'stdio' => array_filter([
                'type' => 'stdio',
                'command' => $target,
                'args' => $extraArgs ?: null,
                'env' => $env ?: null,
            ]),
            'http', 'sse' => array_filter([
                'type' => $type,
                'url' => $target,
                'headers' => $headers ?: null,
            ]),
        };

        $servers[$name] = $entry;

        try {
            MCPManager::writeUserConfig($servers);
        } catch (\Throwable $e) {
            $renderer->error('Failed to write config: ' . $e->getMessage());
            return 1;
        }

        $renderer->success(sprintf('Added MCP server: %s [%s]', $name, $type));
        $renderer->line('  Config: ' . MCPManager::userConfigPath());
        return 0;
    }

    private function remove(Renderer $renderer, array $rest): int
    {
        $name = $rest[0] ?? null;
        if (! is_string($name) || $name === '') {
            $renderer->error('Usage: superagent mcp remove <name>');
            return 2;
        }

        $servers = MCPManager::readUserConfig();
        if (! isset($servers[$name])) {
            $renderer->warning("No server named '{$name}' in user config.");
            return 0;
        }

        unset($servers[$name]);
        try {
            MCPManager::writeUserConfig($servers);
        } catch (\Throwable $e) {
            $renderer->error('Failed to write config: ' . $e->getMessage());
            return 1;
        }

        $renderer->success("Removed MCP server: {$name}");
        return 0;
    }

    private function status(Renderer $renderer): int
    {
        $path = MCPManager::userConfigPath();
        $renderer->info('MCP config:');
        $renderer->line('  User config: ' . $path . (is_file($path) ? '  [ok]' : '  [none]'));

        $servers = MCPManager::readUserConfig();
        $renderer->line(sprintf('  Servers: %d', count($servers)));

        $byType = [];
        foreach ($servers as $cfg) {
            $t = (string) ($cfg['type'] ?? 'stdio');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }
        if ($byType !== []) {
            $renderer->line('  By transport: ' . implode(', ', array_map(
                fn ($t, $n) => "{$t}={$n}",
                array_keys($byType),
                $byType,
            )));
        }

        $renderer->hint('Project-level .mcp.json and ~/.claude.json are loaded additively when Laravel config `superagent.mcp.load_claude_code` is true.');
        return 0;
    }

    private function path(Renderer $renderer): int
    {
        $renderer->line(MCPManager::userConfigPath());
        return 0;
    }

    private function usage(Renderer $renderer, string $sub): int
    {
        $renderer->error("Unknown mcp subcommand: {$sub}");
        $renderer->line('');
        $renderer->line('Usage:');
        $renderer->line('  superagent mcp list                                         List configured MCP servers');
        $renderer->line('  superagent mcp add <name> <stdio|http|sse> <target>         Register a server');
        $renderer->line('    --arg <v>        Repeatable; stdio command args');
        $renderer->line('    --env K=V        Repeatable; stdio process env');
        $renderer->line('    --header H: V    Repeatable; http/sse request header');
        $renderer->line('  superagent mcp remove <name>                                Delete a server');
        $renderer->line('  superagent mcp status                                       Show config source + counts');
        $renderer->line('  superagent mcp path                                         Print config file path');
        return 2;
    }

    private function renderTarget(string $type, array $config): string
    {
        if ($type === 'stdio') {
            $cmd = (string) ($config['command'] ?? '');
            $args = is_array($config['args'] ?? null) ? ' ' . implode(' ', $config['args']) : '';
            return $cmd . $args;
        }
        return (string) ($config['url'] ?? '');
    }
}
