<?php

namespace SuperAgent\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;
use SuperAgent\Tools\Builtin\BashTool;
use SuperAgent\Tools\Builtin\ReadFileTool;
use SuperAgent\Tools\Builtin\WriteFileTool;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

/**
 * Phase 2 smoke tests: streaming callbacks, built-in tools, permissions, budget.
 */
class Phase2SmokeTest extends TestCase
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

    // --- Streaming Callbacks ---

    public function test_streaming_on_text_callback(): void
    {
        $chunks = [];
        $handler = new StreamingHandler(
            onText: function (string $delta, string $full) use (&$chunks) {
                $chunks[] = $delta;
            }
        );

        $agent = $this->makeAgent();
        $result = $agent->prompt('Say "hello world" and nothing else.', $handler);

        $this->assertNotEmpty($chunks, 'Should have received text chunks');
        $this->assertNotEmpty($result->text());
        // Joined chunks should equal the full text
        $this->assertSame($result->text(), implode('', $chunks));
    }

    public function test_streaming_on_tool_use_and_result(): void
    {
        $toolUseCalls = [];
        $toolResults = [];

        $handler = new StreamingHandler(
            onToolUse: function (ContentBlock $block) use (&$toolUseCalls) {
                $toolUseCalls[] = $block->toolName;
            },
            onToolResult: function (string $id, string $name, string $result, bool $isError) use (&$toolResults) {
                $toolResults[] = ['name' => $name, 'isError' => $isError];
            }
        );

        $tool = new ClosureTool(
            toolName: 'get_time',
            toolDescription: 'Get the current time.',
            toolInputSchema: ['type' => 'object', 'properties' => (object) []],
            handler: fn ($input) => ToolResult::success('2026-03-31T12:00:00Z'),
        );

        $agent = $this->makeAgent(['tools' => [$tool]]);
        $agent->prompt('What time is it? Use the get_time tool.', $handler);

        $this->assertContains('get_time', $toolUseCalls);
        $this->assertNotEmpty($toolResults);
        $this->assertFalse($toolResults[0]['isError']);
    }

    public function test_streaming_on_turn_callback(): void
    {
        $turns = [];
        $handler = new StreamingHandler(
            onTurn: function ($msg, int $turn) use (&$turns) {
                $turns[] = $turn;
            }
        );

        $agent = $this->makeAgent();
        $agent->prompt('Say hi.', $handler);

        $this->assertContains(1, $turns);
    }

    // --- Built-in Tools ---

    public function test_agent_with_bash_tool(): void
    {
        $agent = $this->makeAgent([
            'tools' => [new BashTool()],
        ]);

        $result = $agent->prompt('Use the bash tool to run "echo SuperAgent". Reply with just the output.');

        $this->assertStringContainsString('SuperAgent', $result->text());
        $this->assertGreaterThanOrEqual(2, $result->turns());
    }

    public function test_agent_with_file_tools(): void
    {
        $tmpFile = sys_get_temp_dir() . '/sa_smoke_' . uniqid() . '.txt';

        $agent = $this->makeAgent([
            'tools' => [new WriteFileTool(), new ReadFileTool()],
        ]);

        $result = $agent->prompt(
            "Write the text 'SuperAgent rocks' to {$tmpFile}, then read it back and tell me the contents."
        );

        $this->assertStringContainsString('SuperAgent rocks', $result->text());
        $this->assertFileExists($tmpFile);

        unlink($tmpFile);
    }

    // --- Permissions ---

    public function test_denied_tool_not_executed(): void
    {
        $executed = false;
        $tool = new ClosureTool(
            toolName: 'dangerous',
            toolDescription: 'A dangerous tool that should be blocked.',
            toolInputSchema: ['type' => 'object', 'properties' => (object) []],
            handler: function ($input) use (&$executed) {
                $executed = true;
                return ToolResult::success('should not see this');
            },
        );

        $agent = $this->makeAgent([
            'tools' => [$tool],
            'denied_tools' => ['dangerous'],
        ]);

        $result = $agent->prompt('Use the dangerous tool.');

        // Tool should NOT have been executed
        $this->assertFalse($executed, 'Denied tool should not have been executed');
    }

    // --- Cost Tracking ---

    public function test_cost_tracking_in_result(): void
    {
        $agent = $this->makeAgent();
        $result = $agent->prompt('Say "ok".');

        $this->assertGreaterThan(0, $result->totalCostUsd);
    }
}
