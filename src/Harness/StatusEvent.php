<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class StatusEvent extends StreamEvent
{
    public function __construct(
        public readonly string $message,
        public readonly array $data = [],
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'status';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
