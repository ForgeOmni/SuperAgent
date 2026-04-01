<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class SnipTool extends Tool
{
    private static array $snippets = [];

    public function name(): string
    {
        return 'snip';
    }

    public function description(): string
    {
        return 'Store and retrieve code snippets for reuse. Useful for saving important code segments.';
    }

    public function category(): string
    {
        return 'code';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['save', 'get', 'list', 'delete', 'search'],
                    'description' => 'Action to perform: save, get, list, delete, or search.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Snippet name/identifier.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Snippet content (for save action).',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Programming language of the snippet.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Description of what the snippet does.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Tags for categorizing the snippet.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (for search action).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'save':
                return $this->saveSnippet($input);
            case 'get':
                return $this->getSnippet($input);
            case 'list':
                return $this->listSnippets($input);
            case 'delete':
                return $this->deleteSnippet($input);
            case 'search':
                return $this->searchSnippets($input);
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function saveSnippet(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        $content = $input['content'] ?? '';
        
        if (empty($name)) {
            return ToolResult::error('Snippet name is required.');
        }
        
        if (empty($content)) {
            return ToolResult::error('Snippet content is required.');
        }

        self::$snippets[$name] = [
            'name' => $name,
            'content' => $content,
            'language' => $input['language'] ?? 'text',
            'description' => $input['description'] ?? '',
            'tags' => $input['tags'] ?? [],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        return ToolResult::success([
            'message' => 'Snippet saved successfully',
            'name' => $name,
        ]);
    }

    private function getSnippet(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        
        if (empty($name)) {
            return ToolResult::error('Snippet name is required.');
        }
        
        if (!isset(self::$snippets[$name])) {
            return ToolResult::error("Snippet '{$name}' not found.");
        }

        return ToolResult::success(self::$snippets[$name]);
    }

    private function listSnippets(array $input): ToolResult
    {
        $language = $input['language'] ?? null;
        $tags = $input['tags'] ?? [];
        
        $filtered = self::$snippets;
        
        if ($language) {
            $filtered = array_filter($filtered, fn($s) => $s['language'] === $language);
        }
        
        if (!empty($tags)) {
            $filtered = array_filter($filtered, function($s) use ($tags) {
                return !empty(array_intersect($tags, $s['tags']));
            });
        }

        return ToolResult::success([
            'count' => count($filtered),
            'snippets' => array_values($filtered),
        ]);
    }

    private function deleteSnippet(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        
        if (empty($name)) {
            return ToolResult::error('Snippet name is required.');
        }
        
        if (!isset(self::$snippets[$name])) {
            return ToolResult::error("Snippet '{$name}' not found.");
        }

        unset(self::$snippets[$name]);

        return ToolResult::success([
            'message' => 'Snippet deleted successfully',
            'name' => $name,
        ]);
    }

    private function searchSnippets(array $input): ToolResult
    {
        $query = $input['query'] ?? '';
        
        if (empty($query)) {
            return ToolResult::error('Search query is required.');
        }

        $results = [];
        $queryLower = strtolower($query);

        foreach (self::$snippets as $snippet) {
            if (str_contains(strtolower($snippet['name']), $queryLower) ||
                str_contains(strtolower($snippet['content']), $queryLower) ||
                str_contains(strtolower($snippet['description']), $queryLower)) {
                $results[] = $snippet;
            }
        }

        return ToolResult::success([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    public static function clearSnippets(): void
    {
        self::$snippets = [];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}