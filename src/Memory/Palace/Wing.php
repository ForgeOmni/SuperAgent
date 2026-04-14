<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Carbon\Carbon;

/**
 * A Wing of the palace — a top-level container for one subject
 * (person, project, topic, or agent).
 *
 * Wings hold halls; halls hold rooms; rooms hold drawers and closets.
 */
class Wing
{
    public readonly Carbon $createdAt;
    public Carbon $updatedAt;

    public function __construct(
        public readonly string $slug,
        public string $name,
        public readonly WingType $type,
        /** @var string[] keywords used by WingDetector to route new memories */
        public array $keywords = [],
        public string $description = '',
        ?Carbon $createdAt = null,
        ?Carbon $updatedAt = null,
    ) {
        $this->createdAt = $createdAt ?? Carbon::now();
        $this->updatedAt = $updatedAt ?? Carbon::now();
    }

    public function touch(): void
    {
        $this->updatedAt = Carbon::now();
    }

    public static function slugify(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? '';
        $name = trim($name, '_');
        if ($name === '') {
            $name = 'wing';
        }

        return 'wing_' . substr($name, 0, 40);
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type->value,
            'keywords' => $this->keywords,
            'description' => $this->description,
            'created_at' => $this->createdAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            slug: $data['slug'],
            name: $data['name'] ?? $data['slug'],
            type: WingType::from($data['type'] ?? 'general'),
            keywords: $data['keywords'] ?? [],
            description: $data['description'] ?? '',
            createdAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? Carbon::parse($data['updated_at']) : null,
        );
    }
}
