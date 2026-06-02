<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartFlow\Flow;
use SuperAgent\SmartFlow\FlowDefinition;
use SuperAgent\SmartFlow\FlowEngine;
use SuperAgent\SmartFlow\FlowOptions;

class ResumeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/smartflow-resume-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** A flow whose first call is constant and second depends on args['goal']. */
    private function def(): FlowDefinition
    {
        return FlowDefinition::make('resumable', 'demo', function (Flow $flow) {
            $a = $flow->agent('constant plan', ['label' => 'plan']);
            $b = $flow->agent('build: ' . ($flow->args['goal'] ?? ''), ['label' => 'build']);
            return [$a, $b];
        });
    }

    public function test_unchanged_resume_serves_everything_from_cache(): void
    {
        $engine = new FlowEngine();

        $first = $engine->run($this->def(), ['goal' => 'X'], new FlowOptions(
            rehearse: true, runId: 'r1', ledgerDir: $this->dir,
        ));
        $this->assertSame(2, $first->ledger['calls']);
        $this->assertSame(0, $first->ledger['cached_calls']);

        $resumed = $engine->run($this->def(), ['goal' => 'X'], new FlowOptions(
            rehearse: true, runId: 'r2', resumeRunId: 'r1', ledgerDir: $this->dir,
        ));
        $this->assertSame(2, $resumed->ledger['calls']);
        $this->assertSame(2, $resumed->ledger['cached_calls']); // fully cached
        $this->assertSame($first->value, $resumed->value);
    }

    public function test_changed_arg_reruns_from_first_divergent_call(): void
    {
        $engine = new FlowEngine();

        $engine->run($this->def(), ['goal' => 'X'], new FlowOptions(
            rehearse: true, runId: 's1', ledgerDir: $this->dir,
        ));

        // 'plan' is constant → cached; 'build: Y' differs from 'build: X' → live.
        $resumed = $engine->run($this->def(), ['goal' => 'Y'], new FlowOptions(
            rehearse: true, runId: 's2', resumeRunId: 's1', ledgerDir: $this->dir,
        ));

        $this->assertSame(2, $resumed->ledger['calls']);
        $this->assertSame(1, $resumed->ledger['cached_calls']); // only 'plan'
    }
}
