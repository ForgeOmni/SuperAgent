<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\Usage;
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Tools\Builtin\WorkflowTool;

/**
 * Dynamic-workflow behavior of {@see WorkflowTool}: planning, the
 * PipelineEngine bridge, caller-selectable run mode, and backward
 * compatibility with the original static workflows. Also pins the
 * Opus 4.8 pricing row.
 */
class WorkflowToolDynamicTest extends TestCase
{
    private function tool(): WorkflowTool
    {
        return new WorkflowTool();
    }

    public function test_create_dynamic_workflow_records_strategy_and_guards(): void
    {
        $t = $this->tool();
        $res = $t->execute([
            'action' => 'create', 'name' => 'crawl', 'type' => 'dynamic', 'strategy' => 'loop_until',
            'guards' => ['max_iterations' => 4, 'until' => 'queue empty'],
            'steps' => [['agent' => 'general', 'prompt' => 'fetch']],
        ]);

        $this->assertFalse($res->isError);
        $this->assertSame('dynamic', $res->content['type']);
        $this->assertSame('loop_until', $res->content['strategy']);
        $this->assertSame(4, $res->content['guards']['max_iterations']);

        $list = $t->execute(['action' => 'list']);
        $this->assertSame(1, $list->content['count']);
        $this->assertSame('dynamic', $list->content['workflows'][0]['type']);
        $this->assertSame('loop_until', $list->content['workflows'][0]['strategy']);
    }

    public function test_invalid_strategy_is_rejected(): void
    {
        $res = $this->tool()->execute([
            'action' => 'create', 'name' => 'x', 'type' => 'dynamic', 'strategy' => 'bogus',
            'steps' => [['agent' => 'a', 'prompt' => 'p']],
        ]);
        $this->assertTrue($res->isError);
    }

    public function test_plan_expands_loop_until_with_iteration_estimate(): void
    {
        $t = $this->tool();
        $t->execute([
            'action' => 'create', 'name' => 'c', 'type' => 'dynamic', 'strategy' => 'loop_until',
            'guards' => ['max_iterations' => 3, 'until' => 'done'],
            'steps' => [['agent' => 'a', 'prompt' => 'x'], ['agent' => 'b', 'prompt' => 'y']],
        ]);

        $plan = $t->planWorkflow(1);
        $this->assertSame('dynamic', $plan['type']);
        $this->assertSame('loop_until', $plan['strategy']);
        $this->assertSame(6, $plan['estimated_steps']); // 2 body steps * 3 iterations
        $this->assertStringContainsString('exit when: done', $plan['loop']);
    }

    public function test_fan_out_plan_is_single_concurrent_wave(): void
    {
        $t = $this->tool();
        $t->execute([
            'action' => 'create', 'name' => 'f', 'type' => 'dynamic', 'strategy' => 'fan_out',
            'steps' => [['agent' => 'a', 'prompt' => 'x'], ['agent' => 'b', 'prompt' => 'y'], ['agent' => 'c', 'prompt' => 'z']],
        ]);

        $plan = $t->planWorkflow(1);
        $this->assertSame(3, $plan['concurrency']);
        $this->assertCount(1, $plan['waves']);
    }

    public function test_bridge_produces_valid_pipeline_config_for_every_strategy(): void
    {
        foreach (WorkflowTool::STRATEGIES as $strategy) {
            $t = $this->tool();
            $t->execute([
                'action' => 'create', 'name' => $strategy, 'type' => 'dynamic', 'strategy' => $strategy,
                'guards' => ['max_iterations' => 2, 'until' => 'stop'],
                'steps' => [['agent' => 'general', 'prompt' => 'do A'], ['agent' => 'general', 'prompt' => 'do B']],
            ]);

            $cfg = $t->toPipelineConfig(1);
            $this->assertIsArray($cfg);

            // The real engine's parser must accept the bridged config.
            $pc = PipelineConfig::fromArray($cfg);
            $this->assertNotNull($pc->getPipeline('wf-1'), "strategy {$strategy} must bridge to a runnable pipeline");
        }
    }

    public function test_nested_parallel_plus_synthesis_bridges(): void
    {
        // Mirrors the /ultrareview shape: a parallel fan-out then a synthesis step.
        $t = $this->tool();
        $t->execute([
            'action' => 'create', 'name' => 'review', 'type' => 'dynamic', 'strategy' => 'sequential',
            'steps' => [
                ['name' => 'review', 'parallel' => [
                    ['name' => 'r-correctness', 'agent' => 'reviewer', 'prompt' => 'correctness'],
                    ['name' => 'r-security', 'agent' => 'reviewer', 'prompt' => 'security'],
                ]],
                ['name' => 'synthesize', 'agent' => 'reviewer', 'prompt' => 'merge', 'depends_on' => ['review']],
            ],
        ]);

        $pc = PipelineConfig::fromArray($t->toPipelineConfig(1));
        $this->assertNotNull($pc->getPipeline('wf-1'));
    }

    public function test_tool_steps_bridge_as_agent_steps(): void
    {
        // Tool steps (no agent) must still translate into a valid pipeline.
        $t = $this->tool();
        $t->execute([
            'action' => 'create', 'name' => 'toolwf', 'type' => 'dynamic', 'strategy' => 'pipeline',
            'steps' => [['tool' => 'bash', 'input' => ['cmd' => 'ls']], ['tool' => 'grep', 'input' => ['q' => 'TODO']]],
        ]);
        $pc = PipelineConfig::fromArray($t->toPipelineConfig(1));
        $this->assertNotNull($pc->getPipeline('wf-1'));
    }

    public function test_dynamic_run_without_runner_returns_plan(): void
    {
        $t = $this->tool();
        $t->execute(['action' => 'create', 'name' => 'd', 'type' => 'dynamic', 'strategy' => 'sequential', 'steps' => [['agent' => 'a', 'prompt' => 'x']]]);

        $res = $t->execute(['action' => 'run', 'workflow_id' => 1]);
        $this->assertFalse($res->isError);
        $this->assertSame('plan', $res->content['mode']);
        $this->assertArrayHasKey('plan', $res->content);
    }

    public function test_execute_requested_without_runner_is_explicit(): void
    {
        // Mode is caller-chosen: forcing execute with no runner reports clearly.
        $t = $this->tool();
        $t->execute(['action' => 'create', 'name' => 'd', 'type' => 'dynamic', 'strategy' => 'sequential', 'steps' => [['agent' => 'a', 'prompt' => 'x']]]);

        $res = $t->execute(['action' => 'run', 'workflow_id' => 1, 'parameters' => ['execute' => true]]);
        $this->assertStringContainsString('no agent runner', $res->content['message']);
    }

    public function test_dynamic_run_executes_via_injected_runner(): void
    {
        $t = $this->tool();
        $calls = [];
        $t->setPipelineRunner(function ($step, $ctx) use (&$calls) {
            $calls[] = $step->getName();
            return 'ok:' . $step->getName();
        });
        $t->execute(['action' => 'create', 'name' => 'e', 'type' => 'dynamic', 'strategy' => 'sequential', 'steps' => [['name' => 's1', 'agent' => 'general', 'prompt' => 'a']]]);

        $res = $t->execute(['action' => 'run', 'workflow_id' => 1, 'parameters' => ['execute' => true]]);
        $this->assertFalse($res->isError);
        $this->assertContains('s1', $calls);
        $this->assertSame('Workflow executed', $res->content['message']);
    }

    public function test_static_workflow_still_simulates(): void
    {
        $t = $this->tool();
        $t->execute(['action' => 'create', 'name' => 's', 'steps' => [['tool' => 'bash', 'input' => []]]]);

        $res = $t->execute(['action' => 'run', 'workflow_id' => 1]);
        $this->assertSame('Workflow executed', $res->content['message']);
        $this->assertSame('bash', $res->content['results'][0]['tool']);
    }

    public function test_opus_4_8_pricing_is_registered(): void
    {
        $cost = CostCalculator::calculate('claude-opus-4-8', new Usage(1_000_000, 1_000_000));
        // $15 / 1M input + $75 / 1M output.
        $this->assertEqualsWithDelta(90.0, $cost, 0.0001);
    }
}
