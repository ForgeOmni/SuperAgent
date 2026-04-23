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
            'list'       => $this->list($renderer),
            'add'        => $this->add($renderer, $rest),
            'remove'     => $this->remove($renderer, $rest),
            'status'     => $this->status($renderer),
            'path'       => $this->path($renderer),
            'auth'       => $this->auth($renderer, $rest),
            'reset-auth' => $this->resetAuth($renderer, $rest),
            'test'       => $this->test($renderer, $rest),
            default      => $this->usage($renderer, $sub),
        };
    }

    /**
     * `superagent mcp auth <name>` — run the RFC 8628 device-code flow
     * for an MCP server that declares `oauth: {client_id, ...}` in its
     * config, persist the token via McpOAuth.
     */
    private function auth(Renderer $renderer, array $rest): int
    {
        if (empty($rest[0])) {
            $renderer->error('Usage: superagent mcp auth <name>');
            return 2;
        }
        $name = (string) $rest[0];
        $servers = \SuperAgent\MCP\MCPManager::readUserConfig();
        $config = $servers[$name] ?? null;
        if ($config === null) {
            $renderer->error("No MCP server named '{$name}' in user config.");
            return 1;
        }
        $oauth = $config['oauth'] ?? null;
        if (!is_array($oauth)) {
            $renderer->error("Server '{$name}' has no `oauth` block in its config.");
            $renderer->hint(
                "Add an oauth block to ~/.superagent/mcp.json, e.g.:\n" .
                "  \"oauth\": {\n" .
                "    \"client_id\": \"your-client-id\",\n" .
                "    \"device_endpoint\": \"https://auth.example/device/code\",\n" .
                "    \"token_endpoint\": \"https://auth.example/oauth/token\",\n" .
                "    \"scope\": \"openid\"\n" .
                "  }"
            );
            return 2;
        }

        try {
            $token = \SuperAgent\MCP\McpOAuth::authenticate($name, $oauth);
            \SuperAgent\MCP\McpOAuth::storeToken($name, $token);
        } catch (\Throwable $e) {
            $renderer->error('Auth failed: ' . $e->getMessage());
            return 1;
        }
        $renderer->success("OK — token stored at " . \SuperAgent\MCP\McpOAuth::tokenStorePath());
        return 0;
    }

    private function resetAuth(Renderer $renderer, array $rest): int
    {
        if (empty($rest[0])) {
            $renderer->error('Usage: superagent mcp reset-auth <name>');
            return 2;
        }
        $name = (string) $rest[0];
        \SuperAgent\MCP\McpOAuth::clearToken($name);
        $renderer->success("Cleared stored token for '{$name}'.");
        return 0;
    }

    /**
     * `superagent mcp test <name>` — verify a configured server is
     * actually reachable. Returns exit 0 when reachable, 1 otherwise.
     * For stdio: checks that the executable exists and is runnable.
     * For http / sse: issues a HEAD / OPTIONS against the URL.
     */
    private function test(Renderer $renderer, array $rest): int
    {
        if (empty($rest[0])) {
            $renderer->error('Usage: superagent mcp test <name>');
            return 2;
        }
        $name = (string) $rest[0];
        $servers = \SuperAgent\MCP\MCPManager::readUserConfig();
        $config = $servers[$name] ?? null;
        if ($config === null) {
            $renderer->error("No MCP server named '{$name}' in user config.");
            return 1;
        }

        $type = (string) ($config['type'] ?? 'stdio');
        $renderer->info("Testing '{$name}' ({$type})...");

        if ($type === 'stdio') {
            $command = $config['command'] ?? null;
            if (!is_string($command) || $command === '') {
                $renderer->error('stdio server has no `command`.');
                return 1;
            }
            // Just check the binary is resolvable — spawning the server
            // in a test context would leave a zombie; `which` is enough.
            $out = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
            if (!is_string($out) || trim($out) === '') {
                $renderer->error("Command '{$command}' not found in PATH.");
                return 1;
            }
            $renderer->success("Command '{$command}' found: " . trim($out));
            return 0;
        }

        $url = $config['url'] ?? null;
        if (!is_string($url) || $url === '') {
            $renderer->error("{$type} server has no `url`.");
            return 1;
        }
        $ctx = stream_context_create([
            'http'  => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
            'https' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
        ]);
        $headers = @get_headers($url, true, $ctx);
        if (!is_array($headers)) {
            $renderer->error("Could not reach {$url}.");
            return 1;
        }
        $renderer->success("OK — reachable at {$url}");
        return 0;
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
            $authTag = $this->authTagFor($name, $config);
            $renderer->line(sprintf('  %s  [%s]  %s%s', $name, $type, $target, $authTag));
        }
        return 0;
    }

    /**
     * Format an "[auth: ok|needed|missing]" suffix for `list` output.
     * Returns empty string when the server doesn't declare oauth.
     */
    private function authTagFor(string $name, array $config): string
    {
        if (!isset($config['oauth']) || !is_array($config['oauth'])) {
            return '';
        }
        $token = \SuperAgent\MCP\McpOAuth::cachedToken($name);
        if ($token !== null) {
            return '  [auth: ok]';
        }
        return '  [auth: needed — run `superagent mcp auth ' . $name . '`]';
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
        $renderer->line('  superagent mcp auth <name>                                  Run OAuth device flow for an oauth-gated server');
        $renderer->line('  superagent mcp reset-auth <name>                            Clear stored OAuth token for a server');
        $renderer->line('  superagent mcp test <name>                                  Verify a configured server is reachable');
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
