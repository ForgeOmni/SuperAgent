<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class CompactionEvent extends StreamEvent
{
    public function __construct(
        public readonly string $tier,
        public readonly int $tokensSaved,
        public readonly string $strategy,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'compaction';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'tier' => $this->tier,
            'tokens_saved' => $this->tokensSaved,
            'strategy' => $this->strategy,
        ];
    }
}
