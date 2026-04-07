<?php

declare(strict_types=1);

namespace SuperAgent\MCP;

use SuperAgent\MCP\Types\ServerConfig;

/**
 * MCP Bridge: shares stdio-based MCP servers with child processes via TCP.
 *
 * When the parent process connects to an MCP server via stdio, the bridge
 * starts a lightweight TCP listener that proxies JSON-RPC messages between
 * TCP clients (child processes) and the stdio server. This avoids N child
 * processes each spawning their own MCP server.
 *
 * Architecture:
 *   Parent:   StdioTransport ←→ MCPBridge TCP listener (:port)
 *   Child 1:  HttpTransport → localhost:port ──→ MCPBridge ──→ StdioTransport
 *   Child 2:  HttpTransport → localhost:port ──→ MCPBridge ──→ StdioTransport
 *
 * The bridge info is stored in /tmp/superagent_mcp_bridges.json so child
 * processes can discover available bridges without IPC.
 */
class MCPBridge
{
    private static ?self $instance = null;

    /** @var array<string, array{socket: resource, port: int, client: Client}> */
    private array $bridges = [];

    /** Path to the shared bridge registry file */
    private string $registryPath;

    public function __construct()
    {
        $pid = function_exists('posix_getpid') ? posix_getpid() : getmypid();
        $this->registryPath = sys_get_temp_dir() . '/superagent_mcp_bridges_' . $pid . '.json';
    }

    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Start a TCP bridge for an MCP server that's already connected via stdio.
     *
     * @return int The TCP port the bridge is listening on.
     */
    public function startBridge(string $serverName, Client $client): int
    {
        if (isset($this->bridges[$serverName])) {
            return $this->bridges[$serverName]['port'];
        }

        // Bind to a random available port
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) {
            throw new \RuntimeException("Failed to create TCP bridge: {$errstr}");
        }

        // Extract the assigned port
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        // Make non-blocking so we can poll
        stream_set_blocking($socket, false);

        $this->bridges[$serverName] = [
            'socket' => $socket,
            'port' => $port,
            'client' => $client,
        ];

        // Write bridge info to registry file for child process discovery
        $this->updateRegistry();

        return $port;
    }

    /**
     * Process one round of bridge I/O.
     *
     * Accepts new TCP connections, reads requests, forwards to MCP server,
     * and sends responses back. Call this periodically (e.g. in a poll loop).
     */
    public function poll(): void
    {
        foreach ($this->bridges as $serverName => &$bridge) {
            // Accept new connections
            $conn = @stream_socket_accept($bridge['socket'], 0);
            if ($conn) {
                stream_set_blocking($conn, true);
                stream_set_timeout($conn, 30);
                $this->handleConnection($conn, $bridge['client']);
            }
        }
        unset($bridge);
    }

    /**
     * Handle a single TCP connection: read JSON-RPC request, forward to
     * the MCP client, return the response.
     */
    private function handleConnection($conn, Client $client): void
    {
        try {
            // Read the HTTP request (simplified — expects POST with JSON body)
            $request = '';
            $contentLength = 0;
            $headersRead = false;

            while (!feof($conn)) {
                $line = fgets($conn, 8192);
                if ($line === false) {
                    break;
                }

                if (!$headersRead) {
                    $request .= $line;
                    if (preg_match('/Content-Length:\s*(\d+)/i', $line, $m)) {
                        $contentLength = (int) $m[1];
                    }
                    if (trim($line) === '') {
                        $headersRead = true;
                        if ($contentLength > 0) {
                            $body = fread($conn, $contentLength);
                        } else {
                            $body = '';
                        }
                        break;
                    }
                }
            }

            if (empty($body)) {
                $this->sendHttpResponse($conn, 400, ['error' => 'Empty request body']);
                return;
            }

            $message = json_decode($body, true);
            if (!$message || !isset($message['method'])) {
                $this->sendHttpResponse($conn, 400, ['error' => 'Invalid JSON-RPC']);
                return;
            }

            // Forward to the MCP client
            $method = $message['method'];
            $params = $message['params'] ?? [];
            $id = $message['id'] ?? null;

            try {
                $result = match (true) {
                    str_starts_with($method, 'tools/call') => $client->callTool(
                        $params['name'],
                        $params['arguments'] ?? []
                    ),
                    str_starts_with($method, 'resources/read') => $client->readResource($params['uri']),
                    str_starts_with($method, 'resources/list') => ['resources' => $client->listResources()],
                    str_starts_with($method, 'prompts/get') => $client->getPrompt(
                        $params['name'],
                        $params['arguments'] ?? []
                    ),
                    str_starts_with($method, 'prompts/list') => ['prompts' => $client->listPrompts()],
                    str_starts_with($method, 'tools/list') => [
                        'tools' => $client->getTools()->map(fn($t) => [
                            'name' => $t->name,
                            'description' => $t->description,
                            'inputSchema' => $t->inputSchema,
                        ])->values()->toArray(),
                    ],
                    $method === 'initialize' => [
                        'capabilities' => $client->getCapabilities()?->toArray() ?? [],
                        'instructions' => $client->getInstructions(),
                    ],
                    default => throw new \RuntimeException("Unsupported method: {$method}"),
                };

                $response = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
            } catch (\Throwable $e) {
                $response = [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32000, 'message' => $e->getMessage()],
                ];
            }

            $this->sendHttpResponse($conn, 200, $response);

        } catch (\Throwable $e) {
            try {
                $this->sendHttpResponse($conn, 500, ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                error_log('[SuperAgent] MCPBridge response failed (connection broken): ' . $e->getMessage());
            }
        } finally {
            @fclose($conn);
        }
    }

    private function sendHttpResponse($conn, int $status, array $body): void
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $statusText = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            500 => 'Internal Server Error',
            default => 'Unknown',
        };
        $response = "HTTP/1.1 {$status} {$statusText}\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($json) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $json;
        fwrite($conn, $response);
    }

    /**
     * Get bridge info for all active bridges.
     *
     * @return array<string, array{port: int, url: string}>
     */
    public function getBridgeInfo(): array
    {
        $info = [];
        foreach ($this->bridges as $name => $bridge) {
            $info[$name] = [
                'port' => $bridge['port'],
                'url' => "http://127.0.0.1:{$bridge['port']}",
            ];
        }
        return $info;
    }

    /**
     * Read bridge info from the registry file (used by child processes).
     *
     * @return array<string, array{port: int, url: string}>
     */
    public static function readRegistry(?int $parentPid = null): array
    {
        $pid = $parentPid ?? (function_exists('posix_getppid') ? posix_getppid() : 0);
        $path = sys_get_temp_dir() . "/superagent_mcp_bridges_{$pid}.json";

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write current bridge info to the registry file.
     */
    private function updateRegistry(): void
    {
        file_put_contents(
            $this->registryPath,
            json_encode($this->getBridgeInfo(), JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Stop all bridges and clean up.
     */
    public function stopAll(): void
    {
        foreach ($this->bridges as $name => $bridge) {
            if (is_resource($bridge['socket'])) {
                fclose($bridge['socket']);
            }
        }
        $this->bridges = [];

        if (file_exists($this->registryPath)) {
            @unlink($this->registryPath);
        }
    }

    public function __destruct()
    {
        $this->stopAll();
    }
}
