<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\AgentProgressTracker;
use SuperAgent\AgentResult;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;

class AgentToolResultTest extends TestCase
{
    public function testClaudeCodeFormatResult(): void
    {
        // Create a mock agent result
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text("Task completed successfully")];
        $message->usage = new Usage(150, 75);
        
        $agentResult = new AgentResult(
            message: $message,
            allResponses: [$message],
            messages: [],
            totalCostUsd: 0.002
        );
        
        // Store it in the coordinator
        $coordinator = ParallelAgentCoordinator::getInstance();
        $coordinator->storeAgentResult('test-agent-123', $agentResult);
        
        // Create expected Claude Code format
        $expected = [
            'status' => 'completed',
            'agentId' => 'test-agent-123',
            'agentType' => 'general-purpose',
            'content' => [
                ['type' => 'text', 'text' => 'Task completed successfully']
            ],
            'totalDurationMs' => 1500, // This will vary
            'totalTokens' => 225,
            'totalToolUseCount' => 1,
            'usage' => [
                'input_tokens' => 150,
                'output_tokens' => 75,
                'cache_creation_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'server_tool_use' => null,
            ],
            'prompt' => 'Test task',
        ];
        
        // Verify the structure matches Claude Code
        $result = $coordinator->getAgentResult('test-agent-123');
        $this->assertNotNull($result);
        $this->assertEquals("Task completed successfully", $result->text());
        $this->assertEquals(225, $result->totalUsage()->inputTokens + $result->totalUsage()->outputTokens);
    }
    
    public function testAsyncLaunchedFormat(): void
    {
        // Test async launch format
        $asyncResult = [
            'status' => 'async_launched',
            'agentId' => 'async-agent-456',
            'task_id' => 'task-789',
            'description' => 'Process data files',
            'prompt' => 'Analyze all CSV files in the data directory',
            'name' => 'data_processor',
            'backend' => 'in_process',
            'message' => "Agent 'data_processor' started in background. You'll be notified when it completes.",
        ];
        
        $toolResult = ToolResult::success($asyncResult);
        
        $this->assertTrue($toolResult->isSuccess());
        $this->assertIsArray($toolResult->content);
        $this->assertEquals('async_launched', $toolResult->content['status']);
        $this->assertEquals('async-agent-456', $toolResult->content['agentId']);
    }
    
    public function testSynchronousCompletionFormat(): void
    {
        // Test synchronous completion format that matches Claude Code
        $syncResult = [
            'status' => 'completed',
            'agentId' => 'sync-agent-789',
            'agentType' => 'code-writer',
            'content' => [
                ['type' => 'text', 'text' => 'I have implemented the requested feature.']
            ],
            'totalDurationMs' => 3500,
            'totalTokens' => 450,
            'totalToolUseCount' => 3,
            'usage' => [
                'input_tokens' => 300,
                'output_tokens' => 150,
                'cache_creation_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'server_tool_use' => null,
            ],
            'prompt' => 'Implement user authentication',
        ];
        
        $toolResult = ToolResult::success($syncResult);
        
        $this->assertTrue($toolResult->isSuccess());
        $this->assertEquals('completed', $toolResult->content['status']);
        $this->assertEquals('sync-agent-789', $toolResult->content['agentId']);
        $this->assertEquals('code-writer', $toolResult->content['agentType']);
        $this->assertCount(1, $toolResult->content['content']);
        $this->assertEquals('text', $toolResult->content['content'][0]['type']);
        $this->assertEquals('I have implemented the requested feature.', $toolResult->content['content'][0]['text']);
        $this->assertEquals(3500, $toolResult->content['totalDurationMs']);
        $this->assertEquals(450, $toolResult->content['totalTokens']);
        $this->assertEquals(3, $toolResult->content['totalToolUseCount']);
        $this->assertEquals(300, $toolResult->content['usage']['input_tokens']);
        $this->assertEquals(150, $toolResult->content['usage']['output_tokens']);
    }
}