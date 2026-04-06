<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Messages\AssistantMessage;

class TurnCompleteEvent extends StreamEvent
{
    public function __construct(
        public readonly AssistantMessage $message,
        public readonly int $turnNumber,
        public readonly ?array $usage = null,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'turn_complete';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'turn_number' => $this->turnNumber,
            'has_tool_use' => $this->message->hasToolUse(),
            'usage' => $this->usage,
        ];
    }
}
