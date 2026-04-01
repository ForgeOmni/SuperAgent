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

class PermissionTest extends TestCase
{
    private function makeMockProvider(array $responses): LLMProvider
    {
        return new class($responses) implements LLMProvider {
            private int $i = 0;
            public function __construct(private array $responses) {}
            public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator
            {
                yield $this->responses[$this->i++];
            }
            public function formatMessages(array $messages): array { return []; }
            public function formatTools(array $tools): array { return []; }
            public function getModel(): string { return 'mock'; }
            public function setModel(string $model): void {}
            public function name(): string { return 'mock'; }
        };
    }

    private function makeMsg(array $content, StopReason $stop): AssistantMessage
    {
        $msg = new AssistantMessage();
        $msg->content = $content;
        $msg->stopReason = $stop;
        $msg->usage = new Usage(10, 5);
        return $msg;
    }

    private function makePingTool(): ClosureTool
    {
        return new ClosureTool(
            toolName: 'ping',
            toolDescription: 'Ping',
            toolInputSchema: ['type' => 'object', 'properties' => (object) []],
            handler: fn ($input) => ToolResult::success('pong'),
        );
    }

    private function makeSecretTool(): ClosureTool
    {
        return new ClosureTool(
            toolName: 'secret',
            toolDescription: 'Secret tool',
            toolInputSchema: ['type' => 'object', 'properties' => (object) []],
            handler: fn ($input) => ToolResult::success('classified'),
        );
    }

    public function test_denied_tool_returns_error(): void
    {
        $toolResponse = $this->makeMsg(
            [ContentBlock::toolUse('tu_1', 'secret', [])],
            StopReason::ToolUse,
        );
        $finalResponse = $this->makeMsg(
            [ContentBlock::text('ok')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine(
            $provider,
            [$this->makePingTool(), $this->makeSecretTool()],
            deniedTools: ['secret'],
        );

        foreach ($engine->run('use secret') as $_) {}

        $messages = $engine->getMessages();
        $toolResultArr = $messages[2]->toArray();
        $this->assertTrue($toolResultArr['content'][0]['is_error']);
        $this->assertStringContainsString('not permitted', $toolResultArr['content'][0]['content']);
    }

    public function test_allowed_tool_whitelist(): void
    {
        $toolResponse = $this->makeMsg(
            [ContentBlock::toolUse('tu_1', 'secret', [])],
            StopReason::ToolUse,
        );
        $finalResponse = $this->makeMsg(
            [ContentBlock::text('ok')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine(
            $provider,
            [$this->makePingTool(), $this->makeSecretTool()],
            allowedTools: ['ping'], // only ping allowed
        );

        foreach ($engine->run('use secret') as $_) {}

        $messages = $engine->getMessages();
        $toolResultArr = $messages[2]->toArray();
        $this->assertTrue($toolResultArr['content'][0]['is_error']);
        $this->assertStringContainsString('not permitted', $toolResultArr['content'][0]['content']);
    }

    public function test_allowed_tool_passes(): void
    {
        $toolResponse = $this->makeMsg(
            [ContentBlock::toolUse('tu_1', 'ping', [])],
            StopReason::ToolUse,
        );
        $finalResponse = $this->makeMsg(
            [ContentBlock::text('pong received')],
            StopReason::EndTurn,
        );

        $provider = $this->makeMockProvider([$toolResponse, $finalResponse]);
        $engine = new QueryEngine(
            $provider,
            [$this->makePingTool()],
            allowedTools: ['ping'],
        );

        foreach ($engine->run('ping') as $_) {}

        $messages = $engine->getMessages();
        $toolResultArr = $messages[2]->toArray();
        $this->assertFalse($toolResultArr['content'][0]['is_error'] ?? false);
        $this->assertStringContainsString('pong', $toolResultArr['content'][0]['content']);
    }
}
