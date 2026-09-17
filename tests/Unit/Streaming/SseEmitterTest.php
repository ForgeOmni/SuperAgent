<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Streaming\SseEmitter;

/**
 * A run's stream, on the wire of a web response (1.6.0).
 *
 * Nothing here touches symfony/console: this is the path a controller uses,
 * and it is tested with a string buffer as its sink, exactly as a
 * StreamedResponse would use `echo`.
 */
class SseEmitterTest extends TestCase
{
    public function test_a_handler_writes_one_frame_per_event(): void
    {
        $frames = '';
        $emitter = new SseEmitter(function (string $frame) use (&$frames): void {
            $frames .= $frame;
        });

        $handler = $emitter->handler();

        $handler->emitText('Hel', 'Hel');
        $handler->emitText('lo', 'Hello');
        $handler->emitToolUse(ContentBlock::toolUse('call-1', 'get_order', ['id' => 42]));
        $handler->emitToolResult('call-1', 'get_order', '{"id":42}', false);

        $message = new AssistantMessage();
        $message->content = [ContentBlock::text('Hello')];
        $handler->emitFinalMessage($message);

        $emitter->close(['cost_usd' => 0.01]);

        $events = $this->parse($frames);

        $this->assertSame(
            ['text', 'text', 'tool_use', 'tool_result', 'final', 'done'],
            array_column($events, 'event')
        );
        $this->assertSame('lo', $events[1]['data']['delta']);
        $this->assertSame('get_order', $events[2]['data']['name']);
        $this->assertSame('Hello', $events[4]['data']['text']);
        $this->assertSame(0.01, $events[5]['data']['cost_usd']);
    }

    public function test_a_newline_in_the_model_output_cannot_break_the_frame(): void
    {
        // A raw newline inside a frame ends it — which is how half an answer
        // becomes a malformed event. The payload is JSON for exactly this.
        $frames = '';
        $emitter = new SseEmitter(function (string $frame) use (&$frames): void {
            $frames .= $frame;
        });

        $emitter->handler()->emitText("line one\nline two\n\nline three", 'x');

        $events = $this->parse($frames);

        $this->assertCount(1, $events);
        $this->assertSame("line one\nline two\n\nline three", $events[0]['data']['delta']);
    }

    public function test_unicode_survives_intact(): void
    {
        $frames = '';
        $emitter = new SseEmitter(function (string $frame) use (&$frames): void {
            $frames .= $frame;
        });

        $emitter->handler()->emitText('订单已取消', '订单已取消');

        $this->assertStringContainsString('订单已取消', $frames, 'unescaped so a proxy log stays readable');
        $this->assertSame('订单已取消', $this->parse($frames)[0]['data']['delta']);
    }

    public function test_keep_alive_is_a_comment_frame(): void
    {
        $frames = '';
        $emitter = new SseEmitter(function (string $frame) use (&$frames): void {
            $frames .= $frame;
        });

        $emitter->keepAlive();

        $this->assertSame(": keep-alive\n\n", $frames);
        $this->assertSame([], $this->parse($frames), 'a client sees no event');
    }

    public function test_nothing_is_written_after_close(): void
    {
        $frames = '';
        $emitter = new SseEmitter(function (string $frame) use (&$frames): void {
            $frames .= $frame;
        });

        $emitter->close();
        $before = $frames;

        $emitter->send('text', ['delta' => 'late']);
        $emitter->keepAlive();
        $emitter->close();

        $this->assertSame($before, $frames);
        $this->assertTrue($emitter->isClosed());
    }

    public function test_the_response_headers_include_the_one_people_forget(): void
    {
        // Without X-Accel-Buffering: no, nginx buffers the whole response and
        // it arrives in one block — indistinguishable from a streaming bug in
        // the application.
        $this->assertSame('text/event-stream', SseEmitter::HEADERS['Content-Type']);
        $this->assertSame('no', SseEmitter::HEADERS['X-Accel-Buffering']);
        $this->assertStringContainsString('no-transform', SseEmitter::HEADERS['Cache-Control']);
    }

    /** @return list<array{event:string,data:array}> */
    private function parse(string $frames): array
    {
        $events = [];

        foreach (explode("\n\n", trim($frames)) as $block) {
            if ($block === '' || str_starts_with($block, ':')) {
                continue;
            }

            $event = null;
            $data = null;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                }
            }

            if ($event !== null) {
                $events[] = ['event' => $event, 'data' => $data ?? []];
            }
        }

        return $events;
    }
}
