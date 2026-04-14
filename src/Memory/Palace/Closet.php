<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Carbon\Carbon;

/**
 * A Closet holds a short summary of everything in a room, plus pointers
 * back to the original drawers. Closets are what the retriever scans
 * first to decide which drawers are worth loading in full.
 */
class Closet
{
    public readonly Carbon $updatedAt;

    public function __construct(
        public readonly string $wingSlug,
        public readonly Hall $hall,
        public readonly string $roomSlug,
        public string $summary,
        /** @var string[] ordered drawer IDs */
        public array $drawerIds = [],
        ?Carbon $updatedAt = null,
    ) {
        $this->updatedAt = $updatedAt ?? Carbon::now();
    }

    public function toArray(): array
    {
        return [
            'wing_slug' => $this->wingSlug,
            'hall' => $this->hall->value,
            'room_slug' => $this->roomSlug,
            'summary' => $this->summary,
            'drawer_ids' => $this->drawerIds,
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            wingSlug: $data['wing_slug'],
            hall: Hall::from($data['hall']),
            roomSlug: $data['room_slug'],
            summary: $data['summary'] ?? '',
            drawerIds: $data['drawer_ids'] ?? [],
            updatedAt: isset($data['updated_at']) ? Carbon::parse($data['updated_at']) : null,
        );
    }
}
