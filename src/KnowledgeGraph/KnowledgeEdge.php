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
    ) {}

    /**
     * Generate a deterministic edge key.
     */
    public function getKey(): string
    {
        return "{$this->sourceId}|{$this->type->value}|{$this->targetId}";
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
        );
    }
}
