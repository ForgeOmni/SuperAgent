<?php

namespace SuperAgent\Tools;

use SuperAgent\Contracts\ToolInterface;

abstract class Tool implements ToolInterface
{
    private ?ToolStateManager $stateManager = null;

    /**
     * Set the shared state manager for this tool instance.
     */
    public function setStateManager(ToolStateManager $manager): void
    {
        $this->stateManager = $manager;
    }

    /**
     * Get the shared state manager (creates a local fallback if none injected).
     */
    protected function state(): ToolStateManager
    {
        if ($this->stateManager === null) {
            $this->stateManager = new ToolStateManager();
        }
        return $this->stateManager;
    }

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
