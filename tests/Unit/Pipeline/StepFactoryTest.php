<?php

namespace SuperAgent\Tests\Unit\Pipeline;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ApprovalStep;
use SuperAgent\Pipeline\Steps\ConditionalStep;
use SuperAgent\Pipeline\Steps\FailureStrategy;
use SuperAgent\Pipeline\Steps\ParallelStep;
use SuperAgent\Pipeline\Steps\StepFactory;
use SuperAgent\Pipeline\Steps\TransformStep;

class StepFactoryTest extends TestCase
{
    private StepFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new StepFactory();
    }

    // ── Agent Step Parsing ─────────────────────────────────────────

    public function test_parse_agent_step(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'scan',
            'agent' => 'security-scanner',
            'prompt' => 'Scan for vulnerabilities',
            'on_failure' => 'abort',
            'timeout' => 120,
        ]);

        $this->assertInstanceOf(AgentStep::class, $step);
        $this->assertSame('scan', $step->getName());
        $this->assertSame('security-scanner', $step->getAgentType());
        $this->assertSame('Scan for vulnerabilities', $step->getPromptTemplate());
        $this->assertSame(FailureStrategy::ABORT, $step->getFailureStrategy());
        $this->assertSame(120, $step->getTimeout());
    }

    public function test_agent_step_with_model_and_input_from(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'review',
            'agent' => 'reviewer',
            'prompt' => 'Review the code',
            'model' => 'claude-haiku-4-5-20251001',
            'input_from' => [
                'security' => '{{steps.scan.output}}',
            ],
            'depends_on' => ['scan'],
        ]);

        $this->assertInstanceOf(AgentStep::class, $step);
        $this->assertSame(['scan'], $step->getDependencies());
    }

    public function test_agent_step_requires_prompt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must have a prompt");

        $this->factory->fromArray([
            'name' => 'bad-step',
            'agent' => 'scanner',
        ]);
    }

    public function test_agent_step_with_read_only(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'explore',
            'agent' => 'explorer',
            'prompt' => 'Explore the codebase',
            'read_only' => true,
        ]);

        $this->assertInstanceOf(AgentStep::class, $step);
    }

    // ── Parallel Step Parsing ──────────────────────────────────────

    public function test_parse_parallel_step(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'parallel-checks',
            'parallel' => [
                [
                    'name' => 'style',
                    'agent' => 'styler',
                    'prompt' => 'Check style',
                ],
                [
                    'name' => 'lint',
                    'agent' => 'linter',
                    'prompt' => 'Run linter',
                ],
            ],
        ]);

        $this->assertInstanceOf(ParallelStep::class, $step);
        $this->assertSame('parallel-checks', $step->getName());
        $this->assertCount(2, $step->getSteps());
        $this->assertSame('style', $step->getSteps()[0]->getName());
        $this->assertSame('lint', $step->getSteps()[1]->getName());
    }

    // ── Approval Step Parsing ──────────────────────────────────────

    public function test_parse_approval_step(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'deploy-gate',
            'approval' => [
                'message' => 'Deploy to production?',
                'required_approvers' => 2,
                'timeout' => 3600,
            ],
        ]);

        $this->assertInstanceOf(ApprovalStep::class, $step);
        $this->assertSame('deploy-gate', $step->getName());
        $this->assertSame('Deploy to production?', $step->getMessage());
        $this->assertSame(2, $step->getRequiredApprovers());
    }

    // ── Transform Step Parsing ─────────────────────────────────────

    public function test_parse_transform_step(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'aggregate',
            'transform' => [
                'type' => 'merge',
                'sources' => [
                    'a' => '{{steps.step-a.output}}',
                    'b' => '{{steps.step-b.output}}',
                ],
            ],
        ]);

        $this->assertInstanceOf(TransformStep::class, $step);
        $this->assertSame('aggregate', $step->getName());
        $this->assertSame('merge', $step->getType());
    }

    public function test_transform_step_requires_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must specify a type");

        $this->factory->fromArray([
            'name' => 'bad',
            'transform' => ['sources' => []],
        ]);
    }

    // ── Conditional Wrapping ───────────────────────────────────────

    public function test_when_clause_wraps_step_in_conditional(): void
    {
        $steps = $this->factory->fromArrayList([
            [
                'name' => 'notify',
                'agent' => 'notifier',
                'prompt' => 'Notify team',
                'when' => [
                    'step_failed' => 'deploy',
                ],
            ],
        ]);

        $this->assertCount(1, $steps);
        $this->assertInstanceOf(ConditionalStep::class, $steps[0]);
        $this->assertSame('notify', $steps[0]->getName());
    }

    // ── Error Handling ─────────────────────────────────────────────

    public function test_step_requires_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must have a name");

        $this->factory->fromArray([
            'agent' => 'scanner',
            'prompt' => 'scan',
        ]);
    }

    public function test_step_requires_valid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must specify one of");

        $this->factory->fromArray([
            'name' => 'bad',
        ]);
    }

    public function test_invalid_failure_strategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid failure strategy");

        $this->factory->fromArray([
            'name' => 'bad',
            'agent' => 'scanner',
            'prompt' => 'scan',
            'on_failure' => 'explode',
        ]);
    }

    // ── Failure Strategies ─────────────────────────────────────────

    public function test_continue_strategy(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'optional',
            'agent' => 'helper',
            'prompt' => 'Do something',
            'on_failure' => 'continue',
        ]);

        $this->assertSame(FailureStrategy::CONTINUE, $step->getFailureStrategy());
    }

    public function test_retry_strategy_with_max_retries(): void
    {
        $step = $this->factory->fromArray([
            'name' => 'flaky',
            'agent' => 'helper',
            'prompt' => 'Do something',
            'on_failure' => 'retry',
            'max_retries' => 3,
        ]);

        $this->assertSame(FailureStrategy::RETRY, $step->getFailureStrategy());
        $this->assertInstanceOf(AgentStep::class, $step);
    }
}
