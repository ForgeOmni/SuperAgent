<?php

namespace SuperAgent\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\FailureStrategy;
use SuperAgent\Pipeline\Steps\LoopStep;
use SuperAgent\Pipeline\Steps\StepFactory;
use SuperAgent\Pipeline\StepStatus;

class LoopStepTest extends TestCase
{
    private function makeEngine(array $config): PipelineEngine
    {
        return new PipelineEngine(PipelineConfig::fromArray($config));
    }

    // ── StepFactory Parsing ────────────────────────────────────────

    public function test_parse_loop_step(): void
    {
        $factory = new StepFactory();

        $step = $factory->fromArray([
            'name' => 'review-loop',
            'loop' => [
                'max_iterations' => 5,
                'exit_when' => [
                    'output_contains' => ['step' => 'review', 'contains' => 'LGTM'],
                ],
                'steps' => [
                    ['name' => 'review', 'agent' => 'reviewer', 'prompt' => 'Review code'],
                    ['name' => 'fix', 'agent' => 'fixer', 'prompt' => 'Fix issues'],
                ],
            ],
        ]);

        $this->assertInstanceOf(LoopStep::class, $step);
        $this->assertSame('review-loop', $step->getName());
        $this->assertSame(5, $step->getMaxIterations());
        $this->assertCount(2, $step->getBodySteps());
    }

    public function test_parse_loop_requires_max_iterations(): void
    {
        $factory = new StepFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_iterations');

        $factory->fromArray([
            'name' => 'bad-loop',
            'loop' => [
                'steps' => [
                    ['name' => 'a', 'agent' => 'x', 'prompt' => 'p'],
                ],
            ],
        ]);
    }

    public function test_parse_loop_requires_steps(): void
    {
        $factory = new StepFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one body step');

        $factory->fromArray([
            'name' => 'bad-loop',
            'loop' => [
                'max_iterations' => 5,
                'steps' => [],
            ],
        ]);
    }

    // ── Basic Loop Execution ───────────────────────────────────────

    public function test_loop_runs_until_max_iterations(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'my-loop',
                            'loop' => [
                                'max_iterations' => 3,
                                'steps' => [
                                    ['name' => 'work', 'agent' => 'worker', 'prompt' => 'Do work'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$iterations) {
            $iterations++;
            return "Iteration {$iterations} done";
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $iterations);
        $this->assertSame('max_iterations', $result->getStepResult('my-loop')->metadata['exit_reason']);
        $this->assertSame(3, $result->getStepResult('my-loop')->metadata['iterations']);
    }

    public function test_loop_exits_on_output_contains(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'review-loop',
                            'loop' => [
                                'max_iterations' => 10,
                                'exit_when' => [
                                    'output_contains' => ['step' => 'review', 'contains' => 'LGTM'],
                                ],
                                'steps' => [
                                    ['name' => 'review', 'agent' => 'reviewer', 'prompt' => 'Review'],
                                    ['name' => 'fix', 'agent' => 'fixer', 'prompt' => 'Fix'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$iterations) {
            if ($step->getName() === 'review') {
                $iterations++;
                // On 3rd review, output LGTM
                return $iterations >= 3 ? 'All good. LGTM!' : 'Found 2 bugs';
            }
            return 'Fixed bugs';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $iterations);
        $this->assertSame('exit_condition', $result->getStepResult('review-loop')->metadata['exit_reason']);
    }

    // ── Multi-Model Parallel Review ────────────────────────────────

    public function test_loop_with_parallel_review(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'multi-review',
                            'loop' => [
                                'max_iterations' => 5,
                                'exit_when' => [
                                    'all_passed' => [
                                        ['step' => 'claude-review', 'contains' => 'LGTM'],
                                        ['step' => 'gpt-review', 'contains' => 'LGTM'],
                                    ],
                                ],
                                'steps' => [
                                    [
                                        'name' => 'reviews',
                                        'parallel' => [
                                            ['name' => 'claude-review', 'agent' => 'reviewer', 'prompt' => 'Review (Claude)'],
                                            ['name' => 'gpt-review', 'agent' => 'reviewer', 'prompt' => 'Review (GPT)'],
                                        ],
                                    ],
                                    ['name' => 'fix', 'agent' => 'fixer', 'prompt' => 'Fix all issues'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$iterations) {
            if ($step->getName() === 'claude-review') {
                $iterations++;
                return $iterations >= 2 ? 'LGTM' : 'Bug found in auth';
            }
            if ($step->getName() === 'gpt-review') {
                return $iterations >= 2 ? 'LGTM' : 'SQL injection risk';
            }
            return 'Fixed all issues';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $iterations);
        $this->assertSame('exit_condition', $result->getStepResult('multi-review')->metadata['exit_reason']);
    }

    // ── all_passed requires ALL reviewers to pass ──────────────────

    public function test_all_passed_requires_every_reviewer(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 5,
                                'exit_when' => [
                                    'all_passed' => [
                                        ['step' => 'r1', 'contains' => 'LGTM'],
                                        ['step' => 'r2', 'contains' => 'LGTM'],
                                    ],
                                ],
                                'steps' => [
                                    ['name' => 'r1', 'agent' => 'a', 'prompt' => 'p'],
                                    ['name' => 'r2', 'agent' => 'b', 'prompt' => 'q'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$iterations) {
            if ($step->getName() === 'r1') {
                $iterations++;
                return 'LGTM'; // r1 always passes
            }
            // r2 only passes on iteration 3
            return $iterations >= 3 ? 'LGTM' : 'Issues found';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $iterations); // Needed 3 iterations because r2 didn't pass until iteration 3
    }

    // ── any_passed ─────────────────────────────────────────────────

    public function test_any_passed_exits_when_one_passes(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 10,
                                'exit_when' => [
                                    'any_passed' => [
                                        ['step' => 'r1', 'contains' => 'LGTM'],
                                        ['step' => 'r2', 'contains' => 'LGTM'],
                                    ],
                                ],
                                'steps' => [
                                    ['name' => 'r1', 'agent' => 'a', 'prompt' => 'p'],
                                    ['name' => 'r2', 'agent' => 'b', 'prompt' => 'q'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step) {
            if ($step->getName() === 'r1') {
                return 'LGTM'; // r1 passes immediately
            }
            return 'Not yet';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getStepResult('loop')->metadata['iterations']);
    }

    // ── Expression-Based Exit ──────────────────────────────────────

    public function test_loop_exits_on_expression(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 10,
                                'exit_when' => [
                                    'expression' => [
                                        'left' => '{{steps.check.output}}',
                                        'operator' => 'eq',
                                        'right' => '0 issues',
                                    ],
                                ],
                                'steps' => [
                                    ['name' => 'check', 'agent' => 'checker', 'prompt' => 'Count issues'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step) use (&$iterations) {
            $iterations++;
            return $iterations >= 4 ? '0 issues' : "{$iterations} issues";
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(4, $iterations);
        $this->assertSame('exit_condition', $result->getStepResult('loop')->metadata['exit_reason']);
    }

    // ── output_not_contains ────────────────────────────────────────

    public function test_loop_exits_on_output_not_contains(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 10,
                                'exit_when' => [
                                    'output_not_contains' => ['step' => 'review', 'contains' => 'BUG'],
                                ],
                                'steps' => [
                                    ['name' => 'review', 'agent' => 'reviewer', 'prompt' => 'Review'],
                                    ['name' => 'fix', 'agent' => 'fixer', 'prompt' => 'Fix'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step) use (&$iterations) {
            if ($step->getName() === 'review') {
                $iterations++;
                return $iterations >= 3 ? 'All clean, no issues' : 'Found BUG in line 42';
            }
            return 'Fixed';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $iterations);
    }

    // ── Failure Handling in Loop ────────────────────────────────────

    public function test_loop_aborts_on_body_step_failure(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 5,
                                'steps' => [
                                    ['name' => 'risky', 'agent' => 'worker', 'prompt' => 'Do risky work'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function () {
            throw new \RuntimeException('Exploded');
        });

        $result = $engine->run('test');

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Exploded', $result->error);
    }

    public function test_loop_continues_on_failure_with_continue_strategy(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'on_failure' => 'continue',
                            'loop' => [
                                'max_iterations' => 3,
                                'steps' => [
                                    ['name' => 'flaky', 'agent' => 'worker', 'prompt' => 'Work'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function () use (&$iterations) {
            $iterations++;
            if ($iterations === 1) {
                throw new \RuntimeException('First attempt fails');
            }
            return 'OK';
        });

        $result = $engine->run('test');

        // With continue strategy, loop continues despite failure
        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $iterations);
    }

    // ── Iteration Variable Access ──────────────────────────────────

    public function test_iteration_variable_in_context(): void
    {
        $capturedIterations = [];

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'my-loop',
                            'loop' => [
                                'max_iterations' => 3,
                                'steps' => [
                                    ['name' => 'work', 'agent' => 'worker', 'prompt' => 'Iteration {{vars.loop.my-loop.iteration}}'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function (AgentStep $step, $ctx) use (&$capturedIterations) {
            $capturedIterations[] = $ctx->getVariable('loop.my-loop.iteration');
            return 'done';
        });

        $engine->run('test');

        $this->assertSame([1, 2, 3], $capturedIterations);
    }

    // ── Loop Events ────────────────────────────────────────────────

    public function test_loop_emits_iteration_events(): void
    {
        $events = [];

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 2,
                                'steps' => [
                                    ['name' => 'work', 'agent' => 'worker', 'prompt' => 'Work'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(fn () => 'done');
        $engine->on('loop.iteration', function ($data) use (&$events) {
            $events[] = "iter:{$data['iteration']}/{$data['max_iterations']}";
        });

        $engine->run('test');

        $this->assertSame(['iter:1/2', 'iter:2/2'], $events);
    }

    // ── Loop Output ────────────────────────────────────────────────

    public function test_loop_output_contains_last_iteration_results(): void
    {
        $iteration = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 3,
                                'steps' => [
                                    ['name' => 'work', 'agent' => 'worker', 'prompt' => 'Work'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function () use (&$iteration) {
            $iteration++;
            return "Result from iteration {$iteration}";
        });

        $result = $engine->run('test');

        $output = $result->getStepOutput('loop');
        $this->assertIsArray($output);
        $this->assertSame('Result from iteration 3', $output['work']);
    }

    // ── No Exit Condition → Runs to Max ────────────────────────────

    public function test_loop_without_exit_condition_runs_to_max(): void
    {
        $iterations = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 4,
                                'steps' => [
                                    ['name' => 'work', 'agent' => 'w', 'prompt' => 'p'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(function () use (&$iterations) {
            $iterations++;
            return 'done';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(4, $iterations);
        $this->assertSame('max_iterations', $result->getStepResult('loop')->metadata['exit_reason']);
    }

    // ── Single Iteration Exit ──────────────────────────────────────

    public function test_loop_exits_after_first_iteration(): void
    {
        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 10,
                                'exit_when' => [
                                    'output_contains' => ['step' => 'check', 'contains' => 'PASS'],
                                ],
                                'steps' => [
                                    ['name' => 'check', 'agent' => 'checker', 'prompt' => 'Check'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $engine->setAgentRunner(fn () => 'All tests PASS');

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getStepResult('loop')->metadata['iterations']);
    }

    // ── Conditional Steps Inside Loop ──────────────────────────────

    public function test_conditional_step_inside_loop(): void
    {
        $fixCount = 0;

        $engine = $this->makeEngine([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'loop',
                            'loop' => [
                                'max_iterations' => 5,
                                'exit_when' => [
                                    'output_contains' => ['step' => 'review', 'contains' => 'LGTM'],
                                ],
                                'steps' => [
                                    ['name' => 'review', 'agent' => 'reviewer', 'prompt' => 'Review'],
                                    [
                                        'name' => 'fix',
                                        'agent' => 'fixer',
                                        'prompt' => 'Fix: {{steps.review.output}}',
                                        'when' => ['output_contains' => ['step' => 'review', 'contains' => 'BUG']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $iteration = 0;
        $engine->setAgentRunner(function (AgentStep $step) use (&$iteration, &$fixCount) {
            if ($step->getName() === 'review') {
                $iteration++;
                return $iteration >= 3 ? 'LGTM' : 'Found BUG';
            }
            $fixCount++;
            return 'Fixed';
        });

        $result = $engine->run('test');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(3, $iteration);
        $this->assertSame(2, $fixCount); // Fix only runs when review contains "BUG" (iterations 1 & 2)
    }

    // ── Describe ───────────────────────────────────────────────────

    public function test_describe(): void
    {
        $factory = new StepFactory();
        $step = $factory->fromArray([
            'name' => 'my-loop',
            'loop' => [
                'max_iterations' => 5,
                'steps' => [
                    ['name' => 'a', 'agent' => 'x', 'prompt' => 'p'],
                    ['name' => 'b', 'agent' => 'y', 'prompt' => 'q'],
                ],
            ],
        ]);

        $this->assertStringContainsString('my-loop', $step->describe());
        $this->assertStringContainsString('5x', $step->describe());
    }
}
