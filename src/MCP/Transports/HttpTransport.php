<?php

namespace SuperAgent\MCP\Transports;

use SuperAgent\MCP\Contracts\Transport;
use GuzzleHttp\Client;
use Exception;

class HttpTransport implements Transport
{
    private ?Client $client = null;
    private bool $connected = false;
    private $messageCallback = null;
    private $errorCallback = null;
    private $closeCallback = null;
    private array $messageQueue = [];

    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
    ) {}

    /**
     * Connect to the MCP server via HTTP.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->client = new Client([
            'base_uri' => $this->url,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $this->headers),
            'timeout' => 30,
        ]);

        // Test connection with a simple request
        try {
            // Some servers might have a health endpoint
            $this->client->get('/health');
        } catch (Exception $e) {
            // Connection might still be valid even if health endpoint doesn't exist
            logger()->debug("HTTP MCP health check failed: " . $e->getMessage());
        }

        $this->connected = true;
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        $this->connected = false;
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
        return $this->connected;
    }

    /**
     * Send a message to the server.
     */
    public function send(array $message): void
    {
        if (!$this->isConnected()) {
            throw new Exception("Not connected to MCP server");
        }

        try {
            // HTTP transport sends JSON-RPC messages directly
            $response = $this->client->post('/', [
                'json' => $message,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            if ($responseData) {
                // Queue the response for retrieval
                $this->messageQueue[] = $responseData;
                
                // Call message callback if set
                if ($this->messageCallback) {
                    call_user_func($this->messageCallback, $responseData);
                }
            }
        } catch (Exception $e) {
            if ($this->errorCallback) {
                call_user_func($this->errorCallback, $e->getMessage());
            }
            throw new Exception("Failed to send HTTP message: " . $e->getMessage());
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

        // Return queued messages from HTTP responses
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
}