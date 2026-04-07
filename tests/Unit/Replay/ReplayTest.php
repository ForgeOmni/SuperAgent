<?php

namespace SuperAgent\Tests\Unit\Replay;

use PHPUnit\Framework\TestCase;
use SuperAgent\Replay\ReplayRecorder;
use SuperAgent\Replay\ReplayEvent;
use SuperAgent\Replay\ReplayTrace;

class ReplayTest extends TestCase
{
    // ── ReplayEvent ──────────────────────────────────────────────

    public function test_event_type_checks(): void
    {
        $llm = new ReplayEvent(1, ReplayEvent::TYPE_LLM_CALL, 'a1', date('c'), 100.0, []);
        $this->assertTrue($llm->isLlmCall());
        $this->assertFalse($llm->isToolCall());

        $tool = new ReplayEvent(2, ReplayEvent::TYPE_TOOL_CALL, 'a1', date('c'), 50.0, ['tool_name' => 'read']);
        $this->assertTrue($tool->isToolCall());
        $this->assertFalse($tool->isLlmCall());

        $spawn = new ReplayEvent(3, ReplayEvent::TYPE_AGENT_SPAWN, 'a1', date('c'), 0.0, []);
        $this->assertTrue($spawn->isAgentSpawn());

        $msg = new ReplayEvent(4, ReplayEvent::TYPE_AGENT_MESSAGE, 'a1', date('c'), 0.0, []);
        $this->assertTrue($msg->isAgentMessage());

        $snap = new ReplayEvent(5, ReplayEvent::TYPE_STATE_SNAPSHOT, 'a1', date('c'), 0.0, []);
        $this->assertTrue($snap->isStateSnapshot());
    }

    public function test_event_get_data(): void
    {
        $event = new ReplayEvent(1, 'tool_call', 'a1', date('c'), 10.0, [
            'tool_name' => 'bash',
            'exit_code' => 0,
        ]);

        $this->assertEquals('bash', $event->getData('tool_name'));
        $this->assertEquals(0, $event->getData('exit_code'));
        $this->assertNull($event->getData('nonexistent'));
        $this->assertEquals('default', $event->getData('missing', 'default'));
    }

    public function test_event_to_array_roundtrip(): void
    {
        $original = new ReplayEvent(
            step: 42,
            type: ReplayEvent::TYPE_TOOL_CALL,
            agentId: 'agent-x',
            timestamp: '2026-04-07T12:00:00+00:00',
            durationMs: 123.45,
            data: ['tool_name' => 'grep', 'matches' => 5],
        );

        $arr = $original->toArray();
        $restored = ReplayEvent::fromArray($arr);

        $this->assertEquals($original->step, $restored->step);
        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->agentId, $restored->agentId);
        $this->assertEquals($original->timestamp, $restored->timestamp);
        $this->assertEquals($original->durationMs, $restored->durationMs);
        $this->assertEquals($original->data, $restored->data);
    }

    public function test_event_from_array_with_missing_data(): void
    {
        $event = ReplayEvent::fromArray([
            'step' => 1,
            'type' => 'llm_call',
            'agent_id' => 'main',
            'timestamp' => '2026-01-01T00:00:00Z',
            'duration_ms' => 0,
            // 'data' key missing
        ]);

        $this->assertEquals([], $event->data);
    }

    // ── ReplayRecorder ───────────────────────────────────────────

    public function test_recorder_captures_llm_call(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordLlmCall(
            agentId: 'main',
            model: 'claude-sonnet',
            messages: [['role' => 'user', 'content' => 'hello']],
            responseContent: 'Hi there!',
            usage: ['input_tokens' => 100, 'output_tokens' => 20],
            durationMs: 500.0,
        );

        $trace = $recorder->getTrace();
        $events = $trace->events;

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isLlmCall());
        $this->assertEquals('main', $events[0]->agentId);
        $this->assertEquals('claude-sonnet', $events[0]->getData('model'));
    }

    public function test_recorder_captures_tool_call(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordToolCall(
            agentId: 'main',
            toolName: 'read_file',
            toolId: 'tool-1',
            input: ['path' => '/tmp/test.txt'],
            output: 'file contents here',
            durationMs: 50.0,
            isError: false,
        );

        $events = $recorder->getTrace()->events;
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isToolCall());
        $this->assertEquals('read_file', $events[0]->getData('tool_name'));
        $this->assertFalse($events[0]->getData('is_error'));
    }

    public function test_recorder_captures_agent_spawn(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordAgentSpawn(
            agentId: 'child-1',
            parentId: 'main',
            role: 'researcher',
            config: ['model' => 'haiku'],
        );

        $trace = $recorder->getTrace();
        $events = $trace->events;

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isAgentSpawn());
        $this->assertEquals('researcher', $events[0]->getData('role'));
        $this->assertArrayHasKey('child-1', $trace->agents);
    }

    public function test_recorder_captures_agent_message(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordAgentMessage(
            agentId: 'child-1',
            from: 'main',
            to: 'child-1',
            content: 'Please research this topic',
        );

        $events = $recorder->getTrace()->events;
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isAgentMessage());
        $this->assertEquals('main', $events[0]->getData('from'));
    }

    public function test_recorder_captures_state_snapshot(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordStateSnapshot(
            agentId: 'main',
            messages: [['role' => 'user', 'content' => 'hi']],
            turnCount: 5,
            cost: 0.03,
            activeAgents: ['child-1', 'child-2'],
        );

        $events = $recorder->getTrace()->events;
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isStateSnapshot());
        $this->assertEquals(5, $events[0]->getData('turn_count'));
    }

    public function test_recorder_step_counter_increments(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordLlmCall('a', 'm', [], 'r', [], 0.0);
        $recorder->recordToolCall('a', 'read', 'id', [], 'out', 0.0);
        $recorder->recordAgentSpawn('b', 'a', 'worker');

        $this->assertEquals(3, $recorder->getCurrentStep());

        $events = $recorder->getTrace()->events;
        $this->assertEquals(1, $events[0]->step);
        $this->assertEquals(2, $events[1]->step);
        $this->assertEquals(3, $events[2]->step);
    }

    public function test_recorder_should_snapshot(): void
    {
        $recorder = new ReplayRecorder('s', snapshotInterval: 3);

        $this->assertTrue($recorder->shouldSnapshot(3));
        $this->assertTrue($recorder->shouldSnapshot(6));
        $this->assertFalse($recorder->shouldSnapshot(1));
        $this->assertFalse($recorder->shouldSnapshot(4));
    }

    public function test_recorder_should_snapshot_disabled_when_zero(): void
    {
        $recorder = new ReplayRecorder('s', snapshotInterval: 0);

        $this->assertFalse($recorder->shouldSnapshot(0));
        $this->assertFalse($recorder->shouldSnapshot(5));
    }

    public function test_recorder_finalize(): void
    {
        $recorder = new ReplayRecorder('session-1');
        $recorder->recordLlmCall('a', 'm', [], 'r', ['input_tokens' => 10, 'output_tokens' => 5], 0.0);

        $trace = $recorder->finalize();

        $this->assertNotNull($trace->endedAt);
        $this->assertEquals('session-1', $trace->sessionId);
    }

    public function test_recorder_mixed_event_types(): void
    {
        $recorder = new ReplayRecorder('session-1');

        $recorder->recordLlmCall('main', 'opus', [], 'response', ['input_tokens' => 50, 'output_tokens' => 30], 200.0);
        $recorder->recordToolCall('main', 'bash', 't1', ['cmd' => 'ls'], 'file1\nfile2', 100.0);
        $recorder->recordAgentSpawn('child', 'main', 'coder');
        $recorder->recordAgentMessage('child', 'main', 'child', 'do this');
        $recorder->recordStateSnapshot('main', [['role' => 'user', 'content' => 'hi']], 3, 0.01);

        $events = $recorder->getTrace()->events;
        $this->assertCount(5, $events);

        $types = array_map(fn(ReplayEvent $e) => $e->type, $events);
        $this->assertEquals([
            ReplayEvent::TYPE_LLM_CALL,
            ReplayEvent::TYPE_TOOL_CALL,
            ReplayEvent::TYPE_AGENT_SPAWN,
            ReplayEvent::TYPE_AGENT_MESSAGE,
            ReplayEvent::TYPE_STATE_SNAPSHOT,
        ], $types);
    }
}
