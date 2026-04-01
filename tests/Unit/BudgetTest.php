<?php

namespace SuperAgent\Tests\Unit;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\SuperAgentException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;
use SuperAgent\QueryEngine;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

class BudgetTest extends TestCase
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
            public function getModel(): string { return 'claude-sonnet-4-20250514'; }
            public function setModel(string $model): void {}
            public function name(): string { return 'mock'; }
        };
    }

    public function test_budget_enforced(): void
    {
        // Each response uses ~1M tokens = ~$18 per turn
        $makeResponse = function (StopReason $stop): AssistantMessage {
            $msg = new AssistantMessage();
            $msg->content = $stop === StopReason::ToolUse
                ? [ContentBlock::toolUse('tu_1', 'ping', [])]
                : [ContentBlock::text('done')];
            $msg->stopReason = $stop;
            $msg->usage = new Usage(500_000, 500_000); // ~$9 per turn
            return $msg;
        };

        $tool = new ClosureTool(
            toolName: 'ping',
            toolDescription: 'Ping',
            toolInputSchema: ['type' => 'object', 'properties' => (object) []],
            handler: fn ($input) => ToolResult::success('pong'),
        );

        // 3 tool turns = ~$27, budget = $20
        $responses = [
            $makeResponse(StopReason::ToolUse),
            $makeResponse(StopReason::ToolUse),
            $makeResponse(StopReason::ToolUse),
            $makeResponse(StopReason::EndTurn),
        ];

        $provider = $this->makeMockProvider($responses);
        $engine = new QueryEngine(
            $provider,
            [$tool],
            maxBudgetUsd: 20.0,
        );

        $this->expectException(SuperAgentException::class);
        $this->expectExceptionMessage('Budget exhausted');

        foreach ($engine->run('loop') as $_) {}
    }

    public function test_cost_tracking(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('hi')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(1000, 500);

        $provider = $this->makeMockProvider([$msg]);
        $engine = new QueryEngine($provider);

        foreach ($engine->run('hello') as $_) {}

        $cost = $engine->getTotalCostUsd();
        $this->assertGreaterThan(0, $cost);
    }

    public function test_zero_budget_means_unlimited(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('hi')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(1_000_000, 1_000_000);

        $provider = $this->makeMockProvider([$msg]);
        $engine = new QueryEngine($provider, maxBudgetUsd: 0.0);

        $results = [];
        foreach ($engine->run('hello') as $m) {
            $results[] = $m;
        }

        $this->assertCount(1, $results);
    }
}
