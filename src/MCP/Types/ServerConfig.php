<?php

namespace SuperAgent\MCP\Types;

class ServerConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $config,
        public readonly bool $enabled = true,
        public readonly ?array $capabilities = null,
    ) {}

    /**
     * Create a stdio server config.
     */
    public static function stdio(
        string $name,
        string $command,
        array $args = [],
        ?array $env = null
    ): self {
        return new self(
            name: $name,
            type: 'stdio',
            config: [
                'command' => $command,
                'args' => $args,
                'env' => $env ?? [],
            ]
        );
    }

    /**
     * Create an SSE server config.
     */
    public static function sse(
        string $name,
        string $url,
        ?array $headers = null
    ): self {
        return new self(
            name: $name,
            type: 'sse',
            config: [
                'url' => $url,
                'headers' => $headers ?? [],
            ]
        );
    }

    /**
     * Create an HTTP server config.
     */
    public static function http(
        string $name,
        string $url,
        ?array $headers = null
    ): self {
        return new self(
            name: $name,
            type: 'http',
            config: [
                'url' => $url,
                'headers' => $headers ?? [],
            ]
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'config' => $this->config,
            'enabled' => $this->enabled,
            'capabilities' => $this->capabilities,
        ];
    }
}