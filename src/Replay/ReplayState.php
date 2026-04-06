<?php

declare(strict_types=1);

namespace SuperAgent\Replay;

final class ReplayState
{
    public function __construct(
        public readonly int $step,
        public readonly string $agentId,
        public readonly array $messages,
        public readonly int $turnCount,
        public readonly float $costSoFar,
        public readonly array $tokensUsed,
        public readonly array $toolCallsSoFar,
        public readonly array $activeAgents,
    ) {}

    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'agent_id' => $this->agentId,
            'messages' => $this->messages,
            'turn_count' => $this->turnCount,
            'cost_so_far' => $this->costSoFar,
            'tokens_used' => $this->tokensUsed,
            'tool_calls_so_far' => $this->toolCallsSoFar,
            'active_agents' => $this->activeAgents,
        ];
    }
}
