<?php

declare(strict_types=1);

namespace Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use SuperAgent\Memory\Palace\Drawer;
use SuperAgent\Memory\Palace\Hall;
use SuperAgent\Memory\Palace\MemoryDeduplicator;
use SuperAgent\Memory\Palace\PalaceFactory;
use SuperAgent\Memory\Palace\Room;
use SuperAgent\Memory\Palace\Wing;
use SuperAgent\Memory\Palace\WingType;

/**
 * Smoke tests for the Memory Palace: value objects, storage round-trip,
 * tunnel auto-creation, dedup, retrieval, diary, fact checker.
 */
class PalaceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/palace_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function test_storage_roundtrips_a_drawer_with_wing_and_room(): void
    {
        $bundle = PalaceFactory::make($this->tmpDir);

        $wing = new Wing('wing_demo', 'Demo', WingType::PROJECT, ['demo']);
        $bundle->storage->saveWing($wing);

        $room = new Room('auth', 'Auth', 'wing_demo', Hall::FACTS);
        $bundle->storage->saveRoom($room);
        $bundle->graph->recordRoom($room);

        $drawer = new Drawer(
            id: 'drw_one',
            wingSlug: 'wing_demo',
            hall: Hall::FACTS,
            roomSlug: 'auth',
            content: 'We chose Clerk over Auth0 for pricing and DX.',
        );
        $bundle->storage->saveDrawer($drawer);

        $loaded = $bundle->storage->loadDrawer('wing_demo', Hall::FACTS, 'auth', 'drw_one');
        self::assertNotNull($loaded);
        self::assertSame($drawer->content, $loaded->content);
        self::assertSame($drawer->contentHash, $loaded->contentHash);
    }

    public function test_graph_creates_auto_tunnel_across_wings_with_same_room(): void
    {
        $bundle = PalaceFactory::make($this->tmpDir);

        $bundle->storage->saveWing(new Wing('wing_kai', 'Kai', WingType::PERSON, ['kai']));
        $bundle->storage->saveWing(new Wing('wing_driftwood', 'Driftwood', WingType::PROJECT, ['drift']));

        $roomA = new Room('auth-migration', 'Auth Migration', 'wing_kai', Hall::EVENTS);
        $roomB = new Room('auth-migration', 'Auth Migration', 'wing_driftwood', Hall::FACTS);
        $bundle->storage->saveRoom($roomA);
        $bundle->storage->saveRoom($roomB);
        $bundle->graph->recordRoom($roomA);
        $bundle->graph->recordRoom($roomB);

        $tunnels = $bundle->graph->tunnelsFromRoom('wing_kai', 'auth-migration');
        self::assertCount(1, $tunnels);
        self::assertTrue($tunnels[0]->auto);
    }

    public function test_retriever_scopes_results_to_wing_filter(): void
    {
        $bundle = PalaceFactory::make($this->tmpDir);
        $bundle->storage->saveWing(new Wing('wing_a', 'A', WingType::PROJECT));
        $bundle->storage->saveWing(new Wing('wing_b', 'B', WingType::PROJECT));

        $bundle->storage->saveRoom(new Room('x', 'X', 'wing_a', Hall::FACTS));
        $bundle->storage->saveRoom(new Room('x', 'X', 'wing_b', Hall::FACTS));

        $bundle->storage->saveDrawer(new Drawer(
            id: 'a1', wingSlug: 'wing_a', hall: Hall::FACTS, roomSlug: 'x',
            content: 'apple banana cherry'
        ));
        $bundle->storage->saveDrawer(new Drawer(
            id: 'b1', wingSlug: 'wing_b', hall: Hall::FACTS, roomSlug: 'x',
            content: 'apple banana cherry'
        ));

        $resultsA = $bundle->retriever->search('apple banana', 5, ['wing' => 'wing_a']);
        self::assertCount(1, $resultsA);
        self::assertSame('a1', $resultsA[0]['drawer']->id);
    }

    public function test_deduplicator_detects_near_duplicates_by_jaccard(): void
    {
        $bundle = PalaceFactory::make($this->tmpDir);
        $bundle->storage->saveWing(new Wing('wing_demo', 'Demo', WingType::PROJECT));
        $bundle->storage->saveRoom(new Room('notes', 'Notes', 'wing_demo', Hall::EVENTS));

        $text = 'The quick brown fox jumps over the lazy dog twice in the morning light';
        $bundle->storage->saveDrawer(new Drawer(
            id: 'orig', wingSlug: 'wing_demo', hall: Hall::EVENTS, roomSlug: 'notes', content: $text,
        ));

        $dedup = new MemoryDeduplicator($bundle->storage, threshold: 0.7);
        $candidate = new Drawer(
            id: 'new', wingSlug: 'wing_demo', hall: Hall::EVENTS, roomSlug: 'notes',
            content: 'The quick brown fox jumps over the lazy dog twice in the morning',
        );
        self::assertNotNull($dedup->findDuplicate($candidate));
    }

    public function test_agent_diary_stores_and_reads_entries(): void
    {
        $bundle = PalaceFactory::make($this->tmpDir);

        $bundle->diary->write('reviewer', 'PR#42 missing middleware check');
        $bundle->diary->write('reviewer', 'PR#43 rate limit bypass', ['severity' => 'high']);

        $entries = $bundle->diary->read('reviewer', 10);
        self::assertCount(2, $entries);
        self::assertStringContainsString('PR#', $entries[0]->content);
    }

    public function test_temporal_knowledge_graph_invalidation(): void
    {
        $graph = new \SuperAgent\KnowledgeGraph\KnowledgeGraph($this->tmpDir . '/kg.json');

        $graph->addTriple('Kai', 'works_on', 'Orion', validFrom: '2025-06-01T00:00:00+00:00');
        $graph->invalidate('Kai', 'works_on', 'Orion', endedAt: '2026-03-01T00:00:00+00:00');

        $currentEdges = array_filter(
            $graph->queryEntity('Kai', asOf: '2026-04-01T00:00:00+00:00'),
            fn ($e) => ($e->metadata['relation'] ?? null) === 'works_on',
        );
        self::assertCount(0, $currentEdges);

        $historicEdges = array_filter(
            $graph->queryEntity('Kai', asOf: '2025-12-01T00:00:00+00:00'),
            fn ($e) => ($e->metadata['relation'] ?? null) === 'works_on',
        );
        self::assertCount(1, $historicEdges);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
