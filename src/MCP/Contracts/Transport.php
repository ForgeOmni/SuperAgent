<?php

namespace SuperAgent\MCP\Contracts;

interface Transport
{
    /**
     * Connect to the MCP server.
     */
    public function connect(): void;

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void;

    /**
     * Check if connected.
     */
    public function isConnected(): bool;

    /**
     * Send a message to the server.
     */
    public function send(array $message): void;

    /**
     * Receive a message from the server.
     */
    public function receive(): ?array;

    /**
     * Set a callback for incoming messages.
     */
    public function onMessage(callable $callback): void;

    /**
     * Set a callback for errors.
     */
    public function onError(callable $callback): void;

    /**
     * Set a callback for connection close.
     */
    public function onClose(callable $callback): void;
}