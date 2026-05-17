<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * A complete, ready-to-execute squad definition — produced by
 * `YamlSquadLoader` from a YAML team file, consumed directly by
 * `PeerOrchestrator::run()`.
 *
 * SubTask alone captures a single step. SquadPlan captures the
 * team-level state SubTask deliberately doesn't: a stable team name,
 * an optional human-facing description, model-tier overrides, and
 * the `reviewer_loop` pairings (writer-reviewer-feedback triplets)
 * the executor needs to wire failure→re-run feedback injection.
 *
 * The plan is a pure data structure — no behaviour. Loaders produce
 * it, hosts persist it, executors consume it.
 */
final class SquadPlan
{
    /**
     * @param string                                 $name        kebab-case team id ("super-dev", "council")
     * @param string|null                            $description human-facing summary
     * @param SubTask[]                              $subTasks    ordered list of steps
     * @param array<string, array{provider:string,model:string}> $tierMap optional band → (provider, model) override
     * @param array<int, ReviewerLoopBinding>        $loops       writer-reviewer-feedback bindings
     * @param array<string, mixed>                   $metadata    free-form host-supplied hints
     *                                                            (e.g. `tags`, `expected_cost_usd`,
     *                                                            `default_max_cost_usd`)
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $subTasks,
        public readonly array $tierMap = [],
        public readonly array $loops = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'subtasks'    => array_map(static fn (SubTask $s) => $s->toArray(), $this->subTasks),
            'tier_map'    => $this->tierMap,
            'loops'       => array_map(static fn (ReviewerLoopBinding $l) => $l->toArray(), $this->loops),
            'metadata'    => $this->metadata,
        ];
    }
}
