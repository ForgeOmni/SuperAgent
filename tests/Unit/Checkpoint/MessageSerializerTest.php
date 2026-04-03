<?php

namespace SuperAgent\Tests\Unit\Checkpoint;

use PHPUnit\Framework\TestCase;
use SuperAgent\Checkpoint\MessageSerializer;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;

class MessageSerializerTest extends TestCase
{
    public function test_serialize_user_message(): void
    {
        $msg = new UserMessage('Hello');
        $data = MessageSerializer::serialize($msg);

        $this->assertSame('user', $data['_class']);
        $this->assertSame('Hello', $data['content']);
    }

    public function test_deserialize_user_message(): void
    {
        $msg = new UserMessage('Hello');
        $data = MessageSerializer::serialize($msg);
        $restored = MessageSerializer::deserialize($data);

        $this->assertInstanceOf(UserMessage::class, $restored);
        $this->assertSame('Hello', $restored->content);
    }

    public function test_serialize_assistant_message_with_text(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Response text')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(100, 50);

        $data = MessageSerializer::serialize($msg);

        $this->assertSame('assistant', $data['_class']);
    }

    public function test_roundtrip_assistant_message(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hello world')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(100, 50, 10, 5);

        $data = MessageSerializer::serialize($msg);
        $restored = MessageSerializer::deserialize($data);

        $this->assertInstanceOf(AssistantMessage::class, $restored);
        $this->assertSame('Hello world', $restored->text());
        $this->assertSame(StopReason::EndTurn, $restored->stopReason);
        $this->assertSame(100, $restored->usage->inputTokens);
        $this->assertSame(50, $restored->usage->outputTokens);
    }

    public function test_roundtrip_assistant_with_tool_use(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::text('Let me read that file'),
            ContentBlock::toolUse('tu_123', 'Read', ['file_path' => '/src/App.php']),
        ];
        $msg->stopReason = StopReason::ToolUse;
        $msg->usage = new Usage(200, 100);

        $data = MessageSerializer::serialize($msg);
        $restored = MessageSerializer::deserialize($data);

        $this->assertTrue($restored->hasToolUse());
        $toolBlocks = $restored->toolUseBlocks();
        $this->assertCount(1, $toolBlocks);
        $this->assertSame('Read', $toolBlocks[0]->toolName);
        $this->assertSame('/src/App.php', $toolBlocks[0]->toolInput['file_path']);
    }

    public function test_roundtrip_tool_result_message(): void
    {
        $msg = ToolResultMessage::fromResults([
            ['tool_use_id' => 'tu_123', 'content' => 'File content here', 'is_error' => false],
            ['tool_use_id' => 'tu_456', 'content' => 'Error occurred', 'is_error' => true],
        ]);

        $data = MessageSerializer::serialize($msg);
        $restored = MessageSerializer::deserialize($data);

        $this->assertInstanceOf(ToolResultMessage::class, $restored);
    }

    public function test_serialize_all_and_deserialize_all(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [ContentBlock::text('Hi')];
        $assistant->stopReason = StopReason::EndTurn;

        $messages = [
            new UserMessage('Hello'),
            $assistant,
            new UserMessage('Do something'),
        ];

        $serialized = MessageSerializer::serializeAll($messages);
        $this->assertCount(3, $serialized);

        $restored = MessageSerializer::deserializeAll($serialized);
        $this->assertCount(3, $restored);
        $this->assertInstanceOf(UserMessage::class, $restored[0]);
        $this->assertInstanceOf(AssistantMessage::class, $restored[1]);
        $this->assertInstanceOf(UserMessage::class, $restored[2]);
    }

    public function test_roundtrip_thinking_block(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::thinking('Let me think...'),
            ContentBlock::text('Answer'),
        ];
        $msg->stopReason = StopReason::EndTurn;

        $data = MessageSerializer::serialize($msg);
        $restored = MessageSerializer::deserialize($data);

        $this->assertCount(2, $restored->content);
        $this->assertSame('thinking', $restored->content[0]->type);
    }

    public function test_unknown_class_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageSerializer::deserialize(['_class' => 'bogus']);
    }
}
