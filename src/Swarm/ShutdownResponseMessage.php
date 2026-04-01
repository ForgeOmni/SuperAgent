<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

class ShutdownResponseMessage extends StructuredMessage
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $from,
        public readonly bool $approve,
        public readonly ?string $reason = null,
    ) {}
    
    public function getType(): string
    {
        return 'shutdown_response';
    }
    
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->getType(),
            'request_id' => $this->requestId,
            'from' => $this->from,
            'approve' => $this->approve,
            'reason' => $this->reason,
        ]);
    }
}