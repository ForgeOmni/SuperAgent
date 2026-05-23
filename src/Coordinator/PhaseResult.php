<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use SuperAgent\AgentResult;
use SuperAgent\Swarm\AgentStatus;

/**
 * Result of executing a single collaboration phase.
 */
class PhaseResult
{
    /** @var array<string, AgentResult> Agent name => result */
    private array $agentResults = [];

    private AgentStatus $status;
    private ?string $error = null;
    private int $startTime;
    private ?int $endTime = null;

    public function __construct(
        public readonly string $phaseName,
    ) {
        $this->status = AgentStatus::PENDING;
        $this->startTime = hrtime(true);
    }

    public function markRunning(): void
    {
        $this->status = AgentStatus::RUNNING;
        $this->startTime = hrtime(true);
    }

    public function markCompleted(): void
    {
        $this->status = AgentStatus::COMPLETED;
        $this->endTime = hrtime(true);
    }

    public function markFailed(string $error): void
    {
        $this->status = AgentStatus::FAILED;
        $this->error = $error;
        $this->endTime = hrtime(true);
    }

    public function addAgentResult(string $agentName, AgentResult $result): void
    {
        $this->agentResults[$agentName] = $result;
    }

    public function getStatus(): AgentStatus
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->status === AgentStatus::COMPLETED;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, AgentResult>
     */
    public function getAgentResults(): array
    {
        return $this->agentResults;
    }

    public function getAgentResult(string $agentName): ?AgentResult
    {
        return $this->agentResults[$agentName] ?? null;
    }

    public function getDurationMs(): ?float
    {
        $end = $this->endTime ?? hrtime(true);
        return ($end - $this->startTime) / 1_000_000;
    }

    public function getTotalCostUsd(): float
    {
        $cost = 0.0;
        foreach ($this->agentResults as $result) {
            $cost += $result->totalCostUsd;
        }
        return $cost;
    }

    public function getAgentCount(): int
    {
        return count($this->agentResults);
    }

    /**
     * Combine all agent text outputs.
     */
    public function getCombinedText(): string
    {
        $parts = [];
        foreach ($this->agentResults as $name => $result) {
            $parts[] = "[{$name}]\n" . $result->text();
        }
        return implode("\n\n", $parts);
    }

    public function toArray(): array
    {
        $results = [];
        foreach ($this->agentResults as $name => $result) {
            $results[$name] = [
                'text' => $result->text(),
                'turns' => $result->turns(),
                'cost_usd' => $result->totalCostUsd,
            ];
        }

        return [
            'phase' => $this->phaseName,
            'status' => $this->status->value,
            'error' => $this->error,
            'duration_ms' => $this->getDurationMs(),
            'total_cost_usd' => $this->getTotalCostUsd(),
            'agent_count' => $this->getAgentCount(),
            'agents' => $results,
        ];
    }
}
