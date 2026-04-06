<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Messages\AssistantMessage;

class AgentCompleteEvent extends StreamEvent
{
    public function __construct(
        public readonly int $totalTurns,
        public readonly float $totalCostUsd,
        public readonly ?AssistantMessage $finalMessage = null,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'agent_complete';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'total_turns' => $this->totalTurns,
            'total_cost_usd' => $this->totalCostUsd,
            'has_final_message' => $this->finalMessage !== null,
        ];
    }
}
