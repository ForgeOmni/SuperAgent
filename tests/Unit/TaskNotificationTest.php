<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Coordinator\TaskNotification;

class TaskNotificationTest extends TestCase
{
    private function makeFullNotification(): TaskNotification
    {
        return new TaskNotification(
            taskId: 'agent-123',
            status: 'completed',
            summary: 'Refactored the auth module',
            result: 'All files updated successfully',
            usage: ['input_tokens' => 1500, 'output_tokens' => 800],
            costUsd: 0.0234,
            durationMs: 4500.5,
            error: null,
            toolsUsed: ['Read', 'Edit', 'Bash'],
            turnCount: 5,
        );
    }

    public function test_construction_with_all_fields(): void
    {
        $n = $this->makeFullNotification();
        $this->assertSame('agent-123', $n->taskId);
        $this->assertSame('completed', $n->status);
        $this->assertSame('Refactored the auth module', $n->summary);
        $this->assertSame('All files updated successfully', $n->result);
        $this->assertSame(['input_tokens' => 1500, 'output_tokens' => 800], $n->usage);
        $this->assertSame(0.0234, $n->costUsd);
        $this->assertSame(4500.5, $n->durationMs);
        $this->assertNull($n->error);
        $this->assertSame(['Read', 'Edit', 'Bash'], $n->toolsUsed);
        $this->assertSame(5, $n->turnCount);
    }

    public function test_construction_with_minimal_fields(): void
    {
        $n = new TaskNotification(
            taskId: 'task-1',
            status: 'failed',
            summary: 'Something broke',
        );
        $this->assertSame('task-1', $n->taskId);
        $this->assertNull($n->result);
        $this->assertNull($n->usage);
        $this->assertNull($n->costUsd);
        $this->assertNull($n->durationMs);
        $this->assertNull($n->error);
        $this->assertSame([], $n->toolsUsed);
        $this->assertNull($n->turnCount);
    }

    public function test_to_xml_output_format(): void
    {
        $n = $this->makeFullNotification();
        $xml = $n->toXml();

        $this->assertStringContainsString('<task-notification>', $xml);
        $this->assertStringContainsString('</task-notification>', $xml);
        $this->assertStringContainsString('<task-id>agent-123</task-id>', $xml);
        $this->assertStringContainsString('<status>completed</status>', $xml);
        $this->assertStringContainsString('<summary>Refactored the auth module</summary>', $xml);
        $this->assertStringContainsString('<result>All files updated successfully</result>', $xml);
        $this->assertStringContainsString('<input_tokens>1500</input_tokens>', $xml);
        $this->assertStringContainsString('<output_tokens>800</output_tokens>', $xml);
        $this->assertStringContainsString('<cost_usd>0.023400</cost_usd>', $xml);
        $this->assertStringContainsString('<duration_ms>4501</duration_ms>', $xml);
        $this->assertStringContainsString('<tools_used>Read, Edit, Bash</tools_used>', $xml);
        $this->assertStringContainsString('<turn_count>5</turn_count>', $xml);
        $this->assertStringNotContainsString('<error>', $xml);
    }

    public function test_to_xml_escapes_special_characters(): void
    {
        $n = new TaskNotification(
            taskId: 'task-<1>',
            status: 'failed',
            summary: 'Error: "foo" & \'bar\'',
            error: 'Unexpected <tag> in output',
        );
        $xml = $n->toXml();

        $this->assertStringContainsString('task-&lt;1&gt;', $xml);
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('&quot;foo&quot;', $xml);
        $this->assertStringContainsString('Unexpected &lt;tag&gt;', $xml);
    }

    public function test_to_xml_omits_null_optional_fields(): void
    {
        $n = new TaskNotification(
            taskId: 'task-1',
            status: 'completed',
            summary: 'Done',
        );
        $xml = $n->toXml();

        $this->assertStringNotContainsString('<result>', $xml);
        $this->assertStringNotContainsString('<error>', $xml);
        $this->assertStringNotContainsString('<usage>', $xml);
        $this->assertStringNotContainsString('<cost_usd>', $xml);
        $this->assertStringNotContainsString('<duration_ms>', $xml);
        $this->assertStringNotContainsString('<tools_used>', $xml);
        $this->assertStringNotContainsString('<turn_count>', $xml);
    }

    public function test_to_text_compact_format(): void
    {
        $n = $this->makeFullNotification();
        $text = $n->toText();

        $this->assertStringContainsString('[completed] Task agent-123', $text);
        $this->assertStringContainsString('Refactored the auth module', $text);
        $this->assertStringContainsString('Cost: $0.0234', $text);
        $this->assertStringContainsString('Turns: 5', $text);
    }

    public function test_to_text_with_error(): void
    {
        $n = new TaskNotification(
            taskId: 'task-2',
            status: 'failed',
            summary: 'Build failed',
            error: 'Syntax error on line 42',
        );
        $text = $n->toText();

        $this->assertStringContainsString('[failed] Task task-2', $text);
        $this->assertStringContainsString('Error: Syntax error on line 42', $text);
    }

    public function test_from_xml_parses_valid_xml(): void
    {
        $n = $this->makeFullNotification();
        $xml = $n->toXml();
        $parsed = TaskNotification::fromXml($xml);

        $this->assertNotNull($parsed);
        $this->assertSame('agent-123', $parsed->taskId);
        $this->assertSame('completed', $parsed->status);
        $this->assertSame('Refactored the auth module', $parsed->summary);
        $this->assertSame('All files updated successfully', $parsed->result);
    }

    public function test_from_xml_returns_null_for_invalid_xml(): void
    {
        $this->assertNull(TaskNotification::fromXml('not xml at all'));
        $this->assertNull(TaskNotification::fromXml('<unclosed'));
    }

    public function test_from_xml_handles_missing_optional_fields(): void
    {
        $xml = '<task-notification><task-id>t1</task-id><status>completed</status><summary>OK</summary></task-notification>';
        $parsed = TaskNotification::fromXml($xml);

        $this->assertNotNull($parsed);
        $this->assertSame('t1', $parsed->taskId);
        $this->assertNull($parsed->result);
        $this->assertNull($parsed->usage);
        $this->assertNull($parsed->costUsd);
        $this->assertNull($parsed->error);
        $this->assertSame([], $parsed->toolsUsed);
        $this->assertNull($parsed->turnCount);
    }

    public function test_from_result_factory(): void
    {
        $n = TaskNotification::fromResult('task-99', 'completed', [
            'summary' => 'Tests passed',
            'result' => 'All 42 tests green',
            'cost_usd' => 0.01,
            'tools_used' => ['Bash'],
            'turn_count' => 2,
        ]);

        $this->assertSame('task-99', $n->taskId);
        $this->assertSame('completed', $n->status);
        $this->assertSame('Tests passed', $n->summary);
        $this->assertSame('All 42 tests green', $n->result);
        $this->assertSame(0.01, $n->costUsd);
        $this->assertSame(['Bash'], $n->toolsUsed);
        $this->assertSame(2, $n->turnCount);
    }

    public function test_from_result_uses_result_as_summary_fallback(): void
    {
        $n = TaskNotification::fromResult('task-1', 'completed', [
            'result' => 'The final output',
        ]);
        $this->assertSame('The final output', $n->summary);
    }

    public function test_from_result_uses_no_summary_fallback(): void
    {
        $n = TaskNotification::fromResult('task-1', 'killed', []);
        $this->assertSame('No summary', $n->summary);
    }

    public function test_round_trip_xml_preserves_data(): void
    {
        $original = $this->makeFullNotification();
        $xml = $original->toXml();
        $parsed = TaskNotification::fromXml($xml);

        $this->assertNotNull($parsed);
        $this->assertSame($original->taskId, $parsed->taskId);
        $this->assertSame($original->status, $parsed->status);
        $this->assertSame($original->summary, $parsed->summary);
        $this->assertSame($original->result, $parsed->result);
        $this->assertSame($original->usage, $parsed->usage);
        $this->assertSame($original->toolsUsed, $parsed->toolsUsed);
        $this->assertSame($original->turnCount, $parsed->turnCount);
        // Float comparison for cost (number_format introduces rounding)
        $this->assertEqualsWithDelta($original->costUsd, $parsed->costUsd, 0.000001);
        // Duration is rounded
        $this->assertEqualsWithDelta($original->durationMs, $parsed->durationMs, 1.0);
    }

    /** @dataProvider statusProvider */
    public function test_status_values(string $status): void
    {
        $n = new TaskNotification(taskId: 'x', status: $status, summary: 's');
        $this->assertSame($status, $n->status);

        $xml = $n->toXml();
        $this->assertStringContainsString("<status>{$status}</status>", $xml);

        $parsed = TaskNotification::fromXml($xml);
        $this->assertSame($status, $parsed->status);
    }

    public static function statusProvider(): array
    {
        return [
            'completed' => ['completed'],
            'failed' => ['failed'],
            'killed' => ['killed'],
            'timeout' => ['timeout'],
        ];
    }

    public function test_usage_section_in_xml(): void
    {
        $n = new TaskNotification(
            taskId: 'u1',
            status: 'completed',
            summary: 'done',
            usage: ['input_tokens' => 100, 'output_tokens' => 50, 'cache_hits' => 3],
        );
        $xml = $n->toXml();

        $this->assertStringContainsString('<usage>', $xml);
        $this->assertStringContainsString('<input_tokens>100</input_tokens>', $xml);
        $this->assertStringContainsString('<output_tokens>50</output_tokens>', $xml);
        $this->assertStringContainsString('<cache_hits>3</cache_hits>', $xml);

        $parsed = TaskNotification::fromXml($xml);
        $this->assertSame(100, $parsed->usage['input_tokens']);
        $this->assertSame(50, $parsed->usage['output_tokens']);
        $this->assertSame(3, $parsed->usage['cache_hits']);
    }

    public function test_tools_used_list(): void
    {
        $n = new TaskNotification(
            taskId: 't',
            status: 'completed',
            summary: 's',
            toolsUsed: ['Read', 'Bash', 'Edit'],
        );
        $xml = $n->toXml();
        $this->assertStringContainsString('<tools_used>Read, Bash, Edit</tools_used>', $xml);

        $parsed = TaskNotification::fromXml($xml);
        $this->assertSame(['Read', 'Bash', 'Edit'], $parsed->toolsUsed);
    }

    public function test_cost_formatting(): void
    {
        $n = new TaskNotification(
            taskId: 'c',
            status: 'completed',
            summary: 's',
            costUsd: 0.1,
        );
        $xml = $n->toXml();
        $this->assertStringContainsString('<cost_usd>0.100000</cost_usd>', $xml);

        $n2 = new TaskNotification(
            taskId: 'c2',
            status: 'completed',
            summary: 's',
            costUsd: 1.23456789,
        );
        $xml2 = $n2->toXml();
        $this->assertStringContainsString('<cost_usd>1.234568</cost_usd>', $xml2);
    }
}
