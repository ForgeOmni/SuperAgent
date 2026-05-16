<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineDefinition;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ApprovalStep;
use SuperAgent\Pipeline\Steps\FailureStrategy;
use SuperAgent\Pipeline\Steps\ParallelStep;
use SuperAgent\Pipeline\Steps\StepInterface;
use SuperAgent\Swarm\Templates\AgentTemplateManager;

/**
 * Translates a `SubTask[]` plan into a `PipelineDefinition` the
 * existing `PipelineEngine` can run unchanged.
 *
 * This is the piece that makes the workflow itself the orchestrator
 * — every subtask becomes an `AgentStep` pinned to the model that
 * best fits its difficulty, and every `requires_review` subtask gets
 * a trailing `ApprovalStep` so the human sits inline as a peer.
 *
 * Model pinning is encoded in the step's `model` field as
 * `<provider>:<model>` (e.g. `anthropic:claude-opus-4-7`). The
 * orchestrator parses that prefix when dispatching the agent — keeping
 * the AgentStep schema unchanged avoids ripple-edits across YAML
 * configs and tests that already use the field.
 */
final class SquadComposer
{
    /**
     * @param array<string, string> $roleSystemPrompts Default system
     *        prompt per canonical role; subtasks with their own
     *        `systemPrompt` override these.
     */
    public function __construct(
        private readonly ModelTierMap $tierMap = new ModelTierMap(),
        private readonly array $roleSystemPrompts = [],
        private readonly ?AgentTemplateManager $templates = null,
    ) {}

    /**
     * Default role → built-in template name. Used when the subtask
     * doesn't carry its own `templateRef`. Templates are only resolved
     * lazily — missing ones fall back to the role default prompt.
     *
     * @var array<string, string>
     */
    private const DEFAULT_ROLE_TEMPLATES = [
        'research'  => 'researcher',
        'design'    => 'architect',
        'implement' => 'code-writer',
        'verify'    => 'verifier',
        'decide'    => 'decision-maker',
    ];

    /**
     * Tools each role is allowed to call by default. `null` means
     * "no restriction"; an empty array means "no tools at all"
     * (useful for `decide` — the human is the decider).
     *
     * @var array<string, array{tools: ?array<string>, readOnly: bool}>
     */
    private const DEFAULT_ROLE_TOOL_GRANTS = [
        'research'  => ['tools' => ['Read', 'Grep', 'Glob', 'WebFetch', 'WebSearch'], 'readOnly' => true],
        'design'    => ['tools' => ['Read', 'Grep', 'Glob'],                          'readOnly' => true],
        'decide'    => ['tools' => [],                                                'readOnly' => true],
        'implement' => ['tools' => null,                                              'readOnly' => false],
        'verify'    => ['tools' => ['Read', 'Grep', 'Glob', 'Bash'],                  'readOnly' => true],
        'execute'   => ['tools' => null,                                              'readOnly' => false],
    ];

    /**
     * Compose a pipeline named `$pipelineName` from a sub-task plan.
     *
     * @param SubTask[] $subTasks
     * @return array{config: PipelineConfig, roles: array<string, SquadRole>}
     *         The roles map is keyed by step name so the orchestrator
     *         can look up provider/model/session at dispatch time.
     */
    public function compose(string $pipelineName, array $subTasks, string $squadId): array
    {
        // 1. Group subtasks by parallelGroup label (preserving order).
        $groups = $this->groupByParallelLabel($subTasks);

        $steps = [];
        $roles = [];

        foreach ($groups as $group) {
            if (count($group) === 1 || $group[0]->parallelGroup === null) {
                foreach ($group as $subTask) {
                    [$step, $role] = $this->buildAgentStep($subTask, $squadId);
                    $roles[$subTask->name] = $role;
                    $steps[] = $step;

                    if ($subTask->requiresReview) {
                        $steps[] = new ApprovalStep(
                            name: $subTask->name . '-review',
                            message: $this->buildReviewMessage($subTask),
                            dependsOn: [$subTask->name],
                        );
                    }
                }
                continue;
            }

            // Multi-member parallel group → wrap in a single ParallelStep.
            $childSteps = [];
            $sharedDeps = $group[0]->dependsOn;
            foreach ($group as $subTask) {
                [$step, $role] = $this->buildAgentStep($subTask, $squadId, dropDeps: true);
                $roles[$subTask->name] = $role;
                $childSteps[] = $step;
            }
            $parallelName = 'parallel-' . $group[0]->parallelGroup;
            $steps[] = new ParallelStep(
                name: $parallelName,
                steps: $childSteps,
                waitAll: true,
                failureStrategy: FailureStrategy::ABORT,
                dependsOn: $sharedDeps,
            );

            // If any member of the group asked for review, gate the
            // whole fan-in.
            $needsGate = false;
            foreach ($group as $s) {
                if ($s->requiresReview) {
                    $needsGate = true;
                    break;
                }
            }
            if ($needsGate) {
                $steps[] = new ApprovalStep(
                    name: $parallelName . '-review',
                    message: "Review the parallel-group results for '{$parallelName}' before continuing.",
                    dependsOn: [$parallelName],
                );
            }
        }

        $definition = new PipelineDefinition(
            name: $pipelineName,
            steps: $steps,
            description: 'Adaptive Cross-Model Squad workflow',
            metadata: ['squad_id' => $squadId],
        );

        return [
            'config' => PipelineConfig::fromDefinition($definition),
            'roles'  => $roles,
        ];
    }

    /**
     * Build a single AgentStep + the matching SquadRole.
     *
     * @return array{0: AgentStep, 1: SquadRole}
     */
    private function buildAgentStep(SubTask $subTask, string $squadId, bool $dropDeps = false): array
    {
        $resolved = $this->tierMap->resolve($subTask->difficulty);
        $modelTag = $resolved['provider'] . ':' . $resolved['model'];

        $sysPrompt = $subTask->systemPrompt
            ?? $this->roleSystemPrompts[$subTask->role]
            ?? $this->templateSystemPrompt($subTask);

        $role = new SquadRole(
            name: $subTask->name,
            provider: $resolved['provider'],
            model: $resolved['model'],
            tier: $subTask->difficulty,
            systemPrompt: $sysPrompt,
            templateRef: $subTask->templateRef ?? (self::DEFAULT_ROLE_TEMPLATES[$subTask->role] ?? null),
            sessionId: SquadRole::sessionIdFor($squadId, $subTask->name),
        );

        $grant = self::DEFAULT_ROLE_TOOL_GRANTS[$subTask->role] ?? ['tools' => null, 'readOnly' => false];

        $step = new AgentStep(
            name: $subTask->name,
            agentType: $subTask->role,
            prompt: $subTask->prompt,
            model: $modelTag,
            systemPrompt: $sysPrompt,
            readOnly: $grant['readOnly'],
            allowedTools: $grant['tools'],
            inputFrom: $this->buildInputFromMap($subTask->dependsOn),
            failureStrategy: FailureStrategy::ABORT,
            dependsOn: $dropDeps ? [] : $subTask->dependsOn,
        );

        return [$step, $role];
    }

    /**
     * Split subtasks into ordered groups: each group is either a single
     * sequential subtask or a non-empty set sharing the same
     * `parallelGroup` label, in the order they first appear.
     *
     * @param SubTask[] $subTasks
     * @return array<int, SubTask[]>
     */
    private function groupByParallelLabel(array $subTasks): array
    {
        $groups = [];
        $current = null;

        foreach ($subTasks as $subTask) {
            $label = $subTask->parallelGroup;

            if ($label === null) {
                if ($current !== null) {
                    $groups[] = $current['items'];
                    $current = null;
                }
                $groups[] = [$subTask];
                continue;
            }

            if ($current !== null && $current['label'] === $label) {
                $current['items'][] = $subTask;
            } else {
                if ($current !== null) {
                    $groups[] = $current['items'];
                }
                $current = ['label' => $label, 'items' => [$subTask]];
            }
        }

        if ($current !== null) {
            $groups[] = $current['items'];
        }

        return $groups;
    }

    /**
     * Pull a system prompt from the AgentTemplateManager (when one is
     * registered) using the subtask's explicit `templateRef`, or a
     * built-in role default. Returns null when no template applies —
     * the dispatcher then falls back to provider defaults.
     */
    private function templateSystemPrompt(SubTask $subTask): ?string
    {
        if ($this->templates === null) {
            return null;
        }

        $name = $subTask->templateRef ?? (self::DEFAULT_ROLE_TEMPLATES[$subTask->role] ?? null);
        if ($name === null) {
            return null;
        }

        $template = $this->templates->get($name);
        if ($template === null || !method_exists($template, 'getSystemPrompt')) {
            return null;
        }

        $prompt = $template->getSystemPrompt();
        return is_string($prompt) && $prompt !== '' ? $prompt : null;
    }

    /**
     * Build the AgentStep `inputFrom` map so each step receives prior
     * outputs by role name, not by anonymous step index. Makes prompts
     * read naturally ("# topic-decision: ...") instead of forcing the
     * agent to remember step indexes.
     *
     * @param string[] $dependsOn
     * @return array<string, string>
     */
    private function buildInputFromMap(array $dependsOn): array
    {
        $map = [];
        foreach ($dependsOn as $name) {
            $map[$name] = '{{steps.' . $name . '.output}}';
        }
        return $map;
    }

    private function buildReviewMessage(SubTask $subTask): string
    {
        return sprintf(
            "Review the '%s' step output before continuing. Output:\n\n{{steps.%s.output}}",
            $subTask->name,
            $subTask->name,
        );
    }
}
