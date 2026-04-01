<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Enums\Role;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;

class MessageTest extends TestCase
{
    public function test_user_message(): void
    {
        $msg = new UserMessage('hello');

        $this->assertSame(Role::User, $msg->role);
        $this->assertSame('hello', $msg->content);
        $this->assertNotEmpty($msg->id);
        $this->assertNotEmpty($msg->timestamp);
        $this->assertSame(['role' => 'user', 'content' => 'hello'], $msg->toArray());
    }

    public function test_user_message_with_array_content(): void
    {
        $content = [['type' => 'text', 'text' => 'hi']];
        $msg = new UserMessage($content);

        $this->assertSame($content, $msg->content);
        $this->assertSame(['role' => 'user', 'content' => $content], $msg->toArray());
    }

    public function test_system_message(): void
    {
        $msg = new SystemMessage('you are helpful');

        $this->assertSame(Role::System, $msg->role);
        $this->assertSame(['role' => 'system', 'content' => 'you are helpful'], $msg->toArray());
    }

    public function test_assistant_message_text(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hello '), ContentBlock::text('world')];
        $msg->stopReason = StopReason::EndTurn;

        $this->assertSame(Role::Assistant, $msg->role);
        $this->assertSame('Hello world', $msg->text());
        $this->assertFalse($msg->hasToolUse());
        $this->assertEmpty($msg->toolUseBlocks());
    }

    public function test_assistant_message_with_tool_use(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::text('Let me check.'),
            ContentBlock::toolUse('tu_1', 'get_weather', ['city' => 'Tokyo']),
            ContentBlock::toolUse('tu_2', 'calculate', ['expression' => '1+1']),
        ];
        $msg->stopReason = StopReason::ToolUse;

        $this->assertTrue($msg->hasToolUse());
        $this->assertCount(2, $msg->toolUseBlocks());
        $this->assertSame('get_weather', $msg->toolUseBlocks()[0]->toolName);
        $this->assertSame('calculate', $msg->toolUseBlocks()[1]->toolName);
    }

    public function test_assistant_message_to_array(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('hi')];

        $arr = $msg->toArray();
        $this->assertSame('assistant', $arr['role']);
        $this->assertCount(1, $arr['content']);
        $this->assertSame('text', $arr['content'][0]['type']);
    }

    public function test_tool_result_message_from_single(): void
    {
        $msg = ToolResultMessage::fromResult('tu_1', 'sunny');

        $this->assertSame(Role::User, $msg->role);
        $arr = $msg->toArray();
        $this->assertSame('user', $arr['role']);
        $this->assertCount(1, $arr['content']);
        $this->assertSame('tool_result', $arr['content'][0]['type']);
        $this->assertSame('tu_1', $arr['content'][0]['tool_use_id']);
        $this->assertSame('sunny', $arr['content'][0]['content']);
    }

    public function test_tool_result_message_from_multiple(): void
    {
        $msg = ToolResultMessage::fromResults([
            ['tool_use_id' => 'tu_1', 'content' => 'ok'],
            ['tool_use_id' => 'tu_2', 'content' => 'fail', 'is_error' => true],
        ]);

        $arr = $msg->toArray();
        $this->assertCount(2, $arr['content']);
        $this->assertSame('tu_1', $arr['content'][0]['tool_use_id']);
        $this->assertSame('tu_2', $arr['content'][1]['tool_use_id']);
        $this->assertTrue($arr['content'][1]['is_error']);
    }

    public function test_tool_result_message_with_array_content(): void
    {
        $msg = ToolResultMessage::fromResult('tu_1', ['key' => 'value']);

        $arr = $msg->toArray();
        $this->assertSame('{"key":"value"}', $arr['content'][0]['content']);
    }

    public function test_usage(): void
    {
        $usage = new Usage(100, 50, 10, 20);

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(150, $usage->totalTokens());
        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_creation_input_tokens' => 10,
            'cache_read_input_tokens' => 20,
        ], $usage->toArray());
    }

    public function test_usage_without_cache(): void
    {
        $usage = new Usage(100, 50);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
        ], $usage->toArray());
    }
}
