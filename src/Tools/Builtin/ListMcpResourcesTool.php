<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\MCP\MCPManager;

class ListMcpResourcesTool extends Tool
{
    private ?MCPManager $mcpManager;

    public function __construct(?MCPManager $mcpManager = null)
    {
        $this->mcpManager = $mcpManager;
    }

    public function name(): string
    {
        return 'list_mcp_resources';
    }

    public function description(): string
    {
        return 'List all available resources from connected MCP servers.';
    }

    public function category(): string
    {
        return 'mcp';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server' => [
                    'type' => 'string',
                    'description' => 'Filter by specific server name (optional)',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Filter resources by URI pattern (optional)',
                ],
            ],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $manager = $this->mcpManager ?? MCPManager::getInstance();
        $serverFilter = $input['server'] ?? null;
        $pattern = $input['pattern'] ?? null;

        try {
            $resources = $manager->getResources();
            
            if ($resources->isEmpty()) {
                return ToolResult::success('No MCP resources available. Connect to an MCP server first.');
            }

            $result = [];
            
            foreach ($resources as $key => $resource) {
                // Extract server name from key
                [$serverName, ] = explode(':', $key, 2);
                
                // Apply server filter
                if ($serverFilter && $serverName !== $serverFilter) {
                    continue;
                }
                
                // Apply pattern filter
                if ($pattern && !str_contains(strtolower($resource->uri), strtolower($pattern))) {
                    continue;
                }
                
                $result[] = [
                    'server' => $serverName,
                    'uri' => $resource->uri,
                    'name' => $resource->name,
                    'description' => $resource->description,
                    'mimeType' => $resource->mimeType,
                ];
            }

            if (empty($result)) {
                return ToolResult::success('No resources found matching the criteria.');
            }

            return ToolResult::success([
                'count' => count($result),
                'resources' => $result,
            ]);

        } catch (\Exception $e) {
            return ToolResult::error('Failed to list MCP resources: ' . $e->getMessage());
        }
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}