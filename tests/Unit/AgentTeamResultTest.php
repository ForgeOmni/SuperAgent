<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\AgentResult;
use SuperAgent\AgentTeamResult;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;

class AgentTeamResultTest extends TestCase
{
    public function testCreateTeamResult(): void
    {
        // Create mock agent results
        $message1 = new AssistantMessage();
        $message1->content = [ContentBlock::text("Result from agent 1")];
        $message1->usage = new Usage(100, 50);
        
        $result1 = new AgentResult(
            message: $message1,
            allResponses: [$message1],
            messages: [],
            totalCostUsd: 0.05
        );
        
        $message2 = new AssistantMessage();
        $message2->content = [ContentBlock::text("Result from agent 2")];
        $message2->usage = new Usage(80, 40);
        
        $result2 = new AgentResult(
            message: $message2,
            allResponses: [$message2],
            messages: [],
            totalCostUsd: 0.03
        );
        
        $metadata = [
            'agents' => [
                0 => ['name' => 'researcher', 'status' => 'completed'],
                1 => ['name' => 'coder', 'status' => 'completed'],
            ],
            'execution_time' => 15.5,
        ];
        
        $teamResult = new AgentTeamResult([$result1, $result2], $metadata);
        
        $this->assertCount(2, $teamResult->agentResults);
        $this->assertEquals(0.08, $teamResult->totalCostUsd());
        $this->assertTrue($teamResult->allSucceeded());
    }
    
    public function testGetResultsByAgent(): void
    {
        $message1 = new AssistantMessage();
        $message1->content = [ContentBlock::text("Research findings")];
        
        $result1 = new AgentResult(
            message: $message1,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.02
        );
        
        $message2 = new AssistantMessage();
        $message2->content = [ContentBlock::text("Code implementation")];
        
        $result2 = new AgentResult(
            message: $message2,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.04
        );
        
        $metadata = [
            'agents' => [
                0 => ['name' => 'researcher', 'status' => 'completed'],
                1 => ['name' => 'coder', 'status' => 'completed'],
            ],
        ];
        
        $teamResult = new AgentTeamResult([$result1, $result2], $metadata);
        $byAgent = $teamResult->getResultsByAgent();
        
        $this->assertArrayHasKey('researcher', $byAgent);
        $this->assertArrayHasKey('coder', $byAgent);
        $this->assertEquals("Research findings", $byAgent['researcher']->text());
        $this->assertEquals("Code implementation", $byAgent['coder']->text());
    }
    
    public function testTeamResultText(): void
    {
        $message1 = new AssistantMessage();
        $message1->content = [ContentBlock::text("Research complete")];
        
        $result1 = new AgentResult(
            message: $message1,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.01
        );
        
        $message2 = new AssistantMessage();
        $message2->content = [ContentBlock::text("Code written")];
        
        $result2 = new AgentResult(
            message: $message2,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.02
        );
        
        $metadata = [
            'agents' => [
                0 => ['name' => 'researcher'],
                1 => ['name' => 'coder'],
            ],
        ];
        
        $teamResult = new AgentTeamResult([$result1, $result2], $metadata);
        $text = $teamResult->text();
        
        $this->assertStringContainsString("## researcher", $text);
        $this->assertStringContainsString("Research complete", $text);
        $this->assertStringContainsString("## coder", $text);
        $this->assertStringContainsString("Code written", $text);
        $this->assertStringContainsString("---", $text);
    }
    
    public function testTeamResultSummary(): void
    {
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text("Done")];
        
        $response1 = new AssistantMessage();
        $response1->content = [ContentBlock::text("Step 1")];
        $response1->usage = new Usage(100, 50);
        
        $response2 = new AssistantMessage();
        $response2->content = [ContentBlock::text("Step 2")];
        $response2->usage = new Usage(150, 75);
        
        $result = new AgentResult(
            message: $message,
            allResponses: [$response1, $response2],
            messages: [],
            totalCostUsd: 0.025
        );
        
        $metadata = [
            'agents' => [
                0 => ['name' => 'worker'],
            ],
            'execution_time' => 10.5,
        ];
        
        $teamResult = new AgentTeamResult([$result], $metadata);
        $summary = $teamResult->summary();
        
        $this->assertStringContainsString("Agents: 1", $summary);
        $this->assertStringContainsString("Total turns: 2", $summary);
        $this->assertStringContainsString("Total tokens: 375", $summary); // 100+150+50+75
        $this->assertStringContainsString("Total cost: $0.0250", $summary);
        $this->assertStringContainsString("Execution time: 10.50s", $summary);
    }
    
    public function testFailedAgents(): void
    {
        $result = new AgentResult(
            message: null,
            allResponses: [],
            messages: [],
            totalCostUsd: 0
        );
        
        $metadata = [
            'agents' => [
                'researcher' => ['status' => 'completed'],
                'coder' => ['status' => 'failed', 'reason' => 'Timeout', 'error' => 'Connection lost'],
                'reviewer' => ['status' => 'failed', 'reason' => 'Out of memory'],
            ],
        ];
        
        $teamResult = new AgentTeamResult([$result], $metadata);
        
        $this->assertFalse($teamResult->allSucceeded());
        
        $failed = $teamResult->failedAgents();
        $this->assertCount(2, $failed);
        $this->assertArrayHasKey('coder', $failed);
        $this->assertArrayHasKey('reviewer', $failed);
        $this->assertEquals('Timeout', $failed['coder']['reason']);
        $this->assertEquals('Connection lost', $failed['coder']['error']);
        $this->assertEquals('Out of memory', $failed['reviewer']['reason']);
    }
    
    public function testMergeTeamResults(): void
    {
        $message1 = new AssistantMessage();
        $message1->content = [ContentBlock::text("Team 1 result")];
        
        $result1 = new AgentResult(
            message: $message1,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.10
        );
        
        $teamResult1 = new AgentTeamResult(
            [$result1],
            ['agents' => ['agent1' => ['status' => 'completed']], 'execution_time' => 5]
        );
        
        $message2 = new AssistantMessage();
        $message2->content = [ContentBlock::text("Team 2 result")];
        
        $result2 = new AgentResult(
            message: $message2,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.15
        );
        
        $teamResult2 = new AgentTeamResult(
            [$result2],
            ['agents' => ['agent2' => ['status' => 'completed']], 'execution_time' => 7]
        );
        
        $merged = AgentTeamResult::merge($teamResult1, $teamResult2);
        
        $this->assertCount(2, $merged->agentResults);
        $this->assertEquals(0.25, $merged->totalCostUsd());
        $this->assertEquals(12, $merged->metadata['execution_time']);
        $this->assertArrayHasKey('agent1', $merged->metadata['agents']);
        $this->assertArrayHasKey('agent2', $merged->metadata['agents']);
    }
    
    public function testFromSingleResult(): void
    {
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text("Single agent output")];
        
        $result = new AgentResult(
            message: $message,
            allResponses: [],
            messages: [],
            totalCostUsd: 0.05
        );
        
        $teamResult = AgentTeamResult::fromSingle($result, 'main-agent');
        
        $this->assertCount(1, $teamResult->agentResults);
        $this->assertEquals(0.05, $teamResult->totalCostUsd());
        $this->assertTrue($teamResult->metadata['single_agent']);
        
        $byAgent = $teamResult->getResultsByAgent();
        $this->assertArrayHasKey('main-agent', $byAgent);
    }
    
    public function testToArray(): void
    {
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text("Output")];
        
        $response = new AssistantMessage();
        $response->content = [ContentBlock::text("Response")];
        $response->usage = new Usage(100, 50);
        
        $result = new AgentResult(
            message: $message,
            allResponses: [$response],
            messages: [],
            totalCostUsd: 0.03
        );
        
        $metadata = [
            'agents' => [
                0 => ['name' => 'worker', 'status' => 'completed'],
            ],
        ];
        
        $teamResult = new AgentTeamResult([$result], $metadata);
        $array = $teamResult->toArray();
        
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('agents', $array);
        $this->assertArrayHasKey('total_turns', $array);
        $this->assertArrayHasKey('total_usage', $array);
        $this->assertArrayHasKey('total_cost_usd', $array);
        $this->assertArrayHasKey('all_succeeded', $array);
        $this->assertArrayHasKey('failed_agents', $array);
        $this->assertArrayHasKey('metadata', $array);
        
        $this->assertEquals(1, $array['total_turns']);
        $this->assertEquals(100, $array['total_usage']['input_tokens']);
        $this->assertEquals(50, $array['total_usage']['output_tokens']);
        $this->assertEquals(0.03, $array['total_cost_usd']);
        $this->assertTrue($array['all_succeeded']);
    }
}