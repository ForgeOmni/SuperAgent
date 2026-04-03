<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\AgentProgressTracker;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Console\Output\ParallelAgentDisplay;
use Symfony\Component\Console\Output\BufferedOutput;

class ParallelAgentTrackingTest extends TestCase
{
    private ParallelAgentCoordinator $coordinator;
    private InProcessBackend $backend;
    
    protected function setUp(): void
    {
        parent::setUp();
        ParallelAgentCoordinator::reset();
        $this->coordinator = ParallelAgentCoordinator::getInstance();
        $this->backend = new InProcessBackend();
    }
    
    protected function tearDown(): void
    {
        ParallelAgentCoordinator::reset();
        parent::tearDown();
    }
    
    public function testAgentProgressTracking(): void
    {
        $tracker = new AgentProgressTracker('agent_1', 'TestAgent');
        
        // Simulate token usage update
        $tracker->updateFromResponse([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_creation_input_tokens' => 10,
            'cache_read_input_tokens' => 5,
        ]);
        
        $this->assertEquals(165, $tracker->getTotalTokens());
        
        // Add tool activity
        $tracker->addToolActivity([
            'name' => 'Read',
            'input' => ['file_path' => '/test/file.txt'],
        ]);
        
        $progress = $tracker->getProgress();
        $this->assertEquals('agent_1', $progress['agentId']);
        $this->assertEquals('TestAgent', $progress['agentName']);
        $this->assertEquals(1, $progress['toolUseCount']);
        $this->assertEquals(165, $progress['tokenCount']);
        $this->assertStringContainsString('Reading /test/file.txt', $progress['currentActivity']);
    }
    
    public function testParallelAgentCoordination(): void
    {
        // Register multiple agents
        $tracker1 = $this->coordinator->registerAgent('agent_1', 'Agent1');
        $tracker2 = $this->coordinator->registerAgent('agent_2', 'Agent2');
        $tracker3 = $this->coordinator->registerAgent('agent_3', 'Agent3');
        
        // Update their progress
        $tracker1->updateFromResponse(['input_tokens' => 100, 'output_tokens' => 50]);
        $tracker2->updateFromResponse(['input_tokens' => 200, 'output_tokens' => 100]);
        $tracker3->updateFromResponse(['input_tokens' => 150, 'output_tokens' => 75]);
        
        // Add activities
        $tracker1->addToolActivity(['name' => 'Bash', 'input' => ['command' => 'ls -la']]);
        $tracker2->addToolActivity(['name' => 'Grep', 'input' => ['pattern' => 'test']]);
        
        // Get consolidated progress
        $progress = $this->coordinator->getConsolidatedProgress();
        
        $this->assertEquals(3, $progress['totalAgents']);
        $this->assertEquals(675, $progress['totalTokens']); // 150 + 300 + 225
        $this->assertEquals(2, $progress['totalToolUses']);
    }
    
    public function testTeamTracking(): void
    {
        // Create a team
        $team = new TeamContext('team_alpha', 'leader_1');
        $this->coordinator->registerTeam($team);
        
        // Register agents with team
        $tracker1 = $this->coordinator->registerAgent('agent_1', 'Worker1', 'team_alpha');
        $tracker2 = $this->coordinator->registerAgent('agent_2', 'Worker2', 'team_alpha');
        
        // Register standalone agent
        $tracker3 = $this->coordinator->registerAgent('agent_3', 'Standalone');
        
        // Get hierarchical display
        $display = $this->coordinator->getHierarchicalDisplay();
        
        $this->assertCount(2, $display); // Team + Standalone group
        $this->assertEquals('team', $display[0]['type']);
        $this->assertEquals('team_alpha', $display[0]['name']);
        $this->assertCount(2, $display[0]['members']);
        
        $this->assertEquals('standalone', $display[1]['type']);
        $this->assertCount(1, $display[1]['members']);
    }
    
    public function testConsoleDisplay(): void
    {
        // Register agents with activities
        $tracker1 = $this->coordinator->registerAgent('agent_1', 'DataProcessor');
        $tracker1->updateFromResponse(['input_tokens' => 1500, 'output_tokens' => 800]);
        $tracker1->addToolActivity(['name' => 'Read', 'input' => ['file_path' => 'data.csv']]);
        
        $tracker2 = $this->coordinator->registerAgent('agent_2', 'CodeAnalyzer');
        $tracker2->updateFromResponse(['input_tokens' => 3000, 'output_tokens' => 1200]);
        $tracker2->addToolActivity(['name' => 'Grep', 'input' => ['pattern' => 'function.*test']]);
        
        // Create buffered output
        $output = new BufferedOutput();
        $display = new ParallelAgentDisplay($output, $this->coordinator, false);
        
        // Display the agents
        $display->display();
        
        $content = $output->fetch();
        
        // Check output contains expected elements
        $this->assertStringContainsString('Agents: 2', $content);
        $this->assertStringContainsString('Total Tokens:', $content);
        $this->assertStringContainsString('DataProcessor', $content);
        $this->assertStringContainsString('CodeAnalyzer', $content);
        $this->assertStringContainsString('Reading data.csv', $content);
        $this->assertStringContainsString('Searching for: function.*test', $content);
    }
    
    public function testInProcessBackendIntegration(): void
    {
        // Create team context
        $team = new TeamContext('test_team', 'leader');
        $this->backend->setTeamContext($team);
        
        // Spawn multiple agents
        $config1 = new AgentSpawnConfig(
            name: 'Worker1',
            prompt: 'Process data',
            teamName: 'test_team',
            runInBackground: false,
        );
        
        $config2 = new AgentSpawnConfig(
            name: 'Worker2',
            prompt: 'Analyze code',
            teamName: 'test_team',
            runInBackground: false,
        );
        
        $result1 = $this->backend->spawn($config1);
        $result2 = $this->backend->spawn($config2);
        
        $this->assertTrue($result1->success);
        $this->assertTrue($result2->success);
        
        // Get parallel progress
        $progress = $this->backend->getParallelProgress();
        
        $this->assertEquals(2, $progress['totalAgents']);
        $this->assertArrayHasKey('agents', $progress);
        $this->assertCount(2, $progress['agents']);
        
        // Get hierarchical display
        $display = $this->backend->getHierarchicalDisplay();
        
        $this->assertCount(1, $display);
        $this->assertEquals('team', $display[0]['type']);
        $this->assertEquals('test_team', $display[0]['name']);
        $this->assertCount(2, $display[0]['members']);
    }
    
    public function testActivityDescriptions(): void
    {
        $tracker = new AgentProgressTracker('test', 'TestAgent');
        
        // Test various tool activities
        $toolTests = [
            ['name' => 'Read', 'input' => ['file_path' => 'test.txt'], 'expected' => 'Reading test.txt'],
            ['name' => 'Write', 'input' => ['file_path' => 'output.txt'], 'expected' => 'Writing output.txt'],
            ['name' => 'Edit', 'input' => ['file_path' => 'config.yml'], 'expected' => 'Editing config.yml'],
            ['name' => 'Bash', 'input' => ['command' => 'npm install'], 'expected' => 'Running: npm install'],
            ['name' => 'Grep', 'input' => ['pattern' => 'TODO'], 'expected' => 'Searching for: TODO'],
            ['name' => 'Glob', 'input' => ['pattern' => '*.php'], 'expected' => 'Finding files: *.php'],
            ['name' => 'Task', 'input' => ['description' => 'Review code'], 'expected' => 'Spawning agent: Review code'],
        ];
        
        foreach ($toolTests as $test) {
            $tracker->addToolActivity($test);
            $progress = $tracker->getProgress();
            $this->assertEquals($test['expected'], $progress['currentActivity']);
        }
    }
    
    public function testProgressCompletion(): void
    {
        $tracker = new AgentProgressTracker('test', 'TestAgent');
        
        // Add some activities
        $tracker->updateFromResponse(['input_tokens' => 100, 'output_tokens' => 50]);
        $tracker->addToolActivity(['name' => 'Bash', 'input' => ['command' => 'echo test']]);
        
        // Complete the tracker
        $tracker->complete();
        
        $progress = $tracker->getProgress();
        $this->assertNull($progress['currentActivity']);
        $this->assertNotNull($progress['completedAt']);
        $this->assertGreaterThan(0, $progress['durationMs']);
    }
}