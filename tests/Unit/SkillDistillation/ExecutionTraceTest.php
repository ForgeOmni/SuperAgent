<?php

namespace SuperAgent\Tests\Unit\SkillDistillation;

use PHPUnit\Framework\TestCase;
use SuperAgent\SkillDistillation\ExecutionTrace;
use SuperAgent\SkillDistillation\ToolCallRecord;

class ExecutionTraceTest extends TestCase
{
    private function makeTrace(array $toolCalls = [], array $overrides = []): ExecutionTrace
    {
        return new ExecutionTrace(
            originalPrompt: $overrides['prompt'] ?? 'Fix the login bug',
            model: $overrides['model'] ?? 'claude-opus-4-20250514',
            toolCalls: $toolCalls,
            finalOutput: $overrides['finalOutput'] ?? 'Done',
            costUsd: $overrides['costUsd'] ?? 0.50,
            inputTokens: $overrides['inputTokens'] ?? 5000,
            outputTokens: $overrides['outputTokens'] ?? 2000,
            turns: $overrides['turns'] ?? 3,
            createdAt: $overrides['createdAt'] ?? '2026-04-03T10:00:00+00:00',
        );
    }

    private function makeToolCall(string $tool = 'Read', array $input = [], string $output = 'ok'): ToolCallRecord
    {
        return new ToolCallRecord($tool, $input, $output);
    }

    public function test_get_used_tools(): void
    {
        $trace = $this->makeTrace([
            $this->makeToolCall('Read', ['file_path' => '/a.php']),
            $this->makeToolCall('Edit', ['file_path' => '/a.php']),
            $this->makeToolCall('Read', ['file_path' => '/b.php']),
            $this->makeToolCall('Bash', ['command' => 'php test']),
        ]);

        $tools = $trace->getUsedTools();
        $this->assertCount(3, $tools);
        $this->assertContains('Read', $tools);
        $this->assertContains('Edit', $tools);
        $this->assertContains('Bash', $tools);
    }

    public function test_get_tool_sequence_summary(): void
    {
        $trace = $this->makeTrace([
            $this->makeToolCall('Read', ['file_path' => '/src/App.php']),
            $this->makeToolCall('Bash', ['command' => 'phpunit']),
        ]);

        $summary = $trace->getToolSequenceSummary();
        $this->assertCount(2, $summary);
        $this->assertSame('Read', $summary[0]['tool']);
        $this->assertSame('/src/App.php', $summary[0]['input_summary']);
        $this->assertSame('phpunit', $summary[1]['input_summary']);
    }

    public function test_serialization(): void
    {
        $trace = $this->makeTrace([
            $this->makeToolCall('Read', ['file_path' => '/a.php']),
        ]);

        $array = $trace->toArray();
        $restored = ExecutionTrace::fromArray($array);

        $this->assertSame($trace->originalPrompt, $restored->originalPrompt);
        $this->assertSame($trace->model, $restored->model);
        $this->assertSame($trace->costUsd, $restored->costUsd);
        $this->assertCount(1, $restored->toolCalls);
        $this->assertSame('Read', $restored->toolCalls[0]->toolName);
    }
}
