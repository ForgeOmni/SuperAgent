<?php

namespace SuperAgent\MCP\Types;

class Prompt
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly array $arguments = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'arguments' => $this->arguments,
        ];
    }
}