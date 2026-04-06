<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class ThinkingDeltaEvent extends StreamEvent
{
    public function __construct(
        public readonly string $text,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'thinking_delta';
    }

    public function toArray(): array
    {
        return parent::toArray() + ['text' => $this->text];
    }
}
