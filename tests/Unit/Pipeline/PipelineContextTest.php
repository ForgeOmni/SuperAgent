<?php

namespace SuperAgent\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;
use SuperAgent\Pipeline\StepStatus;

class PipelineContextTest extends TestCase
{
    // ── Inputs ─────────────────────────────────────────────────────

    public function test_get_inputs(): void
    {
        $ctx = new PipelineContext(['files' => 'src/', 'mode' => 'strict']);

        $this->assertSame('src/', $ctx->getInput('files'));
        $this->assertSame('strict', $ctx->getInput('mode'));
        $this->assertNull($ctx->getInput('missing'));
    }

    // ── Step Results ───────────────────────────────────────────────

    public function test_step_result_tracking(): void
    {
        $ctx = new PipelineContext();
        $result = StepResult::success('scan', 'No issues found');

        $ctx->setStepResult('scan', $result);

        $this->assertSame($result, $ctx->getStepResult('scan'));
        $this->assertSame('No issues found', $ctx->getStepOutput('scan'));
        $this->assertTrue($ctx->isStepCompleted('scan'));
        $this->assertFalse($ctx->isStepFailed('scan'));
    }

    public function test_failed_step_tracking(): void
    {
        $ctx = new PipelineContext();
        $ctx->setStepResult('deploy', StepResult::failure('deploy', 'Connection refused'));

        $this->assertTrue($ctx->isStepFailed('deploy'));
        $this->assertFalse($ctx->isStepCompleted('deploy'));
    }

    public function test_unknown_step_returns_null(): void
    {
        $ctx = new PipelineContext();

        $this->assertNull($ctx->getStepResult('unknown'));
        $this->assertNull($ctx->getStepOutput('unknown'));
        $this->assertFalse($ctx->isStepCompleted('unknown'));
        $this->assertFalse($ctx->isStepFailed('unknown'));
    }

    // ── Variables ──────────────────────────────────────────────────

    public function test_custom_variables(): void
    {
        $ctx = new PipelineContext();
        $ctx->setVariable('env', 'production');

        $this->assertSame('production', $ctx->getVariable('env'));
        $this->assertNull($ctx->getVariable('missing'));
    }

    // ── Cancel ─────────────────────────────────────────────────────

    public function test_cancellation(): void
    {
        $ctx = new PipelineContext();

        $this->assertFalse($ctx->isCancelled());

        $ctx->cancel();
        $this->assertTrue($ctx->isCancelled());
    }

    // ── Template Resolution ────────────────────────────────────────

    public function test_resolve_input_template(): void
    {
        $ctx = new PipelineContext(['target' => 'production']);

        $this->assertSame(
            'Deploying to production',
            $ctx->resolveTemplate('Deploying to {{inputs.target}}')
        );
    }

    public function test_resolve_step_output_template(): void
    {
        $ctx = new PipelineContext();
        $ctx->setStepResult('scan', StepResult::success('scan', 'All clear'));

        $this->assertSame(
            'Scan result: All clear',
            $ctx->resolveTemplate('Scan result: {{steps.scan.output}}')
        );
    }

    public function test_resolve_step_status_template(): void
    {
        $ctx = new PipelineContext();
        $ctx->setStepResult('build', StepResult::success('build', 'ok'));

        $this->assertSame(
            'Build: completed',
            $ctx->resolveTemplate('Build: {{steps.build.status}}')
        );
    }

    public function test_resolve_step_error_template(): void
    {
        $ctx = new PipelineContext();
        $ctx->setStepResult('deploy', StepResult::failure('deploy', 'Timeout'));

        $this->assertSame(
            'Error: Timeout',
            $ctx->resolveTemplate('Error: {{steps.deploy.error}}')
        );
    }

    public function test_resolve_vars_template(): void
    {
        $ctx = new PipelineContext();
        $ctx->setVariable('version', '2.0');

        $this->assertSame(
            'Version: 2.0',
            $ctx->resolveTemplate('Version: {{vars.version}}')
        );
    }

    public function test_unresolved_template_kept_as_is(): void
    {
        $ctx = new PipelineContext();

        $this->assertSame(
            'Value: {{unknown.path}}',
            $ctx->resolveTemplate('Value: {{unknown.path}}')
        );
    }

    public function test_resolve_array_output_as_json(): void
    {
        $ctx = new PipelineContext();
        $ctx->setStepResult('scan', StepResult::success('scan', ['a' => 1, 'b' => 2]));

        $this->assertSame(
            'Data: {"a":1,"b":2}',
            $ctx->resolveTemplate('Data: {{steps.scan.output}}')
        );
    }

    public function test_resolve_multiple_templates_in_one_string(): void
    {
        $ctx = new PipelineContext(['env' => 'staging']);
        $ctx->setStepResult('build', StepResult::success('build', 'v1.2.3'));

        $this->assertSame(
            'Deploy v1.2.3 to staging',
            $ctx->resolveTemplate('Deploy {{steps.build.output}} to {{inputs.env}}')
        );
    }

    public function test_resolve_nested_input(): void
    {
        $ctx = new PipelineContext(['config' => ['region' => 'us-east-1']]);

        $this->assertSame(
            'Region: us-east-1',
            $ctx->resolveTemplate('Region: {{inputs.config.region}}')
        );
    }

    public function test_get_all_step_results(): void
    {
        $ctx = new PipelineContext();
        $ctx->setStepResult('a', StepResult::success('a', 'out-a'));
        $ctx->setStepResult('b', StepResult::success('b', 'out-b'));

        $results = $ctx->getAllStepResults();
        $this->assertCount(2, $results);
    }
}
