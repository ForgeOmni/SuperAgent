<?php

namespace SuperAgent\Tests\Unit;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;
use SuperAgent\QueryEngine;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

class QueryEngineTest extends TestCase
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
                return array_map(fn (Message $m) => $m->toArray(), $messages);
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

    public function test_simple_text_response(): void
    {
        $response = $this->makeAssistantMessage(
            [ContentBlock::text('Hello!')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$response]);
        $engine = new QueryEngine($provider);

        $results = [];
        foreach ($engine->run('hi') as $msg) {
            $results[] = $msg;
        }

        $this->assertCount(1, $results);
        $this->assertSame('Hello!', $results[0]->text());
        $this->assertSame(StopReason::EndTurn, $results[0]->stopReason);
    }

    public function test_tool_use_loop(): void
    {
        $toolResponse = $this->makeAssistantMessage(
            [ContentBlock::toolUse('tu_1', 'greet', ['name' => 'World'])],
            StopReason::ToolUse,
        );

        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('The greeting is: Hello, World!')],
            StopReason::EndTurn,
        );

        $tool = new ClosureTool(
            toolName: 'greet',
            toolDescription: 'Greet',
            toolInputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            handler: fn ($input) => ToolResult::success("Hello, {$input['name']}!"),
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine($provider, [$tool]);

        $results = [];
        foreach ($engine->run('greet World') as $msg) {
            $results[] = $msg;
        }

        // Two yields: tool_use response + final text response
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->hasToolUse());
        $this->assertSame('The greeting is: Hello, World!', $results[1]->text());

        // Messages: user + assistant(tool_use) + tool_result + assistant(final)
        $messages = $engine->getMessages();
        $this->assertCount(4, $messages);
    }

    public function test_unknown_tool_returns_error(): void
    {
        $toolResponse = $this->makeAssistantMessage(
            [ContentBlock::toolUse('tu_1', 'nonexistent', [])],
            StopReason::ToolUse,
        );

        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Sorry, tool not found.')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine($provider);

        $results = [];
        foreach ($engine->run('use nonexistent') as $msg) {
            $results[] = $msg;
        }

        $this->assertCount(2, $results);

        // Check the tool_result message contains an error
        $messages = $engine->getMessages();
        $toolResultMsg = $messages[2]; // user -> assistant(tool) -> tool_result
        $arr = $toolResultMsg->toArray();
        $this->assertTrue($arr['content'][0]['is_error']);
        $this->assertStringContainsString('nonexistent', $arr['content'][0]['content']);
    }

    public function test_tool_exception_returns_error_result(): void
    {
        $toolResponse = $this->makeAssistantMessage(
            [ContentBlock::toolUse('tu_1', 'broken', [])],
            StopReason::ToolUse,
        );

        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Tool failed.')],
            StopReason::EndTurn,
        );

        $tool = new ClosureTool(
            toolName: 'broken',
            toolDescription: 'Broken tool',
            toolInputSchema: ['type' => 'object', 'properties' => []],
            handler: fn ($input) => throw new \RuntimeException('kaboom'),
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine($provider, [$tool]);

        $results = [];
        foreach ($engine->run('use broken') as $msg) {
            $results[] = $msg;
        }

        $messages = $engine->getMessages();
        $toolResultArr = $messages[2]->toArray();
        $this->assertTrue($toolResultArr['content'][0]['is_error']);
        $this->assertStringContainsString('kaboom', $toolResultArr['content'][0]['content']);
    }

    public function test_max_turns_exceeded(): void
    {
        // Always returns tool_use, never end_turn
        $toolResponse = $this->makeAssistantMessage(
            [ContentBlock::toolUse('tu_1', 'ping', [])],
            StopReason::ToolUse,
        );

        $tool = new ClosureTool(
            toolName: 'ping',
            toolDescription: 'Ping',
            toolInputSchema: ['type' => 'object', 'properties' => []],
            handler: fn ($input) => ToolResult::success('pong'),
        );

        // Provider always returns the same tool_use response
        $infiniteResponses = array_fill(0, 5, $toolResponse);
        $provider = $this->makeMockProvider($infiniteResponses);
        $engine = new QueryEngine($provider, [$tool], maxTurns: 3);

        $this->expectException(\SuperAgent\Exceptions\SuperAgentException::class);
        $this->expectExceptionMessage('max turns');

        foreach ($engine->run('loop forever') as $msg) {
            // consume generator
        }
    }

    public function test_parallel_tool_use(): void
    {
        $toolResponse = $this->makeAssistantMessage(
            [
                ContentBlock::toolUse('tu_1', 'ping', ['id' => '1']),
                ContentBlock::toolUse('tu_2', 'ping', ['id' => '2']),
            ],
            StopReason::ToolUse,
        );

        $finalResponse = $this->makeAssistantMessage(
            [ContentBlock::text('Both done.')],
            StopReason::EndTurn,
        );

        $tool = new ClosureTool(
            toolName: 'ping',
            toolDescription: 'Ping',
            toolInputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            handler: fn ($input) => ToolResult::success("pong-{$input['id']}"),
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine($provider, [$tool]);

        $results = [];
        foreach ($engine->run('ping twice') as $msg) {
            $results[] = $msg;
        }

        // Tool result message should have 2 results
        $messages = $engine->getMessages();
        $toolResultArr = $messages[2]->toArray();
        $this->assertCount(2, $toolResultArr['content']);
        $this->assertSame('tu_1', $toolResultArr['content'][0]['tool_use_id']);
        $this->assertSame('tu_2', $toolResultArr['content'][1]['tool_use_id']);
    }

    public function test_multi_turn_preserves_history(): void
    {
        $response1 = $this->makeAssistantMessage(
            [ContentBlock::text('Hi!')],
            StopReason::EndTurn,
        );
        $response2 = $this->makeAssistantMessage(
            [ContentBlock::text('Your name is X.')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$response1, $response2]);
        $engine = new QueryEngine($provider);

        // First turn
        foreach ($engine->run('My name is X') as $_) {}
        $this->assertCount(2, $engine->getMessages()); // user + assistant

        // Second turn
        foreach ($engine->run('What is my name?') as $_) {}
        $this->assertCount(4, $engine->getMessages()); // + user + assistant
    }
}
