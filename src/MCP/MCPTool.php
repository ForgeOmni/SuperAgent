<?php

namespace SuperAgent\MCP;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\MCP\Client;
use SuperAgent\MCP\Types\Tool as MCPToolType;

class MCPTool extends Tool
{
    public function __construct(
        private readonly Client $client,
        private readonly string $serverName,
        private readonly MCPToolType $mcpTool,
    ) {}

    public function name(): string
    {
        return $this->mcpTool->getFullName($this->serverName);
    }

    public function description(): string
    {
        return "[MCP:{$this->serverName}] " . $this->mcpTool->description;
    }

    public function category(): string
    {
        return 'mcp';
    }

    public function inputSchema(): array
    {
        // Convert MCP schema to SuperAgent schema format
        $schema = $this->mcpTool->inputSchema;
        
        if (empty($schema)) {
            return [
                'type' => 'object',
                'properties' => [],
            ];
        }

        // MCP uses JSON Schema, which is compatible with our format
        return $schema;
    }

    public function execute(array $input): ToolResult
    {
        try {
            // Validate input
            if (!$this->mcpTool->validateInput($input)) {
                return ToolResult::error("Invalid input for MCP tool {$this->mcpTool->name}");
            }

            // Ensure client is initialized
            if (!$this->client->isInitialized()) {
                $this->client->initialize();
            }

            // Call the MCP tool
            $result = $this->client->callTool($this->mcpTool->name, $input);

            // Format the result
            if (is_array($result)) {
                // Handle content array (MCP format)
                if (isset($result['content'])) {
                    return $this->formatContent($result['content']);
                }
                
                // Direct result
                return ToolResult::success($result);
            }

            // String result
            return ToolResult::success($result);

        } catch (\Exception $e) {
            return ToolResult::error("MCP tool error: " . $e->getMessage());
        }
    }

    /**
     * Format MCP content array to SuperAgent result.
     */
    private function formatContent(array $content): ToolResult
    {
        if (empty($content)) {
            return ToolResult::success('');
        }

        // Handle single content item
        if (count($content) === 1) {
            $item = $content[0];
            
            if ($item['type'] === 'text') {
                return ToolResult::success($item['text'] ?? '');
            }
            
            if ($item['type'] === 'image') {
                return ToolResult::success([
                    'type' => 'image',
                    'data' => $item['data'] ?? null,
                    'mimeType' => $item['mimeType'] ?? 'image/png',
                ]);
            }

            if ($item['type'] === 'resource') {
                return ToolResult::success([
                    'type' => 'resource',
                    'uri' => $item['uri'] ?? '',
                    'text' => $item['text'] ?? '',
                ]);
            }
        }

        // Handle multiple content items
        $formatted = [];
        foreach ($content as $item) {
            if ($item['type'] === 'text') {
                $formatted[] = $item['text'] ?? '';
            } else {
                $formatted[] = $item;
            }
        }

        return ToolResult::success($formatted);
    }

    public function isReadOnly(): bool
    {
        // Determine based on tool name patterns
        $name = strtolower($this->mcpTool->name);
        
        $readOnlyPatterns = [
            'list', 'get', 'read', 'search', 'find', 'show', 'describe',
            'view', 'inspect', 'check', 'status', 'info'
        ];

        foreach ($readOnlyPatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the original MCP tool.
     */
    public function getMCPTool(): MCPToolType
    {
        return $this->mcpTool;
    }

    /**
     * Get the MCP client.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the server name.
     */
    public function getServerName(): string
    {
        return $this->serverName;
    }
}