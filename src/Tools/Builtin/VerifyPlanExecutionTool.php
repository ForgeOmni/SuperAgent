<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\Tools\ToolStateManager;

class VerifyPlanExecutionTool extends Tool
{
    private const TOOL_KEY = 'verify_plan_execution';

    private static ?ToolStateManager $sharedState = null;

    private static function shared(): ToolStateManager
    {
        if (self::$sharedState === null) {
            self::$sharedState = new ToolStateManager();
        }
        return self::$sharedState;
    }

    public static function setSharedStateManager(ToolStateManager $manager): void
    {
        self::$sharedState = $manager;
    }

    public function name(): string
    {
        return 'verify_plan_execution';
    }

    public function description(): string
    {
        return 'Verify that executed actions match the planned steps.';
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
                'step_number' => [
                    'type' => 'integer',
                    'description' => 'The step number being executed.',
                ],
                'tool' => [
                    'type' => 'string',
                    'description' => 'The tool that was executed.',
                ],
                'result' => [
                    'type' => 'string',
                    'enum' => ['success', 'failure', 'skipped'],
                    'description' => 'Result of the execution.',
                ],
                'deviation' => [
                    'type' => 'string',
                    'description' => 'Description of any deviation from the plan.',
                ],
                'output' => [
                    'type' => ['string', 'object', 'array'],
                    'description' => 'Output from the tool execution.',
                ],
            ],
            'required' => ['step_number', 'tool', 'result'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $s = self::shared();
        $stepNumber = $input['step_number'] ?? null;
        $tool = $input['tool'] ?? '';
        $result = $input['result'] ?? '';
        $deviation = $input['deviation'] ?? null;
        $output = $input['output'] ?? null;

        if ($stepNumber === null) {
            return ToolResult::error('Step number is required.');
        }

        if (empty($tool)) {
            return ToolResult::error('Tool name is required.');
        }

        $plan = $s->get(self::TOOL_KEY, 'currentExecutionPlan');
        if ($plan === null) {
            return ToolResult::error('No plan is currently being executed.');
        }

        if (!isset($plan['steps'][$stepNumber - 1])) {
            return ToolResult::error("Step {$stepNumber} not found in plan.");
        }

        $plannedStep = $plan['steps'][$stepNumber - 1];

        $execution = [
            'step_number' => $stepNumber,
            'planned_tool' => $plannedStep['tool'] ?? 'unknown',
            'executed_tool' => $tool,
            'result' => $result,
            'deviation' => $deviation,
            'output' => $output,
            'executed_at' => date('Y-m-d H:i:s'),
            'matches_plan' => ($plannedStep['tool'] ?? '') === $tool,
        ];

        $s->push(self::TOOL_KEY, 'executionHistory', $execution);
        $history = $s->get(self::TOOL_KEY, 'executionHistory', []);

        $deviations = [];
        if (!$execution['matches_plan']) {
            $deviations[] = "Tool mismatch: expected '{$plannedStep['tool']}', got '{$tool}'";
        }
        if ($result === 'failure') {
            $deviations[] = "Step failed";
        }
        if ($deviation) {
            $deviations[] = $deviation;
        }

        $completedSteps = count(array_filter($history, fn($e) => $e['result'] === 'success'));
        $totalSteps = count($plan['steps']);
        $progress = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        $response = [
            'step' => $stepNumber,
            'status' => $result,
            'progress' => "{$progress}%",
            'completed' => $completedSteps,
            'total' => $totalSteps,
        ];

        if (!empty($deviations)) {
            $response['deviations'] = $deviations;
        }

        if ($completedSteps >= $totalSteps) {
            $response['plan_complete'] = true;
            $response['summary'] = $this->generateExecutionSummary();
        }

        return ToolResult::success($response);
    }

    private function generateExecutionSummary(): array
    {
        $history = self::shared()->get(self::TOOL_KEY, 'executionHistory', []);
        $successful = count(array_filter($history, fn($e) => $e['result'] === 'success'));
        $failed = count(array_filter($history, fn($e) => $e['result'] === 'failure'));
        $skipped = count(array_filter($history, fn($e) => $e['result'] === 'skipped'));
        $deviations = count(array_filter($history, fn($e) => !$e['matches_plan']));

        return [
            'successful_steps' => $successful,
            'failed_steps' => $failed,
            'skipped_steps' => $skipped,
            'deviations' => $deviations,
            'execution_time' => $this->calculateExecutionTime($history),
        ];
    }

    private function calculateExecutionTime(array $history): string
    {
        if (empty($history)) {
            return '0s';
        }

        $first = reset($history);
        $last = end($history);

        $start = strtotime($first['executed_at']);
        $end = strtotime($last['executed_at']);

        $diff = $end - $start;

        if ($diff < 60) {
            return "{$diff}s";
        } elseif ($diff < 3600) {
            return round($diff / 60) . "m";
        } else {
            return round($diff / 3600, 1) . "h";
        }
    }

    public static function storePlan(array $plan): void
    {
        $s = self::shared();
        $s->set(self::TOOL_KEY, 'currentExecutionPlan', $plan);
        $s->set(self::TOOL_KEY, 'executionHistory', []);
    }

    public static function getExecutionStatus(): array
    {
        $s = self::shared();
        $plan = $s->get(self::TOOL_KEY, 'currentExecutionPlan');

        if ($plan === null) {
            return ['status' => 'no_plan'];
        }

        $history = $s->get(self::TOOL_KEY, 'executionHistory', []);
        $totalSteps = count($plan['steps']);
        $completedSteps = count(array_filter($history, fn($e) => $e['result'] === 'success'));

        return [
            'status' => 'executing',
            'progress' => $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0,
            'completed' => $completedSteps,
            'total' => $totalSteps,
            'history' => $history,
        ];
    }

    public static function reset(): void
    {
        self::shared()->clearTool(self::TOOL_KEY);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
