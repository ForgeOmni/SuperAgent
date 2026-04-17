<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\StreamEvent;
use SuperAgent\Harness\TextDeltaEvent;
use SuperAgent\Harness\ThinkingDeltaEvent;
use SuperAgent\Harness\TurnCompleteEvent;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Harness\ToolCompletedEvent;
use SuperAgent\Harness\CompactionEvent;
use SuperAgent\Harness\StatusEvent;
use SuperAgent\Harness\ErrorEvent;
use SuperAgent\Harness\AgentCompleteEvent;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Messages\AssistantMessage;

class StreamEventTest extends TestCase
{
    // ── TextDeltaEvent ────────────────────────────────────────────

    public function testTextDeltaEvent(): void
    {
        $event = new TextDeltaEvent('Hello');
        $this->assertEquals('text_delta', $event->type());
        $this->assertEquals('Hello', $event->text);
        $this->assertIsFloat($event->timestamp);
    }

    public function testTextDeltaEventSerialization(): void
    {
        $event = new TextDeltaEvent('world');
        $arr = $event->toArray();

        $this->assertEquals('text_delta', $arr['type']);
        $this->assertEquals('world', $arr['text']);
        $this->assertArrayHasKey('timestamp', $arr);
    }

    public function testTextDeltaEventJson(): void
    {
        $event = new TextDeltaEvent('test');
        $json = $event->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('text_delta', $decoded['type']);
        $this->assertEquals('test', $decoded['text']);
    }

    // ── ThinkingDeltaEvent ────────────────────────────────────────

    public function testThinkingDeltaEvent(): void
    {
        $event = new ThinkingDeltaEvent('thinking...');
        $this->assertEquals('thinking_delta', $event->type());
        $this->assertEquals('thinking...', $event->text);
    }

    // ── TurnCompleteEvent ─────────────────────────────────────────

    public function testTurnCompleteEvent(): void
    {
        $msg = new AssistantMessage();
        $event = new TurnCompleteEvent(
            message: $msg,
            turnNumber: 3,
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
        );

        $this->assertEquals('turn_complete', $event->type());
        $this->assertEquals(3, $event->turnNumber);
        $this->assertSame($msg, $event->message);
    }

    public function testTurnCompleteEventSerialization(): void
    {
        $msg = new AssistantMessage();
        $event = new TurnCompleteEvent($msg, 1, null);
        $arr = $event->toArray();

        $this->assertEquals(1, $arr['turn_number']);
        $this->assertFalse($arr['has_tool_use']);
        $this->assertNull($arr['usage']);
    }

    // ── ToolStartedEvent ──────────────────────────────────────────

    public function testToolStartedEvent(): void
    {
        $event = new ToolStartedEvent('read', 'tu_123', ['file_path' => '/test']);
        $this->assertEquals('tool_started', $event->type());
        $this->assertEquals('read', $event->toolName);
        $this->assertEquals('tu_123', $event->toolUseId);
        $this->assertEquals(['file_path' => '/test'], $event->toolInput);
    }

    public function testToolStartedEventSerialization(): void
    {
        $event = new ToolStartedEvent('bash', 'tu_456', ['command' => 'ls']);
        $arr = $event->toArray();

        $this->assertEquals('bash', $arr['tool_name']);
        $this->assertEquals('tu_456', $arr['tool_use_id']);
        $this->assertEquals(['command' => 'ls'], $arr['tool_input']);
    }

    // ── ToolCompletedEvent ────────────────────────────────────────

    public function testToolCompletedEvent(): void
    {
        $event = new ToolCompletedEvent('read', 'tu_789', 'file contents here', false);
        $this->assertEquals('tool_completed', $event->type());
        $this->assertEquals('read', $event->toolName);
        $this->assertEquals('tu_789', $event->toolUseId);
        $this->assertEquals('file contents here', $event->output);
        $this->assertFalse($event->isError);
    }

    public function testToolCompletedEventError(): void
    {
        $event = new ToolCompletedEvent('bash', 'tu_err', 'command failed', true);
        $this->assertTrue($event->isError);
    }

    public function testToolCompletedEventSerializesLength(): void
    {
        $event = new ToolCompletedEvent('read', 'tu_x', str_repeat('X', 1000));
        $arr = $event->toArray();

        $this->assertEquals(1000, $arr['output_length']);
        // Full output NOT in serialization (avoid bloating NDJSON)
        $this->assertArrayNotHasKey('output', $arr);
    }

    // ── CompactionEvent ───────────────────────────────────────────

    public function testCompactionEvent(): void
    {
        $event = new CompactionEvent('micro', 5000, 'tool_result_truncation');
        $this->assertEquals('compaction', $event->type());
        $this->assertEquals('micro', $event->tier);
        $this->assertEquals(5000, $event->tokensSaved);
        $this->assertEquals('tool_result_truncation', $event->strategy);
    }

    public function testCompactionEventSerialization(): void
    {
        $event = new CompactionEvent('full', 10000, 'llm_summary');
        $arr = $event->toArray();

        $this->assertEquals('full', $arr['tier']);
        $this->assertEquals(10000, $arr['tokens_saved']);
        $this->assertEquals('llm_summary', $arr['strategy']);
    }

    // ── StatusEvent ───────────────────────────────────────────────

    public function testStatusEvent(): void
    {
        $event = new StatusEvent('Retrying...', ['attempt' => 2]);
        $this->assertEquals('status', $event->type());
        $this->assertEquals('Retrying...', $event->message);
        $this->assertEquals(['attempt' => 2], $event->data);
    }

    // ── ErrorEvent ────────────────────────────────────────────────

    public function testErrorEvent(): void
    {
        $event = new ErrorEvent('Connection failed', true, 'network_error');
        $this->assertEquals('error', $event->type());
        $this->assertEquals('Connection failed', $event->message);
        $this->assertTrue($event->recoverable);
        $this->assertEquals('network_error', $event->code);
    }

    public function testErrorEventDefaults(): void
    {
        $event = new ErrorEvent('Something broke');
        $this->assertTrue($event->recoverable);
        $this->assertNull($event->code);
    }

    // ── AgentCompleteEvent ────────────────────────────────────────

    public function testAgentCompleteEvent(): void
    {
        $msg = new AssistantMessage();
        $event = new AgentCompleteEvent(5, 0.42, $msg);
        $this->assertEquals('agent_complete', $event->type());
        $this->assertEquals(5, $event->totalTurns);
        $this->assertEquals(0.42, $event->totalCostUsd);
        $this->assertTrue($event->toArray()['has_final_message']);
    }

    public function testAgentCompleteEventWithoutMessage(): void
    {
        $event = new AgentCompleteEvent(0, 0.0);
        $this->assertFalse($event->toArray()['has_final_message']);
    }

    // ── StreamEventEmitter ────────────────────────────────────────

    public function testEmitterDispatchesToListeners(): void
    {
        $emitter = new StreamEventEmitter();
        $received = [];

        $emitter->on(function (StreamEvent $event) use (&$received) {
            $received[] = $event;
        });

        $emitter->emit(new TextDeltaEvent('hello'));
        $emitter->emit(new StatusEvent('status'));

        $this->assertCount(2, $received);
        $this->assertInstanceOf(TextDeltaEvent::class, $received[0]);
        $this->assertInstanceOf(StatusEvent::class, $received[1]);
    }

    public function testEmitterMultipleListeners(): void
    {
        $emitter = new StreamEventEmitter();
        $count1 = 0;
        $count2 = 0;

        $emitter->on(function () use (&$count1) { $count1++; });
        $emitter->on(function () use (&$count2) { $count2++; });

        $emitter->emit(new TextDeltaEvent('x'));

        $this->assertEquals(1, $count1);
        $this->assertEquals(1, $count2);
    }

    public function testEmitterOffRemovesListener(): void
    {
        $emitter = new StreamEventEmitter();
        $count = 0;

        $id = $emitter->on(function () use (&$count) { $count++; });
        $emitter->emit(new TextDeltaEvent('a'));
        $emitter->off($id);
        $emitter->emit(new TextDeltaEvent('b'));

        $this->assertEquals(1, $count);
    }

    public function testEmitterRecordsHistory(): void
    {
        $emitter = new StreamEventEmitter(recordHistory: true);

        $emitter->emit(new TextDeltaEvent('one'));
        $emitter->emit(new TextDeltaEvent('two'));

        $history = $emitter->getHistory();
        $this->assertCount(2, $history);
        $this->assertEquals('one', $history[0]->text);
    }

    public function testEmitterHistoryNotRecordedByDefault(): void
    {
        $emitter = new StreamEventEmitter();
        $emitter->emit(new TextDeltaEvent('x'));

        $this->assertEmpty($emitter->getHistory());
    }

    public function testEmitterClearHistory(): void
    {
        $emitter = new StreamEventEmitter(recordHistory: true);
        $emitter->emit(new TextDeltaEvent('x'));
        $emitter->clearHistory();

        $this->assertEmpty($emitter->getHistory());
    }

    public function testEmitterToStreamingHandler(): void
    {
        $emitter = new StreamEventEmitter(recordHistory: true);
        $handler = $emitter->toStreamingHandler();

        $this->assertInstanceOf(\SuperAgent\StreamingHandler::class, $handler);

        // Text callback
        $handler->emitText('hello', 'hello');
        $this->assertCount(1, $emitter->getHistory());
        $this->assertInstanceOf(TextDeltaEvent::class, $emitter->getHistory()[0]);
    }
}
