<?php

namespace SuperAgent\Tests\Unit\Fork;

use PHPUnit\Framework\TestCase;
use SuperAgent\Fork\ForkBranch;
use SuperAgent\Fork\ForkResult;
use SuperAgent\Fork\ForkScorer;
use SuperAgent\Fork\ForkSession;

class ForkTest extends TestCase
{
    // ── ForkBranch ────────────────────────────────────────────────

    public function test_branch_starts_pending(): void
    {
        $branch = new ForkBranch('write tests');
        $this->assertTrue($branch->isPending());
        $this->assertFalse($branch->isRunning());
        $this->assertFalse($branch->isCompleted());
        $this->assertFalse($branch->isFailed());
    }

    public function test_branch_lifecycle_pending_running_completed(): void
    {
        $branch = new ForkBranch('implement feature');

        $branch->markRunning();
        $this->assertTrue($branch->isRunning());
        $this->assertFalse($branch->isPending());

        $branch->markCompleted(
            messages: [['role' => 'assistant', 'content' => 'Done']],
            cost: 0.05,
            turns: 3,
            durationMs: 1500.0,
        );

        $this->assertTrue($branch->isCompleted());
        $this->assertFalse($branch->isRunning());
        $this->assertEquals(0.05, $branch->cost);
        $this->assertEquals(3, $branch->turns);
        $this->assertEquals(1500.0, $branch->durationMs);
    }

    public function test_branch_failure(): void
    {
        $branch = new ForkBranch('risky operation');
        $branch->markRunning();
        $branch->markFailed('Out of budget', 500.0);

        $this->assertTrue($branch->isFailed());
        $this->assertEquals('Out of budget', $branch->error);
        $this->assertEquals(500.0, $branch->durationMs);
    }

    public function test_branch_last_assistant_message(): void
    {
        $branch = new ForkBranch('prompt');
        $branch->markCompleted(
            messages: [
                ['role' => 'user', 'content' => 'hello'],
                ['role' => 'assistant', 'content' => 'first reply'],
                ['role' => 'user', 'content' => 'follow up'],
                ['role' => 'assistant', 'content' => 'final answer'],
            ],
            cost: 0.01,
            turns: 2,
            durationMs: 100.0,
        );

        $this->assertEquals('final answer', $branch->getLastAssistantMessage());
    }

    public function test_branch_last_assistant_message_returns_null_when_no_messages(): void
    {
        $branch = new ForkBranch('prompt');
        $this->assertNull($branch->getLastAssistantMessage());
    }

    public function test_branch_to_array_contains_all_fields(): void
    {
        $branch = new ForkBranch('test prompt', ['model' => 'opus']);
        $branch->markCompleted([['role' => 'assistant', 'content' => 'ok']], 0.02, 1, 200.0);
        $branch->score = 0.95;

        $arr = $branch->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertEquals('test prompt', $arr['prompt']);
        $this->assertEquals('completed', $arr['status']);
        $this->assertEquals(0.02, $arr['cost']);
        $this->assertEquals(1, $arr['turns']);
        $this->assertEquals(0.95, $arr['score']);
        $this->assertEquals(200.0, $arr['duration_ms']);
        $this->assertEquals(['model' => 'opus'], $arr['config']);
        $this->assertEquals('ok', $arr['last_message']);
    }

    // ── ForkSession ──────────────────────────────────────────────

    public function test_session_creation(): void
    {
        $session = new ForkSession(
            baseMessages: [['role' => 'user', 'content' => 'hi']],
            forkPoint: 5,
            config: ['model' => 'sonnet'],
        );

        $this->assertNotEmpty($session->id);
        $this->assertEquals(5, $session->forkPoint);
        $this->assertEquals(0, $session->getBranchCount());
    }

    public function test_session_add_branches(): void
    {
        $session = new ForkSession([], 0);

        $b1 = $session->addBranch('prompt A');
        $b2 = $session->addBranch('prompt B', ['model' => 'haiku']);

        $this->assertEquals(2, $session->getBranchCount());
        $this->assertEquals('prompt A', $b1->prompt);
        $this->assertEquals('prompt B', $b2->prompt);
        $this->assertArrayHasKey('model', $b2->config);
    }

    public function test_session_get_branch_by_id(): void
    {
        $session = new ForkSession([], 0);
        $added = $session->addBranch('find me');

        $found = $session->getBranch($added->id);
        $this->assertSame($added, $found);

        $this->assertNull($session->getBranch('nonexistent'));
    }

    public function test_session_to_array(): void
    {
        $session = new ForkSession([['role' => 'user', 'content' => 'test']], 3);
        $session->addBranch('branch 1');
        $session->addBranch('branch 2');

        $arr = $session->toArray();

        $this->assertEquals($session->id, $arr['id']);
        $this->assertEquals(3, $arr['fork_point']);
        $this->assertEquals(2, $arr['branch_count']);
        $this->assertCount(2, $arr['branches']);
    }

    public function test_session_config_merged_into_branches(): void
    {
        $session = new ForkSession([], 0, ['max_turns' => 10]);
        $branch = $session->addBranch('test', ['temperature' => 0.5]);

        $this->assertEquals(10, $branch->config['max_turns']);
        $this->assertEquals(0.5, $branch->config['temperature']);
    }

    // ── ForkScorer ───────────────────────────────────────────────

    public function test_scorer_cost_efficiency(): void
    {
        $cheap = new ForkBranch('cheap');
        $cheap->cost = 0.01;

        $expensive = new ForkBranch('expensive');
        $expensive->cost = 1.0;

        $this->assertGreaterThan(
            ForkScorer::costEfficiency($expensive),
            ForkScorer::costEfficiency($cheap),
        );
    }

    public function test_scorer_cost_efficiency_zero_cost_returns_max(): void
    {
        $free = new ForkBranch('free');
        $free->cost = 0.0;

        $this->assertEquals(1.0, ForkScorer::costEfficiency($free));
    }

    public function test_scorer_brevity(): void
    {
        $short = new ForkBranch('short');
        $short->turns = 1;

        $long = new ForkBranch('long');
        $long->turns = 10;

        $this->assertGreaterThan(
            ForkScorer::brevity($long),
            ForkScorer::brevity($short),
        );
    }

    public function test_scorer_completeness(): void
    {
        $branch = new ForkBranch('test');
        $branch->resultMessages = [
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'read'],
                ['type' => 'text', 'text' => 'reading...'],
                ['type' => 'tool_use', 'name' => 'edit'],
            ]],
        ];

        $this->assertEquals(2.0, ForkScorer::completeness($branch));
    }

    public function test_scorer_composite(): void
    {
        $branch = new ForkBranch('test');
        $branch->cost = 0.1;
        $branch->turns = 2;
        $branch->resultMessages = [];

        $scorer = ForkScorer::composite(
            [ForkScorer::costEfficiency(...), ForkScorer::brevity(...)],
            [0.5, 0.5],
        );

        $score = $scorer($branch);
        $this->assertGreaterThan(0, $score);
    }

    // ── ForkResult ───────────────────────────────────────────────

    public function test_result_get_completed_and_failed(): void
    {
        $b1 = new ForkBranch('a');
        $b1->markCompleted([['role' => 'assistant', 'content' => 'ok']], 0.01, 1, 100.0);

        $b2 = new ForkBranch('b');
        $b2->markFailed('error', 50.0);

        $b3 = new ForkBranch('c');
        $b3->markCompleted([['role' => 'assistant', 'content' => 'also ok']], 0.02, 2, 200.0);

        $result = new ForkResult(
            sessionId: 'test-session',
            branches: [$b1, $b2, $b3],
            totalCost: 0.03,
            totalDurationMs: 350.0,
            completedCount: 2,
            failedCount: 1,
        );

        $this->assertCount(2, $result->getCompleted());
        $this->assertCount(1, $result->getFailed());
    }

    public function test_result_get_best_branch(): void
    {
        $cheap = new ForkBranch('cheap');
        $cheap->markCompleted([], 0.001, 1, 50.0);

        $expensive = new ForkBranch('expensive');
        $expensive->markCompleted([], 1.0, 10, 5000.0);

        $result = new ForkResult('s', [$cheap, $expensive], 1.001, 5050.0, 2, 0);

        $best = $result->getBest(ForkScorer::costEfficiency(...));
        $this->assertSame($cheap, $best);
    }

    public function test_result_get_ranked_assigns_scores(): void
    {
        $b1 = new ForkBranch('a');
        $b1->markCompleted([], 0.5, 5, 500.0);

        $b2 = new ForkBranch('b');
        $b2->markCompleted([], 0.1, 1, 100.0);

        $result = new ForkResult('s', [$b1, $b2], 0.6, 600.0, 2, 0);
        $ranked = $result->getRanked(ForkScorer::costEfficiency(...));

        $this->assertNotNull($ranked[0]->score);
        $this->assertNotNull($ranked[1]->score);
        $this->assertGreaterThanOrEqual($ranked[1]->score, $ranked[0]->score);
    }

    public function test_result_summary(): void
    {
        $b = new ForkBranch('test');
        $b->markCompleted([], 0.01, 1, 100.0);

        $result = new ForkResult('sess-1', [$b], 0.01, 100.0, 1, 0);
        $summary = $result->getSummary();

        $this->assertEquals('sess-1', $summary['session_id']);
        $this->assertEquals(1, $summary['total_branches']);
        $this->assertEquals(1, $summary['completed']);
        $this->assertEquals(0, $summary['failed']);
    }
}
