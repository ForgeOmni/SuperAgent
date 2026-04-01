<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

class StreamingHandlerTest extends TestCase
{
    public function test_emit_text(): void
    {
        $received = [];
        $handler = new StreamingHandler(
            onText: function (string $delta, string $full) use (&$received) {
                $received[] = ['delta' => $delta, 'full' => $full];
            }
        );

        $handler->emitText('Hello', 'Hello');
        $handler->emitText(' world', 'Hello world');

        $this->assertCount(2, $received);
        $this->assertSame('Hello', $received[0]['delta']);
        $this->assertSame('Hello world', $received[1]['full']);
    }

    public function test_emit_thinking(): void
    {
        $received = [];
        $handler = new StreamingHandler(
            onThinking: function (string $delta, string $full) use (&$received) {
                $received[] = $full;
            }
        );

        $handler->emitThinking('think', 'think');
        $handler->emitThinking('ing', 'thinking');

        $this->assertCount(2, $received);
        $this->assertSame('thinking', $received[1]);
    }

    public function test_emit_tool_use(): void
    {
        $received = null;
        $handler = new StreamingHandler(
            onToolUse: function (ContentBlock $block) use (&$received) {
                $received = $block;
            }
        );

        $block = ContentBlock::toolUse('tu_1', 'bash', ['command' => 'ls']);
        $handler->emitToolUse($block);

        $this->assertNotNull($received);
        $this->assertSame('bash', $received->toolName);
    }

    public function test_emit_tool_result(): void
    {
        $received = [];
        $handler = new StreamingHandler(
            onToolResult: function (string $id, string $name, string $result, bool $isError) use (&$received) {
                $received = compact('id', 'name', 'result', 'isError');
            }
        );

        $handler->emitToolResult('tu_1', 'bash', 'output', false);

        $this->assertSame('tu_1', $received['id']);
        $this->assertSame('bash', $received['name']);
        $this->assertFalse($received['isError']);
    }

    public function test_emit_turn(): void
    {
        $turnNum = null;
        $handler = new StreamingHandler(
            onTurn: function (AssistantMessage $msg, int $turn) use (&$turnNum) {
                $turnNum = $turn;
            }
        );

        $handler->emitTurn(new AssistantMessage(), 3);
        $this->assertSame(3, $turnNum);
    }

    public function test_emit_final_message(): void
    {
        $received = null;
        $handler = new StreamingHandler(
            onFinalMessage: function (AssistantMessage $msg) use (&$received) {
                $received = $msg;
            }
        );

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('done')];
        $handler->emitFinalMessage($msg);

        $this->assertSame('done', $received->text());
    }

    public function test_emit_raw_event(): void
    {
        $received = [];
        $handler = new StreamingHandler(
            onRawEvent: function (string $event, array $data) use (&$received) {
                $received[] = $event;
            }
        );

        $handler->emitRawEvent('message_start', []);
        $handler->emitRawEvent('content_block_delta', []);

        $this->assertSame(['message_start', 'content_block_delta'], $received);
    }

    public function test_null_callbacks_dont_throw(): void
    {
        $handler = new StreamingHandler(); // all null

        // None of these should throw
        $handler->emitText('a', 'a');
        $handler->emitThinking('t', 't');
        $handler->emitToolUse(ContentBlock::toolUse('id', 'name', []));
        $handler->emitToolResult('id', 'name', 'r', false);
        $handler->emitTurn(new AssistantMessage(), 1);
        $handler->emitFinalMessage(new AssistantMessage());
        $handler->emitRawEvent('test', []);

        $this->assertTrue(true); // No exception = pass
    }
}
