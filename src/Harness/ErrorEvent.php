<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class ErrorEvent extends StreamEvent
{
    public function __construct(
        public readonly string $message,
        public readonly bool $recoverable = true,
        public readonly ?string $code = null,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'error';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'message' => $this->message,
            'recoverable' => $this->recoverable,
            'code' => $this->code,
        ];
    }
}
