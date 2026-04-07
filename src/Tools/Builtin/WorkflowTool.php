<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class WorkflowTool extends Tool
{

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

        $workflowId = $this->state()->nextId($this->name());

        $this->state()->putIn($this->name(), 'workflows', $workflowId, [
            'id' => $workflowId,
            'name' => $name,
            'description' => $description,
            'steps' => $steps,
            'created_at' => date('Y-m-d H:i:s'),
            'run_count' => 0,
            'last_run' => null,
        ]);

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

        $workflows = $this->state()->get($this->name(), 'workflows', []);

        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        $workflow = $workflows[$workflowId];
        $workflow['run_count']++;
        $workflow['last_run'] = date('Y-m-d H:i:s');

        $this->state()->putIn($this->name(), 'workflows', $workflowId, $workflow);

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

        $workflows = $this->state()->get($this->name(), 'workflows', []);

        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        return ToolResult::success($workflows[$workflowId]);
    }

    private function listWorkflows(): ToolResult
    {
        $workflows = $this->state()->get($this->name(), 'workflows', []);
        $summary = [];

        foreach ($workflows as $workflow) {
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

        $workflows = $this->state()->get($this->name(), 'workflows', []);

        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        $name = $workflows[$workflowId]['name'];
        $this->state()->removeFrom($this->name(), 'workflows', $workflowId);

        return ToolResult::success([
            'message' => 'Workflow deleted',
            'workflow_id' => $workflowId,
            'name' => $name,
        ]);
    }

    public function clearWorkflows(): void
    {
        $this->state()->clearTool($this->name());
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}