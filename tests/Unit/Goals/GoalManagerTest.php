<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Goals;

use PHPUnit\Framework\TestCase;
use SuperAgent\Goals\GoalAlreadyExistsException;
use SuperAgent\Goals\GoalManager;
use SuperAgent\Goals\GoalStatus;
use SuperAgent\Goals\InMemoryGoalStore;
use SuperAgent\Tools\Builtin\CreateGoalTool;
use SuperAgent\Tools\Builtin\GetGoalTool;
use SuperAgent\Tools\Builtin\UpdateGoalTool;

class GoalManagerTest extends TestCase
{
    public function test_create_returns_active_goal(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $goal = $manager->create('thread-1', 'ship the P0-1 fix', 1000);
        $this->assertSame(GoalStatus::Active, $goal->status);
        $this->assertSame('ship the P0-1 fix', $goal->objective);
        $this->assertSame(1000, $goal->tokenBudget);
        $this->assertSame(0, $goal->tokensUsed);
    }

    public function test_create_rejects_empty_objective(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $this->expectException(\InvalidArgumentException::class);
        $manager->create('thread-1', '   ', null);
    }

    public function test_create_rejects_non_positive_budget(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $this->expectException(\InvalidArgumentException::class);
        $manager->create('thread-1', 'do thing', 0);
    }

    public function test_create_fails_when_active_goal_exists(): void
    {
        // Codex contract: create_goal fails if an active goal already
        // exists. Forces the model to call update_goal first.
        $manager = new GoalManager(new InMemoryGoalStore());
        $manager->create('thread-1', 'first', null);
        $this->expectException(GoalAlreadyExistsException::class);
        $manager->create('thread-1', 'second', null);
    }

    public function test_complete_frees_thread_for_new_goal(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $g1 = $manager->create('thread-1', 'first', null);
        $manager->markComplete($g1->id);
        $g2 = $manager->create('thread-1', 'second', null);
        $this->assertSame('second', $g2->objective);
    }

    public function test_record_usage_increments_total_and_flips_to_budget_limited(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $goal = $manager->create('thread-1', 'do thing', 100);

        $after1 = $manager->recordUsage($goal->id, 60);
        $this->assertSame(60, $after1->tokensUsed);
        $this->assertSame(GoalStatus::Active, $after1->status);

        // Crossing the budget flips status. The model should see this
        // on the next `get_goal` call and act on the budget_limit prompt.
        $after2 = $manager->recordUsage($goal->id, 50);
        $this->assertSame(110, $after2->tokensUsed);
        $this->assertSame(GoalStatus::BudgetLimited, $after2->status);
    }

    public function test_continuation_prompt_renders_template_with_objective_wrapped(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $goal = $manager->create('thread-1', 'do the thing', 500);
        $rendered = $manager->renderContinuationPrompt($goal);
        // Template constants present.
        $this->assertStringContainsString('Continue working toward the active thread goal', $rendered);
        // Objective is wrapped — the raw string MUST be inside an
        // untrusted_objective block to neutralise prompt injection.
        $this->assertStringContainsString('<untrusted_objective>', $rendered);
        $this->assertStringContainsString('do the thing', $rendered);
        $this->assertStringContainsString('</untrusted_objective>', $rendered);
        // Budget numbers interpolated.
        $this->assertStringContainsString('Token budget: 500', $rendered);
        $this->assertStringContainsString('Tokens remaining: 500', $rendered);
    }

    public function test_budget_limit_prompt_renders_with_budget(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $goal = $manager->create('thread-1', 'objective text', 100);
        $exhausted = $manager->recordUsage($goal->id, 100);
        $rendered = $manager->renderBudgetLimitPrompt($exhausted);
        $this->assertStringContainsString('reached its token budget', $rendered);
        $this->assertStringContainsString('<untrusted_objective>', $rendered);
        $this->assertStringContainsString('Token budget: 100', $rendered);
    }

    // ── Tools ─────────────────────────────────────────────────────

    public function test_create_goal_tool_returns_serialised_goal(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $tool = new CreateGoalTool($manager, 'thread-1');
        $r = $tool->execute(['objective' => 'do thing', 'token_budget' => 200]);
        $this->assertTrue($r->isSuccess());
        $payload = $r->content;
        $this->assertTrue($payload['created']);
        $this->assertSame('do thing', $payload['goal']['objective']);
        $this->assertSame(200, $payload['goal']['token_budget']);
    }

    public function test_create_goal_tool_returns_error_when_one_exists(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $tool = new CreateGoalTool($manager, 'thread-1');
        $tool->execute(['objective' => 'first']);
        $r = $tool->execute(['objective' => 'second']);
        $this->assertTrue($r->isError);
        $this->assertStringContainsString('already has goal', $r->contentAsString());
    }

    public function test_get_goal_tool_returns_null_when_none_exists(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $tool = new GetGoalTool($manager, 'thread-1');
        $r = $tool->execute([]);
        $this->assertTrue($r->isSuccess());
        $this->assertNull($r->content['goal']);
    }

    public function test_update_goal_tool_only_accepts_complete_status(): void
    {
        $manager = new GoalManager(new InMemoryGoalStore());
        $manager->create('thread-1', 'do thing');
        $tool = new UpdateGoalTool($manager, 'thread-1');
        // Codex contract — only `complete` is accepted from the model.
        $r = $tool->execute(['status' => 'paused']);
        $this->assertTrue($r->isError);
        $this->assertStringContainsString('only status=`complete`', $r->contentAsString());
    }

    public function test_update_goal_tool_marks_complete_and_returns_final_usage(): void
    {
        $store = new InMemoryGoalStore();
        $manager = new GoalManager($store);
        $goal = $manager->create('thread-1', 'do thing', 1000);
        $manager->recordUsage($goal->id, 250);
        $tool = new UpdateGoalTool($manager, 'thread-1');
        $r = $tool->execute(['status' => 'complete']);
        $this->assertTrue($r->isSuccess());
        $this->assertSame(GoalStatus::Complete->value, $r->content['goal']['status']);
        $this->assertSame(250, $r->content['final_tokens_used']);
    }

    public function test_update_goal_tool_input_schema_constrains_status_enum(): void
    {
        // The wire shape MUST advertise the enum so OpenAI-compatible
        // servers reject `paused` etc. before the model gets ideas.
        $tool = new UpdateGoalTool(new GoalManager(new InMemoryGoalStore()), 'thread-1');
        $schema = $tool->inputSchema();
        $this->assertSame(['complete'], $schema['properties']['status']['enum']);
    }
}
