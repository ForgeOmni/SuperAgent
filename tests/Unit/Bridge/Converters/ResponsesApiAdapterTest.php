<?php

namespace SuperAgent\Tests\Unit\Bridge\Converters;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Converters\ResponsesApiAdapter;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;

class ResponsesApiAdapterTest extends TestCase
{
    public function test_simple_string_input(): void
    {
        $result = ResponsesApiAdapter::fromResponsesApi([
            'input' => 'Hello world',
            'instructions' => 'Be helpful',
        ]);

        $this->assertCount(1, $result['messages']);
        $this->assertInstanceOf(UserMessage::class, $result['messages'][0]);
        $this->assertSame('Be helpful', $result['systemPrompt']);
    }

    public function test_message_items(): void
    {
        $result = ResponsesApiAdapter::fromResponsesApi([
            'input' => [
                ['type' => 'message', 'role' => 'user', 'content' => 'Hello'],
                ['type' => 'message', 'role' => 'system', 'content' => 'System info'],
            ],
            'instructions' => 'Base instructions',
        ]);

        $this->assertCount(1, $result['messages']);
        $this->assertInstanceOf(UserMessage::class, $result['messages'][0]);
        // instructions + system message
        $this->assertStringContainsString('Base instructions', $result['systemPrompt']);
        $this->assertStringContainsString('System info', $result['systemPrompt']);
    }

    public function test_function_call_item(): void
    {
        $result = ResponsesApiAdapter::fromResponsesApi([
            'input' => [
                [
                    'type' => 'function_call',
                    'call_id' => 'call_abc',
                    'name' => 'bash',
                    'arguments' => '{"command":"ls"}',
                ],
            ],
        ]);

        $this->assertCount(1, $result['messages']);
        $msg = $result['messages'][0];
        $this->assertInstanceOf(AssistantMessage::class, $msg);
        $this->assertTrue($msg->hasToolUse());

        $block = $msg->toolUseBlocks()[0];
        $this->assertSame('call_abc', $block->toolUseId);
        $this->assertSame('bash', $block->toolName);
        $this->assertSame(['command' => 'ls'], $block->toolInput);
    }

    public function test_function_call_output_item(): void
    {
        $result = ResponsesApiAdapter::fromResponsesApi([
            'input' => [
                [
                    'type' => 'function_call_output',
                    'call_id' => 'call_abc',
                    'output' => 'file.txt\ndir/',
                ],
            ],
        ]);

        $this->assertCount(1, $result['messages']);
        $this->assertInstanceOf(ToolResultMessage::class, $result['messages'][0]);
    }

    public function test_mixed_conversation(): void
    {
        $result = ResponsesApiAdapter::fromResponsesApi([
            'input' => [
                ['type' => 'message', 'role' => 'user', 'content' => 'List files'],
                [
                    'type' => 'function_call',
                    'call_id' => 'call_1',
                    'name' => 'bash',
                    'arguments' => '{"command":"ls"}',
                ],
                [
                    'type' => 'function_call_output',
                    'call_id' => 'call_1',
                    'output' => 'a.txt b.txt',
                ],
                ['type' => 'message', 'role' => 'user', 'content' => 'Now read a.txt'],
            ],
            'tools' => [
                ['type' => 'function', 'name' => 'bash', 'description' => 'Run bash'],
            ],
        ]);

        $this->assertCount(4, $result['messages']);
        $this->assertInstanceOf(UserMessage::class, $result['messages'][0]);
        $this->assertInstanceOf(AssistantMessage::class, $result['messages'][1]);
        $this->assertInstanceOf(ToolResultMessage::class, $result['messages'][2]);
        $this->assertInstanceOf(UserMessage::class, $result['messages'][3]);
        $this->assertCount(1, $result['tools']);
    }

    public function test_to_responses_api_text(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hello there')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(100, 50);

        $response = ResponsesApiAdapter::toResponsesApi($msg, 'gpt-4o', 'resp_123');

        $this->assertSame('resp_123', $response['id']);
        $this->assertSame('response', $response['object']);
        $this->assertSame('completed', $response['status']);
        $this->assertCount(1, $response['output']);
        $this->assertSame('message', $response['output'][0]['type']);
        $this->assertSame('Hello there', $response['output'][0]['content'][0]['text']);
    }

    public function test_to_responses_api_function_call(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::toolUse('call_1', 'bash', ['command' => 'ls']),
        ];
        $msg->stopReason = StopReason::ToolUse;
        $msg->usage = new Usage(100, 50);

        $response = ResponsesApiAdapter::toResponsesApi($msg, 'gpt-4o', 'resp_456');

        $this->assertSame('incomplete', $response['status']);
        $this->assertSame('function_call', $response['output'][0]['type']);
        $this->assertSame('call_1', $response['output'][0]['call_id']);
        $this->assertSame('bash', $response['output'][0]['name']);
    }

    public function test_to_stream_events(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(10, 5);

        $events = ResponsesApiAdapter::toStreamEvents($msg, 'gpt-4o', 'resp_789');

        $this->assertNotEmpty($events);

        // First event should be response.created
        $this->assertStringContainsString('event: response.created', $events[0]);

        // Last event should be response.completed
        $last = end($events);
        $this->assertStringContainsString('event: response.completed', $last);
    }
}
