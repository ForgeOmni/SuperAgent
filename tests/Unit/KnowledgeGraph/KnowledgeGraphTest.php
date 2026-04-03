<?php

namespace SuperAgent\Tests\Unit\KnowledgeGraph;

use PHPUnit\Framework\TestCase;
use SuperAgent\KnowledgeGraph\EdgeType;
use SuperAgent\KnowledgeGraph\KnowledgeGraph;
use SuperAgent\KnowledgeGraph\NodeType;

class KnowledgeGraphTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'kg_test_');
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    // ── Node Operations ────────────────────────────────────────────

    public function test_add_and_get_node(): void
    {
        $graph = new KnowledgeGraph(null);
        $node = $graph->addNode(NodeType::FILE, '/src/App.php', ['lang' => 'php']);

        $this->assertSame('file:/src/App.php', $node->id);
        $this->assertSame(NodeType::FILE, $node->type);
        $this->assertSame('/src/App.php', $node->label);
        $this->assertSame('php', $node->metadata['lang']);

        $found = $graph->getNode($node->id);
        $this->assertNotNull($found);
    }

    public function test_add_existing_node_touches_it(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/src/App.php');
        $node = $graph->addNode(NodeType::FILE, '/src/App.php', ['extra' => 'data']);

        $this->assertSame(1, $node->accessCount); // touch() increments from 0
        $this->assertSame('data', $node->metadata['extra']);
        $this->assertCount(1, $graph->getNodes(NodeType::FILE));
    }

    public function test_find_node(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::AGENT, 'reviewer');

        $this->assertNotNull($graph->findNode(NodeType::AGENT, 'reviewer'));
        $this->assertNull($graph->findNode(NodeType::AGENT, 'nonexistent'));
    }

    public function test_get_nodes_by_type(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/a.php');
        $graph->addNode(NodeType::FILE, '/b.php');
        $graph->addNode(NodeType::AGENT, 'worker');

        $this->assertCount(2, $graph->getNodes(NodeType::FILE));
        $this->assertCount(1, $graph->getNodes(NodeType::AGENT));
        $this->assertCount(3, $graph->getNodes());
    }

    // ── Edge Operations ────────────────────────────────────────────

    public function test_add_and_get_edges(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::AGENT, 'worker');
        $graph->addNode(NodeType::FILE, '/a.php');

        $edge = $graph->addEdge('agent:worker', 'file:/a.php', EdgeType::MODIFIED, 'worker');

        $this->assertSame('agent:worker', $edge->sourceId);
        $this->assertSame(EdgeType::MODIFIED, $edge->type);
    }

    public function test_add_edge_deduplicates(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addEdge('a', 'b', EdgeType::READ, 'w1');
        $graph->addEdge('a', 'b', EdgeType::READ, 'w2'); // Same key, updated

        $edges = $graph->getEdgesFrom('a');
        $this->assertCount(1, $edges);
        $this->assertSame('w2', $edges[0]->agentName); // Updated
    }

    public function test_get_edges_from(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addEdge('a', 'b', EdgeType::READ);
        $graph->addEdge('a', 'c', EdgeType::MODIFIED);
        $graph->addEdge('x', 'y', EdgeType::READ);

        $this->assertCount(2, $graph->getEdgesFrom('a'));
        $this->assertCount(1, $graph->getEdgesFrom('a', EdgeType::READ));
    }

    public function test_get_edges_to(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addEdge('a', 'target', EdgeType::READ);
        $graph->addEdge('b', 'target', EdgeType::MODIFIED);

        $this->assertCount(2, $graph->getEdgesTo('target'));
        $this->assertCount(1, $graph->getEdgesTo('target', EdgeType::MODIFIED));
    }

    public function test_get_edges_by_agent(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addEdge('a', 'b', EdgeType::READ, 'agent-1');
        $graph->addEdge('c', 'd', EdgeType::MODIFIED, 'agent-2');
        $graph->addEdge('e', 'f', EdgeType::READ, 'agent-1');

        $this->assertCount(2, $graph->getEdgesByAgent('agent-1'));
    }

    // ── Query API ──────────────────────────────────────────────────

    public function test_get_files_modified_by(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/a.php');
        $graph->addNode(NodeType::FILE, '/b.php');
        $graph->addEdge('agent:w1', 'file:/a.php', EdgeType::MODIFIED, 'w1');
        $graph->addEdge('agent:w1', 'file:/b.php', EdgeType::CREATED, 'w1');
        $graph->addEdge('agent:w2', 'file:/a.php', EdgeType::READ, 'w2'); // Only read

        $files = $graph->getFilesModifiedBy('w1');
        $this->assertCount(2, $files);
        $this->assertContains('/a.php', $files);
        $this->assertContains('/b.php', $files);

        $this->assertEmpty($graph->getFilesModifiedBy('w2'));
    }

    public function test_get_agents_for_file(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/a.php');
        $graph->addEdge('agent:w1', 'file:/a.php', EdgeType::READ, 'w1');
        $graph->addEdge('agent:w2', 'file:/a.php', EdgeType::MODIFIED, 'w2');

        $agents = $graph->getAgentsForFile('/a.php');
        $this->assertCount(2, $agents);
        $this->assertContains('w1', $agents);
        $this->assertContains('w2', $agents);
    }

    public function test_get_hot_files(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/hot.php');
        $graph->addNode(NodeType::FILE, '/hot.php'); // touch
        $graph->addNode(NodeType::FILE, '/hot.php'); // touch
        $graph->addNode(NodeType::FILE, '/cold.php');

        $hot = $graph->getHotFiles(2);
        $this->assertCount(2, $hot);
        $this->assertSame('/hot.php', $hot[0]['file']);
        $this->assertSame(2, $hot[0]['access_count']); // 2 touches (initial add has count=0)
    }

    public function test_search_nodes(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/src/UserController.php');
        $graph->addNode(NodeType::FILE, '/src/OrderService.php');
        $graph->addNode(NodeType::DECISION, 'Use repository pattern');

        $results = $graph->searchNodes('user');
        $this->assertCount(1, $results);

        $results = $graph->searchNodes('pattern', NodeType::DECISION);
        $this->assertCount(1, $results);
    }

    public function test_get_decisions(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::DECISION, 'Use singleton');
        $graph->addNode(NodeType::DECISION, 'Add caching');
        $graph->addNode(NodeType::FILE, '/a.php');

        $decisions = $graph->getDecisions();
        $this->assertCount(2, $decisions);
    }

    public function test_get_summary(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/a.php');
        $graph->addNode(NodeType::AGENT, 'worker');
        $graph->addNode(NodeType::DECISION, 'Use factory pattern');
        $graph->addEdge('agent:worker', 'file:/a.php', EdgeType::MODIFIED, 'worker');

        $summary = $graph->getSummary();
        $this->assertStringContainsString('1 files', $summary);
        $this->assertStringContainsString('1 agents', $summary);
        $this->assertStringContainsString('factory pattern', $summary);
    }

    // ── Persistence ────────────────────────────────────────────────

    public function test_persistence(): void
    {
        $g1 = new KnowledgeGraph($this->tempFile);
        $g1->addNode(NodeType::FILE, '/persistent.php');
        $g1->addEdge('a', 'b', EdgeType::READ, 'w1');
        unset($g1);

        $g2 = new KnowledgeGraph($this->tempFile);
        $this->assertCount(1, $g2->getNodes(NodeType::FILE));
        $this->assertNotNull($g2->findNode(NodeType::FILE, '/persistent.php'));
    }

    // ── Clear / Statistics ─────────────────────────────────────────

    public function test_clear(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/a.php');
        $graph->addEdge('a', 'b', EdgeType::READ);

        $count = $graph->clear();
        $this->assertSame(2, $count);
        $this->assertEmpty($graph->getNodes());
    }

    public function test_statistics(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/a.php');
        $graph->addNode(NodeType::AGENT, 'w1');
        $graph->addEdge('agent:w1', 'file:/a.php', EdgeType::MODIFIED);

        $stats = $graph->getStatistics();
        $this->assertSame(2, $stats['total_nodes']);
        $this->assertSame(1, $stats['total_edges']);
        $this->assertArrayHasKey('file', $stats['nodes_by_type']);
        $this->assertArrayHasKey('modified', $stats['edges_by_type']);
    }

    // ── Export / Import ────────────────────────────────────────────

    public function test_export_and_import(): void
    {
        $g1 = new KnowledgeGraph(null);
        $g1->addNode(NodeType::FILE, '/export.php');
        $g1->addEdge('a', 'b', EdgeType::READ, 'w1');

        $exported = $g1->export();
        $this->assertSame('1.0', $exported['version']);

        $g2 = new KnowledgeGraph(null);
        $imported = $g2->import($exported);
        $this->assertSame(2, $imported); // 1 node + 1 edge
        $this->assertNotNull($g2->findNode(NodeType::FILE, '/export.php'));
    }

    public function test_import_skips_duplicates(): void
    {
        $graph = new KnowledgeGraph(null);
        $graph->addNode(NodeType::FILE, '/dup.php');

        $data = $graph->export();
        $imported = $graph->import($data);
        $this->assertSame(0, $imported);
    }
}
