<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace\Diary;

use Carbon\Carbon;
use SuperAgent\Memory\Palace\Drawer;
use SuperAgent\Memory\Palace\Hall;
use SuperAgent\Memory\Palace\PalaceStorage;
use SuperAgent\Memory\Palace\Wing;
use SuperAgent\Memory\Palace\WingType;

/**
 * Per-agent diary stored inside a dedicated wing.
 *
 * Every specialist agent (reviewer, architect, ops, ...) gets its own
 * wing of type AGENT with a single hall_events room "diary". Diary
 * entries are tiny, compact lines describing findings or decisions —
 * they keep each agent's expertise separate from shared memory.
 *
 * The diary intentionally uses the same Drawer format as the rest of the
 * palace so searches still surface diary entries when relevant.
 */
class AgentDiary
{
    public function __construct(private readonly PalaceStorage $storage) {}

    public function write(string $agentName, string $entry, array $tags = []): Drawer
    {
        $wing = $this->ensureWing($agentName);
        $drawer = new Drawer(
            id: Drawer::generateId(),
            wingSlug: $wing->slug,
            hall: Hall::EVENTS,
            roomSlug: 'diary',
            content: $entry,
            metadata: $tags !== [] ? ['tags' => implode(',', $tags)] : [],
        );
        $this->storage->saveDrawer($drawer);

        return $drawer;
    }

    /**
     * @return Drawer[] most-recent first
     */
    public function read(string $agentName, int $lastN = 10): array
    {
        $wing = $this->ensureWing($agentName);
        $drawers = iterator_to_array(
            $this->storage->iterateDrawers($wing->slug, Hall::EVENTS, 'diary'),
            false,
        );
        usort($drawers, fn (Drawer $a, Drawer $b) => $b->createdAt <=> $a->createdAt);

        return array_slice($drawers, 0, $lastN);
    }

    public function summary(string $agentName): string
    {
        $entries = $this->read($agentName, 20);
        if (empty($entries)) {
            return '';
        }
        $lines = [];
        foreach ($entries as $e) {
            $when = $e->createdAt->format('Y-m-d');
            $preview = str_replace(["\n", "\r"], ' ', $e->content);
            if (strlen($preview) > 120) {
                $preview = substr($preview, 0, 117) . '...';
            }
            $lines[] = "[{$when}] {$preview}";
        }

        return implode("\n", $lines);
    }

    private function ensureWing(string $agentName): Wing
    {
        $slug = Wing::slugify('agent_' . $agentName);
        $wing = $this->storage->loadWing($slug);
        if ($wing !== null) {
            return $wing;
        }
        $wing = new Wing(
            slug: $slug,
            name: $agentName,
            type: WingType::AGENT,
            keywords: [strtolower($agentName)],
            description: "Diary wing for agent {$agentName}",
            createdAt: Carbon::now(),
        );
        $this->storage->saveWing($wing);

        return $wing;
    }
}
