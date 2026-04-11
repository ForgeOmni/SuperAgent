<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Observer interface for collaboration pipeline lifecycle events.
 */
interface PipelineListener
{
    /**
     * Called when the pipeline starts executing.
     *
     * @param string[] $phaseNames Ordered list of phases to execute
     */
    public function onPipelineStart(array $phaseNames): void;

    /**
     * Called when the pipeline finishes.
     */
    public function onPipelineComplete(CollaborationResult $result): void;

    /**
     * Called when a phase begins execution.
     */
    public function onPhaseStart(string $phaseName, int $agentCount): void;

    /**
     * Called when a phase completes successfully.
     */
    public function onPhaseComplete(string $phaseName, PhaseResult $result): void;

    /**
     * Called when a phase fails.
     */
    public function onPhaseFailed(string $phaseName, string $error, FailureStrategy $strategy): void;

    /**
     * Called when a phase is skipped due to its condition returning false.
     */
    public function onPhaseSkipped(string $phaseName, string $reason): void;

    /**
     * Called when a phase is being retried.
     */
    public function onPhaseRetry(string $phaseName, int $attempt, int $maxRetries): void;

    /**
     * Called when an individual agent is spawned within a phase.
     */
    public function onAgentSpawned(string $phaseName, string $agentId, string $agentName): void;

    /**
     * Called when an individual agent completes within a phase.
     */
    public function onAgentComplete(string $phaseName, string $agentId, string $agentName): void;
}
