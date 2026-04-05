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
        $this->loadConfiguredSources();
    }

    /**
     * Load MCP servers from configured sources.
     */
    private function loadConfiguredSources(): void
    {
        try {
            if (!$this->isLaravelAvailable()) {
                return;
            }

            // Load from Claude Code configs if enabled
            if (config('superagent.mcp.load_claude_code', false)) {
                $this->loadFromClaudeCode();
            }

            // Load from additional configured paths (JSON files)
            $paths = config('superagent.mcp.paths', []);
            foreach ($paths as $path) {
                $resolved = $this->resolvePath($path);
                $this->loadFromJsonFile($resolved);
            }
        } catch (\Throwable) {
            // Silently skip if config is unavailable
        }
    }

    /**
     * Load MCP servers from a JSON config file.
     *
     * Accepts both Claude Code format (mcpServers) and
     * SuperAgent format (servers).
     */
    public function loadFromJsonFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $config = $this->readJsonFile($filePath);
        if ($config === null) {
            return;
        }

        $servers = $config['mcpServers'] ?? $config['servers'] ?? [];

        foreach ($servers as $name => $serverConfig) {
            $serverConfig = $this->expandEnvVars($serverConfig);
            $server = $this->buildServerConfig($name, $serverConfig);

            if ($server && !$this->servers->has($name)) {
                $this->registerServer($server);
            }
        }
    }

    /**
     * Resolve a path that may be relative to base_path().
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->resolveBasePath($path);
    }

    /**
     * Check if Laravel is fully booted.
     */
    private function isLaravelAvailable(): bool
    {
        try {
            return function_exists('app') && app()->bound('config');
        } catch (\Throwable) {
            return false;
        }
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

            // For stdio servers, start a TCP bridge so child processes can
            // share this MCP connection instead of spawning their own.
            if ($config->type === 'stdio') {
                try {
                    $bridge = MCPBridge::getInstance();
                    $port = $bridge->startBridge($serverName, $client);
                    logger()->info("MCP bridge started for '{$serverName}' on port {$port}");
                } catch (\Throwable $e) {
                    // Bridge is optional — log and continue
                    logger()->warning("Failed to start MCP bridge for '{$serverName}': " . $e->getMessage());
                }
            }

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
     *
     * For stdio servers, first checks if a parent process has an MCPBridge
     * running. If so, the child process connects via HTTP transport to the
     * bridge instead of spawning a new MCP server process.
     */
    private function createTransport(ServerConfig $config): Transport
    {
        if ($config->type === 'stdio') {
            // Check if a parent bridge is available for this server
            $bridges = MCPBridge::readRegistry();
            if (isset($bridges[$config->name])) {
                $url = $bridges[$config->name]['url'];
                return new HttpTransport($url);
            }
        }

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
     * Load configuration from array.
     *
     * Accepts both SuperAgent format (key: "servers") and
     * Claude Code format (key: "mcpServers").
     */
    public function loadConfiguration(array $config): void
    {
        // Support both formats
        $servers = $config['servers'] ?? $config['mcpServers'] ?? [];

        foreach ($servers as $name => $serverConfig) {
            $server = $this->buildServerConfig($name, $serverConfig);

            if ($server) {
                $this->registerServer($server);
            }
        }
    }

    /**
     * Load MCP servers from Claude Code's .mcp.json file.
     *
     * Claude Code stores project-level MCP configs at the project root
     * in .mcp.json with the format:
     * {
     *   "mcpServers": {
     *     "server-name": { "type": "stdio", "command": "...", ... }
     *   }
     * }
     */
    public function loadFromClaudeCodeProject(?string $projectRoot = null): void
    {
        $projectRoot ??= $this->resolveBasePath('');
        $file = rtrim($projectRoot, '/') . '/.mcp.json';

        if (!is_file($file)) {
            return;
        }

        $config = $this->readJsonFile($file);
        if ($config === null) {
            return;
        }

        $servers = $config['mcpServers'] ?? [];

        foreach ($servers as $name => $serverConfig) {
            $serverConfig = $this->expandEnvVars($serverConfig);
            $server = $this->buildServerConfig($name, $serverConfig);

            if ($server && !$this->servers->has($name)) {
                $this->registerServer($server);
            }
        }
    }

    /**
     * Load MCP servers from Claude Code's user config (~/.claude.json).
     *
     * User-level and local-scope MCP servers are stored in
     * ~/.claude.json under "mcpServers".
     */
    public function loadFromClaudeCodeUser(): void
    {
        $home = $_ENV['HOME'] ?? getenv('HOME') ?? ($_SERVER['HOME'] ?? null);
        if ($home === null) {
            return;
        }

        $file = $home . '/.claude.json';
        if (!is_file($file)) {
            return;
        }

        $config = $this->readJsonFile($file);
        if ($config === null) {
            return;
        }

        $servers = $config['mcpServers'] ?? [];

        foreach ($servers as $name => $serverConfig) {
            $serverConfig = $this->expandEnvVars($serverConfig);
            $server = $this->buildServerConfig($name, $serverConfig);

            if ($server && !$this->servers->has($name)) {
                $this->registerServer($server);
            }
        }
    }

    /**
     * Load MCP servers from all Claude Code config sources.
     *
     * Loads project-level (.mcp.json) first, then user-level (~/.claude.json).
     * Project configs take precedence — if a server name already exists,
     * the user-level config is skipped for that server.
     */
    public function loadFromClaudeCode(?string $projectRoot = null): void
    {
        $this->loadFromClaudeCodeProject($projectRoot);
        $this->loadFromClaudeCodeUser();
    }

    /**
     * Build a ServerConfig from a config array.
     */
    private function buildServerConfig(string $name, array $serverConfig): ?ServerConfig
    {
        $type = $serverConfig['type'] ?? 'stdio';

        return match ($type) {
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
    }

    /**
     * Read and decode a JSON file.
     */
    private function readJsonFile(string $path): ?array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Recursively expand ${VAR} and ${VAR:-default} in config values.
     */
    private function expandEnvVars(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace_callback(
                '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-(.*?))?\}/',
                function (array $m): string {
                    $envVal = $_ENV[$m[1]] ?? getenv($m[1]);
                    if ($envVal !== false && $envVal !== '') {
                        return $envVal;
                    }
                    return $m[2] ?? '';
                },
                $value
            );
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->expandEnvVars($v), $value);
        }

        return $value;
    }

    /**
     * Get base path, using Laravel's base_path() if available,
     * otherwise walk up from cwd to find the project root.
     */
    private function resolveBasePath(string $relative): string
    {
        try {
            if (function_exists('base_path') && function_exists('app') && app()->bound('config')) {
                return base_path($relative);
            }
        } catch (\Throwable) {
        }

        $root = self::findProjectRoot();
        return $root . ($relative !== '' ? '/' . $relative : '');
    }

    /**
     * Find the project root by walking up from cwd.
     */
    private static function findProjectRoot(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $dir = getcwd();
        for ($i = 0; $i < 20; $i++) {
            if (file_exists($dir . '/composer.json') || file_exists($dir . '/artisan') || is_dir($dir . '/.git')) {
                $cached = $dir;
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        $cached = getcwd();
        return $cached;
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
    /**
     * Get MCP instructions from all connected servers.
     * Used by SystemPromptBuilder to inject tool usage instructions
     * into the system prompt.
     *
     * @return array<string, string> serverName => instructions
     */
    public function getConnectedInstructions(): array
    {
        $instructions = [];

        foreach ($this->clients as $name => $client) {
            $serverInstructions = $client->getInstructions();
            if ($serverInstructions !== null && $serverInstructions !== '') {
                $instructions[$name] = $serverInstructions;
            }
        }

        return $instructions;
    }

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