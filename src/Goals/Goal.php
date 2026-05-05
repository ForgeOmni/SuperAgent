<?php

declare(strict_types=1);

namespace SuperAgent\Goals;

/**
 * Immutable value-object snapshot of a goal at a point in time.
 *
 * Thread-scoped: every goal belongs to a single thread (a continuous
 * agent conversation). A thread has at most one active goal — codex
 * enforces this via `create_goal` failing when one already exists,
 * and we mirror that contract in `GoalManager::create()`.
 *
 * Field meanings:
 *
 *   id            — opaque, store-assigned. Stable across resumes.
 *   threadId      — the conversation this goal lives in.
 *   objective     — user-supplied free-form text. MUST be passed
 *                   through `Security\UntrustedInput::wrap()` before
 *                   it lands in any prompt so prompt-injection from
 *                   user goals is contained.
 *   tokenBudget   — null = unbounded. When set, the runtime flips the
 *                   status to `budget_limited` once `tokensUsed >=
 *                   tokenBudget` and injects the budget_limit.md
 *                   template.
 *   tokensUsed    — running total since goal creation. Maintained by
 *                   the host on each turn-end via
 *                   `GoalManager::recordUsage()`.
 *   createdAt /
 *   updatedAt     — Unix timestamps in seconds. The continuation
 *                   template uses the delta to surface "time spent
 *                   pursuing goal".
 */
final class Goal
{
    public function __construct(
        public readonly string      $id,
        public readonly string      $threadId,
        public readonly string      $objective,
        public readonly GoalStatus  $status,
        public readonly ?int        $tokenBudget,
        public readonly int         $tokensUsed,
        public readonly int         $createdAt,
        public readonly int         $updatedAt,
    ) {}

    /** Tokens still available before the budget triggers. */
    public function remainingBudget(): ?int
    {
        if ($this->tokenBudget === null) return null;
        return max(0, $this->tokenBudget - $this->tokensUsed);
    }

    /** Wall-clock seconds since the goal was created. */
    public function elapsedSeconds(?int $now = null): int
    {
        return ($now ?? time()) - $this->createdAt;
    }

    public function withStatus(GoalStatus $status): self
    {
        return new self(
            $this->id, $this->threadId, $this->objective, $status,
            $this->tokenBudget, $this->tokensUsed, $this->createdAt, time(),
        );
    }

    public function withTokensUsed(int $tokens): self
    {
        return new self(
            $this->id, $this->threadId, $this->objective, $this->status,
            $this->tokenBudget, $tokens, $this->createdAt, time(),
        );
    }

    /** Shape suitable for JSON / dashboard / get_goal tool result. */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'thread_id'         => $this->threadId,
            'objective'         => $this->objective,
            'status'            => $this->status->value,
            'token_budget'      => $this->tokenBudget,
            'tokens_used'       => $this->tokensUsed,
            'remaining_tokens'  => $this->remainingBudget(),
            'time_used_seconds' => $this->elapsedSeconds(),
            'created_at'        => $this->createdAt,
            'updated_at'        => $this->updatedAt,
        ];
    }
}
