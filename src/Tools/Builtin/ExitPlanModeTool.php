<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class ExitPlanModeTool extends Tool
{
    public function name(): string
    {
        return 'exit_plan_mode';
    }

    public function description(): string
    {
        return 'Exit planning mode and present the plan for approval or execution.';
    }

    public function category(): string
    {
        return 'planning';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['review', 'execute', 'save', 'discard'],
                    'description' => 'What to do with the plan: review, execute, save, or discard.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Additional notes about the plan.',
                ],
                'modifications' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'step_number' => ['type' => 'integer'],
                            'modification' => ['type' => 'string'],
                        ],
                    ],
                    'description' => 'Modifications to make to specific steps before executing.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        if (!EnterPlanModeTool::isInPlanMode()) {
            return ToolResult::error('Not in plan mode. Use enter_plan_mode first.');
        }

        $action = $input['action'] ?? 'review';
        $notes = $input['notes'] ?? '';
        $modifications = $input['modifications'] ?? [];

        $plan = EnterPlanModeTool::getCurrentPlan();
        
        // Apply modifications if any
        foreach ($modifications as $mod) {
            $stepNum = $mod['step_number'] ?? 0;
            $modification = $mod['modification'] ?? '';
            
            if (isset($plan['steps'][$stepNum - 1])) {
                $plan['steps'][$stepNum - 1]['modified'] = true;
                $plan['steps'][$stepNum - 1]['modification'] = $modification;
            }
        }

        // Add notes to plan
        if (!empty($notes)) {
            $plan['notes'] = $notes;
        }

        $plan['completed_at'] = date('Y-m-d H:i:s');
        $plan['total_steps'] = count($plan['steps']);

        switch ($action) {
            case 'review':
                // Exit plan mode but return the plan for review
                EnterPlanModeTool::exitPlanMode();
                return ToolResult::success([
                    'message' => 'Plan ready for review',
                    'plan' => $plan,
                    'next_action' => 'Review the plan and decide whether to execute',
                ]);

            case 'execute':
                // Exit plan mode and mark plan as ready to execute
                $plan['status'] = 'ready_to_execute';
                EnterPlanModeTool::exitPlanMode();
                
                // Store plan for execution
                VerifyPlanExecutionTool::storePlan($plan);
                
                return ToolResult::success([
                    'message' => 'Plan approved for execution',
                    'plan' => $plan,
                    'next_action' => 'Execute each step in sequence',
                ]);

            case 'save':
                // Save plan for later
                $plan['status'] = 'saved';
                $planId = uniqid('plan_');
                
                // In real implementation, save to storage
                EnterPlanModeTool::exitPlanMode();
                
                return ToolResult::success([
                    'message' => 'Plan saved',
                    'plan_id' => $planId,
                    'plan' => $plan,
                ]);

            case 'discard':
                // Discard the plan
                EnterPlanModeTool::exitPlanMode();
                
                return ToolResult::success([
                    'message' => 'Plan discarded',
                    'steps_discarded' => count($plan['steps']),
                ]);

            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}