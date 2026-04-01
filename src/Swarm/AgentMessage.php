<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Message sent between agents.
 */
class AgentMessage
{
    public readonly string $timestamp;
    
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $content,
        public readonly ?string $summary = null,
        ?string $timestamp = null,
        public readonly ?string $requestId = null,
        public readonly ?string $color = null,
        public readonly array $metadata = [],
    ) {
        $this->timestamp = $timestamp ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
    
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'content' => $this->content,
            'summary' => $this->summary,
            'timestamp' => $this->timestamp,
            'request_id' => $this->requestId,
            'color' => $this->color,
            'metadata' => $this->metadata,
        ], fn($v) => $v !== null && $v !== []);
    }
    
    public static function fromArray(array $data): self
    {
        return new self(
            from: $data['from'],
            to: $data['to'],
            content: $data['content'],
            summary: $data['summary'] ?? null,
            timestamp: $data['timestamp'] ?? null,
            requestId: $data['request_id'] ?? null,
            color: $data['color'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}