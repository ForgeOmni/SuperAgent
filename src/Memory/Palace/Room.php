<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Carbon\Carbon;

/**
 * A Room is a named topic within a Wing's Hall.
 *
 * Rooms are the finest-grained grouping before a Drawer. When the same
 * room name appears in multiple wings, a Tunnel is created to bridge them.
 */
class Room
{
    public readonly Carbon $createdAt;
    public Carbon $updatedAt;

    public function __construct(
        public readonly string $slug,
        public string $name,
        public readonly string $wingSlug,
        public readonly Hall $hall,
        public string $summary = '',
        public int $drawerCount = 0,
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
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');
        if ($name === '') {
            $name = 'room';
        }

        return substr($name, 0, 50);
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'wing_slug' => $this->wingSlug,
            'hall' => $this->hall->value,
            'summary' => $this->summary,
            'drawer_count' => $this->drawerCount,
            'created_at' => $this->createdAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            slug: $data['slug'],
            name: $data['name'] ?? $data['slug'],
            wingSlug: $data['wing_slug'],
            hall: Hall::from($data['hall'] ?? 'events'),
            summary: $data['summary'] ?? '',
            drawerCount: (int) ($data['drawer_count'] ?? 0),
            createdAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? Carbon::parse($data['updated_at']) : null,
        );
    }
}
