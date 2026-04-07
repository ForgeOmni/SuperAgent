<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\Tools\ToolStateManager;

/**
 * Plan mode with V2 interview phase ported from Claude Code.
 *
 * Two workflows:
 *  - Interview phase (default): Iterative pair-planning with Explore/Read tools,
 *    incremental plan file updates, and AskUserQuestion for ambiguities.
 *  - Traditional 5-phase: Explore agents → Plan agents → Review → Final plan → Exit.
 *
 * Plan file structure:
 *  - Context (why the change is needed)
 *  - Recommended approach (no alternatives — one clear path)
 *  - Critical files to modify
 *  - Existing code to reuse
 *  - Verification/testing section
 */
class EnterPlanModeTool extends Tool
{
    /** How often to send full reminders */
    private const TURNS_BETWEEN_REMINDERS = 5;

    private const TOOL_KEY = 'enter_plan_mode';

    /**
     * Shared state manager for static method access.
     * Instance methods prefer the injected state() from the base class.
     */
    private static ?ToolStateManager $sharedState = null;

    private static function shared(): ToolStateManager
    {
        if (self::$sharedState === null) {
            self::$sharedState = new ToolStateManager();
        }
        return self::$sharedState;
    }

    /**
     * Inject a shared state manager (for testing or Swarm mode).
     */
    public static function setSharedStateManager(ToolStateManager $manager): void
    {
        self::$sharedState = $manager;
    }

    public function name(): string
    {
        return 'enter_plan_mode';
    }

    public function description(): string
    {
        return 'Enter planning mode. In interview phase, explore the codebase iteratively and build a plan collaboratively with the user before execution.';
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
                'interview' => [
                    'type' => 'boolean',
                    'description' => 'Use interview phase (iterative planning with user). Default true.',
                ],
            ],
            'required' => ['description'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $s = self::shared();

        if ($s->get(self::TOOL_KEY, 'inPlanMode', false)) {
            return ToolResult::error('Already in plan mode. Exit current plan first.');
        }

        $description = $input['description'] ?? '';
        $estimatedSteps = $input['estimated_steps'] ?? null;
        $tags = $input['tags'] ?? [];
        $useInterview = $input['interview'] ?? self::isInterviewPhaseEnabled();

        if (empty($description)) {
            return ToolResult::error('Plan description is required.');
        }

        $s->set(self::TOOL_KEY, 'inPlanMode', true);
        $s->set(self::TOOL_KEY, 'interviewPhaseEnabled', $useInterview);
        $s->set(self::TOOL_KEY, 'turnsSinceReminder', 0);

        // Generate plan file path
        $plansDir = self::getPlansDirectory();
        $slug = self::generateSlug();
        $planFilePath = "{$plansDir}/{$slug}.md";
        $s->set(self::TOOL_KEY, 'planFilePath', $planFilePath);

        $s->set(self::TOOL_KEY, 'currentPlan', [
            'description' => $description,
            'estimated_steps' => $estimatedSteps,
            'tags' => $tags,
            'started_at' => date('Y-m-d H:i:s'),
            'steps' => [],
            'status' => 'planning',
            'interview' => $useInterview,
            'plan_file' => $planFilePath,
        ]);

        // Write initial plan file
        self::writePlanFile($description);

        $instructions = $useInterview
            ? self::getInterviewInstructions()
            : self::getTraditionalInstructions();

        return ToolResult::success([
            'message' => 'Entered plan mode',
            'description' => $description,
            'mode' => 'planning',
            'workflow' => $useInterview ? 'interview' : 'traditional',
            'plan_file' => $planFilePath,
            'instructions' => $instructions,
        ]);
    }

    /**
     * Get interview phase instructions (iterative pair-planning).
     */
    public static function getInterviewInstructions(): string
    {
        return <<<'INST'
You are now in PLAN MODE (interview phase). You are pair-planning with the user.

WORKFLOW — repeat until the plan is complete:
1. **Explore** — Read code using Glob/Grep/Read tools to understand the codebase
2. **Update plan** — Incrementally capture findings in the plan file
3. **Ask user** — Use AskUserQuestion for any ambiguities or design decisions
4. Repeat until plan covers: what to change, which files, existing code to reuse, how to verify

PLAN FILE STRUCTURE:
- **Context**: Why the change is needed
- **Recommended approach**: One clear path (no alternatives)
- **Critical files to modify**: With line numbers and function names
- **Existing code to reuse**: Functions, utilities, patterns already in the codebase
- **Verification**: How to test the changes work

RULES:
- Do NOT modify any files (read-only tools only: Read, Grep, Glob, Bash for read commands)
- Do NOT start implementing — only plan
- Update the plan file incrementally as you learn more
- Ask the user when you encounter ambiguities
- When the plan is complete, call exit_plan_mode to request approval

ENDING CRITERIA: Plan is complete when all ambiguities are resolved and it covers:
what to change, which files, existing code to reuse, and how to verify.
INST;
    }

    /**
     * Get traditional 5-phase instructions.
     */
    public static function getTraditionalInstructions(): string
    {
        return <<<'INST'
You are now in PLAN MODE (5-phase workflow).

PHASES:
1. **Initial Understanding** — Launch Explore agents in parallel to investigate the codebase
2. **Design** — Launch Plan agents to design the implementation approach
3. **Review** — Review plans from agents, identify gaps
4. **Final Plan** — Compile the final plan document with all details
5. **Exit** — Call exit_plan_mode to request approval before executing

RULES:
- Do NOT modify any files during planning
- Use read-only tools only (Read, Grep, Glob, Bash for read commands)
- Capture all findings in the plan file
- When ready, call exit_plan_mode to present the plan for approval
INST;
    }

    /**
     * Get plan mode reminder (injected periodically as attachment).
     */
    public static function getPlanModeReminder(): ?string
    {
        $s = self::shared();

        if (!$s->get(self::TOOL_KEY, 'inPlanMode', false)) {
            return null;
        }

        $turns = $s->get(self::TOOL_KEY, 'turnsSinceReminder', 0) + 1;
        $s->set(self::TOOL_KEY, 'turnsSinceReminder', $turns);

        if ($turns % self::TURNS_BETWEEN_REMINDERS === 0) {
            return $s->get(self::TOOL_KEY, 'interviewPhaseEnabled', true)
                ? self::getInterviewInstructions()
                : self::getTraditionalInstructions();
        }

        return 'REMINDER: You are in plan mode. Do NOT modify files. Update the plan file and ask user about ambiguities.';
    }

    /**
     * Update the plan file with new content.
     */
    public static function updatePlanFile(string $section, string $content): void
    {
        $planFilePath = self::shared()->get(self::TOOL_KEY, 'planFilePath');
        if ($planFilePath === null) {
            return;
        }

        $existing = file_exists($planFilePath)
            ? file_get_contents($planFilePath)
            : '';

        $sectionHeader = "## {$section}";
        if (str_contains($existing, $sectionHeader)) {
            $pattern = '/(## ' . preg_quote($section, '/') . '\n)([\s\S]*?)(?=\n## |\z)/';
            $replacement = "## {$section}\n{$content}\n";
            $existing = preg_replace($pattern, $replacement, $existing);
        } else {
            $existing .= "\n\n## {$section}\n{$content}\n";
        }

        file_put_contents($planFilePath, $existing);
    }

    /**
     * Get the current plan file content.
     */
    public static function getPlanFileContent(): string
    {
        $planFilePath = self::shared()->get(self::TOOL_KEY, 'planFilePath');
        if ($planFilePath === null || !file_exists($planFilePath)) {
            return '';
        }
        return file_get_contents($planFilePath) ?: '';
    }

    public static function isInPlanMode(): bool
    {
        return self::shared()->get(self::TOOL_KEY, 'inPlanMode', false);
    }

    public static function addStep(array $step): void
    {
        $s = self::shared();
        if ($s->get(self::TOOL_KEY, 'inPlanMode', false)) {
            $plan = $s->get(self::TOOL_KEY, 'currentPlan', []);
            $plan['steps'][] = array_merge($step, [
                'step_number' => count($plan['steps'] ?? []) + 1,
                'added_at' => date('Y-m-d H:i:s'),
            ]);
            $s->set(self::TOOL_KEY, 'currentPlan', $plan);
        }
    }

    public static function getCurrentPlan(): array
    {
        return self::shared()->get(self::TOOL_KEY, 'currentPlan', []);
    }

    public static function exitPlanMode(): array
    {
        $s = self::shared();
        $plan = $s->get(self::TOOL_KEY, 'currentPlan', []);
        $s->set(self::TOOL_KEY, 'inPlanMode', false);
        $s->set(self::TOOL_KEY, 'currentPlan', []);
        return $plan;
    }

    public static function reset(): void
    {
        self::shared()->clearTool(self::TOOL_KEY);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function setPrePlanMode(?string $mode): void
    {
        self::shared()->set(self::TOOL_KEY, 'prePlanMode', $mode);
    }

    public static function getPrePlanMode(): ?string
    {
        return self::shared()->get(self::TOOL_KEY, 'prePlanMode');
    }

    public static function getPlanFilePath(): ?string
    {
        return self::shared()->get(self::TOOL_KEY, 'planFilePath');
    }

    public static function isInterviewPhaseEnabled(): bool
    {
        return self::shared()->get(self::TOOL_KEY, 'interviewPhaseEnabled', true)
            && \SuperAgent\Config\ExperimentalFeatures::enabled('plan_interview');
    }

    public static function setInterviewPhaseEnabled(bool $enabled): void
    {
        self::shared()->set(self::TOOL_KEY, 'interviewPhaseEnabled', $enabled);
    }

    private static function writePlanFile(string $description): void
    {
        $planFilePath = self::shared()->get(self::TOOL_KEY, 'planFilePath');
        $dir = dirname($planFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = "# Plan: {$description}\n\n"
            . "Created: " . date('Y-m-d H:i:s') . "\n\n"
            . "## Context\n*Why this change is needed*\n\n"
            . "## Recommended Approach\n*One clear implementation path*\n\n"
            . "## Critical Files\n*Files to modify with line numbers*\n\n"
            . "## Existing Code to Reuse\n*Functions, utilities, patterns*\n\n"
            . "## Verification\n*How to test the changes*\n";

        file_put_contents($planFilePath, $content);
    }

    private static function getPlansDirectory(): string
    {
        $home = $_ENV['HOME'] ?? $_ENV['USERPROFILE'] ?? '/tmp';
        $dir = $home . '/.claude/plans';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function generateSlug(): string
    {
        $adjectives = ['swift', 'bright', 'calm', 'keen', 'bold', 'quick', 'sharp', 'warm'];
        $nouns = ['fox', 'bear', 'hawk', 'wolf', 'deer', 'lion', 'eagle', 'otter'];
        $verbs = ['plan', 'draft', 'sketch', 'map', 'chart', 'trace', 'forge', 'craft'];

        return $adjectives[array_rand($adjectives)]
            . '-' . $nouns[array_rand($nouns)]
            . '-' . $verbs[array_rand($verbs)];
    }
}
