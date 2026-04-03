<?php

namespace Tests\Smoke;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\AgentResult;
use SuperAgent\AgentTeamResult;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;
use SuperAgent\Swarm\AgentMailbox;
use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\AgentProgressTracker;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\TeamMember;
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Tools\Builtin\SendMessageTool;
use SuperAgent\Tools\ToolResult;
use Psr\Log\NullLogger;

/**
 * Smoke tests for multi-agent functionality.
 * These tests verify basic functionality works without deep testing.
 */
class MultiAgentSmokeTest extends TestCase
{
    /**
     * Test 1: Basic AgentTeamResult creation and aggregation
     */
    public function testAgentTeamResultBasics(): void
    {
        // Create mock agent results
        $message1 = new AssistantMessage();
        $message1->content = [ContentBlock::text("Agent 1 completed task")];
        $message1->usage = new Usage(100, 50);
        
        $result1 = new AgentResult(
            message: $message1,
            allResponses: [$message1],
            messages: [],
            totalCostUsd: 0.01
        );
        
        $message2 = new AssistantMessage();
        $message2->content = [ContentBlock::text("Agent 2 completed task")];
        $message2->usage = new Usage(80, 40);
        
        $result2 = new AgentResult(
            message: $message2,
            allResponses: [$message2],
            messages: [],
            totalCostUsd: 0.008
        );
        
        // Create team result
        $teamResult = new AgentTeamResult(
            [$result1, $result2],
            [
                'agents' => [
                    0 => ['name' => 'worker1', 'status' => 'completed'],
                    1 => ['name' => 'worker2', 'status' => 'completed'],
                ],
                'execution_time' => 5.2,
            ]
        );
        
        // Basic assertions
        $this->assertNotNull($teamResult);
        $this->assertCount(2, $teamResult->agentResults);
        $this->assertEqualsWithDelta(0.018, $teamResult->totalCostUsd(), 0.0001);
        $this->assertEquals(270, $teamResult->totalUsage()->inputTokens + $teamResult->totalUsage()->outputTokens);
        $this->assertTrue($teamResult->allSucceeded());
        
        // Test summary generation
        $summary = $teamResult->summary();
        $this->assertStringContainsString("Team execution completed", $summary);
        $this->assertStringContainsString("Agents: 2", $summary);
    }
    
    /**
     * Test 2: ParallelAgentCoordinator basic functionality
     */
    public function testParallelAgentCoordinator(): void
    {
        $coordinator = ParallelAgentCoordinator::getInstance();
        $coordinator->startExecution();
        
        // Register an agent
        $tracker = $coordinator->registerAgent(
            'test-agent-001',
            'TestAgent',
            'test-team'
        );
        
        $this->assertNotNull($tracker);
        $this->assertInstanceOf(AgentProgressTracker::class, $tracker);
        $this->assertEquals('TestAgent', $tracker->getAgentName());
        
        // Update progress
        $tracker->setCurrentActivity("Processing data");
        $tracker->updateFromResponse([
            'input_tokens' => 100,
            'output_tokens' => 50,
        ]);
        
        // Get progress
        $progress = $tracker->getProgress();
        $this->assertEquals("Processing data", $progress['currentActivity']);
        $this->assertEquals(150, $progress['tokenCount']);
        
        // Test result storage
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text("Test completed")];
        $result = new AgentResult($message, [], [], 0.005);
        
        $coordinator->storeAgentResult('test-agent-001', $result);
        $retrievedResult = $coordinator->getAgentResult('test-agent-001');
        
        $this->assertNotNull($retrievedResult);
        $this->assertEquals("Test completed", $retrievedResult->text());
        
        // Test active agents
        $activeAgents = $coordinator->getActiveAgents();
        $this->assertIsArray($activeAgents);
        
        // Clean up
        $coordinator->unregisterAgent('test-agent-001');
    }
    
    /**
     * Test 3: AgentMailbox basic operations
     */
    public function testAgentMailbox(): void
    {
        $mailbox = new AgentMailbox(
            sys_get_temp_dir() . '/test_mailboxes_' . uniqid()
        );
        
        // Test message writing
        $message1 = new AgentMessage(
            from: 'agent-sender',
            to: 'agent-receiver',
            content: 'Hello from sender',
            summary: 'Greeting'
        );
        
        $written = $mailbox->writeMessage('agent-receiver', $message1);
        $this->assertTrue($written);
        
        // Test message reading
        $this->assertTrue($mailbox->hasMessages('agent-receiver'));
        $this->assertEquals(1, $mailbox->getMessageCount('agent-receiver'));
        
        // Test peek (non-destructive read)
        $peeked = $mailbox->peekMessages('agent-receiver');
        $this->assertCount(1, $peeked);
        $this->assertEquals('Hello from sender', $peeked[0]->content);
        $this->assertEquals(1, $mailbox->getMessageCount('agent-receiver')); // Still there
        
        // Test consume (destructive read)
        $consumed = $mailbox->consumeMessages('agent-receiver');
        $this->assertCount(1, $consumed);
        $this->assertEquals('Hello from sender', $consumed[0]->content);
        $this->assertEquals(0, $mailbox->getMessageCount('agent-receiver')); // Gone
        
        // Test broadcast
        $broadcastMsg = new AgentMessage(
            from: 'coordinator',
            to: '*',
            content: 'Team announcement',
            summary: 'Announcement'
        );
        
        $recipients = ['agent-1', 'agent-2', 'agent-3'];
        $sent = $mailbox->broadcastMessage($broadcastMsg, $recipients);
        $this->assertEquals(3, $sent);
        
        // Verify each agent received the message
        foreach ($recipients as $agentId) {
            $this->assertTrue($mailbox->hasMessages($agentId));
        }
        
        // Clean up
        $mailbox->clearAllMailboxes();
    }
    
    /**
     * Test 4: SendMessageTool basic functionality
     */
    public function testSendMessageTool(): void
    {
        $tool = new SendMessageTool(new NullLogger());
        
        // Test tool metadata
        $this->assertEquals('send_message', $tool->name());
        $this->assertStringContainsString('Send messages', $tool->description());
        $this->assertTrue($tool->isReadOnly());
        
        // Test input schema
        $schema = $tool->inputSchema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('to', $schema['properties']);
        $this->assertArrayHasKey('message', $schema['properties']);
        
        // Test execution with missing recipient
        $result = $tool->execute(['message' => 'test']);
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError);
        
        // Test execution with missing message
        $result = $tool->execute(['to' => 'agent-1']);
        $this->assertTrue($result->isError);
    }
    
    /**
     * Test 5: AgentTool Claude Code format result
     */
    public function testAgentToolResultFormat(): void
    {
        // Test synchronous completion format
        $syncResult = ToolResult::success([
            'status' => 'completed',
            'agentId' => 'test-agent-123',
            'agentType' => 'general-purpose',
            'content' => [
                ['type' => 'text', 'text' => 'Task completed successfully']
            ],
            'totalDurationMs' => 2500,
            'totalTokens' => 350,
            'totalToolUseCount' => 2,
            'usage' => [
                'input_tokens' => 200,
                'output_tokens' => 150,
                'cache_creation_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'server_tool_use' => null,
            ],
            'prompt' => 'Complete the task',
        ]);
        
        $this->assertTrue($syncResult->isSuccess());
        $data = $syncResult->content;
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('test-agent-123', $data['agentId']);
        $this->assertIsArray($data['content']);
        $this->assertEquals('text', $data['content'][0]['type']);
        
        // Test async launch format
        $asyncResult = ToolResult::success([
            'status' => 'async_launched',
            'agentId' => 'async-agent-456',
            'task_id' => 'task-001',
            'description' => 'Process files',
            'prompt' => 'Analyze all files',
            'name' => 'file_processor',
            'backend' => 'in_process',
            'message' => "Agent 'file_processor' started in background.",
        ]);
        
        $this->assertTrue($asyncResult->isSuccess());
        $data = $asyncResult->content;
        $this->assertEquals('async_launched', $data['status']);
        $this->assertEquals('async-agent-456', $data['agentId']);
    }
    
    /**
     * Test 6: Team Context and Team Members
     */
    public function testTeamContext(): void
    {
        $teamContext = new TeamContext('test-team', 'leader-001');
        
        // Add team members
        $member1 = new TeamMember(
            agentId: 'worker-001',
            name: 'Worker1',
            backend: BackendType::IN_PROCESS,
            taskId: 'task-001',
            status: AgentStatus::RUNNING
        );
        
        $member2 = new TeamMember(
            agentId: 'worker-002',
            name: 'Worker2',
            backend: BackendType::IN_PROCESS,
            taskId: 'task-002',
            status: AgentStatus::PENDING
        );
        
        $teamContext->addMember($member1);
        $teamContext->addMember($member2);
        
        // Test team operations
        $this->assertEquals('test-team', $teamContext->getTeamName());
        $this->assertEquals('leader-001', $teamContext->getLeaderId());
        $this->assertEquals(2, $teamContext->getMemberCount());
        
        $members = $teamContext->getMembers();
        $this->assertCount(2, $members);
        
        $member = $teamContext->getMember('worker-001');
        $this->assertNotNull($member);
        $this->assertEquals('Worker1', $member->name);
        
        // Test leader check
        $this->assertTrue($teamContext->isLeader('leader-001'));
        $this->assertFalse($teamContext->isLeader('worker-001'));
        
        // Test member removal
        $teamContext->removeMember('worker-002');
        $this->assertEquals(1, $teamContext->getMemberCount());
    }
    
    /**
     * Test 7: InProcessBackend spawn and message handling
     */
    public function testInProcessBackend(): void
    {
        $backend = new InProcessBackend(new NullLogger());
        
        // Create spawn config
        $config = new AgentSpawnConfig(
            name: 'test-agent',
            prompt: 'Test prompt',
            teamName: 'test-team',
            runInBackground: false
        );
        
        // Test spawn
        $result = $backend->spawn($config);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->agentId);
        $this->assertNotEmpty($result->taskId);
        
        $agentId = $result->agentId;
        
        // Test status check
        $status = $backend->getStatus($agentId);
        $this->assertNotNull($status);
        
        // Test message sending
        $message = new AgentMessage(
            from: 'test-sender',
            to: $agentId,
            content: 'Test message',
            summary: 'Test'
        );
        
        // This should not throw
        $backend->sendMessage($agentId, $message);
        
        // Clean up
        $backend->cleanup($agentId);
    }
    
    /**
     * Test 8: Integration test - Multi-agent workflow
     */
    public function testMultiAgentWorkflow(): void
    {
        $coordinator = ParallelAgentCoordinator::getInstance();
        $coordinator->startExecution();
        
        // Simulate multiple agents working
        $agentIds = [];
        $trackers = [];
        
        for ($i = 1; $i <= 3; $i++) {
            $agentId = "agent-$i";
            $agentIds[] = $agentId;
            
            $tracker = $coordinator->registerAgent(
                $agentId,
                "Worker$i",
                'integration-team'
            );
            
            $trackers[$agentId] = $tracker;
            
            // Simulate work
            $tracker->setCurrentActivity("Processing task $i");
            $tracker->updateFromResponse([
                'input_tokens' => 100 * $i,
                'output_tokens' => 50 * $i,
            ]);
        }
        
        // Store results for each agent
        foreach ($agentIds as $i => $agentId) {
            $message = new AssistantMessage();
            $message->content = [ContentBlock::text("Agent $agentId completed")];
            
            $result = new AgentResult(
                message: $message,
                allResponses: [],
                messages: [],
                totalCostUsd: 0.001 * ($i + 1)
            );
            
            $coordinator->storeAgentResult($agentId, $result);
        }
        
        // Collect team results
        $teamResult = $coordinator->collectTeamResults();
        
        $this->assertInstanceOf(AgentTeamResult::class, $teamResult);
        // Note: collectTeamResults returns all stored results, might be more than 3 from previous tests
        $this->assertGreaterThanOrEqual(3, count($teamResult->agentResults));
        
        // Verify aggregation - total cost should be at least 0.006 (might be more from previous tests)
        $totalCost = $teamResult->totalCostUsd();
        $this->assertGreaterThanOrEqual(0.006, $totalCost);
        
        // Clean up
        foreach ($agentIds as $agentId) {
            $coordinator->unregisterAgent($agentId);
        }
    }
    
    /**
     * Test 9: Error handling and edge cases
     */
    public function testErrorHandling(): void
    {
        // Test empty team result
        $emptyTeamResult = new AgentTeamResult([], []);
        $this->assertEquals(0, $emptyTeamResult->totalTurns());
        $this->assertEquals(0, $emptyTeamResult->totalCostUsd());
        
        // Test mailbox with invalid agent ID
        $mailbox = new AgentMailbox();
        $this->assertFalse($mailbox->hasMessages('non-existent-agent'));
        $this->assertEquals(0, $mailbox->getMessageCount('non-existent-agent'));
        
        // Test coordinator with non-existent agent
        $coordinator = ParallelAgentCoordinator::getInstance();
        $result = $coordinator->getAgentResult('non-existent');
        $this->assertNull($result);
        
        // Test message filtering with no matches
        $filtered = $mailbox->filterMessages(
            'test-agent',
            from: 'unknown-sender'
        );
        $this->assertCount(0, $filtered);
    }
    
    /**
     * Test 10: Performance and scalability smoke test
     */
    public function testPerformanceBasics(): void
    {
        $startTime = microtime(true);
        
        // Create many agents quickly
        $coordinator = ParallelAgentCoordinator::getInstance();
        $agentIds = [];
        
        for ($i = 1; $i <= 50; $i++) {
            $agentId = "perf-agent-$i";
            $tracker = $coordinator->registerAgent($agentId, "Agent$i");
            $agentIds[] = $agentId;
            
            // Minimal work simulation
            $tracker->setCurrentActivity("Task $i");
        }
        
        // Time check - should be fast
        $elapsed = microtime(true) - $startTime;
        $this->assertLessThan(1.0, $elapsed, "Creating 50 agents took too long");
        
        // Test mailbox with many messages
        $mailbox = new AgentMailbox();
        $mailboxStart = microtime(true);
        
        for ($i = 1; $i <= 100; $i++) {
            $message = new AgentMessage(
                from: 'sender',
                to: 'receiver',
                content: "Message $i",
                summary: "Msg $i"
            );
            $mailbox->writeMessage('receiver', $message);
        }
        
        $mailboxElapsed = microtime(true) - $mailboxStart;
        $this->assertLessThan(0.5, $mailboxElapsed, "Writing 100 messages took too long");
        
        // Verify message limit enforcement
        $this->assertEquals(100, $mailbox->getMessageCount('receiver'));
        
        // Clean up
        foreach ($agentIds as $agentId) {
            $coordinator->unregisterAgent($agentId);
        }
        $mailbox->clearAllMailboxes();
    }
}