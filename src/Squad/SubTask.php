<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Atomic unit of work inside a Squad workflow.
 *
 * Sub-tasks are produced by `TaskDecomposer`, consumed by
 * `SquadComposer` to build pipeline steps. The shape is intentionally
 * narrow — no callbacks, no behaviour. All policy lives in the
 * decomposer/composer; sub-tasks are pure data so they can be
 * persisted, replayed, and diffed across resume cycles.
 *
 * Naming convention: `$name` is what shows up as the pipeline step
 * name AND the role identifier on the blackboard. Keep it stable
 * across decompositions of the same prompt so resume can match a
 * prior step's checkpoint by name.
 */
final class SubTask
{
    /**
     *
     * @param string         $name           Stable step / role identifier
     *                                       (kebab-case, e.g. "topic-decision").
     * @param string         $role           Human-readable role label
     *                                       (e.g. "topic-strategist").
     * @param string         $prompt         Prompt template — can reference
     *                                       prior steps via `{{steps.X.output}}`.
     * @param DifficultyClass $difficulty    Difficulty band.
     * @param string[]       $dependsOn      Names of subtasks this one needs.
     * @param bool           $requiresReview Whether to inject an HITL
     *                                       approval gate AFTER this step.
     * @param string|null    $systemPrompt   Optional system prompt; if null
     *                                       the composer uses a role default.
     * @param string|null    $templateRef    Optional reference to a preset
     *                                       template name in AgentTemplateManager.
     * @param string|null    $parallelGroup  Label identifying a fan-out
     *                                       cluster. Subtasks sharing a
     *                                       non-null label run in parallel
     *                                       inside one `ParallelStep`.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $role,
        public readonly string $prompt,
        public readonly DifficultyClass $difficulty,
        public readonly array $dependsOn = [],
        public readonly bool $requiresReview = false,
        public readonly ?string $systemPrompt = null,
        public readonly ?string $templateRef = null,
        public readonly ?string $parallelGroup = null,
    ) {}

    public function toArray(): array
    {
        return [
            'name'            => $this->name,
            'role'            => $this->role,
            'prompt'          => $this->prompt,
            'difficulty'      => $this->difficulty->value,
            'depends_on'      => $this->dependsOn,
            'requires_review' => $this->requiresReview,
            'system_prompt'   => $this->systemPrompt,
            'template_ref'    => $this->templateRef,
            'parallel_group'  => $this->parallelGroup,
        ];
    }
}
