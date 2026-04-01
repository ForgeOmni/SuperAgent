<?php

namespace SuperAgent\Tools;

use SuperAgent\Contracts\ToolInterface;

abstract class Tool implements ToolInterface
{
    /**
     * Get the name of this tool.
     * This is a convenience method that wraps name().
     */
    public function getName(): string
    {
        return $this->name();
    }
    
    /**
     * Get the category of this tool.
     * Categories: file, search, execution, network, etc.
     */
    public function category(): string
    {
        return 'general';
    }

    public function isReadOnly(): bool
    {
        return false;
    }
    
    /**
     * Whether this tool requires user interaction.
     * Override in subclasses that need explicit user involvement.
     */
    public function requiresUserInteraction(): bool
    {
        return false;
    }

    /**
     * Convert to the provider-agnostic tool definition format.
     */
    public function toDefinition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => self::ensureObjectFields($this->inputSchema()),
            'category' => $this->category(),
        ];
    }

    /**
     * Ensure 'properties' and similar fields are objects (not arrays)
     * so json_encode produces {} instead of [].
     */
    protected static function ensureObjectFields(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties']) && empty($schema['properties'])) {
            $schema['properties'] = (object) [];
        }

        return $schema;
    }
}
