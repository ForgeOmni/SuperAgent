<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace\Layers;

use SuperAgent\Memory\Palace\Hall;
use SuperAgent\Memory\Palace\PalaceRetriever;
use SuperAgent\Memory\Palace\PalaceStorage;

/**
 * Loads the 4 memory layers on demand.
 *
 * The contract: each call returns either a string (content) or an empty
 * string (not present / layer turned off). The caller decides how to
 * assemble them into the system prompt. Wake-up = L0 + L1.
 */
class LayerManager
{
    public function __construct(
        private readonly PalaceStorage $storage,
        private readonly PalaceRetriever $retriever,
    ) {}

    public function identity(): string
    {
        return trim($this->storage->loadIdentity());
    }

    public function criticalFacts(): string
    {
        return trim($this->storage->loadCriticalFacts());
    }

    /**
     * The wake-up payload that fires at session start.
     * Keeps the output short so it fits in a single system-message block.
     */
    public function wakeUp(?string $wingSlug = null): string
    {
        $parts = [];
        $identity = $this->identity();
        if ($identity !== '') {
            $parts[] = "## Identity (L0)\n" . $identity;
        }
        $facts = $this->criticalFacts();
        if ($facts !== '') {
            $parts[] = "## Critical Facts (L1)\n" . $facts;
        }

        if ($wingSlug !== null) {
            $wing = $this->storage->loadWing($wingSlug);
            if ($wing !== null) {
                $roomsBrief = [];
                foreach ($this->storage->listRooms($wingSlug) as $room) {
                    $roomsBrief[] = "- [{$room->hall->value}] {$room->name}"
                        . ($room->summary !== '' ? ' — ' . $this->truncate($room->summary, 80) : '');
                }
                if (!empty($roomsBrief)) {
                    $parts[] = "## Wing: {$wing->name}\n" . implode("\n", array_slice($roomsBrief, 0, 12));
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * L2 room recall: return closet summaries for rooms that match a hint.
     *
     * @return array<int, array{wing:string, hall:string, room:string, summary:string}>
     */
    public function recallRooms(string $hint, int $limit = 5): array
    {
        $hint = strtolower($hint);
        $out = [];
        foreach ($this->storage->listRooms() as $room) {
            $score = 0;
            if (str_contains(strtolower($room->name), $hint) || str_contains(strtolower($room->slug), $hint)) {
                $score += 3;
            }
            if ($room->summary !== '' && str_contains(strtolower($room->summary), $hint)) {
                $score += 1;
            }
            if ($score > 0) {
                $out[] = [
                    'wing' => $room->wingSlug,
                    'hall' => $room->hall->value,
                    'room' => $room->name,
                    'summary' => $room->summary,
                    '_score' => $score,
                ];
            }
        }
        usort($out, fn ($a, $b) => $b['_score'] <=> $a['_score']);
        $out = array_slice($out, 0, $limit);
        foreach ($out as &$row) {
            unset($row['_score']);
        }

        return $out;
    }

    /**
     * L3 deep search: full drawer search via the retriever.
     *
     * @param array{wing?: string, hall?: Hall, room?: string, follow_tunnels?: bool} $filters
     * @return array<int, array{drawer: \SuperAgent\Memory\Palace\Drawer, score: float, breakdown: array<string,float>}>
     */
    public function deepSearch(string $query, int $limit = 5, array $filters = []): array
    {
        return $this->retriever->search($query, $limit, $filters);
    }

    private function truncate(string $s, int $n): string
    {
        return strlen($s) <= $n ? $s : substr($s, 0, $n - 3) . '...';
    }
}
