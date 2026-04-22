<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAgent\Tools\Builtin\AgentTool;

/**
 * Locks down the three productivity states returned by AgentTool when a
 * child sub-agent finishes:
 *
 *   1. `completed` — tool calls were made AND files were written.
 *   2. `completed_no_writes` — tool calls were made, but no file-write tool
 *      was invoked (Write/Edit/MultiEdit/NotebookEdit/Create). This catches
 *      sub-agents that ran Bash/Read/etc. but never persisted output.
 *   3. `completed_empty` — zero tool calls. The child produced only prose,
 *      which in /team runs is the "model described the plan instead of
 *      executing it" failure mode (e.g. the RUN 61 SuperAgent fire-and-forget
 *      symptom). Must be treated as a failure by the orchestrator.
 *
 * Behavior is implementation-private; tested via reflection so the public
 * surface stays small (orchestrators only consume the returned array).
 */
class AgentToolProductivityTest extends TestCase
{
    private function makeAgentToolWithActiveTask(string $agentId): AgentTool
    {
        $tool = new AgentTool();
        $rc = new ReflectionClass($tool);
        $activeTasks = $rc->getProperty('activeTasks');
        $activeTasks->setAccessible(true);
        $activeTasks->setValue($tool, [
            $agentId => [
                'task_id' => 'task_' . $agentId,
                'name' => 'test',
                'backend_instance' => null,
                'started_at' => new \DateTimeImmutable(),
                'tool_counts' => [],
                'files_written' => [],
            ],
        ]);
        return $tool;
    }

    private function recordTool(AgentTool $tool, string $agentId, string $toolName, array $input): void
    {
        $rc = new ReflectionClass($tool);
        $m = $rc->getMethod('recordToolUse');
        $m->setAccessible(true);
        $m->invoke($tool, $agentId, $toolName, $input);
    }

    private function buildInfo(AgentTool $tool, string $agentId, int $childReportedTurns): array
    {
        $rc = new ReflectionClass($tool);
        $m = $rc->getMethod('buildProductivityInfo');
        $m->setAccessible(true);
        return $m->invoke($tool, $agentId, $childReportedTurns);
    }

    public function testCompletedWithWrites(): void
    {
        $agentId = 'agent_a';
        $tool = $this->makeAgentToolWithActiveTask($agentId);

        $this->recordTool($tool, $agentId, 'Read',  ['file_path' => '/tmp/in.md']);
        $this->recordTool($tool, $agentId, 'Write', ['file_path' => '/tmp/out.md']);
        $this->recordTool($tool, $agentId, 'Write', ['file_path' => '/tmp/out.csv']);

        $info = $this->buildInfo($tool, $agentId, 5);

        $this->assertSame('completed', $info['status']);
        $this->assertNull($info['productivityWarning']);
        $this->assertSame(['/tmp/out.md', '/tmp/out.csv'], $info['filesWritten']);
        $this->assertSame(['Read' => 1, 'Write' => 2], $info['toolCallsByName']);
        $this->assertSame(3, $info['totalToolUseCount']); // observed tool uses, not child-reported turns
    }

    public function testToolsRanButNoWritesStaysCompleted(): void
    {
        // As of RUN 72 fix (2026-04-22) "called tools but didn't Write" is
        // no longer a failure status. MINIMAX-style orchestrators over-read
        // the old `completed_no_writes` status as terminal failure and
        // fell back to self-impersonation mid-run. The status stays
        // `completed`; the warning becomes advisory so the caller can
        // decide based on context (advisory consults return text, not files).
        $agentId = 'agent_b';
        $tool = $this->makeAgentToolWithActiveTask($agentId);

        $this->recordTool($tool, $agentId, 'Bash', ['command' => 'ls']);
        $this->recordTool($tool, $agentId, 'Read', ['file_path' => '/tmp/x.md']);

        $info = $this->buildInfo($tool, $agentId, 4);

        $this->assertSame('completed', $info['status'],
            'status must stay `completed` when tool calls happened, even without Write');
        $this->assertNotNull($info['productivityWarning']);
        $this->assertStringContainsString('wrote no files', $info['productivityWarning']);
        $this->assertStringContainsString('advisory', $info['productivityWarning'],
            'warning must frame this as advisory, not a failure');
        $this->assertSame([], $info['filesWritten']);
        $this->assertSame(2, $info['totalToolUseCount']);
    }

    public function testCompletedEmpty(): void
    {
        $agentId = 'agent_c';
        $tool = $this->makeAgentToolWithActiveTask($agentId);

        // No tools recorded — mirrors RUN 61 where SuperAgent reported
        // success:true but the child invoked zero tools.
        $info = $this->buildInfo($tool, $agentId, 3);

        $this->assertSame('completed_empty', $info['status']);
        $this->assertNotNull($info['productivityWarning']);
        $this->assertStringContainsString('zero tool calls', $info['productivityWarning']);
        $this->assertSame([], $info['filesWritten']);
        $this->assertSame([], $info['toolCallsByName']);
        // When nothing was observed, we fall back to the child-reported turn count
        // so callers still see *something* for book-keeping.
        $this->assertSame(3, $info['totalToolUseCount']);
    }

    public function testDuplicatePathsDeduped(): void
    {
        $agentId = 'agent_d';
        $tool = $this->makeAgentToolWithActiveTask($agentId);

        // Edit->Edit->Write on the same file: we keep the file path once.
        $this->recordTool($tool, $agentId, 'Edit',  ['file_path' => '/tmp/a.md']);
        $this->recordTool($tool, $agentId, 'Edit',  ['file_path' => '/tmp/a.md']);
        $this->recordTool($tool, $agentId, 'Write', ['file_path' => '/tmp/a.md']);

        $info = $this->buildInfo($tool, $agentId, 3);

        $this->assertSame('completed', $info['status']);
        $this->assertSame(['/tmp/a.md'], $info['filesWritten']);
        $this->assertSame(['Edit' => 2, 'Write' => 1], $info['toolCallsByName']);
    }

    public function testWriteToolWithoutPathDoesNotPollute(): void
    {
        $agentId = 'agent_e';
        $tool = $this->makeAgentToolWithActiveTask($agentId);

        // A malformed tool_use with no file_path shouldn't count as a write —
        // filesWritten stays empty, but since a tool call DID happen, status
        // is plain `completed` with an advisory warning (post-RUN 72 fix).
        $this->recordTool($tool, $agentId, 'Write', []);

        $info = $this->buildInfo($tool, $agentId, 1);

        $this->assertSame('completed', $info['status']);
        $this->assertNotNull($info['productivityWarning']);
        $this->assertSame([], $info['filesWritten']);
        $this->assertSame(['Write' => 1], $info['toolCallsByName']);
    }
}
