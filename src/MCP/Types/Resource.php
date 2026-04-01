<?php

namespace SuperAgent\MCP\Types;

class Resource
{
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $mimeType = 'text/plain',
    ) {}

    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'name' => $this->name,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];
    }
}