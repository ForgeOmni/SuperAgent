<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\Pipeline\PipelineResult;
use SuperAgent\Pipeline\StepStatus;

/**
 * Outcome of a `PeerOrchestrator::run()` — wraps a `PipelineResult`
 * with squad-specific metadata (the role roster + blackboard
 * snapshot) so a caller can resume, audit, or hand off the run
 * without poking at the underlying pipeline internals.
 */
final class SquadResult
{
    /**
     * @param array<string, SquadRole> $roles Keyed by step name.
     */
    public function __construct(
        public readonly string $squadId,
        public readonly PipelineResult $pipelineResult,
        public readonly array $roles,
        public readonly Blackboard $blackboard,
        public readonly array $modelTierSnapshot,
        public readonly ?PeerMailbox $mailbox = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->pipelineResult->status === StepStatus::COMPLETED;
    }

    /**
     * @return string[] Step names that have a usable output and can
     *                  be skipped on resume.
     */
    public function completedStepNames(): array
    {
        $names = [];
        foreach ($this->pipelineResult->getStepResults() as $r) {
            if ($r->status === StepStatus::COMPLETED) {
                $names[] = $r->stepName;
            }
        }
        return $names;
    }
}
