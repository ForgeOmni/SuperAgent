<?php

declare(strict_types=1);

namespace SuperAgent\Goals;

use Ramsey\Uuid\Uuid;
use SuperAgent\Goals\Contracts\GoalStore;

/**
 * Process-scoped goal store. State is lost on shutdown — fine for
 * tests and short-lived CLI sessions; SuperAICore wraps this with a
 * persistent Eloquent-backed store so production goals survive
 * restarts and resumes.
 */
final class InMemoryGoalStore implements GoalStore
{
    /** @var array<string, Goal> id → Goal */
    private array $byId = [];

    /** @var array<string, string> threadId → id of active goal */
    private array $activeByThread = [];

    public function create(string $threadId, string $objective, ?int $tokenBudget): Goal
    {
        if (isset($this->activeByThread[$threadId])) {
            $existing = $this->byId[$this->activeByThread[$threadId]];
            // codex semantics: create_goal fails if a goal already
            // exists. The caller should call get_goal first.
            throw new GoalAlreadyExistsException(
                "Thread {$threadId} already has goal {$existing->id} "
                . "(status: {$existing->status->value}). Call update_goal "
                . "to mark it complete before creating a new one."
            );
        }
        $now = time();
        $goal = new Goal(
            id:           Uuid::uuid4()->toString(),
            threadId:     $threadId,
            objective:    $objective,
            status:       GoalStatus::Active,
            tokenBudget:  $tokenBudget,
            tokensUsed:   0,
            createdAt:    $now,
            updatedAt:    $now,
        );
        $this->byId[$goal->id] = $goal;
        $this->activeByThread[$threadId] = $goal->id;
        return $goal;
    }

    public function findActive(string $threadId): ?Goal
    {
        $id = $this->activeByThread[$threadId] ?? null;
        if ($id === null) return null;
        return $this->byId[$id] ?? null;
    }

    public function findById(string $id): ?Goal
    {
        return $this->byId[$id] ?? null;
    }

    public function transition(string $id, GoalStatus $status): ?Goal
    {
        if (! isset($this->byId[$id])) return null;
        $goal = $this->byId[$id]->withStatus($status);
        $this->byId[$id] = $goal;
        if ($status === GoalStatus::Complete) {
            // Free the thread's active slot so future create_goal()
            // calls succeed.
            unset($this->activeByThread[$goal->threadId]);
        }
        return $goal;
    }

    public function recordTokens(string $id, int $tokensUsed): ?Goal
    {
        if (! isset($this->byId[$id])) return null;
        $goal = $this->byId[$id]->withTokensUsed($tokensUsed);
        $this->byId[$id] = $goal;
        return $goal;
    }
}
