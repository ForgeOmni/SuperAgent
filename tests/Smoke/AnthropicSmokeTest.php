<?php

namespace SuperAgent\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Enums\StopReason;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

/**
 * Smoke tests that hit the real Anthropic API.
 * Requires ANTHROPIC_API_KEY env var.
 *
 * Run: ANTHROPIC_API_KEY=sk-xxx vendor/bin/phpunit --testsuite Smoke
 */
class AnthropicSmokeTest extends TestCase
{
    private ?string $apiKey;

    protected function setUp(): void
    {
        $this->apiKey = getenv('ANTHROPIC_API_KEY') ?: null;

        if (empty($this->apiKey)) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }
    }

    private function makeAgent(array $extra = []): Agent
    {
        return new Agent(array_merge([
            'api_key' => $this->apiKey,
            'model' => 'claude-sonnet-4-20250514',
        ], $extra));
    }

    public function test_simple_chat(): void
    {
        $agent = $this->makeAgent();
        $result = $agent->prompt('What is 2+2? Reply with just the number.');

        $this->assertNotEmpty($result->text());
        $this->assertStringContainsString('4', $result->text());
        $this->assertSame(1, $result->turns());
        $this->assertGreaterThan(0, $result->totalUsage()->inputTokens);
        $this->assertGreaterThan(0, $result->totalUsage()->outputTokens);
    }

    public function test_tool_use(): void
    {
        $tool = new ClosureTool(
            toolName: 'get_weather',
            toolDescription: 'Get current weather for a city.',
            toolInputSchema: [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['city'],
            ],
            handler: fn ($input) => ToolResult::success("Weather in {$input['city']}: Rainy, 15°C"),
        );

        $agent = $this->makeAgent(['tools' => [$tool]]);
        $result = $agent->prompt('What is the weather in Paris?');

        $this->assertNotEmpty($result->text());
        // Agent should have used the tool (2 turns: tool_use + final)
        $this->assertGreaterThanOrEqual(2, $result->turns());
        // Final answer should reference the weather data
        $this->assertTrue(
            str_contains(strtolower($result->text()), 'rain') ||
            str_contains($result->text(), '15'),
            'Response should contain weather data from tool'
        );
    }

    public function test_parallel_tool_use(): void
    {
        $tool = new ClosureTool(
            toolName: 'lookup',
            toolDescription: 'Look up a value by key.',
            toolInputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string'],
                ],
                'required' => ['key'],
            ],
            handler: fn ($input) => ToolResult::success("Value for {$input['key']}: 42"),
        );

        $agent = $this->makeAgent(['tools' => [$tool]]);
        $result = $agent->prompt('Look up both "alpha" and "beta" for me. Use the lookup tool for each.');

        $this->assertNotEmpty($result->text());
        $this->assertGreaterThanOrEqual(2, $result->turns());
    }

    public function test_multi_turn_memory(): void
    {
        $agent = $this->makeAgent();

        $r1 = $agent->prompt('My favorite color is blue. Just acknowledge.');
        $this->assertNotEmpty($r1->text());

        $r2 = $agent->prompt('What is my favorite color? Reply with just the color.');
        $this->assertStringContainsString('blue', strtolower($r2->text()));
        $this->assertCount(4, $agent->getMessages()); // user+assistant+user+assistant
    }

    public function test_system_prompt(): void
    {
        $agent = $this->makeAgent();
        $agent->withSystemPrompt('You are a pirate. Always reply in pirate speak. Keep it to one sentence.');

        $result = $agent->prompt('Hello, how are you?');

        $text = strtolower($result->text());
        $this->assertTrue(
            str_contains($text, 'arr') ||
            str_contains($text, 'ahoy') ||
            str_contains($text, 'matey') ||
            str_contains($text, 'ye') ||
            str_contains($text, 'sail') ||
            str_contains($text, 'sea'),
            "Expected pirate speak, got: {$result->text()}"
        );
    }

    public function test_stream_turns(): void
    {
        $tool = new ClosureTool(
            toolName: 'ping',
            toolDescription: 'Returns pong.',
            toolInputSchema: ['type' => 'object', 'properties' => []],
            handler: fn ($input) => ToolResult::success('pong'),
        );

        $agent = $this->makeAgent(['tools' => [$tool]]);

        $turns = [];
        foreach ($agent->stream('Use the ping tool, then say "done".') as $msg) {
            $turns[] = $msg;
        }

        $this->assertGreaterThanOrEqual(2, count($turns));
        $this->assertTrue($turns[0]->hasToolUse());
        $this->assertSame(StopReason::EndTurn, end($turns)->stopReason);
    }

    public function test_clear_resets_conversation(): void
    {
        $agent = $this->makeAgent();
        $agent->prompt('My name is Test.');

        $this->assertNotEmpty($agent->getMessages());
        $agent->clear();
        $this->assertEmpty($agent->getMessages());
    }
}
