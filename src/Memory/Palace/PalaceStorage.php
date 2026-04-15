<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Symfony\Component\Yaml\Yaml;

/**
 * Hierarchical file storage for the palace.
 *
 * Layout:
 *   {basePath}/palace/
 *     wings.json                       -- wing registry
 *     tunnels.json                     -- cross-wing links
 *     graph.json                       -- palace navigation graph
 *     identity.txt                     -- L0 identity
 *     critical_facts.md                -- L1 critical facts
 *     wings/{wing_slug}/
 *       halls/{hall}/rooms/{room_slug}/
 *         room.json
 *         drawers/{drawer_id}.md
 *         drawers/{drawer_id}.emb      -- optional embedding sidecar (json array)
 *         closet.json
 *
 * All writes go through save* methods. Reads are per-entity; full-palace
 * walks happen through PalaceGraph or PalaceRetriever.
 */
class PalaceStorage
{
    public function __construct(
        private readonly string $basePath,
    ) {
        $this->ensureDir($this->basePath);
        $this->ensureDir($this->basePath . '/wings');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    // ── Wings ──────────────────────────────────────────────────────

    public function saveWing(Wing $wing): void
    {
        $this->ensureDir($this->wingDir($wing->slug));
        $this->writeJson($this->wingDir($wing->slug) . '/wing.json', $wing->toArray());
        $this->updateWingRegistry();
    }

    public function loadWing(string $slug): ?Wing
    {
        $path = $this->wingDir($slug) . '/wing.json';
        if (!file_exists($path)) {
            return null;
        }
        $data = $this->readJson($path);

        return $data ? Wing::fromArray($data) : null;
    }

    /** @return Wing[] */
    public function listWings(): array
    {
        $wings = [];
        $dir = $this->basePath . '/wings';
        if (!is_dir($dir)) {
            return [];
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $wing = $this->loadWing($entry);
            if ($wing !== null) {
                $wings[] = $wing;
            }
        }

        return $wings;
    }

    private function updateWingRegistry(): void
    {
        $registry = [];
        foreach ($this->listWings() as $w) {
            $registry[$w->slug] = [
                'name' => $w->name,
                'type' => $w->type->value,
                'keywords' => $w->keywords,
            ];
        }
        $this->writeJson($this->basePath . '/wings.json', $registry);
    }

    // ── Rooms ──────────────────────────────────────────────────────

    public function saveRoom(Room $room): void
    {
        $this->ensureDir($this->roomDir($room->wingSlug, $room->hall, $room->slug));
        $this->writeJson(
            $this->roomDir($room->wingSlug, $room->hall, $room->slug) . '/room.json',
            $room->toArray(),
        );
    }

    public function loadRoom(string $wingSlug, Hall $hall, string $roomSlug): ?Room
    {
        $path = $this->roomDir($wingSlug, $hall, $roomSlug) . '/room.json';
        if (!file_exists($path)) {
            return null;
        }
        $data = $this->readJson($path);

        return $data ? Room::fromArray($data) : null;
    }

    /** @return Room[] */
    public function listRooms(?string $wingSlug = null, ?Hall $hall = null): array
    {
        $rooms = [];
        $wings = $wingSlug !== null ? [$wingSlug] : array_map(fn (Wing $w) => $w->slug, $this->listWings());

        foreach ($wings as $w) {
            $halls = $hall !== null ? [$hall] : Hall::cases();
            foreach ($halls as $h) {
                $hallDir = $this->wingDir($w) . '/halls/' . $h->value . '/rooms';
                if (!is_dir($hallDir)) {
                    continue;
                }
                foreach (scandir($hallDir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $room = $this->loadRoom($w, $h, $entry);
                    if ($room !== null) {
                        $rooms[] = $room;
                    }
                }
            }
        }

        return $rooms;
    }

    // ── Drawers ────────────────────────────────────────────────────

    public function saveDrawer(Drawer $drawer): void
    {
        $dir = $this->roomDir($drawer->wingSlug, $drawer->hall, $drawer->roomSlug) . '/drawers';
        $this->ensureDir($dir);
        file_put_contents($dir . '/' . $drawer->id . '.md', $drawer->toMarkdown());
        if ($drawer->embedding !== null) {
            file_put_contents(
                $dir . '/' . $drawer->id . '.emb',
                json_encode($drawer->embedding),
            );
        }
    }

    public function loadDrawer(string $wingSlug, Hall $hall, string $roomSlug, string $id): ?Drawer
    {
        $dir = $this->roomDir($wingSlug, $hall, $roomSlug) . '/drawers';
        $path = $dir . '/' . $id . '.md';
        if (!file_exists($path)) {
            return null;
        }
        $parsed = $this->parseFrontmatter((string) file_get_contents($path));
        if ($parsed === null) {
            return null;
        }
        $embedding = null;
        $embPath = $dir . '/' . $id . '.emb';
        if (file_exists($embPath)) {
            $decoded = json_decode((string) file_get_contents($embPath), true);
            if (is_array($decoded)) {
                $embedding = $decoded;
            }
        }

        return Drawer::fromMarkdown($parsed['frontmatter'], $parsed['content'], $embedding);
    }

    public function deleteDrawer(string $wingSlug, Hall $hall, string $roomSlug, string $id): bool
    {
        $dir = $this->roomDir($wingSlug, $hall, $roomSlug) . '/drawers';
        $path = $dir . '/' . $id . '.md';
        if (!file_exists($path)) {
            return false;
        }
        @unlink($path);
        @unlink($dir . '/' . $id . '.emb');

        return true;
    }

    /**
     * Yield all drawers in a scope (optionally filtered by wing+hall+room).
     *
     * Uses a generator to avoid loading thousands of drawers at once.
     *
     * @return \Generator<Drawer>
     */
    public function iterateDrawers(?string $wingSlug = null, ?Hall $hall = null, ?string $roomSlug = null): \Generator
    {
        $wings = $wingSlug !== null ? [$wingSlug] : array_map(fn (Wing $w) => $w->slug, $this->listWings());

        foreach ($wings as $w) {
            $halls = $hall !== null ? [$hall] : Hall::cases();
            foreach ($halls as $h) {
                $roomsBase = $this->wingDir($w) . '/halls/' . $h->value . '/rooms';
                if (!is_dir($roomsBase)) {
                    continue;
                }
                $roomSlugs = $roomSlug !== null
                    ? [$roomSlug]
                    : array_values(array_filter(
                        scandir($roomsBase) ?: [],
                        fn ($e) => $e !== '.' && $e !== '..',
                    ));
                foreach ($roomSlugs as $rSlug) {
                    $drawerDir = $roomsBase . '/' . $rSlug . '/drawers';
                    if (!is_dir($drawerDir)) {
                        continue;
                    }
                    foreach (glob($drawerDir . '/*.md') ?: [] as $file) {
                        $id = pathinfo($file, PATHINFO_FILENAME);
                        $drawer = $this->loadDrawer($w, $h, $rSlug, $id);
                        if ($drawer !== null) {
                            yield $drawer;
                        }
                    }
                }
            }
        }
    }

    // ── Closets ────────────────────────────────────────────────────

    public function saveCloset(Closet $closet): void
    {
        $dir = $this->roomDir($closet->wingSlug, $closet->hall, $closet->roomSlug);
        $this->ensureDir($dir);
        $this->writeJson($dir . '/closet.json', $closet->toArray());
    }

    public function loadCloset(string $wingSlug, Hall $hall, string $roomSlug): ?Closet
    {
        $path = $this->roomDir($wingSlug, $hall, $roomSlug) . '/closet.json';
        if (!file_exists($path)) {
            return null;
        }
        $data = $this->readJson($path);

        return $data ? Closet::fromArray($data) : null;
    }

    // ── Tunnels ────────────────────────────────────────────────────

    /** @return Tunnel[] */
    public function loadTunnels(): array
    {
        $path = $this->basePath . '/tunnels.json';
        if (!file_exists($path)) {
            return [];
        }
        $data = $this->readJson($path) ?? [];

        return array_map(fn ($d) => Tunnel::fromArray($d), $data);
    }

    /** @param Tunnel[] $tunnels */
    public function saveTunnels(array $tunnels): void
    {
        $this->writeJson(
            $this->basePath . '/tunnels.json',
            array_map(fn (Tunnel $t) => $t->toArray(), $tunnels),
        );
    }

    // ── L0/L1 plain files ──────────────────────────────────────────

    public function loadIdentity(): string
    {
        $path = $this->basePath . '/identity.txt';

        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    public function saveIdentity(string $text): void
    {
        file_put_contents($this->basePath . '/identity.txt', $text);
    }

    public function loadCriticalFacts(): string
    {
        $path = $this->basePath . '/critical_facts.md';

        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    public function saveCriticalFacts(string $text): void
    {
        file_put_contents($this->basePath . '/critical_facts.md', $text);
    }

    // ── Paths ──────────────────────────────────────────────────────

    public function wingDir(string $slug): string
    {
        return $this->basePath . '/wings/' . $slug;
    }

    public function roomDir(string $wingSlug, Hall $hall, string $roomSlug): string
    {
        return $this->wingDir($wingSlug) . '/halls/' . $hall->value . '/rooms/' . $roomSlug;
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function writeJson(string $path, mixed $data): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    private function readJson(string $path): ?array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{frontmatter: array<string,mixed>, content: string}|null
     */
    private function parseFrontmatter(string $content): ?array
    {
        if (!str_starts_with($content, '---')) {
            return null;
        }
        $end = strpos($content, "\n---\n", 4);
        if ($end === false) {
            $end = strpos($content, "\r\n---\r\n", 4);
        }
        if ($end === false) {
            return null;
        }
        $fmStr = substr($content, 4, $end - 4);
        $bodyStart = $end + (str_contains($content, "\r\n") ? 7 : 5);
        $body = substr($content, $bodyStart);
        try {
            $fm = Yaml::parse($fmStr);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($fm)) {
            $fm = [];
        }

        return ['frontmatter' => $fm, 'content' => trim($body)];
    }
}
