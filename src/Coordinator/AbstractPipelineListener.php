<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Base pipeline listener with no-op implementations.
 * Extend this to override only the events you care about.
 */
abstract class AbstractPipelineListener implements PipelineListener
{
    public function onPipelineStart(array $phaseNames): void {}

    public function onPipelineComplete(CollaborationResult $result): void {}

    public function onPhaseStart(string $phaseName, int $agentCount): void {}

    public function onPhaseComplete(string $phaseName, PhaseResult $result): void {}

    public function onPhaseFailed(string $phaseName, string $error, FailureStrategy $strategy): void {}

    public function onPhaseSkipped(string $phaseName, string $reason): void {}

    public function onPhaseRetry(string $phaseName, int $attempt, int $maxRetries): void {}

    public function onAgentSpawned(string $phaseName, string $agentId, string $agentName): void {}

    public function onAgentComplete(string $phaseName, string $agentId, string $agentName): void {}
}
