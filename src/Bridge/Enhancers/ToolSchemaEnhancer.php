<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Bridge\BridgeToolProxy;
use SuperAgent\Messages\AssistantMessage;

/**
 * Optimizes tool definitions before sending to the LLM provider.
 *
 * - Ensures empty properties are `{}` not `[]` (JSON schema requirement)
 * - Optionally enriches tool descriptions with usage hints
 */
class ToolSchemaEnhancer implements EnhancerInterface
{
    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        foreach ($tools as $i => $tool) {
            if ($tool instanceof BridgeToolProxy) {
                $tools[$i] = $this->enhanceTool($tool);
            }
        }
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        return $message;
    }

    private function enhanceTool(BridgeToolProxy $tool): BridgeToolProxy
    {
        $schema = $tool->inputSchema();
        $schema = $this->ensureObjectFields($schema);

        $description = $tool->description();

        // Apply known tool description enhancements
        $enhancements = function_exists('config') ? config('superagent.bridge.tool_enhancements', []) : [];
        if (isset($enhancements[$tool->name()])) {
            $description = $enhancements[$tool->name()];
        }

        return new BridgeToolProxy(
            $tool->name(),
            $description,
            $schema,
        );
    }

    /**
     * Ensure all 'properties' fields in JSON Schema are objects, not arrays.
     *
     * When PHP encodes an empty array [], it becomes JSON [] instead of {}.
     * JSON Schema requires properties to be an object.
     */
    private function ensureObjectFields(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties']) && empty($schema['properties'])) {
            $schema['properties'] = (object) [];
        }

        // Recursively fix nested schemas
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = $this->ensureObjectFields($prop);
                }
            }
        }

        // Fix items schema for array types
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->ensureObjectFields($schema['items']);
        }

        return $schema;
    }
}
