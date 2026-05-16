<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ParallelStep;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\SquadComposer;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TaskDecomposer;

class ParallelCompositionTest extends TestCase
{
    public function test_decomposer_marks_subtasks_inside_a_parallel_group(): void
    {
        $prompt = "同时调研竞品A的认证方案和竞品B的认证方案，综合两份调研结果给出建议";
        $subs = (new TaskDecomposer())->decompose($prompt);

        $parallel = array_filter($subs, fn ($s) => $s->parallelGroup !== null);
        $this->assertNotEmpty($parallel, 'Decomposer must mark parallel group on "同时" prompts');
    }

    public function test_composer_wraps_parallel_group_in_single_parallel_step(): void
    {
        $subs = [
            new SubTask('upstream',   'execute', 'set up',  DifficultyClass::EASY),
            new SubTask('worker-a',   'research', 'A',       DifficultyClass::EASY, dependsOn: ['upstream'], parallelGroup: 'g1'),
            new SubTask('worker-b',   'research', 'B',       DifficultyClass::EASY, dependsOn: ['upstream'], parallelGroup: 'g1'),
            new SubTask('synthesize', 'design',   'combine', DifficultyClass::MODERATE, dependsOn: ['parallel-g1']),
        ];

        $composed = (new SquadComposer())->compose('test', $subs, 'squad-x');
        $config = $composed['config'];
        $pipeline = $config->getPipeline('test');
        $steps = $pipeline->steps;

        $hasParallel = false;
        foreach ($steps as $step) {
            if ($step instanceof ParallelStep) {
                $hasParallel = true;
                $this->assertSame('parallel-g1', $step->getName());
            }
        }
        $this->assertTrue($hasParallel, 'Group with >1 member must become a ParallelStep');
    }
}
