<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartFlow\FlowEngine;
use SuperAgent\SmartFlow\FlowOptions;
use SuperAgent\SmartFlow\FlowRegistry;
use SuperAgent\SmartFlow\YamlFlowLoader;

class YamlFlowLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/smartflow-yaml-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private const YAML = <<<YAML
name: demo-flow
description: A demo flow exercising every strategy.
phases:
  - title: Plan
  - title: Review
schemas:
  plan:
    type: object
    required: [summary]
    properties:
      summary: {type: string}
steps:
  - name: plan
    role: planner
    phase: Plan
    prompt: "Plan: {{args.goal}}"
    schema: plan
  - name: reviews
    strategy: parallel
    phase: Review
    agents:
      - {role: reviewer, prompt: "Review A of {{steps.plan.output.summary}}"}
      - {role: reviewer, prompt: "Review B"}
  - name: drafts
    strategy: pipeline
    over: "{{args.topics}}"
    stages:
      - {role: writer, prompt: "Write about {{item}}"}
  - name: accept
    strategy: gate
    check: "nonempty:{{steps.plan.output.summary}}"
    required: true
return: reviews
YAML;

    public function test_compiles_and_runs_all_strategies_under_rehearsal(): void
    {
        $def = (new YamlFlowLoader())->loadString(self::YAML);
        $this->assertSame('demo-flow', $def->name);
        $this->assertCount(2, $def->phases);

        $result = (new FlowEngine())->run($def, [
            'goal' => 'ship it',
            'topics' => ['a', 'b'],
        ], new FlowOptions(rehearse: true, ledgerDir: $this->dir));

        $this->assertTrue($result->isSuccessful(), $result->error ?? '');
        // return: reviews → the parallel step's array of 2 reviews.
        $this->assertIsArray($result->value);
        $this->assertCount(2, $result->value);
        // plan(1) + reviews(2) + drafts(2 items x 1 stage) = 5 agent calls + 1 gate.
        $this->assertSame(5, $result->ledger['calls']);
        $this->assertSame(0.0, $result->costUsd());
    }

    public function test_registry_discovers_and_loads_yaml_flow(): void
    {
        file_put_contents($this->dir . '/demo-flow.yaml', self::YAML);
        $registry = new FlowRegistry([$this->dir]);

        $this->assertTrue($registry->has('demo-flow'));
        $list = $registry->list();
        $this->assertArrayHasKey('demo-flow', $list);
        $this->assertStringContainsString('demo flow', strtolower($list['demo-flow']['description']));

        $def = $registry->get('demo-flow');
        $this->assertNotNull($def);
        $this->assertSame('demo-flow', $def->name);
    }

    public function test_condition_evaluator(): void
    {
        $loader = new YamlFlowLoader();
        $ctx = ['args' => ['x' => 'hello'], 'steps' => ['s' => ['output' => '']]];
        $this->assertTrue($loader->evalCondition('nonempty:{{args.x}}', $ctx));
        $this->assertFalse($loader->evalCondition('nonempty:{{steps.s.output}}', $ctx));
        $this->assertTrue($loader->evalCondition('equals:{{args.x}}|hello', $ctx));
        $this->assertTrue($loader->evalCondition('contains:{{args.x}}|ell', $ctx));
    }
}
