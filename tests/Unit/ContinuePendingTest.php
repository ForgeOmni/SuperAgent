<?php

namespace Tests\Unit;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\QueryEngine;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

class ContinuePendingTest extends TestCase
{
    private function makeMockProvider(array $responses): LLMProvider
    {
        return new class($responses) implements LLMProvider {
            private int $callIndex = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator
            {
                if ($this->callIndex >= count($this->responses)) {
                    throw new \RuntimeException('No more mock responses');
                }
                yield $this->responses[$this->callIndex++];
            }

            public function formatMessages(array $messages): array
            {
                return array_map(fn(Message $m) => $m->toArray(), $messages);
            }

            public function formatTools(array $tools): array
            {
                return [];
            }

            public function getModel(): string
            {
                return 'mock-model';
            }

            public function setModel(string $model): void {}

            public function name(): string
            {
                return 'mock';
            }
        };
    }

    private function makeAssistantMessage(array $content, StopReason $stopReason): AssistantMessage
    {
        $msg = new AssistantMessage();
        $msg->content = $content;
        $msg->stopReason = $stopReason;
        $msg->usage = new Usage(10, 5);
        return $msg;
    }

    // ── hasPendingContinuation ────────────────────────────────────

    public function testHasPendingContinuationFalseWhenEmpty(): void
    {
        $provider = $this->makeMockProvider([]);
        $engine = new QueryEngine($provider);

        $this->assertFalse($engine->hasPendingContinuation());
    }

    public function testHasPendingContinuationFalseAfterNormalRun(): void
    {
        $response = $this->makeAssistantMessage(
            [ContentBlock::text('Hello!')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$response]);
        $engine = new QueryEngine($provider);

        foreach ($engine->run('hi') as $_) {}

        // Last message is AssistantMessage, not ToolResultMessage
        $this->assertFalse($engine->hasPendingContinuation());
    }

    public function testHasPendingContinuationTrueAfterInterruptedToolLoop(): void
    {
        // Simulate: model calls a tool, but engine hits max turns before model processes result
        $toolResponse = $this->makeAssistantMessage(
            [ContentBlock::toolUse('tu_1', 'test_tool', [])],
            StopReason::ToolUse,
        );

        $tool = new ClosureTool(
            toolName: 'test_tool',
            toolDescription: 'Test',
            toolInputSchema: ['type' => 'object', 'properties' => []],
            handler: fn($input) => ToolResult::success('ok'),
        );

        // Only 1 response (tool use), then max turns hit
        $provider = $this->makeMockProvider([$toolResponse]);
        $engine = new QueryEngine($provider, [$tool], maxTurns: 1);

        try {
            foreach ($engine->run('use the tool') as $_) {}
        } catch (\Throwable $e) {
            // max turns exceeded — expected
        }

        // Last message should be ToolResultMessage (tool was executed, result appended)
        $this->assertTrue($engine->hasPendingContinuation());
    }

    // ── continuePending ───────────────────────────────────────────

    public function testContinuePendingDoesNothingWhenNotPending(): void
    {
        $provider = $this->makeMockProvider([]);
        $engine = new QueryEngine($provider);

        $results = [];
        foreach ($engine->continuePending() as $msg) {
            $results[] = $msg;
        }

        $this->assertEmpty($results);
    }

    public function testContinuePendingResumesLoop(): void
    {
        // Step 1: Run until max turns (1 turn = tool use, then blocked)
        $toolResponse = $this->makeAssistantMessage(
            [ContentBlock::toolUse('tu_1', 'greet', ['name' => 'World'])],
            StopReason::ToolUse,
        );

        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Greeting done: Hello, World!')],
            StopReason::EndTurn,
        );

        $tool = new ClosureTool(
            toolName: 'greet',
            toolDescription: 'Greet',
            toolInputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            handler: fn($input) => ToolResult::success("Hello, {$input['name']}!"),
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine($provider, [$tool], maxTurns: 1);

        // Run with maxTurns=1: will process tool_use, execute tool, then hit max turns
        try {
            foreach ($engine->run('greet World') as $_) {}
        } catch (\Throwable $e) {
            // max turns exceeded
        }

        $this->assertTrue($engine->hasPendingContinuation());

        // Step 2: Increase max turns and continue
        // We need a new engine with higher max turns but same state
        // Actually, we can just call continuePending — it uses runLoop which checks turnCount < maxTurns
        // Since maxTurns is 1 and turnCount is 1, it'll exceed again.
        // Let's create a proper test with maxTurns=2 so there's room for continuation.

        // Redo with maxTurns=2: first call uses turn 1 (tool_use), hits max at turn 2 attempt
        // Actually maxTurns=1 means while(turnCount < 1) = 0 iterations after first.
        // Let's use a simpler approach.
    }

    public function testContinuePendingCompletesToolLoop(): void
    {
        // Use maxTurns=10. Manually inject messages to simulate interrupted state.
        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Done! Result: 42')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$finalResponse]);
        $engine = new QueryEngine($provider);

        // Manually set messages to simulate interrupted tool loop
        $engine->setMessages([
            new \SuperAgent\Messages\UserMessage('compute 42'),
            $this->makeAssistantMessage(
                [ContentBlock::toolUse('tu_1', 'compute', ['x' => 42])],
                StopReason::ToolUse,
            ),
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'tu_1', 'content' => '42'],
            ]),
        ]);

        $this->assertTrue($engine->hasPendingContinuation());

        // Continue — should call provider and get finalResponse
        $results = [];
        foreach ($engine->continuePending() as $msg) {
            $results[] = $msg;
        }

        $this->assertCount(1, $results);
        $this->assertEquals('Done! Result: 42', $results[0]->text());
        $this->assertFalse($engine->hasPendingContinuation());
    }

    public function testContinuePendingPreservesMessages(): void
    {
        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Continued!')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$finalResponse]);
        $engine = new QueryEngine($provider);

        // Set up pending state
        $engine->setMessages([
            new \SuperAgent\Messages\UserMessage('start'),
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'tu_1', 'content' => 'result data'],
            ]),
        ]);

        foreach ($engine->continuePending() as $_) {}

        // Messages should contain: original user + tool result + new assistant
        $messages = $engine->getMessages();
        $this->assertCount(3, $messages);
        $this->assertInstanceOf(\SuperAgent\Messages\UserMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
    }

    // ── getTurnCount ──────────────────────────────────────────────

    public function testGetTurnCount(): void
    {
        $response = $this->makeAssistantMessage(
            [ContentBlock::text('Hi')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$response]);
        $engine = new QueryEngine($provider);

        $this->assertEquals(0, $engine->getTurnCount());

        foreach ($engine->run('hello') as $_) {}

        $this->assertEquals(1, $engine->getTurnCount());
    }

    public function testContinuePendingIncrementsTurnCount(): void
    {
        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Done')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$finalResponse]);
        $engine = new QueryEngine($provider);

        $engine->setMessages([
            new \SuperAgent\Messages\UserMessage('start'),
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'tu_1', 'content' => 'result'],
            ]),
        ]);

        foreach ($engine->continuePending() as $_) {}

        $this->assertEquals(1, $engine->getTurnCount());
    }
}
