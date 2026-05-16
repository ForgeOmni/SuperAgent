<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TaskDecomposer;

class TaskDecomposerTest extends TestCase
{
    public function test_short_prompt_yields_single_subtask(): void
    {
        $subTasks = (new TaskDecomposer())->decompose('What is 2 + 2?');

        $this->assertCount(1, $subTasks);
        $this->assertSame([], $subTasks[0]->dependsOn);
    }

    public function test_numbered_list_yields_one_subtask_per_item(): void
    {
        $prompt = "Please:\n1. Research the authentication module\n2. Design a new flow\n3. Implement OAuth2";
        $subTasks = (new TaskDecomposer())->decompose($prompt);

        $this->assertCount(3, $subTasks);
        $this->assertSame('research', $subTasks[0]->role);
        $this->assertSame('design',   $subTasks[1]->role);
        $this->assertSame('implement',$subTasks[2]->role);

        $this->assertSame([],                       $subTasks[0]->dependsOn);
        $this->assertSame([$subTasks[0]->name],     $subTasks[1]->dependsOn);
        $this->assertSame([$subTasks[1]->name],     $subTasks[2]->dependsOn);
    }

    public function test_chinese_step_keywords_split_prompt(): void
    {
        $prompt = '先调研竞品的认证方案，然后设计架构，最后实现OAuth2接入';
        $subTasks = (new TaskDecomposer())->decompose($prompt);

        $this->assertCount(3, $subTasks);
    }

    public function test_decide_role_or_review_keyword_forces_human_gate(): void
    {
        $prompt = "1. 调研选题方向\n2. 敲定选题（需要人工审核）\n3. 写稿子";
        $subTasks = (new TaskDecomposer())->decompose($prompt);

        // The "敲定" subtask is the decision step — it must be gated.
        $decisionSubtask = null;
        foreach ($subTasks as $s) {
            if ($s->role === 'decide') {
                $decisionSubtask = $s;
                break;
            }
        }
        $this->assertNotNull($decisionSubtask);
        $this->assertTrue($decisionSubtask->requiresReview);
    }

    public function test_decomposer_returns_subtasks_with_difficulty(): void
    {
        $prompt = "1. Briefly summarise the file structure\n2. Architect a migration strategy for the legacy auth system that handles concurrent writes safely";
        $subTasks = (new TaskDecomposer())->decompose($prompt);

        $this->assertCount(2, $subTasks);

        // The second task is harder — should land in a strictly higher band.
        $bandOrder = ['trivial' => 0, 'easy' => 1, 'moderate' => 2, 'hard' => 3, 'expert' => 4];
        $this->assertGreaterThan(
            $bandOrder[$subTasks[0]->difficulty->value],
            $bandOrder[$subTasks[1]->difficulty->value],
        );
    }
}
