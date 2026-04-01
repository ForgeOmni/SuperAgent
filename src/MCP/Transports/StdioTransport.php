<?php

namespace SuperAgent\MCP\Transports;

use SuperAgent\MCP\Contracts\Transport;
use Symfony\Component\Process\Process;
use Exception;

class StdioTransport implements Transport
{
    private ?Process $process = null;
    private bool $connected = false;
    private $messageCallback = null;
    private $errorCallback = null;
    private $closeCallback = null;
    private string $buffer = '';

    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
    ) {}

    /**
     * Connect to the MCP server via stdio.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        // Build the full command with arguments
        $fullCommand = array_merge([$this->command], $this->args);

        // Create and start the process
        $this->process = new Process(
            $fullCommand,
            null,
            array_merge($_ENV, $this->env),
            null,
            null
        );

        $this->process->start();

        // Wait a bit for the process to start
        usleep(100000); // 100ms

        if (!$this->process->isRunning()) {
            $error = $this->process->getErrorOutput();
            throw new Exception("Failed to start MCP server: {$error}");
        }

        $this->connected = true;

        // Start reading output in background
        $this->startReading();
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        if (!$this->connected || !$this->process) {
            return;
        }

        $this->process->stop(5); // 5 second timeout
        $this->connected = false;
        $this->process = null;

        if ($this->closeCallback) {
            call_user_func($this->closeCallback);
        }
    }

    /**
     * Check if connected.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->process && $this->process->isRunning();
    }

    /**
     * Send a message to the server.
     */
    public function send(array $message): void
    {
        if (!$this->isConnected()) {
            throw new Exception("Not connected to MCP server");
        }

        $json = json_encode($message);
        if ($json === false) {
            throw new Exception("Failed to encode message");
        }

        // MCP uses newline-delimited JSON
        $this->process->getInput()->write($json . "\n");
    }

    /**
     * Receive a message from the server.
     */
    public function receive(): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        // Check for complete messages in buffer
        if (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            if (trim($line) === '') {
                return null;
            }

            $message = json_decode($line, true);
            if ($message === null) {
                if ($this->errorCallback) {
                    call_user_func($this->errorCallback, "Invalid JSON: {$line}");
                }
                return null;
            }

            return $message;
        }

        // Read more data from process
        $output = $this->process->getIncrementalOutput();
        if ($output) {
            $this->buffer .= $output;
            // Try again recursively
            return $this->receive();
        }

        // Check for errors
        $error = $this->process->getIncrementalErrorOutput();
        if ($error && $this->errorCallback) {
            call_user_func($this->errorCallback, $error);
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
     * Start reading output from the process.
     */
    private function startReading(): void
    {
        // In a real implementation, this would run in a background thread
        // For now, messages are read when receive() is called
        // Laravel's queue system could be used for async processing
    }

    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->disconnect();
        }
    }
}