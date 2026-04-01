<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class WorkflowTool extends Tool
{
    private static array $workflows = [];
    private static int $nextId = 1;

    public function name(): string
    {
        return 'workflow';
    }

    public function description(): string
    {
        return 'Create and manage reusable workflows that combine multiple tools and steps.';
    }

    public function category(): string
    {
        return 'automation';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'run', 'get', 'list', 'delete'],
                    'description' => 'Workflow action: create, run, get, list, or delete.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Workflow name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Workflow description.',
                ],
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tool' => ['type' => 'string'],
                            'input' => ['type' => 'object'],
                            'condition' => ['type' => 'string'],
                        ],
                    ],
                    'description' => 'Workflow steps.',
                ],
                'workflow_id' => [
                    'type' => 'integer',
                    'description' => 'Workflow ID (for run/get/delete actions).',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Parameters to pass when running workflow.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                return $this->createWorkflow($input);
            case 'run':
                return $this->runWorkflow($input);
            case 'get':
                return $this->getWorkflow($input);
            case 'list':
                return $this->listWorkflows();
            case 'delete':
                return $this->deleteWorkflow($input);
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function createWorkflow(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $steps = $input['steps'] ?? [];

        if (empty($name)) {
            return ToolResult::error('Workflow name is required.');
        }

        if (empty($steps)) {
            return ToolResult::error('Workflow must have at least one step.');
        }

        $workflowId = self::$nextId++;
        
        self::$workflows[$workflowId] = [
            'id' => $workflowId,
            'name' => $name,
            'description' => $description,
            'steps' => $steps,
            'created_at' => date('Y-m-d H:i:s'),
            'run_count' => 0,
            'last_run' => null,
        ];

        return ToolResult::success([
            'message' => 'Workflow created',
            'workflow_id' => $workflowId,
            'name' => $name,
            'steps' => count($steps),
        ]);
    }

    private function runWorkflow(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;
        $parameters = $input['parameters'] ?? [];

        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }

        if (!isset(self::$workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        $workflow = &self::$workflows[$workflowId];
        $workflow['run_count']++;
        $workflow['last_run'] = date('Y-m-d H:i:s');

        // Simulate workflow execution
        $results = [];
        foreach ($workflow['steps'] as $index => $step) {
            $results[] = [
                'step' => $index + 1,
                'tool' => $step['tool'],
                'status' => 'simulated',
                'message' => "Would execute {$step['tool']}",
            ];
        }

        return ToolResult::success([
            'message' => 'Workflow executed',
            'workflow_id' => $workflowId,
            'name' => $workflow['name'],
            'steps_executed' => count($workflow['steps']),
            'results' => $results,
        ]);
    }

    private function getWorkflow(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;

        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }

        if (!isset(self::$workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        return ToolResult::success(self::$workflows[$workflowId]);
    }

    private function listWorkflows(): ToolResult
    {
        $summary = [];
        
        foreach (self::$workflows as $workflow) {
            $summary[] = [
                'id' => $workflow['id'],
                'name' => $workflow['name'],
                'description' => $workflow['description'],
                'steps' => count($workflow['steps']),
                'run_count' => $workflow['run_count'],
                'created_at' => $workflow['created_at'],
            ];
        }

        return ToolResult::success([
            'count' => count($summary),
            'workflows' => $summary,
        ]);
    }

    private function deleteWorkflow(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;

        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }

        if (!isset(self::$workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        $name = self::$workflows[$workflowId]['name'];
        unset(self::$workflows[$workflowId]);

        return ToolResult::success([
            'message' => 'Workflow deleted',
            'workflow_id' => $workflowId,
            'name' => $name,
        ]);
    }

    public static function clearWorkflows(): void
    {
        self::$workflows = [];
        self::$nextId = 1;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}