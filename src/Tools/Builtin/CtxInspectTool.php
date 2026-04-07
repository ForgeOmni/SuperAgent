<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class CtxInspectTool extends Tool
{
    public function name(): string
    {
        return 'ctx_inspect';
    }

    public function description(): string
    {
        return 'Inspect the current context, including variables, tool history, and memory usage.';
    }

    public function category(): string
    {
        return 'debug';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['memory', 'tools', 'tasks', 'config', 'all'],
                    'description' => 'Type of context to inspect.',
                ],
                'verbose' => [
                    'type' => 'boolean',
                    'description' => 'Include detailed information. Default: false.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $type = $input['type'] ?? 'all';
        $verbose = $input['verbose'] ?? false;

        $context = [];

        switch ($type) {
            case 'memory':
                $context = $this->inspectMemory($verbose);
                break;
            case 'tools':
                $context = $this->inspectTools($verbose);
                break;
            case 'tasks':
                $context = $this->inspectTasks($verbose);
                break;
            case 'config':
                $context = $this->inspectConfig($verbose);
                break;
            case 'all':
            default:
                $context = [
                    'memory' => $this->inspectMemory(false),
                    'tools' => $this->inspectTools(false),
                    'tasks' => $this->inspectTasks(false),
                    'config' => $this->inspectConfig(false),
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
                break;
        }

        return ToolResult::success($context);
    }

    private function inspectMemory(bool $verbose): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'formatted' => [
                'current' => $this->formatBytes(memory_get_usage(true)),
                'peak' => $this->formatBytes(memory_get_peak_usage(true)),
            ],
        ];
    }

    private function inspectTools(bool $verbose): array
    {
        $registry = \SuperAgent\Tools\BuiltinToolRegistry::all();
        
        $categories = [];
        foreach ($registry as $name => $tool) {
            $category = $tool->category();
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            $categories[$category][] = $name;
        }

        return [
            'total_tools' => count($registry),
            'categories' => array_keys($categories),
            'tools_by_category' => $verbose ? $categories : array_map('count', $categories),
        ];
    }

    private function inspectTasks(bool $verbose): array
    {
        $tasks = TaskCreateTool::getAllTasks();
        
        $stats = [
            'total' => count($tasks),
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        foreach ($tasks as $task) {
            $stats[$task['status']]++;
        }

        $result = ['stats' => $stats];
        
        if ($verbose) {
            $result['tasks'] = array_values($tasks);
        }

        return $result;
    }

    private function inspectConfig(bool $verbose): array
    {
        $config = $this->state()->get('config', 'config', []);
        
        return [
            'total_keys' => $this->countKeys($config),
            'top_level_keys' => array_keys($config),
            'config' => $verbose ? $config : null,
        ];
    }

    private function countKeys(array $array): int
    {
        $count = count($array);
        foreach ($array as $value) {
            if (is_array($value)) {
                $count += $this->countKeys($value);
            }
        }
        return $count;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}