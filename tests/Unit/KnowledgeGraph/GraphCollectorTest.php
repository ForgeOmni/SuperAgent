<?php

namespace SuperAgent\Tests\Unit\KnowledgeGraph;

use PHPUnit\Framework\TestCase;
use SuperAgent\KnowledgeGraph\EdgeType;
use SuperAgent\KnowledgeGraph\GraphCollector;
use SuperAgent\KnowledgeGraph\KnowledgeGraph;
use SuperAgent\KnowledgeGraph\NodeType;

class GraphCollectorTest extends TestCase
{
    private KnowledgeGraph $graph;
    private GraphCollector $collector;

    protected function setUp(): void
    {
        $this->graph = new KnowledgeGraph(null);
        $this->collector = new GraphCollector($this->graph, 'test-agent');
    }

    public function test_record_read(): void
    {
        $this->collector->recordToolCall('Read', ['file_path' => '/src/App.php']);

        $this->assertNotNull($this->graph->findNode(NodeType::FILE, '/src/App.php'));
        $this->assertNotNull($this->graph->findNode(NodeType::AGENT, 'test-agent'));

        $edges = $this->graph->getEdgesByType(EdgeType::READ);
        $this->assertCount(1, $edges);
        $this->assertSame('test-agent', $edges[0]->agentName);
    }

    public function test_record_edit(): void
    {
        $this->collector->recordToolCall('Edit', ['file_path' => '/src/App.php']);

        $edges = $this->graph->getEdgesByType(EdgeType::MODIFIED);
        $this->assertCount(1, $edges);
    }

    public function test_record_write(): void
    {
        $this->collector->recordToolCall('Write', ['file_path' => '/src/New.php']);

        $edges = $this->graph->getEdgesByType(EdgeType::CREATED);
        $this->assertCount(1, $edges);
    }

    public function test_record_bash(): void
    {
        $this->collector->recordToolCall('Bash', ['command' => 'phpunit tests/']);

        $tools = $this->graph->getNodes(NodeType::TOOL);
        $this->assertCount(1, $tools);
        $this->assertStringContainsString('phpunit', $tools[0]->label);

        $edges = $this->graph->getEdgesByType(EdgeType::EXECUTED);
        $this->assertCount(1, $edges);
    }

    public function test_record_grep(): void
    {
        $output = "/src/App.php:10:class App\n/src/Helper.php:5:function help";
        $this->collector->recordToolCall('Grep', ['pattern' => 'class'], $output);

        $files = $this->graph->getNodes(NodeType::FILE);
        $this->assertCount(2, $files);

        $edges = $this->graph->getEdgesByType(EdgeType::SEARCHED);
        $this->assertCount(2, $edges);
    }

    public function test_record_glob(): void
    {
        $output = "/src/A.php\n/src/B.php\n/src/C.php";
        $this->collector->recordToolCall('Glob', ['pattern' => '**/*.php'], $output);

        $files = $this->graph->getNodes(NodeType::FILE);
        $this->assertCount(3, $files);
    }

    public function test_skip_error_tool_calls(): void
    {
        $this->collector->recordToolCall('Read', ['file_path' => '/error.php'], '', isError: true);

        $this->assertEmpty($this->graph->getNodes(NodeType::FILE));
    }

    public function test_record_decision(): void
    {
        $this->collector->recordDecision('Use singleton pattern for DB connection');

        $decisions = $this->graph->getDecisions();
        $this->assertCount(1, $decisions);
        $this->assertSame('Use singleton pattern for DB connection', $decisions[0]->label);

        $edges = $this->graph->getEdgesByType(EdgeType::DECIDED);
        $this->assertCount(1, $edges);
    }

    public function test_record_dependency(): void
    {
        $this->collector->recordDependency('/src/Controller.php', '/src/Service.php');

        $edges = $this->graph->getEdgesByType(EdgeType::DEPENDS_ON);
        $this->assertCount(1, $edges);
        $this->assertSame('file:/src/Controller.php', $edges[0]->sourceId);
        $this->assertSame('file:/src/Service.php', $edges[0]->targetId);
    }

    public function test_record_symbol(): void
    {
        $this->collector->recordSymbol('UserController', '/src/UserController.php', ['type' => 'class']);

        $symbols = $this->graph->getNodes(NodeType::SYMBOL);
        $this->assertCount(1, $symbols);

        $edges = $this->graph->getEdgesByType(EdgeType::DEFINED_IN);
        $this->assertCount(1, $edges);
    }

    public function test_set_agent_name(): void
    {
        $this->collector->setAgentName('reviewer');
        $this->collector->recordToolCall('Read', ['file_path' => '/a.php']);

        $agents = $this->graph->getNodes(NodeType::AGENT);
        $this->assertCount(1, $agents);
        $this->assertSame('reviewer', $agents[0]->label);
    }

    public function test_multiple_agents(): void
    {
        $this->collector->setAgentName('agent-1');
        $this->collector->recordToolCall('Read', ['file_path' => '/shared.php']);

        $this->collector->setAgentName('agent-2');
        $this->collector->recordToolCall('Edit', ['file_path' => '/shared.php']);

        $agents = $this->graph->getAgentsForFile('/shared.php');
        $this->assertCount(2, $agents);
        $this->assertContains('agent-1', $agents);
        $this->assertContains('agent-2', $agents);
    }

    public function test_skip_missing_input(): void
    {
        $this->collector->recordToolCall('Read', []); // No file_path
        $this->collector->recordToolCall('Edit', []); // No file_path
        $this->collector->recordToolCall('Bash', []); // No command

        // Only agent node should exist
        $this->assertCount(1, $this->graph->getNodes());
    }
}
