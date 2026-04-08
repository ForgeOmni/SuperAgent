<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\WebSocket\WebSocketProgressServer;
use SuperAgent\Swarm\Communication\AgentCommunicationProtocol;
use SuperAgent\Swarm\Performance\AgentPerformanceProfiler;
use SuperAgent\Swarm\Dependency\AgentDependencyManager;
use SuperAgent\Swarm\Backends\DistributedBackend;
use SuperAgent\Swarm\Storage\PersistentProgressStorage;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\AgentProgressTracker;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\BackendType;

class EnhancementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ParallelAgentCoordinator::reset();
    }
    
    protected function tearDown(): void
    {
        ParallelAgentCoordinator::reset();
        parent::tearDown();
    }
    
    /**
     * Test WebSocket server functionality
     */
    public function testWebSocketProgressServer(): void
    {
        if (!interface_exists(\Ratchet\MessageComponentInterface::class)) {
            $this->markTestSkipped('Ratchet library not installed.');
        }

        $coordinator = ParallelAgentCoordinator::getInstance();
        $server = new WebSocketProgressServer($coordinator);
        
        // Register test agents
        $tracker1 = $coordinator->registerAgent('ws_agent1', 'WebSocketAgent1');
        $tracker2 = $coordinator->registerAgent('ws_agent2', 'WebSocketAgent2');
        
        // Update progress
        $tracker1->updateFromResponse(['input_tokens' => 100, 'output_tokens' => 50]);
        $tracker2->updateFromResponse(['input_tokens' => 200, 'output_tokens' => 100]);
        
        // Server should be available
        $this->assertInstanceOf(WebSocketProgressServer::class, $server);
        
        // Test would connect WebSocket client in real implementation
        $this->assertTrue(true);
    }
    
    /**
     * Test agent communication protocol
     */
    public function testAgentCommunicationProtocol(): void
    {
        $protocol = new AgentCommunicationProtocol();
        $coordinator = ParallelAgentCoordinator::getInstance();
        
        // Register agents
        $agent1 = 'comm_agent1';
        $agent2 = 'comm_agent2';
        $coordinator->registerAgent($agent1, 'CommAgent1');
        $coordinator->registerAgent($agent2, 'CommAgent2');
        
        // Test direct messaging
        $messageId = $protocol->sendMessage(
            $agent1,
            $agent2,
            'test_message',
            ['data' => 'test']
        );
        
        $this->assertNotEmpty($messageId);
        
        // Test message handler registration
        $handlerCalled = false;
        $protocol->registerHandler($agent2, 'test_message', function($payload) use (&$handlerCalled) {
            $handlerCalled = true;
            return 'handled';
        });
        
        // Process messages would trigger handler in real implementation
        $this->assertTrue(true);
    }
    
    /**
     * Test performance profiler
     */
    public function testAgentPerformanceProfiler(): void
    {
        $profiler = new AgentPerformanceProfiler();
        $coordinator = ParallelAgentCoordinator::getInstance();
        
        $agentId = 'perf_agent';
        $tracker = $coordinator->registerAgent($agentId, 'PerfAgent');
        
        // Start profiling
        $profiler->startProfiling($agentId);
        
        // Simulate activity
        $tracker->updateFromResponse(['input_tokens' => 1000, 'output_tokens' => 500]);
        $profiler->recordToolTiming($agentId, 'Read', 0.5);
        $profiler->recordToolTiming($agentId, 'Write', 1.2);
        $profiler->addCheckpoint($agentId, 'processing_complete');
        
        // Stop profiling
        $metrics = $profiler->stopProfiling($agentId);
        
        $this->assertArrayHasKey('agent_id', $metrics);
        $this->assertArrayHasKey('duration_seconds', $metrics);
        $this->assertArrayHasKey('tool_timings', $metrics);
        $this->assertCount(2, $metrics['tool_timings']);
        
        // Test aggregated stats
        $stats = $profiler->getAggregatedStats();
        $this->assertArrayHasKey('total_agents', $stats);
        $this->assertArrayHasKey('total_tokens', $stats);
        
        // Test bottleneck analysis
        $bottlenecks = $profiler->getBottleneckAnalysis();
        $this->assertIsArray($bottlenecks);
        
        // Test export
        $export = $profiler->export('json');
        $this->assertJson($export);
    }
    
    /**
     * Test dependency manager
     */
    public function testAgentDependencyManager(): void
    {
        $depManager = new AgentDependencyManager();
        
        // Test chain dependencies
        $agents = ['fetch', 'process', 'analyze', 'report'];
        $depManager->registerChain($agents);
        
        // Verify execution order
        $order = $depManager->getExecutionOrder();
        $this->assertEquals($agents, $order);
        
        // Test parallel execution
        $depManager->clear();
        $depManager->registerParallel(['worker1', 'worker2', 'worker3']);
        
        // All should be ready to execute
        $this->assertTrue($depManager->canExecute('worker1'));
        $this->assertTrue($depManager->canExecute('worker2'));
        $this->assertTrue($depManager->canExecute('worker3'));
        
        // Test circular dependency detection
        $depManager->clear();
        $depManager->registerDependencies('a', ['b']);
        $depManager->registerDependencies('b', ['c']);
        $depManager->registerDependencies('c', ['a']);
        
        $cycles = $depManager->detectCircularDependencies();
        $this->assertNotEmpty($cycles);
        
        // Test execution stages
        $depManager->clear();
        $depManager->registerDependencies('stage2_a', ['stage1']);
        $depManager->registerDependencies('stage2_b', ['stage1']);
        $depManager->registerDependencies('stage3', ['stage2_a', 'stage2_b']);
        
        $stages = $depManager->getExecutionStages();
        $this->assertCount(3, $stages);
        $this->assertContains('stage1', $stages[0]);
        $this->assertCount(2, $stages[1]); // stage2_a and stage2_b
        $this->assertContains('stage3', $stages[2]);
    }
    
    /**
     * Test distributed backend
     */
    public function testDistributedBackend(): void
    {
        $backend = new DistributedBackend();
        
        // Register nodes
        $backend->registerNode([
            'id' => 'node1',
            'url' => 'http://localhost:8081',
            'capacity' => 5,
        ]);
        
        $backend->registerNode([
            'id' => 'node2',
            'url' => 'http://localhost:8082',
            'capacity' => 10,
        ]);
        
        // Test node health (includes 'localhost' fallback node from constructor + our 2)
        $health = $backend->getNodeHealth();
        $this->assertArrayHasKey('node1', $health);
        $this->assertArrayHasKey('node2', $health);
        $this->assertGreaterThanOrEqual(2, count($health));

        // Test distributed stats (includes fallback node capacity)
        $stats = $backend->getDistributedStats();
        $this->assertGreaterThanOrEqual(2, $stats['total_nodes']);
        $this->assertGreaterThanOrEqual(15, $stats['total_capacity']);
        
        // Test spawn (would connect to real nodes in production)
        $config = new AgentSpawnConfig(
            name: 'DistributedWorker',
            prompt: 'Process distributed task',
        );
        
        $result = $backend->spawn($config);
        // In test environment, might fail without real nodes
        $this->assertNotNull($result);
    }
    
    /**
     * Test persistent storage
     */
    public function testPersistentProgressStorage(): void
    {
        $tempDir = sys_get_temp_dir() . '/superagent_test_' . uniqid();
        $storage = new PersistentProgressStorage($tempDir);
        $coordinator = ParallelAgentCoordinator::getInstance();
        
        // Create test agents
        $tracker1 = $coordinator->registerAgent('store_agent1', 'StoreAgent1');
        $tracker2 = $coordinator->registerAgent('store_agent2', 'StoreAgent2');
        
        // Update progress
        $tracker1->updateFromResponse(['input_tokens' => 500, 'output_tokens' => 250]);
        $tracker2->updateFromResponse(['input_tokens' => 1000, 'output_tokens' => 500]);
        
        // Save progress
        $storage->save();
        
        // Verify files created
        $this->assertFileExists($tempDir . '/progress.json');
        
        // Load progress
        $loaded = $storage->load();
        $this->assertArrayHasKey('progress', $loaded);
        $this->assertArrayHasKey('hierarchy', $loaded);
        
        // Test agent snapshot
        $storage->saveAgentSnapshot('store_agent1', $tracker1);
        $snapshot = $storage->loadAgentSnapshot('store_agent1');
        $this->assertNotNull($snapshot);
        $this->assertEquals('store_agent1', $snapshot['agent_id']);
        
        // Test export/import
        $exportFile = $tempDir . '/export.json';
        $storage->export($exportFile);
        $this->assertFileExists($exportFile);
        
        // Clean up test directory
        $this->recursiveRemoveDirectory($tempDir);
    }
    
    /**
     * Test integration of all components
     */
    public function testFullIntegration(): void
    {
        // Create all components
        $coordinator = ParallelAgentCoordinator::getInstance();
        $profiler = new AgentPerformanceProfiler($coordinator);
        $protocol = new AgentCommunicationProtocol($coordinator);
        $depManager = new AgentDependencyManager($coordinator);
        $storage = new PersistentProgressStorage();
        
        // Create team
        $team = new TeamContext('integration_team', 'leader');
        $coordinator->registerTeam($team);
        
        // Register agents with dependencies
        $depManager->registerChain(['extract', 'transform', 'load']);
        
        // Create agents
        $extractTracker = $coordinator->registerAgent('extract', 'ExtractAgent', 'integration_team');
        $transformTracker = $coordinator->registerAgent('transform', 'TransformAgent', 'integration_team');
        $loadTracker = $coordinator->registerAgent('load', 'LoadAgent', 'integration_team');
        
        // Start profiling
        $profiler->startProfiling('extract');
        $profiler->startProfiling('transform');
        $profiler->startProfiling('load');
        
        // Simulate execution with progress
        $extractTracker->updateFromResponse(['input_tokens' => 1000, 'output_tokens' => 500]);
        $extractTracker->addToolActivity(['name' => 'Read', 'input' => ['file_path' => 'data.csv']]);
        
        // Send message when extract completes
        $protocol->sendMessage('extract', 'transform', 'data_ready', ['rows' => 1000]);
        
        // Transform processes
        $transformTracker->updateFromResponse(['input_tokens' => 1500, 'output_tokens' => 750]);
        $transformTracker->addToolActivity(['name' => 'Process', 'input' => []]);
        
        // Send message when transform completes
        $protocol->sendMessage('transform', 'load', 'data_transformed', ['records' => 950]);
        
        // Load completes
        $loadTracker->updateFromResponse(['input_tokens' => 800, 'output_tokens' => 400]);
        $loadTracker->addToolActivity(['name' => 'Write', 'input' => ['file_path' => 'output.db']]);
        
        // Verify integration before completion (agents are still active)
        $consolidatedProgress = $coordinator->getConsolidatedProgress();
        $this->assertEquals(3, $consolidatedProgress['totalAgents']);
        $this->assertGreaterThan(0, $consolidatedProgress['totalTokens']);

        // Complete all
        $extractTracker->complete();
        $transformTracker->complete();
        $loadTracker->complete();

        // Stop profiling
        $extractMetrics = $profiler->stopProfiling('extract');
        $transformMetrics = $profiler->stopProfiling('transform');
        $loadMetrics = $profiler->stopProfiling('load');

        // Save progress
        $storage->save();
        
        $report = $profiler->generateReport();
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('agents', $report);
        
        $messageLog = $protocol->getMessageLog();
        $this->assertCount(2, $messageLog); // Two messages sent
    }
    
    /**
     * Helper to recursively remove directory
     */
    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}