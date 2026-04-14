<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

/**
 * Palace navigation graph — tracks which rooms exist in which wings and
 * maintains auto-tunnels where the same room slug appears across wings.
 *
 * The graph is derived, not authoritative: PalaceStorage is the source of
 * truth. PalaceGraph's job is to keep a fast lookup so we can answer
 * "give me every wing that has a room called 'auth-migration'" without
 * walking the filesystem each query.
 *
 * Semantics of an auto-tunnel: any two (wing, room) nodes that share the
 * same room slug (regardless of hall) are connected. When retrieval
 * finds one, it can traverse to the other via a tunnel.
 */
class PalaceGraph
{
    /** @var array<string, array<int, array{wing:string, hall:string}>> roomSlug => [{wing,hall}] */
    private array $roomIndex = [];

    /** @var Tunnel[] keyed by id */
    private array $tunnels = [];

    public function __construct(private readonly PalaceStorage $storage)
    {
        $this->rebuild();
    }

    /**
     * Rebuild the index from storage. Run this after bulk writes.
     */
    public function rebuild(): void
    {
        $this->roomIndex = [];
        foreach ($this->storage->listRooms() as $room) {
            $this->roomIndex[$room->slug][] = [
                'wing' => $room->wingSlug,
                'hall' => $room->hall->value,
            ];
        }

        $this->tunnels = [];
        foreach ($this->storage->loadTunnels() as $t) {
            $this->tunnels[$t->id] = $t;
        }

        $this->regenerateAutoTunnels();
    }

    /**
     * Note that a room now exists; update the index and create auto-tunnels
     * with any matching rooms in other wings.
     */
    public function recordRoom(Room $room): void
    {
        $entry = ['wing' => $room->wingSlug, 'hall' => $room->hall->value];
        $existing = $this->roomIndex[$room->slug] ?? [];
        $alreadyIndexed = false;
        foreach ($existing as $e) {
            if ($e['wing'] === $entry['wing'] && $e['hall'] === $entry['hall']) {
                $alreadyIndexed = true;
                break;
            }
        }
        if (!$alreadyIndexed) {
            $this->roomIndex[$room->slug][] = $entry;
        }

        $this->createAutoTunnelsFor($room);
        $this->persistTunnels();
    }

    /**
     * Wings that contain a room with this slug.
     *
     * @return array<int, array{wing:string, hall:string}>
     */
    public function wingsWithRoom(string $roomSlug): array
    {
        return $this->roomIndex[$roomSlug] ?? [];
    }

    /** @return Tunnel[] */
    public function tunnelsFromRoom(string $wingSlug, string $roomSlug): array
    {
        $out = [];
        foreach ($this->tunnels as $t) {
            if ($t->fromWingSlug === $wingSlug && $t->fromRoomSlug === $roomSlug) {
                $out[] = $t;
            } elseif ($t->toWingSlug === $wingSlug && $t->toRoomSlug === $roomSlug) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Explicitly create a tunnel between two (wing, hall, room) points.
     */
    public function createTunnel(
        string $fromWing,
        Hall $fromHall,
        string $fromRoom,
        string $toWing,
        Hall $toHall,
        string $toRoom,
        string $note = '',
    ): Tunnel {
        $id = Tunnel::generateId($fromWing, $fromRoom, $toWing, $toRoom);
        $tunnel = new Tunnel(
            id: $id,
            fromWingSlug: $fromWing,
            fromRoomSlug: $fromRoom,
            toWingSlug: $toWing,
            toRoomSlug: $toRoom,
            fromHall: $fromHall,
            toHall: $toHall,
            auto: false,
            note: $note,
        );
        $this->tunnels[$id] = $tunnel;
        $this->persistTunnels();

        return $tunnel;
    }

    public function deleteTunnel(string $id): bool
    {
        if (!isset($this->tunnels[$id])) {
            return false;
        }
        unset($this->tunnels[$id]);
        $this->persistTunnels();

        return true;
    }

    /** @return Tunnel[] */
    public function listTunnels(?string $wingSlug = null): array
    {
        if ($wingSlug === null) {
            return array_values($this->tunnels);
        }

        return array_values(array_filter(
            $this->tunnels,
            fn (Tunnel $t) => $t->fromWingSlug === $wingSlug || $t->toWingSlug === $wingSlug,
        ));
    }

    public function stats(): array
    {
        $auto = 0;
        $explicit = 0;
        foreach ($this->tunnels as $t) {
            $t->auto ? $auto++ : $explicit++;
        }

        return [
            'rooms_indexed' => array_sum(array_map('count', $this->roomIndex)),
            'unique_room_slugs' => count($this->roomIndex),
            'tunnels_total' => count($this->tunnels),
            'tunnels_auto' => $auto,
            'tunnels_explicit' => $explicit,
        ];
    }

    // ── Internal ───────────────────────────────────────────────────

    private function regenerateAutoTunnels(): void
    {
        // Wipe auto tunnels; keep explicit ones.
        foreach ($this->tunnels as $id => $t) {
            if ($t->auto) {
                unset($this->tunnels[$id]);
            }
        }
        foreach ($this->roomIndex as $roomSlug => $occurrences) {
            if (count($occurrences) < 2) {
                continue;
            }
            for ($i = 0; $i < count($occurrences); $i++) {
                for ($j = $i + 1; $j < count($occurrences); $j++) {
                    $this->addAutoTunnel($roomSlug, $occurrences[$i], $occurrences[$j]);
                }
            }
        }
        $this->persistTunnels();
    }

    private function createAutoTunnelsFor(Room $room): void
    {
        $occurrences = $this->roomIndex[$room->slug] ?? [];
        foreach ($occurrences as $other) {
            if ($other['wing'] === $room->wingSlug && $other['hall'] === $room->hall->value) {
                continue;
            }
            $this->addAutoTunnel(
                $room->slug,
                ['wing' => $room->wingSlug, 'hall' => $room->hall->value],
                $other,
            );
        }
    }

    /**
     * @param array{wing:string, hall:string} $a
     * @param array{wing:string, hall:string} $b
     */
    private function addAutoTunnel(string $roomSlug, array $a, array $b): void
    {
        if ($a['wing'] === $b['wing']) {
            return; // same-wing corridors are halls, not tunnels
        }
        $id = Tunnel::generateId($a['wing'], $roomSlug, $b['wing'], $roomSlug);
        if (isset($this->tunnels[$id])) {
            return;
        }
        $this->tunnels[$id] = new Tunnel(
            id: $id,
            fromWingSlug: $a['wing'],
            fromRoomSlug: $roomSlug,
            toWingSlug: $b['wing'],
            toRoomSlug: $roomSlug,
            fromHall: Hall::from($a['hall']),
            toHall: Hall::from($b['hall']),
            auto: true,
        );
    }

    private function persistTunnels(): void
    {
        $this->storage->saveTunnels(array_values($this->tunnels));
    }
}
