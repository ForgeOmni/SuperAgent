<?php

namespace SuperAgent\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Pipeline\PipelineResult;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ApprovalStep;
use SuperAgent\Pipeline\StepStatus;

class PipelineEngineTest extends TestCase
{
    private function makeEngine(array $config): PipelineEngine
    {
        return new PipelineEngine(PipelineConfig::fromArray($config));
    }

    private function simpleAgentRunner(): callable
    {
        return fn (AgentStep $step, $ctx) => "Output from {$step->getAgentType()}";
    }

    // ── Basic Execution ────────────────────────────────────────────

    public function test_run_simple_pipeline(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan files'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('test', $result->pipelineName);
        $this->assertSame(StepStatus::COMPLETED, $result->status);
        $this->assertCount(1, $result->getStepResults());
        $this->assertSame('Output from scanner', $result->getStepOutput('scan'));
    }

    public function test_run_multi_step_pipeline(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan'],
                        ['name' => 'review', 'agent' => 'reviewer', 'prompt' => 'Review'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->getStepResults());
        $this->assertSame('Output from scanner', $result->getStepOutput('scan'));
        $this->assertSame('Output from reviewer', $result->getStepOutput('review'));
    }

    // ── Pipeline Not Found ─────────────────────────────────────────

    public function test_run_nonexistent_pipeline(): void
    {
        $engine = $this->makeEngine(['pipelines' => []]);

        $result = $engine->run('ghost');

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('not found', $result->error);
    }

    // ── Input Validation ───────────────────────────────────────────

    public function test_run_with_missing_required_input(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'inputs' => [
                        ['name' => 'files', 'required' => true],
                    ],
                    'steps' => [
                        ['name' => 's', 'agent' => 'a', 'prompt' => 'p'],
                    ],
                ],
            ],
        ]);

        $result = $engine->run('test');

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('files', $result->error);
    }

    public function test_run_with_valid_inputs(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'inputs' => [
                        ['name' => 'files', 'required' => true],
                    ],
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan {{inputs.files}}'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(fn (AgentStep $step, $ctx) => "Scanned");

        $result = $engine->run('test', ['files' => 'src/']);

        $this->assertTrue($result->isSuccessful());
    }

    // ── Failure Strategies ─────────────────────────────────────────

    public function test_abort_on_step_failure(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'fail', 'agent' => 'a', 'prompt' => 'p', 'on_failure' => 'abort'],
                        ['name' => 'skip', 'agent' => 'b', 'prompt' => 'q'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) {
            if ($step->getName() === 'fail') {
                throw new \RuntimeException('Boom');
            }
            return 'ok';
        });

        $result = $engine->run('test');

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Boom', $result->error);

        // Second step should be skipped
        $skipResult = $result->getStepResult('skip');
        $this->assertNotNull($skipResult);
        $this->assertSame(StepStatus::SKIPPED, $skipResult->status);
    }

    public function test_continue_on_step_failure(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'fail', 'agent' => 'a', 'prompt' => 'p', 'on_failure' => 'continue'],
                        ['name' => 'next', 'agent' => 'b', 'prompt' => 'q', 'on_failure' => 'continue'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) {
            if ($step->getName() === 'fail') {
                throw new \RuntimeException('Boom');
            }
            return 'ok';
        });

        $result = $engine->run('test');

        // Pipeline succeeds because no ABORT strategy triggered
        $this->assertTrue($result->isSuccessful());

        $failResult = $result->getStepResult('fail');
        $this->assertSame(StepStatus::FAILED, $failResult->status);

        $nextResult = $result->getStepResult('next');
        $this->assertSame(StepStatus::COMPLETED, $nextResult->status);
    }

    public function test_retry_on_step_failure(): void
    {
        $attempts = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'flaky',
                            'agent' => 'a',
                            'prompt' => 'p',
                            'on_failure' => 'retry',
                            'max_retries' => 2,
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException("Attempt {$attempts} failed");
            }
            return 'finally ok';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $attempts);
        $this->assertSame('finally ok', $result->getStepOutput('flaky'));
    }

    // ── Dependencies ───────────────────────────────────────────────

    public function test_dependency_ordering(): void
    {
        $order = [];

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'deploy', 'agent' => 'deployer', 'prompt' => 'Deploy', 'depends_on' => ['build']],
                        ['name' => 'build', 'agent' => 'builder', 'prompt' => 'Build'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$order) {
            $order[] = $step->getName();
            return "done {$step->getName()}";
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(['build', 'deploy'], $order);
    }

    public function test_skip_step_when_dependency_not_met(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'build', 'agent' => 'a', 'prompt' => 'p', 'on_failure' => 'continue'],
                        ['name' => 'deploy', 'agent' => 'b', 'prompt' => 'q', 'depends_on' => ['build']],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) {
            if ($step->getName() === 'build') {
                throw new \RuntimeException('Build failed');
            }
            return 'ok';
        });

        $result = $engine->run('test');

        // Deploy should be skipped because build failed (dependency not completed)
        $deployResult = $result->getStepResult('deploy');
        $this->assertSame(StepStatus::SKIPPED, $deployResult->status);
    }

    // ── Parallel Steps ─────────────────────────────────────────────

    public function test_parallel_step_execution(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'checks',
                            'parallel' => [
                                ['name' => 'lint', 'agent' => 'linter', 'prompt' => 'Lint'],
                                ['name' => 'test', 'agent' => 'tester', 'prompt' => 'Test'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());

        // Parallel step output is a map of sub-step outputs
        $output = $result->getStepOutput('checks');
        $this->assertIsArray($output);
        $this->assertSame('Output from linter', $output['lint']);
        $this->assertSame('Output from tester', $output['test']);
    }

    public function test_parallel_step_with_sub_failure(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'checks',
                            'parallel' => [
                                ['name' => 'lint', 'agent' => 'linter', 'prompt' => 'Lint'],
                                ['name' => 'bad', 'agent' => 'broken', 'prompt' => 'Fail'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) {
            if ($step->getAgentType() === 'broken') {
                throw new \RuntimeException('Broken agent');
            }
            return 'ok';
        });

        $result = $engine->run('test');

        // Pipeline fails because parallel step has a failure
        $this->assertFalse($result->isSuccessful());
    }

    // ── Approval Gates ─────────────────────────────────────────────

    public function test_approval_approved(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'gate',
                            'approval' => [
                                'message' => 'Proceed?',
                            ],
                        ],
                        ['name' => 'deploy', 'agent' => 'deployer', 'prompt' => 'Deploy'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());
        $engine->setApprovalHandler(fn (ApprovalStep $step, $ctx) => true);

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Output from deployer', $result->getStepOutput('deploy'));
    }

    public function test_approval_denied(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'gate',
                            'approval' => ['message' => 'Proceed?'],
                        ],
                        ['name' => 'deploy', 'agent' => 'deployer', 'prompt' => 'Deploy'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());
        $engine->setApprovalHandler(fn () => false);

        $result = $engine->run('test');

        $this->assertFalse($result->isSuccessful());
        $gateResult = $result->getStepResult('gate');
        $this->assertSame(StepStatus::FAILED, $gateResult->status);
    }

    public function test_approval_auto_approve_when_no_handler(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'gate', 'approval' => ['message' => 'Proceed?']],
                        ['name' => 'deploy', 'agent' => 'deployer', 'prompt' => 'Deploy'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());
        // No approval handler set — should auto-approve

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
    }

    // ── Conditional Steps ──────────────────────────────────────────

    public function test_conditional_step_executed_when_condition_met(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'build', 'agent' => 'builder', 'prompt' => 'Build'],
                        [
                            'name' => 'deploy',
                            'agent' => 'deployer',
                            'prompt' => 'Deploy',
                            'when' => ['step_succeeded' => 'build'],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Output from deployer', $result->getStepOutput('deploy'));
    }

    public function test_conditional_step_skipped_when_condition_not_met(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'build', 'agent' => 'builder', 'prompt' => 'Build', 'on_failure' => 'continue'],
                        [
                            'name' => 'deploy',
                            'agent' => 'deployer',
                            'prompt' => 'Deploy',
                            'when' => ['step_succeeded' => 'build'],
                            'on_failure' => 'continue',
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) {
            if ($step->getName() === 'build') {
                throw new \RuntimeException('Build failed');
            }
            return 'ok';
        });

        $result = $engine->run('test');

        $deployResult = $result->getStepResult('deploy');
        $this->assertSame(StepStatus::SKIPPED, $deployResult->status);
    }

    public function test_conditional_step_failed_trigger(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'build', 'agent' => 'builder', 'prompt' => 'Build', 'on_failure' => 'continue'],
                        [
                            'name' => 'notify',
                            'agent' => 'notifier',
                            'prompt' => 'Notify failure',
                            'when' => ['step_failed' => 'build'],
                            'on_failure' => 'continue',
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) {
            if ($step->getName() === 'build') {
                throw new \RuntimeException('Build failed');
            }
            return 'Notification sent';
        });

        $result = $engine->run('test');

        $notifyResult = $result->getStepResult('notify');
        $this->assertSame(StepStatus::COMPLETED, $notifyResult->status);
        $this->assertSame('Notification sent', $notifyResult->output);
    }

    // ── Transform Steps ────────────────────────────────────────────

    public function test_transform_merge_step(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'a', 'agent' => 'agent-a', 'prompt' => 'Do A'],
                        ['name' => 'b', 'agent' => 'agent-b', 'prompt' => 'Do B'],
                        [
                            'name' => 'merge',
                            'transform' => [
                                'type' => 'merge',
                                'sources' => [
                                    'result_a' => '{{steps.a.output}}',
                                    'result_b' => '{{steps.b.output}}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $merged = $result->getStepOutput('merge');
        $this->assertSame('Output from agent-a', $merged['result_a']);
        $this->assertSame('Output from agent-b', $merged['result_b']);
    }

    public function test_transform_template_step(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan'],
                        [
                            'name' => 'report',
                            'transform' => [
                                'type' => 'template',
                                'template' => '# Report\nScan: {{steps.scan.output}}',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertStringContainsString('Output from scanner', $result->getStepOutput('report'));
    }

    // ── Events ─────────────────────────────────────────────────────

    public function test_event_listeners(): void
    {
        $events = [];

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $engine->on('pipeline.start', function ($data) use (&$events) {
            $events[] = 'start:' . $data['pipeline'];
        });
        $engine->on('pipeline.end', function ($data) use (&$events) {
            $events[] = 'end:' . $data['pipeline'];
        });
        $engine->on('step.start', function ($data) use (&$events) {
            $events[] = 'step:' . $data['step'];
        });

        $engine->run('test');

        $this->assertContains('start:test', $events);
        $this->assertContains('end:test', $events);
        $this->assertContains('step:scan', $events);
    }

    // ── Statistics ──────────────────────────────────────────────────

    public function test_statistics(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'a' => ['steps' => [
                    ['name' => 's1', 'agent' => 'x', 'prompt' => 'p'],
                    ['name' => 's2', 'agent' => 'y', 'prompt' => 'q'],
                ]],
                'b' => ['steps' => [
                    ['name' => 's3', 'agent' => 'z', 'prompt' => 'r'],
                ]],
            ],
        ]);

        $stats = $engine->getStatistics();
        $this->assertSame(2, $stats['pipelines']);
        $this->assertSame(3, $stats['total_steps']);
    }

    // ── Result Summary ─────────────────────────────────────────────

    public function test_result_summary(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 's1', 'agent' => 'a', 'prompt' => 'p', 'on_failure' => 'continue'],
                        ['name' => 's2', 'agent' => 'b', 'prompt' => 'q'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step) {
            if ($step->getName() === 's1') {
                throw new \RuntimeException('fail');
            }
            return 'ok';
        });

        $result = $engine->run('test');

        $summary = $result->getSummary();
        $this->assertSame('test', $summary['pipeline']);
        $this->assertSame(2, $summary['steps']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(1, $summary['failed']);
    }

    public function test_result_get_all_outputs(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'a', 'agent' => 'x', 'prompt' => 'p'],
                        ['name' => 'b', 'agent' => 'y', 'prompt' => 'q'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner($this->simpleAgentRunner());

        $result = $engine->run('test');
        $outputs = $result->getAllOutputs();

        $this->assertArrayHasKey('a', $outputs);
        $this->assertArrayHasKey('b', $outputs);
    }

    // ── Reload ─────────────────────────────────────────────────────

    public function test_reload_config(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'old' => ['steps' => [['name' => 's', 'agent' => 'a', 'prompt' => 'p']]],
            ],
        ]);

        $this->assertSame(['old'], $engine->getPipelineNames());

        $engine->reload(PipelineConfig::fromArray([
            'pipelines' => [
                'new' => ['steps' => [['name' => 's', 'agent' => 'a', 'prompt' => 'p']]],
            ],
        ]));

        $this->assertSame(['new'], $engine->getPipelineNames());
    }

    // ── Template Resolution in Agent Prompts ───────────────────────

    public function test_agent_step_prompt_resolves_templates(): void
    {
        $capturedPrompt = null;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'inputs' => [['name' => 'target', 'required' => true]],
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan {{inputs.target}}'],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$capturedPrompt) {
            // Build the spawn config to get the resolved prompt
            $config = $step->buildSpawnConfig($ctx);
            $capturedPrompt = $config->prompt;
            return 'ok';
        });

        $engine->run('test', ['target' => 'production']);

        $this->assertSame('Scan production', $capturedPrompt);
    }

    // ── Input From Previous Steps ──────────────────────────────────

    public function test_input_from_injects_previous_step_output(): void
    {
        $capturedPrompt = null;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan'],
                        [
                            'name' => 'review',
                            'agent' => 'reviewer',
                            'prompt' => 'Review the code',
                            'input_from' => [
                                'security' => '{{steps.scan.output}}',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$capturedPrompt) {
            if ($step->getName() === 'review') {
                $config = $step->buildSpawnConfig($ctx);
                $capturedPrompt = $config->prompt;
            }
            return "Output from {$step->getAgentType()}";
        });

        $engine->run('test');

        $this->assertStringContainsString('Review the code', $capturedPrompt);
        $this->assertStringContainsString('Output from scanner', $capturedPrompt);
        $this->assertStringContainsString('## security', $capturedPrompt);
    }
}
