<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use SuperAgent\Swarm\AgentStatus;

/**
 * Aggregated result of an entire collaboration pipeline execution.
 */
class CollaborationResult
{
    /** @var array<string, PhaseResult> */
    private array $phaseResults = [];

    private AgentStatus $status = AgentStatus::PENDING;
    private float $startTime;
    private ?float $endTime = null;
    /** @var string[] Phases that were skipped due to conditions */
    private array $skippedPhases = [];

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function addPhaseResult(PhaseResult $result): void
    {
        $this->phaseResults[$result->phaseName] = $result;
    }

    public function addSkippedPhase(string $phaseName): void
    {
        $this->skippedPhases[] = $phaseName;
    }

    public function markRunning(): void
    {
        $this->status = AgentStatus::RUNNING;
        $this->startTime = microtime(true);
    }

    public function markCompleted(): void
    {
        $this->status = AgentStatus::COMPLETED;
        $this->endTime = microtime(true);
    }

    public function markFailed(): void
    {
        $this->status = AgentStatus::FAILED;
        $this->endTime = microtime(true);
    }

    public function isSuccessful(): bool
    {
        return $this->status === AgentStatus::COMPLETED;
    }

    public function getStatus(): AgentStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, PhaseResult>
     */
    public function getPhaseResults(): array
    {
        return $this->phaseResults;
    }

    public function getPhaseResult(string $phaseName): ?PhaseResult
    {
        return $this->phaseResults[$phaseName] ?? null;
    }

    /** @return string[] */
    public function getSkippedPhases(): array
    {
        return $this->skippedPhases;
    }

    public function getDurationMs(): float
    {
        $end = $this->endTime ?? microtime(true);
        return ($end - $this->startTime) * 1000;
    }

    public function getTotalCostUsd(): float
    {
        $cost = 0.0;
        foreach ($this->phaseResults as $result) {
            $cost += $result->getTotalCostUsd();
        }
        return $cost;
    }

    public function getTotalAgentCount(): int
    {
        $count = 0;
        foreach ($this->phaseResults as $result) {
            $count += $result->getAgentCount();
        }
        return $count;
    }

    public function getCompletedPhaseCount(): int
    {
        $count = 0;
        foreach ($this->phaseResults as $result) {
            if ($result->isSuccessful()) {
                $count++;
            }
        }
        return $count;
    }

    public function getFailedPhases(): array
    {
        $failed = [];
        foreach ($this->phaseResults as $name => $result) {
            if ($result->getStatus() === AgentStatus::FAILED) {
                $failed[$name] = $result->getError();
            }
        }
        return $failed;
    }

    /**
     * Get a text summary of the pipeline execution.
     */
    public function summary(): string
    {
        $total = count($this->phaseResults);
        $completed = $this->getCompletedPhaseCount();
        $failed = count($this->getFailedPhases());
        $skipped = count($this->skippedPhases);
        $agents = $this->getTotalAgentCount();
        $cost = $this->getTotalCostUsd();
        $duration = $this->getDurationMs();

        return sprintf(
            'Pipeline %s: %d/%d phases completed, %d failed, %d skipped | %d agents | $%.4f | %.1fs',
            $this->status->value,
            $completed,
            $total,
            $failed,
            $skipped,
            $agents,
            $cost,
            $duration / 1000,
        );
    }

    /**
     * Collect all agent text outputs grouped by phase.
     */
    public function getAllText(): string
    {
        $parts = [];
        foreach ($this->phaseResults as $phaseName => $phaseResult) {
            $parts[] = "=== Phase: {$phaseName} ===\n" . $phaseResult->getCombinedText();
        }
        return implode("\n\n", $parts);
    }

    public function toArray(): array
    {
        $phases = [];
        foreach ($this->phaseResults as $name => $result) {
            $phases[$name] = $result->toArray();
        }

        return [
            'status' => $this->status->value,
            'duration_ms' => $this->getDurationMs(),
            'total_cost_usd' => $this->getTotalCostUsd(),
            'total_agents' => $this->getTotalAgentCount(),
            'completed_phases' => $this->getCompletedPhaseCount(),
            'failed_phases' => $this->getFailedPhases(),
            'skipped_phases' => $this->skippedPhases,
            'phases' => $phases,
        ];
    }
}
