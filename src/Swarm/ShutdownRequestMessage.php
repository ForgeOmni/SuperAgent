<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

class ShutdownRequestMessage extends StructuredMessage
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $from,
        public readonly ?string $reason = null,
    ) {}
    
    public function getType(): string
    {
        return 'shutdown_request';
    }
    
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->getType(),
            'request_id' => $this->requestId,
            'from' => $this->from,
            'reason' => $this->reason,
        ]);
    }
}