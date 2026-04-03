<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * A node in the knowledge graph representing a file, symbol, agent, or decision.
 */
class KnowledgeNode
{
    public function __construct(
        public readonly string $id,
        public readonly NodeType $type,
        public readonly string $label,
        public array $metadata = [],
        public string $lastUpdatedAt = '',
        public int $accessCount = 0,
    ) {
        if (empty($this->lastUpdatedAt)) {
            $this->lastUpdatedAt = date('c');
        }
    }

    /**
     * Generate a deterministic node ID from type and label.
     */
    public static function makeId(NodeType $type, string $label): string
    {
        return $type->value . ':' . $label;
    }

    /**
     * Record an access (read/modify/query).
     */
    public function touch(): void
    {
        $this->accessCount++;
        $this->lastUpdatedAt = date('c');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->label,
            'metadata' => $this->metadata,
            'last_updated_at' => $this->lastUpdatedAt,
            'access_count' => $this->accessCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            type: NodeType::from($data['type'] ?? 'file'),
            label: $data['label'] ?? '',
            metadata: $data['metadata'] ?? [],
            lastUpdatedAt: $data['last_updated_at'] ?? date('c'),
            accessCount: (int) ($data['access_count'] ?? 0),
        );
    }
}
