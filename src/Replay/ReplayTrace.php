<?php

declare(strict_types=1);

namespace SuperAgent\Replay;

final class ReplayTrace
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $startedAt,
        public string $endedAt = '',
        public array $events = [],
        public array $agents = [],
        public int $totalTurns = 0,
        public float $totalCost = 0.0,
    ) {}

    public function addEvent(ReplayEvent $event): void
    {
        $this->events[] = $event;
    }

    public function getEventsForAgent(string $agentId): array
    {
        return array_values(array_filter(
            $this->events,
            fn(ReplayEvent $e) => $e->agentId === $agentId,
        ));
    }

    public function getEventsInRange(int $fromStep, int $toStep): array
    {
        return array_values(array_filter(
            $this->events,
            fn(ReplayEvent $e) => $e->step >= $fromStep && $e->step <= $toStep,
        ));
    }

    public function getToolCalls(): array
    {
        return array_values(array_filter(
            $this->events,
            fn(ReplayEvent $e) => $e->isToolCall(),
        ));
    }

    public function getLlmCalls(): array
    {
        return array_values(array_filter(
            $this->events,
            fn(ReplayEvent $e) => $e->isLlmCall(),
        ));
    }

    public function getEventAt(int $step): ?ReplayEvent
    {
        foreach ($this->events as $event) {
            if ($event->step === $step) {
                return $event;
            }
        }
        return null;
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'agents' => $this->agents,
            'total_turns' => $this->totalTurns,
            'total_cost' => $this->totalCost,
            'event_count' => $this->count(),
        ];
    }

    public static function fromArray(array $data, array $events = []): self
    {
        return new self(
            sessionId: $data['session_id'],
            startedAt: $data['started_at'],
            endedAt: $data['ended_at'] ?? '',
            events: $events,
            agents: $data['agents'] ?? [],
            totalTurns: $data['total_turns'] ?? 0,
            totalCost: (float) ($data['total_cost'] ?? 0.0),
        );
    }
}
