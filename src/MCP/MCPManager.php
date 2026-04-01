<?php

namespace SuperAgent\MCP;

use SuperAgent\MCP\Types\ServerConfig;
use SuperAgent\MCP\Transports\StdioTransport;
use SuperAgent\MCP\Transports\SSETransport;
use SuperAgent\MCP\Transports\HttpTransport;
use SuperAgent\MCP\Contracts\Transport;
use Illuminate\Support\Collection;
use Exception;

class MCPManager
{
    private static ?self $instance = null;
    private Collection $servers;
    private Collection $clients;
    private Collection $tools;
    private Collection $resources;

    private function __construct()
    {
        $this->servers = collect();
        $this->clients = collect();
        $this->tools = collect();
        $this->resources = collect();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register an MCP server configuration.
     */
    public function registerServer(ServerConfig $config): void
    {
        $this->servers->put($config->name, $config);
    }

    /**
     * Connect to an MCP server.
     */
    public function connect(string $serverName): Client
    {
        // Check if already connected
        if ($this->clients->has($serverName)) {
            return $this->clients->get($serverName);
        }

        // Get server config
        $config = $this->servers->get($serverName);
        if (!$config) {
            throw new Exception("MCP server '{$serverName}' not registered");
        }

        if (!$config->enabled) {
            throw new Exception("MCP server '{$serverName}' is disabled");
        }

        // Create appropriate transport
        $transport = $this->createTransport($config);

        // Create client
        $client = new Client($config, $transport);

        try {
            // Initialize connection
            $client->initialize();

            // Store client
            $this->clients->put($serverName, $client);

            // Register tools from this server
            $this->registerToolsFromServer($serverName, $client);

            // Register resources from this server
            $this->registerResourcesFromServer($serverName, $client);

            return $client;

        } catch (Exception $e) {
            throw new Exception("Failed to connect to MCP server '{$serverName}': " . $e->getMessage());
        }
    }

    /**
     * Disconnect from an MCP server.
     */
    public function disconnect(string $serverName): void
    {
        $client = $this->clients->get($serverName);
        if ($client) {
            $client->disconnect();
            $this->clients->forget($serverName);
            
            // Remove tools from this server
            $this->tools = $this->tools->reject(function ($tool) use ($serverName) {
                return $tool->getServerName() === $serverName;
            });

            // Remove resources from this server
            $this->resources = $this->resources->reject(function ($resource, $key) use ($serverName) {
                return str_starts_with($key, "{$serverName}:");
            });
        }
    }

    /**
     * Disconnect from all MCP servers.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients->keys() as $serverName) {
            $this->disconnect($serverName);
        }
    }

    /**
     * Get a connected client.
     */
    public function getClient(string $serverName): ?Client
    {
        return $this->clients->get($serverName);
    }

    /**
     * Get all connected clients.
     */
    public function getClients(): Collection
    {
        return $this->clients;
    }

    /**
     * Get all registered servers.
     */
    public function getServers(): Collection
    {
        return $this->servers;
    }

    /**
     * Get all available MCP tools.
     */
    public function getTools(): Collection
    {
        return $this->tools;
    }

    /**
     * Get all available MCP resources.
     */
    public function getResources(): Collection
    {
        return $this->resources;
    }

    /**
     * Create transport based on server config.
     */
    private function createTransport(ServerConfig $config): Transport
    {
        return match ($config->type) {
            'stdio' => new StdioTransport(
                $config->config['command'],
                $config->config['args'] ?? [],
                $config->config['env'] ?? []
            ),
            'sse' => new SSETransport(
                $config->config['url'],
                $config->config['headers'] ?? []
            ),
            'http' => new HttpTransport(
                $config->config['url'],
                $config->config['headers'] ?? []
            ),
            default => throw new Exception("Unsupported transport type: {$config->type}"),
        };
    }

    /**
     * Register tools from an MCP server.
     */
    private function registerToolsFromServer(string $serverName, Client $client): void
    {
        $tools = $client->getTools();
        
        foreach ($tools as $mcpTool) {
            $tool = new MCPTool($client, $serverName, $mcpTool);
            $this->tools->put($tool->name(), $tool);
        }
    }

    /**
     * Register resources from an MCP server.
     */
    private function registerResourcesFromServer(string $serverName, Client $client): void
    {
        $resources = $client->getResources();
        
        foreach ($resources as $resource) {
            $key = "{$serverName}:{$resource->uri}";
            $this->resources->put($key, $resource);
        }
    }

    /**
     * Load configuration from file or array.
     */
    public function loadConfiguration(array $config): void
    {
        foreach ($config['servers'] ?? [] as $name => $serverConfig) {
            $type = $serverConfig['type'] ?? 'stdio';
            
            $server = match ($type) {
                'stdio' => ServerConfig::stdio(
                    $name,
                    $serverConfig['command'],
                    $serverConfig['args'] ?? [],
                    $serverConfig['env'] ?? null
                ),
                'sse' => ServerConfig::sse(
                    $name,
                    $serverConfig['url'],
                    $serverConfig['headers'] ?? null
                ),
                'http' => ServerConfig::http(
                    $name,
                    $serverConfig['url'],
                    $serverConfig['headers'] ?? null
                ),
                default => null,
            };

            if ($server) {
                $this->registerServer($server);
            }
        }
    }

    /**
     * Auto-connect to enabled servers.
     */
    public function autoConnect(): void
    {
        foreach ($this->servers as $serverName => $config) {
            if ($config->enabled) {
                try {
                    $this->connect($serverName);
                    logger()->info("Connected to MCP server: {$serverName}");
                } catch (Exception $e) {
                    logger()->warning("Failed to auto-connect to MCP server '{$serverName}': " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get tool by name.
     */
    public function getTool(string $name): ?MCPTool
    {
        return $this->tools->get($name);
    }

    /**
     * Search for tools by pattern.
     */
    public function searchTools(string $pattern): Collection
    {
        return $this->tools->filter(function ($tool) use ($pattern) {
            return str_contains(strtolower($tool->name()), strtolower($pattern)) ||
                   str_contains(strtolower($tool->description()), strtolower($pattern));
        });
    }

    /**
     * Read a resource.
     */
    public function readResource(string $serverName, string $uri): mixed
    {
        $client = $this->getClient($serverName);
        if (!$client) {
            throw new Exception("Server '{$serverName}' is not connected");
        }

        return $client->readResource($uri);
    }

    /**
     * Clear all connections and data.
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->disconnectAll();
            self::$instance->servers = collect();
            self::$instance->clients = collect();
            self::$instance->tools = collect();
            self::$instance->resources = collect();
        }
    }
}