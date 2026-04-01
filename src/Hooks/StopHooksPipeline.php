<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stop hooks pipeline ported from Claude Code.
 *
 * Runs after the model response and before message persistence:
 *
 *  1. Execute Stop hooks (ON_STOP event)
 *  2. If teammate: run TaskCompleted hooks for in-progress tasks
 *  3. If teammate: run TeammateIdle hooks
 *
 * Each phase can:
 *  - Produce blocking errors (injected as user messages)
 *  - Prevent continuation (stops the agent loop)
 */
class StopHooksPipeline
{
    public function __construct(
        private HookRegistry $hookRegistry,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Run the full stop hooks pipeline.
     *
     * @param array  $messages          All messages in the conversation
     * @param array  $assistantMessages Assistant messages from this turn
     * @param array  $context           Additional context (agent_id, agent_type, permission_mode, etc.)
     * @return StopHookResult
     */
    public function execute(
        array $messages,
        array $assistantMessages,
        array $context = [],
    ): StopHookResult {
        $startTime = microtime(true);
        $blockingErrors = [];
        $preventContinuation = false;
        $stopReason = null;
        $hookCount = 0;
        $hookInfos = [];
        $hookErrors = [];

        try {
            // --- Phase 1: Execute Stop hooks ---
            $stopResult = $this->executeStopHooks($messages, $assistantMessages, $context);

            $hookCount += $stopResult['hook_count'];
            $hookErrors = array_merge($hookErrors, $stopResult['errors']);
            $hookInfos = array_merge($hookInfos, $stopResult['infos']);

            if ($stopResult['prevent_continuation']) {
                $preventContinuation = true;
                $stopReason = $stopResult['stop_reason'];
            }

            if (!empty($stopResult['blocking_errors'])) {
                $blockingErrors = array_merge($blockingErrors, $stopResult['blocking_errors']);
            }

            // Short-circuit if prevented
            if ($preventContinuation) {
                return $this->buildResult(
                    $blockingErrors, true, $stopReason,
                    $hookCount, $hookInfos, $hookErrors, $startTime,
                );
            }

            // --- Phase 2 & 3: Teammate hooks (TaskCompleted + TeammateIdle) ---
            $isTeammate = $context['is_teammate'] ?? false;
            if ($isTeammate) {
                $teammateResult = $this->executeTeammateHooks($messages, $context);

                $hookCount += $teammateResult['hook_count'];
                $hookErrors = array_merge($hookErrors, $teammateResult['errors']);

                if ($teammateResult['prevent_continuation']) {
                    $preventContinuation = true;
                    $stopReason = $teammateResult['stop_reason'];
                }

                if (!empty($teammateResult['blocking_errors'])) {
                    $blockingErrors = array_merge($blockingErrors, $teammateResult['blocking_errors']);
                }
            }

            return $this->buildResult(
                $blockingErrors, $preventContinuation, $stopReason,
                $hookCount, $hookInfos, $hookErrors, $startTime,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logger->error('Stop hooks pipeline failed', [
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return new StopHookResult(
                blockingErrors: [],
                preventContinuation: false,
                hookCount: $hookCount,
                hookInfos: $hookInfos,
                hookErrors: [$e->getMessage()],
                durationMs: $durationMs,
            );
        }
    }

    /**
     * Execute ON_STOP hooks.
     */
    private function executeStopHooks(array $messages, array $assistantMessages, array $context): array
    {
        $result = $this->hookRegistry->executeHooks(
            HookEvent::ON_STOP,
            new HookInput(
                hookEvent: HookEvent::ON_STOP,
                sessionId: $context['session_id'] ?? 'unknown',
                cwd: $context['cwd'] ?? getcwd() ?: '.',
                gitRepoRoot: $context['git_repo_root'] ?? null,
                additionalData: [
                    'agent_id' => $context['agent_id'] ?? null,
                    'agent_type' => $context['agent_type'] ?? null,
                    'permission_mode' => $context['permission_mode'] ?? null,
                    'message_count' => count($messages),
                    'assistant_message_count' => count($assistantMessages),
                ],
            ),
        );

        $errors = [];
        $blockingErrors = [];
        $infos = [];

        if ($result->errorMessage !== null) {
            $errors[] = $result->errorMessage;
            $blockingErrors[] = $result->errorMessage;
        }

        return [
            'hook_count' => 1,
            'errors' => $errors,
            'blocking_errors' => $blockingErrors,
            'infos' => $infos,
            'prevent_continuation' => $result->preventContinuation,
            'stop_reason' => $result->stopReason,
        ];
    }

    /**
     * Execute TaskCompleted and TeammateIdle hooks for teammate agents.
     */
    private function executeTeammateHooks(array $messages, array $context): array
    {
        $hookCount = 0;
        $errors = [];
        $blockingErrors = [];
        $preventContinuation = false;
        $stopReason = null;

        $teammateName = $context['teammate_name'] ?? '';
        $teamName = $context['team_name'] ?? '';
        $tasks = $context['in_progress_tasks'] ?? [];

        // Phase 2: TaskCompleted hooks for each in-progress task owned by this teammate
        foreach ($tasks as $task) {
            $hookCount++;
            $taskResult = $this->hookRegistry->executeHooks(
                HookEvent::TASK_COMPLETED,
                new HookInput(
                    hookEvent: HookEvent::TASK_COMPLETED,
                    sessionId: $context['session_id'] ?? 'unknown',
                    cwd: $context['cwd'] ?? getcwd() ?: '.',
                    gitRepoRoot: $context['git_repo_root'] ?? null,
                    additionalData: [
                        'task_id' => $task['id'] ?? '',
                        'task_subject' => $task['subject'] ?? '',
                        'task_description' => $task['description'] ?? '',
                        'teammate_name' => $teammateName,
                        'team_name' => $teamName,
                    ],
                ),
            );

            if ($taskResult->errorMessage !== null) {
                $errors[] = $taskResult->errorMessage;
                $blockingErrors[] = $taskResult->errorMessage;
            }

            if ($taskResult->preventContinuation) {
                $preventContinuation = true;
                $stopReason = $taskResult->stopReason ?? 'TaskCompleted hook prevented continuation';
            }
        }

        // Phase 3: TeammateIdle hooks
        $hookCount++;
        $idleResult = $this->hookRegistry->executeHooks(
            HookEvent::TEAMMATE_IDLE,
            new HookInput(
                hookEvent: HookEvent::TEAMMATE_IDLE,
                sessionId: $context['session_id'] ?? 'unknown',
                cwd: $context['cwd'] ?? getcwd() ?: '.',
                gitRepoRoot: $context['git_repo_root'] ?? null,
                additionalData: [
                    'teammate_name' => $teammateName,
                    'team_name' => $teamName,
                ],
            ),
        );

        if ($idleResult->errorMessage !== null) {
            $errors[] = $idleResult->errorMessage;
            $blockingErrors[] = $idleResult->errorMessage;
        }

        if ($idleResult->preventContinuation) {
            $preventContinuation = true;
            $stopReason = $idleResult->stopReason ?? 'TeammateIdle hook prevented continuation';
        }

        return [
            'hook_count' => $hookCount,
            'errors' => $errors,
            'blocking_errors' => $blockingErrors,
            'prevent_continuation' => $preventContinuation,
            'stop_reason' => $stopReason,
        ];
    }

    private function buildResult(
        array $blockingErrors,
        bool $preventContinuation,
        ?string $stopReason,
        int $hookCount,
        array $hookInfos,
        array $hookErrors,
        float $startTime,
    ): StopHookResult {
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new StopHookResult(
            blockingErrors: $blockingErrors,
            preventContinuation: $preventContinuation,
            stopReason: $stopReason,
            hookCount: $hookCount,
            hookInfos: $hookInfos,
            hookErrors: $hookErrors,
            durationMs: $durationMs,
        );
    }
}

/**
 * Result from the stop hooks pipeline.
 */
class StopHookResult
{
    public function __construct(
        /** @var string[] Blocking error messages to inject */
        public readonly array $blockingErrors = [],
        /** Whether the agent loop should stop */
        public readonly bool $preventContinuation = false,
        /** Reason for stopping (if preventContinuation) */
        public readonly ?string $stopReason = null,
        /** Number of hooks executed */
        public readonly int $hookCount = 0,
        /** Hook execution info for debugging */
        public readonly array $hookInfos = [],
        /** Hook errors (non-blocking) */
        public readonly array $hookErrors = [],
        /** Total pipeline duration in milliseconds */
        public readonly int $durationMs = 0,
    ) {}

    public function hasBlockingErrors(): bool
    {
        return !empty($this->blockingErrors);
    }

    public function toArray(): array
    {
        return [
            'blocking_errors' => $this->blockingErrors,
            'prevent_continuation' => $this->preventContinuation,
            'stop_reason' => $this->stopReason,
            'hook_count' => $this->hookCount,
            'hook_errors' => $this->hookErrors,
            'duration_ms' => $this->durationMs,
        ];
    }
}
