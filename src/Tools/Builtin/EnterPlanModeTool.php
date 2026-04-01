<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class EnterPlanModeTool extends Tool
{
    private static bool $inPlanMode = false;
    private static array $currentPlan = [];

    public function name(): string
    {
        return 'enter_plan_mode';
    }

    public function description(): string
    {
        return 'Enter planning mode where all actions are simulated and collected into a plan before execution.';
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
                'description' => [
                    'type' => 'string',
                    'description' => 'Description of what you plan to accomplish.',
                ],
                'estimated_steps' => [
                    'type' => 'integer',
                    'description' => 'Estimated number of steps in the plan.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Tags to categorize this plan.',
                ],
            ],
            'required' => ['description'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        if (self::$inPlanMode) {
            return ToolResult::error('Already in plan mode. Exit current plan first.');
        }

        $description = $input['description'] ?? '';
        $estimatedSteps = $input['estimated_steps'] ?? null;
        $tags = $input['tags'] ?? [];

        if (empty($description)) {
            return ToolResult::error('Plan description is required.');
        }

        self::$inPlanMode = true;
        self::$currentPlan = [
            'description' => $description,
            'estimated_steps' => $estimatedSteps,
            'tags' => $tags,
            'started_at' => date('Y-m-d H:i:s'),
            'steps' => [],
            'status' => 'planning',
        ];

        return ToolResult::success([
            'message' => 'Entered plan mode',
            'description' => $description,
            'mode' => 'planning',
            'instructions' => 'All subsequent tool calls will be simulated and added to the plan. Use exit_plan_mode to finalize.',
        ]);
    }

    /**
     * Check if currently in plan mode.
     */
    public static function isInPlanMode(): bool
    {
        return self::$inPlanMode;
    }

    /**
     * Add a step to the current plan.
     */
    public static function addStep(array $step): void
    {
        if (self::$inPlanMode) {
            self::$currentPlan['steps'][] = array_merge($step, [
                'step_number' => count(self::$currentPlan['steps']) + 1,
                'added_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Get the current plan.
     */
    public static function getCurrentPlan(): array
    {
        return self::$currentPlan;
    }

    /**
     * Exit plan mode and return the plan.
     */
    public static function exitPlanMode(): array
    {
        $plan = self::$currentPlan;
        self::$inPlanMode = false;
        self::$currentPlan = [];
        return $plan;
    }

    /**
     * Reset plan mode (for testing).
     */
    public static function reset(): void
    {
        self::$inPlanMode = false;
        self::$currentPlan = [];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}