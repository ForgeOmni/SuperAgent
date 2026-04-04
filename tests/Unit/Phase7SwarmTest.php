<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SuperAgent\Permissions\PermissionMode;
use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\AgentTask;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\IsolationMode;
use SuperAgent\Swarm\PlanApprovalResponseMessage;
use SuperAgent\Swarm\ShutdownRequestMessage;
use SuperAgent\Swarm\ShutdownResponseMessage;
use SuperAgent\Swarm\Team;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\TeamMember;
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Tools\Builtin\SendMessageTool;

class Phase7SwarmTest extends TestCase
{
    private string $testPath;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testPath = sys_get_temp_dir() . '/test_swarm_' . uniqid();
        if (!file_exists($this->testPath)) {
            mkdir($this->testPath, 0755, true);
        }
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->testPath)) {
            $this->deleteDirectory($this->testPath);
        }
    }
    
    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        
        rmdir($dir);
    }
    
    public function testSwarmTypes(): void
    {
        // Test BackendType enum
        $this->assertEquals('in-process', BackendType::IN_PROCESS->value);
        $this->assertEquals('process', BackendType::PROCESS->value);
        $this->assertEquals('docker', BackendType::DOCKER->value);
        $this->assertEquals('remote', BackendType::REMOTE->value);
        
        // Test IsolationMode enum
        $this->assertEquals('none', IsolationMode::NONE->value);
        $this->assertEquals('worktree', IsolationMode::WORKTREE->value);
        $this->assertEquals('container', IsolationMode::CONTAINER->value);
        
        // Test AgentStatus enum
        $this->assertEquals('pending', AgentStatus::PENDING->value);
        $this->assertEquals('running', AgentStatus::RUNNING->value);
        $this->assertEquals('completed', AgentStatus::COMPLETED->value);
        $this->assertEquals('failed', AgentStatus::FAILED->value);
        $this->assertEquals('cancelled', AgentStatus::CANCELLED->value);
    }
    
    public function testAgentSpawnConfig(): void
    {
        $config = new AgentSpawnConfig(
            name: 'test_agent',
            prompt: 'Test task',
            teamName: 'test_team',
            model: 'claude-3-opus',
            systemPrompt: 'You are a test agent',
            permissionMode: PermissionMode::DEFAULT,
            backend: BackendType::IN_PROCESS,
            isolation: IsolationMode::NONE,
            runInBackground: true,
            allowedTools: ['read_file', 'write_file'],
            planModeRequired: false,
        );
        
        $this->assertEquals('test_agent', $config->name);
        $this->assertEquals('Test task', $config->prompt);
        $this->assertEquals('test_team', $config->teamName);
        $this->assertEquals('claude-3-opus', $config->model);
        $this->assertTrue($config->runInBackground);
    }
    
    public function testAgentMessage(): void
    {
        $message = new AgentMessage(
            from: 'agent1',
            to: 'agent2',
            content: 'Test message',
            summary: 'Test',
            requestId: 'req_123',
            color: '#FF0000',
        );
        
        $this->assertEquals('agent1', $message->from);
        $this->assertEquals('agent2', $message->to);
        $this->assertEquals('Test message', $message->content);
        
        // Test serialization
        $array = $message->toArray();
        $this->assertArrayHasKey('from', $array);
        $this->assertArrayHasKey('to', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('timestamp', $array);
        
        // Test deserialization
        $restored = AgentMessage::fromArray($array);
        $this->assertEquals($message->from, $restored->from);
        $this->assertEquals($message->to, $restored->to);
        $this->assertEquals($message->content, $restored->content);
    }
    
    public function testStructuredMessages(): void
    {
        // Test ShutdownRequestMessage
        $shutdown = new ShutdownRequestMessage('req_123', 'leader', 'Finishing task');
        $this->assertEquals('shutdown_request', $shutdown->getType());
        $array = $shutdown->toArray();
        $this->assertEquals('req_123', $array['request_id']);
        $this->assertEquals('leader', $array['from']);
        $this->assertEquals('Finishing task', $array['reason']);
        
        // Test ShutdownResponseMessage
        $response = new ShutdownResponseMessage('req_123', 'agent', true);
        $this->assertEquals('shutdown_response', $response->getType());
        $this->assertTrue($response->approve);
        
        // Test PlanApprovalResponseMessage
        $approval = new PlanApprovalResponseMessage(
            'req_456',
            'leader',
            false,
            'Needs more detail',
            PermissionMode::PLAN
        );
        $this->assertEquals('plan_approval_response', $approval->getType());
        $this->assertFalse($approval->approve);
        $this->assertEquals('Needs more detail', $approval->feedback);
    }
    
    public function testAgentTask(): void
    {
        $task = new AgentTask(
            taskId: 'task_123',
            agentId: 'agent_123',
            agentName: 'worker',
            status: AgentStatus::RUNNING,
            backend: BackendType::IN_PROCESS,
            teamName: 'test_team',
            startedAt: new \DateTimeImmutable(),
        );
        
        $this->assertEquals('task_123', $task->taskId);
        $this->assertEquals('agent_123', $task->agentId);
        $this->assertTrue($task->isActive());
        $this->assertFalse($task->isCompleted());
        
        // Test completed task
        $completedTask = new AgentTask(
            taskId: 'task_456',
            agentId: 'agent_456',
            agentName: 'worker2',
            status: AgentStatus::COMPLETED,
            backend: BackendType::IN_PROCESS,
        );
        
        $this->assertFalse($completedTask->isActive());
        $this->assertTrue($completedTask->isCompleted());
    }
    
    public function testTeam(): void
    {
        $member1 = new TeamMember(
            agentId: 'agent_1',
            name: 'worker1',
            backend: BackendType::IN_PROCESS,
            status: AgentStatus::RUNNING,
        );
        
        $member2 = new TeamMember(
            agentId: 'agent_2',
            name: 'worker2',
            backend: BackendType::PROCESS,
            status: AgentStatus::PENDING,
        );
        
        $team = new Team(
            name: 'test_team',
            leaderId: 'leader_123',
            members: [$member1, $member2],
            createdAt: new \DateTimeImmutable(),
        );
        
        $this->assertEquals('test_team', $team->name);
        $this->assertEquals('leader_123', $team->leaderId);
        $this->assertCount(2, $team->members);
        
        // Test serialization
        $array = $team->toArray();
        $this->assertEquals('test_team', $array['name']);
        $this->assertEquals('leader_123', $array['leader_id']);
        $this->assertCount(2, $array['members']);
        
        // Test deserialization
        $restored = Team::fromArray($array);
        $this->assertEquals($team->name, $restored->name);
        $this->assertEquals($team->leaderId, $restored->leaderId);
        $this->assertCount(2, $restored->members);
    }
    
    public function testTeamContext(): void
    {
        $context = new TeamContext('test_team', 'leader_123');
        
        $this->assertEquals('test_team', $context->getTeamName());
        $this->assertEquals('leader_123', $context->getLeaderId());
        $this->assertTrue($context->isLeader('leader_123'));
        $this->assertFalse($context->isLeader('agent_123'));
        
        // Add members
        $member1 = new TeamMember(
            agentId: 'agent_1',
            name: 'worker1',
            backend: BackendType::IN_PROCESS,
        );
        
        $context->addMember($member1);
        
        $this->assertCount(1, $context->getMembers());
        $this->assertNotNull($context->getMember('agent_1'));
        $this->assertNotNull($context->getMemberByName('worker1'));
        $this->assertEquals('agent_1', $context->resolveAgentId('worker1'));
        
        // Remove member
        $context->removeMember('agent_1');
        $this->assertCount(0, $context->getMembers());
        $this->assertNull($context->getMember('agent_1'));
        
        // Test save/load
        $context->addMember($member1);
        $context->save($this->testPath);
        
        $loaded = TeamContext::load('test_team', $this->testPath);
        $this->assertNotNull($loaded);
        $this->assertEquals('test_team', $loaded->getTeamName());
        $this->assertEquals('leader_123', $loaded->getLeaderId());
        $this->assertCount(1, $loaded->getMembers());
    }
    
    public function testInProcessBackend(): void
    {
        $backend = new InProcessBackend(new NullLogger());
        
        $this->assertEquals(BackendType::IN_PROCESS, $backend->getType());
        $this->assertTrue($backend->isAvailable());
        
        // Create spawn config
        $config = new AgentSpawnConfig(
            name: 'test_agent',
            prompt: 'Test task',
            runInBackground: false,
        );
        
        // Spawn agent
        $result = $backend->spawn($config);
        
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->agentId);
        $this->assertNotEmpty($result->taskId);
        $this->assertNull($result->error);
        
        // Check status
        $status = $backend->getStatus($result->agentId);
        $this->assertEquals(AgentStatus::PENDING, $status);
        
        // Send message
        $message = new AgentMessage(
            from: 'test',
            to: 'test_agent',
            content: 'Hello agent',
        );
        
        $backend->sendMessage($result->agentId, $message);
        
        // Request shutdown
        $backend->requestShutdown($result->agentId, 'Test complete');
        
        // Clean up
        $backend->cleanup($result->agentId);
        $this->assertNull($backend->getStatus($result->agentId));
    }
    
    public function testProcessBackend(): void
    {
        $backend = new ProcessBackend(null, new NullLogger());
        
        $this->assertEquals(BackendType::PROCESS, $backend->getType());
        
        // Check availability (may not be available in all test environments)
        if (!$backend->isAvailable()) {
            $this->markTestSkipped('Process backend not available');
        }
        
        // Create spawn config
        $config = new AgentSpawnConfig(
            name: 'test_process',
            prompt: 'Test process task',
        );
        
        // Spawn would fail without proper agent script
        $result = $backend->spawn($config);
        
        if (!$result->success) {
            // Expected if agent script doesn't exist
            $this->assertNotEmpty($result->error);
        }
    }
    
    public function testAgentTool(): void
    {
        $tool = new AgentTool(new NullLogger());
        
        $this->assertEquals('agent', $tool->name());
        $this->assertStringContainsString('Launch a new agent', $tool->description());
        $this->assertEquals('execution', $tool->category());
        
        // Test input schema
        $schema = $tool->inputSchema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertArrayHasKey('prompt', $schema['properties']);
        $this->assertArrayHasKey('subagent_type', $schema['properties']);
        
        // Test execution (synchronous in-process mode)
        $result = $tool->execute([
            'description' => 'Test agent',
            'prompt' => 'Perform test task',
            'subagent_type' => 'general-purpose',
            'backend' => 'in-process',
        ]);

        $this->assertTrue($result->isSuccess());
        $data = $result->data;
        $this->assertEquals('completed', $data['status']);
        $this->assertNotEmpty($data['agentId']);
    }
    
    public function testSendMessageTool(): void
    {
        $tool = new SendMessageTool(new NullLogger());
        
        $this->assertEquals('send_message', $tool->name());
        $this->assertStringContainsString('Send messages', $tool->description());
        $this->assertEquals('communication', $tool->category());
        $this->assertTrue($tool->isReadOnly());
        
        // Test input schema
        $schema = $tool->inputSchema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('to', $schema['properties']);
        $this->assertArrayHasKey('message', $schema['properties']);
        
        // Test without team context
        $result = $tool->execute([
            'to' => 'agent_123',
            'message' => 'Hello',
            'summary' => 'Test message',
        ]);
        
        $this->assertTrue($result->isSuccess());
        
        // Test broadcast without team
        $result = $tool->execute([
            'to' => '*',
            'message' => 'Broadcast',
            'summary' => 'Test broadcast',
        ]);
        
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Not in a team context', $result->error);
        
        // Test with team context
        $teamContext = new TeamContext('test_team', 'leader_123');
        $teamContext->addMember(new TeamMember(
            agentId: 'agent_1',
            name: 'worker1',
            backend: BackendType::IN_PROCESS,
        ));
        
        $tool->setTeamContext($teamContext);
        
        // Test broadcast with team
        $result = $tool->execute([
            'to' => '*',
            'message' => 'Team broadcast',
            'summary' => 'Test team broadcast',
        ]);
        
        $this->assertTrue($result->isSuccess());
        $data = $result->data;
        $this->assertArrayHasKey('recipients', $data);
        
        // Test structured message
        $result = $tool->execute([
            'to' => 'worker1',
            'message' => [
                'type' => 'shutdown_request',
                'reason' => 'Task complete',
            ],
        ]);
        
        $this->assertTrue($result->isSuccess());
        $data = $result->data;
        $this->assertArrayHasKey('request_id', $data);
    }
    
    public function testAgentCommunication(): void
    {
        // Create team context
        $teamContext = new TeamContext('comm_team', 'leader_123');
        
        // Create agent tool
        $agentTool = new AgentTool(new NullLogger());
        $agentTool->setTeamContext($teamContext);
        
        // Spawn an agent
        $spawnResult = $agentTool->execute([
            'description' => 'Test worker',
            'prompt' => 'Process data',
            'name' => 'worker1',
            'team_name' => 'comm_team',
        ]);
        
        $this->assertTrue($spawnResult->isSuccess());
        $agentId = $spawnResult->data['agentId'];

        // Create send message tool
        $sendTool = new SendMessageTool(new NullLogger());
        $sendTool->setTeamContext($teamContext);

        // Send message to agent — agent already completed synchronously, so
        // we just verify the tool itself succeeds with the team context.
        $messageResult = $sendTool->execute([
            'to' => 'worker1',
            'message' => 'Start processing',
            'summary' => 'Start command',
        ]);

        $this->assertTrue($messageResult->isSuccess());
    }
    
    public function testMessageRouting(): void
    {
        $mailboxDir = sys_get_temp_dir() . '/superagent_mailboxes';
        if (!is_dir($mailboxDir)) {
            mkdir($mailboxDir, 0755, true);
        }
        
        // Create mailbox file
        $agentId = 'test_routing_agent';
        $mailboxPath = $mailboxDir . '/' . $agentId . '.mailbox';
        
        // Write initial message
        $message1 = new AgentMessage(
            from: 'sender1',
            to: $agentId,
            content: 'First message',
        );
        
        file_put_contents($mailboxPath, json_encode([$message1->toArray()]));
        
        // Add another message
        $sendTool = new SendMessageTool(new NullLogger());
        $result = $sendTool->execute([
            'to' => $agentId,
            'message' => 'Second message',
            'summary' => 'Test',
        ]);
        
        $this->assertTrue($result->isSuccess());
        
        // Check mailbox
        $content = file_get_contents($mailboxPath);
        $messages = json_decode($content, true);
        
        $this->assertCount(2, $messages);
        $this->assertEquals('First message', $messages[0]['content']);
        $this->assertEquals('Second message', $messages[1]['content']);
        
        // Clean up
        unlink($mailboxPath);
    }
    
    public function testBackendRegistry(): void
    {
        // Test multiple backends
        $backends = [
            new InProcessBackend(new NullLogger()),
            new ProcessBackend(null, new NullLogger()),
        ];
        
        $availableCount = 0;
        foreach ($backends as $backend) {
            if ($backend->isAvailable()) {
                $availableCount++;
            }
        }
        
        // At least InProcessBackend should be available
        $this->assertGreaterThanOrEqual(1, $availableCount);
    }
}