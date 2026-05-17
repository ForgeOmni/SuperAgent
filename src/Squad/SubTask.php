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
 *
 * Cross-mode fields (added with `Modes\ModeRouter`):
 *
 *   - `$mode`           — when set (`'auto'`|`'smart'`|`'squad'`),
 *                          the step recurses into that mode via
 *                          `ModeRouter` instead of executing as a
 *                          plain leaf dispatch.
 *   - `$teamRef`        — when `$mode === 'squad'`, name of a
 *                          team in `TeamRegistry` to load and run
 *                          for this step.
 *   - `$modeChain`      — fallback escalation: try modes in order
 *                          until one succeeds. Failure detection is
 *                          governed by `$failCriteria`.
 *   - `$failCriteria`   — regex applied to step output; match = fail
 *                          → escalate to next entry in modeChain.
 *   - `$parallelModes`  — fork-join: run multiple modes on the same
 *                          prompt, then merge via `$mergePrompt`.
 *
 * Every cross-mode field defaults to null — pre-existing subtasks
 * are byte-compatible.
 */
final class SubTask
{
    /**
     * @param array<array{mode:string, team?:string, options?:array}>|null $parallelModes
     * @param list<string>|null $modeChain
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
        public readonly ?string $mode = null,
        public readonly ?string $teamRef = null,
        public readonly ?array $modeChain = null,
        public readonly ?string $failCriteria = null,
        public readonly ?array $parallelModes = null,
        public readonly ?string $mergePrompt = null,
    ) {}

    /**
     * Whether this step recurses into another mode rather than
     * running as a plain leaf dispatch. True when any of the
     * cross-mode fields is set.
     */
    public function isCrossMode(): bool
    {
        return $this->mode !== null
            || $this->modeChain !== null
            || $this->parallelModes !== null;
    }

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
            'mode'            => $this->mode,
            'team_ref'        => $this->teamRef,
            'mode_chain'      => $this->modeChain,
            'fail_criteria'   => $this->failCriteria,
            'parallel_modes'  => $this->parallelModes,
            'merge_prompt'    => $this->mergePrompt,
        ];
    }
}
