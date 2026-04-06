<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

class WorktreeInfo
{
    public function __construct(
        public readonly string $slug,
        public readonly string $path,
        public readonly string $branch,
        public readonly string $originalPath,
        public readonly ?string $agentId,
        public readonly int $createdAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            slug: $data['slug'],
            path: $data['path'],
            branch: $data['branch'] ?? '',
            originalPath: $data['original_path'] ?? '',
            agentId: $data['agent_id'] ?? null,
            createdAt: (int) ($data['created_at'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'path' => $this->path,
            'branch' => $this->branch,
            'original_path' => $this->originalPath,
            'agent_id' => $this->agentId,
            'created_at' => $this->createdAt,
        ];
    }
}
