<?php

namespace SuperAgent\MCP\Types;

class Tool
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema = [],
    ) {}

    /**
     * Get the full tool name including MCP prefix.
     */
    public function getFullName(string $serverName): string
    {
        return "mcp_{$serverName}_{$this->name}";
    }

    /**
     * Validate input against the schema.
     */
    public function validateInput(array $input): bool
    {
        // Simple validation for now
        // Could be enhanced with JSON Schema validation
        if (empty($this->inputSchema)) {
            return true;
        }

        $required = $this->inputSchema['required'] ?? [];
        foreach ($required as $field) {
            if (!isset($input[$field])) {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }
}