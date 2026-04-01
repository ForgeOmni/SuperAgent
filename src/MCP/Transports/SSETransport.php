<?php

namespace SuperAgent\MCP\Transports;

use SuperAgent\MCP\Contracts\Transport;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Exception;

class SSETransport implements Transport
{
    private ?Client $client = null;
    private bool $connected = false;
    private $messageCallback = null;
    private $errorCallback = null;
    private $closeCallback = null;
    private ?string $sessionId = null;
    private array $messageQueue = [];

    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
    ) {}

    /**
     * Connect to the MCP server via SSE.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->client = new Client([
            'base_uri' => $this->url,
            'headers' => array_merge([
                'Accept' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ], $this->headers),
            'stream' => true,
            'timeout' => 0, // No timeout for SSE
        ]);

        // Establish SSE connection
        try {
            // Start SSE session
            $response = $this->client->post('/sse/sessions', [
                'json' => [
                    'capabilities' => [
                        'tools' => ['call'],
                        'resources' => ['read', 'list'],
                        'prompts' => ['get', 'list'],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $this->sessionId = $body['sessionId'] ?? null;

            if (!$this->sessionId) {
                throw new Exception("Failed to get session ID from SSE server");
            }

            $this->connected = true;
            
            // Start listening for events
            $this->startEventStream();
        } catch (Exception $e) {
            throw new Exception("Failed to connect to SSE server: " . $e->getMessage());
        }
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        if (!$this->connected || !$this->sessionId) {
            return;
        }

        try {
            // Close SSE session
            $this->client->delete("/sse/sessions/{$this->sessionId}");
        } catch (Exception $e) {
            // Log but don't throw
            logger()->warning("Error closing SSE session: " . $e->getMessage());
        }

        $this->connected = false;
        $this->sessionId = null;
        $this->client = null;

        if ($this->closeCallback) {
            call_user_func($this->closeCallback);
        }
    }

    /**
     * Check if connected.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->sessionId !== null;
    }

    /**
     * Send a message to the server.
     */
    public function send(array $message): void
    {
        if (!$this->isConnected()) {
            throw new Exception("Not connected to MCP server");
        }

        // Send message via POST
        try {
            $response = $this->client->post("/sse/sessions/{$this->sessionId}/messages", [
                'json' => $message,
            ]);

            // Response should come through SSE stream
        } catch (Exception $e) {
            if ($this->errorCallback) {
                call_user_func($this->errorCallback, $e->getMessage());
            }
            throw new Exception("Failed to send message: " . $e->getMessage());
        }
    }

    /**
     * Receive a message from the server.
     */
    public function receive(): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        // Return queued messages
        if (!empty($this->messageQueue)) {
            return array_shift($this->messageQueue);
        }

        return null;
    }

    /**
     * Set a callback for incoming messages.
     */
    public function onMessage(callable $callback): void
    {
        $this->messageCallback = $callback;
    }

    /**
     * Set a callback for errors.
     */
    public function onError(callable $callback): void
    {
        $this->errorCallback = $callback;
    }

    /**
     * Set a callback for connection close.
     */
    public function onClose(callable $callback): void
    {
        $this->closeCallback = $callback;
    }

    /**
     * Start listening to the SSE event stream.
     */
    private function startEventStream(): void
    {
        // In a production environment, this would run in a separate process/thread
        // For now, we'll poll the endpoint
        
        // This is a simplified implementation
        // Real SSE would require long-running connection with event parsing
        register_shutdown_function(function () {
            if ($this->isConnected()) {
                $this->disconnect();
            }
        });
    }

    /**
     * Parse SSE event data.
     */
    private function parseSSEEvent(string $data): ?array
    {
        $lines = explode("\n", $data);
        $event = null;
        $eventData = '';

        foreach ($lines as $line) {
            if (strpos($line, 'event:') === 0) {
                $event = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $eventData .= trim(substr($line, 5));
            }
        }

        if ($event === 'message' && $eventData) {
            $message = json_decode($eventData, true);
            if ($message) {
                return $message;
            }
        }

        return null;
    }
}