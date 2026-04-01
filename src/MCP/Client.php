<?php

namespace SuperAgent\MCP;

use SuperAgent\MCP\Contracts\Transport;
use SuperAgent\MCP\Types\ServerConfig;
use SuperAgent\MCP\Types\ServerCapabilities;
use SuperAgent\MCP\Types\Tool;
use SuperAgent\MCP\Types\Resource;
use SuperAgent\MCP\Types\Prompt;
use Illuminate\Support\Collection;
use Exception;

class Client
{
    private Transport $transport;
    private ?ServerCapabilities $capabilities = null;
    private Collection $tools;
    private Collection $resources;
    private Collection $prompts;
    private int $messageId = 1;
    private array $pendingRequests = [];
    private bool $initialized = false;
    private ?string $instructions = null;

    public function __construct(
        private readonly ServerConfig $config,
        Transport $transport
    ) {
        $this->transport = $transport;
        $this->tools = collect();
        $this->resources = collect();
        $this->prompts = collect();

        // Set up message handlers
        $this->transport->onMessage([$this, 'handleMessage']);
        $this->transport->onError([$this, 'handleError']);
        $this->transport->onClose([$this, 'handleClose']);
    }

    /**
     * Initialize the connection to the MCP server.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->transport->connect();

        // Send initialization request
        $response = $this->request('initialize', [
            'protocolVersion' => '1.0.0',
            'capabilities' => [
                'tools' => ['call'],
                'resources' => ['read', 'list'],
                'prompts' => ['get', 'list'],
            ],
            'clientInfo' => [
                'name' => 'SuperAgent',
                'version' => '1.0.0',
            ],
        ]);

        if (isset($response['capabilities'])) {
            $this->capabilities = new ServerCapabilities($response['capabilities']);
        }

        // Capture server instructions (for system prompt injection)
        if (isset($response['instructions']) && is_string($response['instructions'])) {
            $this->instructions = $response['instructions'];
        }

        // Notify initialized
        $this->notify('initialized', []);

        // Discover available features
        $this->discoverTools();
        $this->discoverResources();
        $this->discoverPrompts();

        $this->initialized = true;
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        if ($this->transport->isConnected()) {
            $this->transport->disconnect();
        }
        $this->initialized = false;
    }

    /**
     * Call a tool on the MCP server.
     */
    public function callTool(string $name, array $arguments = []): mixed
    {
        $tool = $this->tools->firstWhere('name', $name);
        if (!$tool) {
            throw new Exception("Tool {$name} not found");
        }

        return $this->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);
    }

    /**
     * Read a resource from the MCP server.
     */
    public function readResource(string $uri): mixed
    {
        return $this->request('resources/read', [
            'uri' => $uri,
        ]);
    }

    /**
     * List available resources.
     */
    public function listResources(): array
    {
        $response = $this->request('resources/list', []);
        return $response['resources'] ?? [];
    }

    /**
     * Get a prompt from the MCP server.
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        return $this->request('prompts/get', [
            'name' => $name,
            'arguments' => $arguments,
        ]);
    }

    /**
     * List available prompts.
     */
    public function listPrompts(): array
    {
        $response = $this->request('prompts/list', []);
        return $response['prompts'] ?? [];
    }

    /**
     * Discover available tools from the server.
     */
    private function discoverTools(): void
    {
        if (!$this->capabilities?->hasTools()) {
            return;
        }

        try {
            $response = $this->request('tools/list', []);
            $tools = $response['tools'] ?? [];

            $this->tools = collect($tools)->map(function ($tool) {
                return new Tool(
                    name: $tool['name'],
                    description: $tool['description'] ?? '',
                    inputSchema: $tool['inputSchema'] ?? []
                );
            });
        } catch (Exception $e) {
            // Log error but don't fail initialization
            logger()->warning("Failed to discover MCP tools: " . $e->getMessage());
        }
    }

    /**
     * Discover available resources from the server.
     */
    private function discoverResources(): void
    {
        if (!$this->capabilities?->hasResources()) {
            return;
        }

        try {
            $resources = $this->listResources();
            $this->resources = collect($resources)->map(function ($resource) {
                return new Resource(
                    uri: $resource['uri'],
                    name: $resource['name'] ?? '',
                    description: $resource['description'] ?? '',
                    mimeType: $resource['mimeType'] ?? 'text/plain'
                );
            });
        } catch (Exception $e) {
            logger()->warning("Failed to discover MCP resources: " . $e->getMessage());
        }
    }

    /**
     * Discover available prompts from the server.
     */
    private function discoverPrompts(): void
    {
        if (!$this->capabilities?->hasPrompts()) {
            return;
        }

        try {
            $prompts = $this->listPrompts();
            $this->prompts = collect($prompts)->map(function ($prompt) {
                return new Prompt(
                    name: $prompt['name'],
                    description: $prompt['description'] ?? '',
                    arguments: $prompt['arguments'] ?? []
                );
            });
        } catch (Exception $e) {
            logger()->warning("Failed to discover MCP prompts: " . $e->getMessage());
        }
    }

    /**
     * Send a request to the MCP server and wait for response.
     */
    private function request(string $method, array $params): array
    {
        $id = $this->messageId++;
        
        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];

        // Create a promise for the response
        $promise = new \React\Promise\Deferred();
        $this->pendingRequests[$id] = $promise;

        // Send the message
        $this->transport->send($message);

        // Wait for response (with timeout)
        $timeout = 30; // 30 seconds timeout
        $start = time();
        
        while (!isset($this->pendingRequests[$id]->resolved)) {
            if (time() - $start > $timeout) {
                unset($this->pendingRequests[$id]);
                throw new Exception("Request timeout for method: {$method}");
            }
            
            // Check for incoming messages
            if ($message = $this->transport->receive()) {
                $this->handleMessage($message);
            }
            
            usleep(10000); // 10ms
        }

        $response = $this->pendingRequests[$id]->result;
        unset($this->pendingRequests[$id]);

        if (isset($response['error'])) {
            throw new Exception("MCP Error: " . json_encode($response['error']));
        }

        return $response['result'] ?? [];
    }

    /**
     * Send a notification to the MCP server (no response expected).
     */
    private function notify(string $method, array $params): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];

        $this->transport->send($message);
    }

    /**
     * Handle incoming message from transport.
     */
    public function handleMessage(array $message): void
    {
        // Handle response to our request
        if (isset($message['id']) && isset($this->pendingRequests[$message['id']])) {
            $promise = $this->pendingRequests[$message['id']];
            $promise->result = $message;
            $promise->resolved = true;
        }
        
        // Handle server notifications
        if (!isset($message['id']) && isset($message['method'])) {
            $this->handleNotification($message);
        }
    }

    /**
     * Handle server notifications.
     */
    private function handleNotification(array $message): void
    {
        $method = $message['method'];
        $params = $message['params'] ?? [];

        switch ($method) {
            case 'tools/changed':
                $this->discoverTools();
                break;
            case 'resources/changed':
                $this->discoverResources();
                break;
            case 'prompts/changed':
                $this->discoverPrompts();
                break;
            default:
                logger()->info("Received MCP notification: {$method}", $params);
        }
    }

    /**
     * Handle transport errors.
     */
    public function handleError(string $error): void
    {
        logger()->error("MCP transport error: {$error}");
        
        // Reject all pending requests
        foreach ($this->pendingRequests as $id => $promise) {
            $promise->result = ['error' => ['message' => $error]];
            $promise->resolved = true;
        }
    }

    /**
     * Handle transport close.
     */
    public function handleClose(): void
    {
        $this->initialized = false;
        
        // Reject all pending requests
        foreach ($this->pendingRequests as $id => $promise) {
            $promise->result = ['error' => ['message' => 'Connection closed']];
            $promise->resolved = true;
        }
    }

    /**
     * Get available tools.
     */
    public function getTools(): Collection
    {
        return $this->tools;
    }

    /**
     * Get available resources.
     */
    public function getResources(): Collection
    {
        return $this->resources;
    }

    /**
     * Get available prompts.
     */
    public function getPrompts(): Collection
    {
        return $this->prompts;
    }

    /**
     * Get server capabilities.
     */
    public function getCapabilities(): ?ServerCapabilities
    {
        return $this->capabilities;
    }

    /**
     * Check if initialized.
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Get the server's instructions (for system prompt injection).
     * MCP servers can provide instructions on how to use their tools.
     */
    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    /**
     * Get the server config.
     */
    public function getConfig(): ServerConfig
    {
        return $this->config;
    }
}