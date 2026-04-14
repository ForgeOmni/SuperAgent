<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * A directed edge (relationship) between two nodes in the knowledge graph.
 */
class KnowledgeEdge
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $targetId,
        public readonly EdgeType $type,
        public readonly string $agentName = '',
        public readonly string $createdAt = '',
        public array $metadata = [],
        // Temporal validity window (MemPalace-style). Both are ISO 8601
        // timestamps or empty. validFrom defaults to createdAt on read.
        // validUntil empty means "still true".
        public string $validFrom = '',
        public string $validUntil = '',
    ) {}

    /**
     * Generate a deterministic edge key.
     */
    public function getKey(): string
    {
        return "{$this->sourceId}|{$this->type->value}|{$this->targetId}";
    }

    /**
     * Is this edge valid at a given point in time?
     * If $asOf is null, checks right now.
     */
    public function isValidAt(?string $asOf = null): bool
    {
        $asOf ??= date('c');
        $from = $this->validFrom !== '' ? $this->validFrom : $this->createdAt;
        if ($from !== '' && strcmp($asOf, $from) < 0) {
            return false;
        }
        if ($this->validUntil !== '' && strcmp($asOf, $this->validUntil) > 0) {
            return false;
        }

        return true;
    }

    public function isInvalidated(): bool
    {
        return $this->validUntil !== '';
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'target_id' => $this->targetId,
            'type' => $this->type->value,
            'agent_name' => $this->agentName,
            'created_at' => $this->createdAt ?: date('c'),
            'metadata' => $this->metadata,
            'valid_from' => $this->validFrom,
            'valid_until' => $this->validUntil,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            sourceId: $data['source_id'] ?? '',
            targetId: $data['target_id'] ?? '',
            type: EdgeType::from($data['type'] ?? 'read'),
            agentName: $data['agent_name'] ?? '',
            createdAt: $data['created_at'] ?? date('c'),
            metadata: $data['metadata'] ?? [],
            validFrom: $data['valid_from'] ?? '',
            validUntil: $data['valid_until'] ?? '',
        );
    }
}
