<?php

namespace SuperAgent\Tests\Unit\Bridge\Converters;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Converters\OpenAIMessageAdapter;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;

class OpenAIMessageAdapterTest extends TestCase
{
    public function test_extracts_system_prompt(): void
    {
        $result = OpenAIMessageAdapter::fromOpenAI([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertSame('You are helpful.', $result['systemPrompt']);
        $this->assertCount(1, $result['messages']);
        $this->assertInstanceOf(UserMessage::class, $result['messages'][0]);
    }

    public function test_merges_multiple_system_messages(): void
    {
        $result = OpenAIMessageAdapter::fromOpenAI([
            ['role' => 'system', 'content' => 'Part 1.'],
            ['role' => 'system', 'content' => 'Part 2.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertSame("Part 1.\n\nPart 2.", $result['systemPrompt']);
    }

    public function test_converts_user_message(): void
    {
        $result = OpenAIMessageAdapter::fromOpenAI([
            ['role' => 'user', 'content' => 'Hello world'],
        ]);

        $this->assertNull($result['systemPrompt']);
        $this->assertCount(1, $result['messages']);
        $this->assertInstanceOf(UserMessage::class, $result['messages'][0]);
    }

    public function test_converts_assistant_with_tool_calls(): void
    {
        $result = OpenAIMessageAdapter::fromOpenAI([
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'bash',
                        'arguments' => '{"command":"ls"}',
                    ],
                ]],
            ],
        ]);

        $this->assertCount(1, $result['messages']);
        $msg = $result['messages'][0];
        $this->assertInstanceOf(AssistantMessage::class, $msg);
        $this->assertTrue($msg->hasToolUse());

        $blocks = $msg->toolUseBlocks();
        $this->assertSame('call_123', $blocks[0]->toolUseId);
        $this->assertSame('bash', $blocks[0]->toolName);
        $this->assertSame(['command' => 'ls'], $blocks[0]->toolInput);
    }

    public function test_converts_tool_result_messages(): void
    {
        $result = OpenAIMessageAdapter::fromOpenAI([
            ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => 'file.txt'],
            ['role' => 'tool', 'tool_call_id' => 'call_456', 'content' => 'done'],
        ]);

        $this->assertCount(1, $result['messages']);
        $msg = $result['messages'][0];
        $this->assertInstanceOf(ToolResultMessage::class, $msg);
        $this->assertCount(2, $msg->content);
    }

    public function test_round_trip_user_message(): void
    {
        $original = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $parsed = OpenAIMessageAdapter::fromOpenAI($original);
        $back = OpenAIMessageAdapter::toOpenAI($parsed['messages'], $parsed['systemPrompt']);

        $this->assertSame('user', $back[0]['role']);
        $this->assertSame('Hello', $back[0]['content']);
    }

    public function test_to_completion_response(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi there')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(10, 5);

        $response = OpenAIMessageAdapter::toCompletionResponse($msg, 'gpt-4o', 'req-123');

        $this->assertSame('chatcmpl-req-123', $response['id']);
        $this->assertSame('chat.completion', $response['object']);
        $this->assertSame('gpt-4o', $response['model']);
        $this->assertSame('stop', $response['choices'][0]['finish_reason']);
        $this->assertSame('Hi there', $response['choices'][0]['message']['content']);
        $this->assertSame(10, $response['usage']['prompt_tokens']);
        $this->assertSame(5, $response['usage']['completion_tokens']);
    }

    public function test_to_completion_response_with_tool_calls(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::toolUse('call_1', 'bash', ['command' => 'ls']),
        ];
        $msg->stopReason = StopReason::ToolUse;
        $msg->usage = new Usage(10, 5);

        $response = OpenAIMessageAdapter::toCompletionResponse($msg, 'gpt-4o', 'req-456');

        $this->assertSame('tool_calls', $response['choices'][0]['finish_reason']);
        $this->assertArrayHasKey('tool_calls', $response['choices'][0]['message']);
        $this->assertSame('call_1', $response['choices'][0]['message']['tool_calls'][0]['id']);
    }
}
