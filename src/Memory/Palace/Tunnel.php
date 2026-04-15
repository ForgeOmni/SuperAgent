<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Carbon\Carbon;

/**
 * A Tunnel bridges the same room across two different wings.
 *
 * Tunnels are auto-created by PalaceGraph when the same room slug appears
 * in multiple wings — that's the signal that the same topic exists in
 * different contexts (e.g. "auth-migration" in wing_kai and wing_driftwood).
 *
 * Tunnels can also be created explicitly for cross-topic links.
 */
class Tunnel
{
    public readonly Carbon $createdAt;

    public function __construct(
        public readonly string $id,
        public readonly string $fromWingSlug,
        public readonly string $fromRoomSlug,
        public readonly string $toWingSlug,
        public readonly string $toRoomSlug,
        public readonly Hall $fromHall,
        public readonly Hall $toHall,
        public readonly bool $auto = true,
        public string $note = '',
        ?Carbon $createdAt = null,
    ) {
        $this->createdAt = $createdAt ?? Carbon::now();
    }

    public static function generateId(
        string $fromWing,
        string $fromRoom,
        string $toWing,
        string $toRoom,
    ): string {
        return 'tun_' . substr(hash('sha256', "$fromWing|$fromRoom|$toWing|$toRoom"), 0, 12);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_wing' => $this->fromWingSlug,
            'from_room' => $this->fromRoomSlug,
            'to_wing' => $this->toWingSlug,
            'to_room' => $this->toRoomSlug,
            'from_hall' => $this->fromHall->value,
            'to_hall' => $this->toHall->value,
            'auto' => $this->auto,
            'note' => $this->note,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            fromWingSlug: $data['from_wing'],
            fromRoomSlug: $data['from_room'],
            toWingSlug: $data['to_wing'],
            toRoomSlug: $data['to_room'],
            fromHall: Hall::from($data['from_hall']),
            toHall: Hall::from($data['to_hall']),
            auto: (bool) ($data['auto'] ?? true),
            note: $data['note'] ?? '',
            createdAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
        );
    }
}
