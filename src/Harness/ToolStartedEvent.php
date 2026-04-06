<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class ToolStartedEvent extends StreamEvent
{
    public function __construct(
        public readonly string $toolName,
        public readonly string $toolUseId,
        public readonly array $toolInput = [],
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'tool_started';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'tool_name' => $this->toolName,
            'tool_use_id' => $this->toolUseId,
            'tool_input' => $this->toolInput,
        ];
    }
}
