<?php

declare(strict_types=1);

namespace SuperAgent\Replay;

final class ReplayPlayer
{
    private int $currentStep = 0;

    public function __construct(
        private readonly ReplayTrace $trace,
    ) {}

    /**
     * Jump to a specific step and reconstruct state at that point.
     */
    public function stepTo(int $step): ReplayState
    {
        $step = max(0, min($step, $this->trace->count()));
        $this->currentStep = $step;
        return $this->buildStateAt($step);
    }

    /**
     * Advance one step forward.
     */
    public function next(): ReplayState
    {
        if ($this->currentStep < $this->trace->count()) {
            $this->currentStep++;
        }
        return $this->buildStateAt($this->currentStep);
    }

    /**
     * Go back one step.
     */
    public function previous(): ReplayState
    {
        if ($this->currentStep > 0) {
            $this->currentStep--;
        }
        return $this->buildStateAt($this->currentStep);
    }

    /**
     * Inspect a specific agent's state at the current step.
     */
    public function inspect(string $agentId): array
    {
        $events = $this->trace->getEventsInRange(0, $this->currentStep);
        $agentEvents = array_filter($events, fn(ReplayEvent $e) => $e->agentId === $agentId);

        $toolCalls = [];
        $llmCalls = 0;
        $totalCost = 0.0;
        $lastActivity = null;

        foreach ($agentEvents as $event) {
            $lastActivity = $event->timestamp;
            if ($event->isToolCall()) {
                $toolCalls[] = $event->getData('tool_name');
            } elseif ($event->isLlmCall()) {
                $llmCalls++;
                $usage = $event->getData('usage', []);
                $inputTokens = $usage['input_tokens'] ?? $usage['inputTokens'] ?? 0;
                $outputTokens = $usage['output_tokens'] ?? $usage['outputTokens'] ?? 0;
                $totalCost += ($inputTokens * 3.0 / 1_000_000) + ($outputTokens * 15.0 / 1_000_000);
            }
        }

        return [
            'agent_id' => $agentId,
            'at_step' => $this->currentStep,
            'event_count' => count($agentEvents),
            'llm_calls' => $llmCalls,
            'tool_calls' => $toolCalls,
            'estimated_cost' => $totalCost,
            'last_activity' => $lastActivity,
            'agent_info' => $this->trace->agents[$agentId] ?? null,
        ];
    }

    /**
     * Create a sub-trace starting from a specific step, for re-execution.
     */
    public function fork(int $fromStep): ReplayTrace
    {
        $events = $this->trace->getEventsInRange(0, $fromStep);
        $forkedTrace = new ReplayTrace(
            sessionId: $this->trace->sessionId . '_fork_' . $fromStep,
            startedAt: date('c'),
            events: $events,
            agents: $this->trace->agents,
        );
        return $forkedTrace;
    }

    /**
     * Search events by query string (tool name, content substring).
     */
    public function search(string $query): array
    {
        $query = mb_strtolower($query);
        $results = [];

        foreach ($this->trace->events as $event) {
            $searchable = mb_strtolower(json_encode($event->data));
            if (str_contains($searchable, $query)) {
                $results[] = $event;
            }
        }

        return $results;
    }

    /**
     * Get a formatted timeline of events with cost/token info.
     */
    public function getTimeline(): array
    {
        $timeline = [];
        $cumulativeCost = 0.0;

        foreach ($this->trace->events as $event) {
            $entry = [
                'step' => $event->step,
                'type' => $event->type,
                'agent' => $event->agentId,
                'timestamp' => $event->timestamp,
                'duration_ms' => $event->durationMs,
            ];

            if ($event->isLlmCall()) {
                $usage = $event->getData('usage', []);
                $inputTokens = $usage['input_tokens'] ?? $usage['inputTokens'] ?? 0;
                $outputTokens = $usage['output_tokens'] ?? $usage['outputTokens'] ?? 0;
                $cost = ($inputTokens * 3.0 / 1_000_000) + ($outputTokens * 15.0 / 1_000_000);
                $cumulativeCost += $cost;
                $entry['model'] = $event->getData('model');
                $entry['tokens'] = ['input' => $inputTokens, 'output' => $outputTokens];
                $entry['cost'] = round($cost, 6);
            } elseif ($event->isToolCall()) {
                $entry['tool'] = $event->getData('tool_name');
                $entry['is_error'] = $event->getData('is_error', false);
            } elseif ($event->isAgentSpawn()) {
                $entry['role'] = $event->getData('role');
                $entry['parent'] = $event->getData('parent_id');
            }

            $entry['cumulative_cost'] = round($cumulativeCost, 6);
            $timeline[] = $entry;
        }

        return $timeline;
    }

    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    public function getCurrentState(): ReplayState
    {
        return $this->buildStateAt($this->currentStep);
    }

    private function buildStateAt(int $step): ReplayState
    {
        $events = $this->trace->getEventsInRange(0, $step);

        $messages = [];
        $turnCount = 0;
        $cost = 0.0;
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];
        $toolCalls = [];
        $activeAgents = [];

        // Find the latest state snapshot at or before this step
        $latestSnapshot = null;
        foreach ($events as $event) {
            if ($event->isStateSnapshot()) {
                $latestSnapshot = $event;
            }
        }

        if ($latestSnapshot !== null) {
            $messages = $latestSnapshot->getData('messages_serialized', []);
            $turnCount = $latestSnapshot->getData('turn_count', 0);
            $cost = (float) $latestSnapshot->getData('cost', 0.0);
            $activeAgents = $latestSnapshot->getData('active_agents', []);
        }

        // Replay events after the snapshot
        $startAfter = $latestSnapshot ? $latestSnapshot->step : 0;
        foreach ($events as $event) {
            if ($event->step <= $startAfter) {
                continue;
            }

            if ($event->isLlmCall()) {
                $turnCount++;
                $usage = $event->getData('usage', []);
                $tokens['input'] += $usage['input_tokens'] ?? $usage['inputTokens'] ?? 0;
                $tokens['output'] += $usage['output_tokens'] ?? $usage['outputTokens'] ?? 0;
                $tokens['cache_read'] += $usage['cache_read_input_tokens'] ?? $usage['cacheReadInputTokens'] ?? 0;
                $tokens['cache_creation'] += $usage['cache_creation_input_tokens'] ?? $usage['cacheCreationInputTokens'] ?? 0;
                $cost += ($tokens['input'] * 3.0 / 1_000_000) + ($tokens['output'] * 15.0 / 1_000_000);
            } elseif ($event->isToolCall()) {
                $toolCalls[] = $event->getData('tool_name');
            } elseif ($event->isAgentSpawn()) {
                $activeAgents[] = $event->agentId;
            }
        }

        $primaryAgent = 'main';
        if (!empty($events)) {
            $lastEvent = end($events);
            $primaryAgent = $lastEvent->agentId;
        }

        return new ReplayState(
            step: $step,
            agentId: $primaryAgent,
            messages: $messages,
            turnCount: $turnCount,
            costSoFar: $cost,
            tokensUsed: $tokens,
            toolCallsSoFar: $toolCalls,
            activeAgents: array_unique($activeAgents),
        );
    }
}
