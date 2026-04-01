<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Team information.
 */
class Team
{
    public function __construct(
        public readonly string $name,
        public readonly string $leaderId,
        public readonly array $members = [],
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly array $metadata = [],
    ) {}
    
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'leader_id' => $this->leaderId,
            'members' => array_map(fn($m) => $m instanceof TeamMember ? $m->toArray() : $m, $this->members),
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
        ];
    }
    
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            leaderId: $data['leader_id'],
            members: array_map(
                fn($m) => is_array($m) ? TeamMember::fromArray($m) : $m,
                $data['members'] ?? []
            ),
            createdAt: isset($data['created_at']) ? new \DateTimeImmutable($data['created_at']) : null,
            metadata: $data['metadata'] ?? [],
        );
    }
}