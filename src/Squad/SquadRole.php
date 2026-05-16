<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Stable per-role configuration used by `PeerOrchestrator` when
 * dispatching an `AgentStep` to a concrete provider.
 *
 * A role is the *persistent* identity of an agent across a Squad's
 * lifetime — same role name across decomposition + resume means same
 * provider/model AND the same session ID, so token caching and
 * conversation memory carry over without re-priming the model.
 *
 * Distinguish from `SubTask`: a subtask is a unit of work; a role is
 * a "seat" at the table. Two subtasks can share a role when the same
 * voice handles both (e.g. the same reviewer reviews two artefacts).
 */
final class SquadRole
{
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly string $model,
        public readonly DifficultyClass $tier,
        public readonly ?string $systemPrompt = null,
        public readonly ?string $templateRef = null,
        public readonly ?string $sessionId = null,
    ) {}

    /**
     * Build the canonical session ID for this role inside a Squad.
     *
     * Stable across resumes — that's the whole reason resume can reuse
     * a prior session's KV cache.
     */
    public static function sessionIdFor(string $squadId, string $roleName): string
    {
        return "squad:{$squadId}:role:{$roleName}";
    }

    public function withSessionId(string $sessionId): self
    {
        return new self(
            name: $this->name,
            provider: $this->provider,
            model: $this->model,
            tier: $this->tier,
            systemPrompt: $this->systemPrompt,
            templateRef: $this->templateRef,
            sessionId: $sessionId,
        );
    }
}
