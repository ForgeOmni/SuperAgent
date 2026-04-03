<?php

namespace SuperAgent\Tests\Unit\Pipeline;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ParallelStep;

class PipelineConfigTest extends TestCase
{
    // ── Basic Parsing ──────────────────────────────────────────────

    public function test_parse_minimal_config(): void
    {
        $config = PipelineConfig::fromArray([
            'version' => '1.0',
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'scan',
                            'agent' => 'scanner',
                            'prompt' => 'Scan files',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('1.0', $config->getVersion());
        $this->assertSame(['test'], $config->getPipelineNames());

        $pipeline = $config->getPipeline('test');
        $this->assertNotNull($pipeline);
        $this->assertCount(1, $pipeline->steps);
        $this->assertInstanceOf(AgentStep::class, $pipeline->steps[0]);
    }

    public function test_parse_defaults(): void
    {
        $config = PipelineConfig::fromArray([
            'defaults' => [
                'failure_strategy' => 'continue',
                'timeout' => 600,
                'max_retries' => 2,
            ],
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'scan',
                            'agent' => 'scanner',
                            'prompt' => 'Scan',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('continue', $config->getDefaultFailureStrategy());
        $this->assertSame(600, $config->getDefaultTimeout());
    }

    public function test_parse_pipeline_with_inputs_and_outputs(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'review' => [
                    'description' => 'Code review pipeline',
                    'inputs' => [
                        ['name' => 'files', 'type' => 'string', 'required' => true],
                        ['name' => 'focus', 'required' => false, 'default' => 'general'],
                    ],
                    'outputs' => [
                        'report' => '{{steps.review.output}}',
                    ],
                    'steps' => [
                        [
                            'name' => 'review',
                            'agent' => 'reviewer',
                            'prompt' => 'Review {{inputs.files}}',
                        ],
                    ],
                ],
            ],
        ]);

        $pipeline = $config->getPipeline('review');
        $this->assertSame('Code review pipeline', $pipeline->description);
        $this->assertCount(2, $pipeline->inputs);
        $this->assertSame('{{steps.review.output}}', $pipeline->outputs['report']);
    }

    public function test_parse_pipeline_with_triggers(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'ci' => [
                    'triggers' => [
                        ['event' => 'pr_created'],
                        ['event' => 'manual'],
                    ],
                    'steps' => [
                        ['name' => 's1', 'agent' => 'a', 'prompt' => 'p'],
                    ],
                ],
            ],
        ]);

        $pipeline = $config->getPipeline('ci');
        $this->assertTrue($pipeline->hasTrigger('pr_created'));
        $this->assertTrue($pipeline->hasTrigger('manual'));
        $this->assertFalse($pipeline->hasTrigger('cron'));
    }

    // ── Multiple Pipelines ─────────────────────────────────────────

    public function test_parse_multiple_pipelines(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'build' => [
                    'steps' => [['name' => 's1', 'agent' => 'a', 'prompt' => 'p']],
                ],
                'deploy' => [
                    'steps' => [['name' => 's2', 'agent' => 'b', 'prompt' => 'q']],
                ],
            ],
        ]);

        $this->assertCount(2, $config->getPipelines());
        $this->assertNotNull($config->getPipeline('build'));
        $this->assertNotNull($config->getPipeline('deploy'));
        $this->assertNull($config->getPipeline('missing'));
    }

    // ── Complex Step Types ─────────────────────────────────────────

    public function test_parse_parallel_steps(): void
    {
        $config = PipelineConfig::fromArray([
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

        $step = $config->getPipeline('test')->steps[0];
        $this->assertInstanceOf(ParallelStep::class, $step);
        $this->assertCount(2, $step->getSteps());
    }

    // ── Validation ─────────────────────────────────────────────────

    public function test_validate_returns_no_errors_for_valid_config(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'inputs' => [['name' => 'files', 'required' => true]],
                    'outputs' => ['result' => '{{steps.scan.output}}'],
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'scanner', 'prompt' => 'Scan'],
                    ],
                ],
            ],
        ]);

        $this->assertEmpty($config->validate());
    }

    public function test_validate_duplicate_step_names(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'a', 'prompt' => 'p'],
                        ['name' => 'scan', 'agent' => 'b', 'prompt' => 'q'],
                    ],
                ],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('duplicate step name', $errors[0]);
    }

    public function test_validate_unknown_dependency(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'steps' => [
                        [
                            'name' => 'deploy',
                            'agent' => 'deployer',
                            'prompt' => 'Deploy',
                            'depends_on' => ['nonexistent'],
                        ],
                    ],
                ],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unknown step', $errors[0]);
    }

    public function test_validate_output_references_unknown_step(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'outputs' => ['result' => '{{steps.ghost.output}}'],
                    'steps' => [
                        ['name' => 'scan', 'agent' => 'a', 'prompt' => 'p'],
                    ],
                ],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ghost', $errors[0]);
    }

    public function test_validate_input_missing_name(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'inputs' => [['type' => 'string']],
                    'steps' => [['name' => 's', 'agent' => 'a', 'prompt' => 'p']],
                ],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("missing 'name'", $errors[0]);
    }

    public function test_validate_invalid_default_failure_strategy(): void
    {
        $config = PipelineConfig::fromArray([
            'defaults' => ['failure_strategy' => 'explode'],
            'pipelines' => [],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('failure_strategy', $errors[0]);
    }

    // ── File Loading ───────────────────────────────────────────────

    public function test_from_yaml_file_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PipelineConfig::fromYamlFile('/nonexistent/path.yaml');
    }

    public function test_from_yaml_files_skips_missing(): void
    {
        $config = PipelineConfig::fromYamlFiles(['/nonexistent/a.yaml', '/nonexistent/b.yaml']);

        $this->assertEmpty($config->getPipelines());
    }

    // ── Pipeline Definition ────────────────────────────────────────

    public function test_validate_inputs(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'inputs' => [
                        ['name' => 'files', 'required' => true],
                        ['name' => 'mode', 'required' => false, 'default' => 'fast'],
                    ],
                    'steps' => [['name' => 's', 'agent' => 'a', 'prompt' => 'p']],
                ],
            ],
        ]);

        $pipeline = $config->getPipeline('test');

        // Missing required input
        $errors = $pipeline->validateInputs([]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('files', $errors[0]);

        // All inputs provided
        $errors = $pipeline->validateInputs(['files' => 'src/']);
        $this->assertEmpty($errors);
    }

    public function test_apply_input_defaults(): void
    {
        $config = PipelineConfig::fromArray([
            'pipelines' => [
                'test' => [
                    'inputs' => [
                        ['name' => 'files', 'required' => true],
                        ['name' => 'mode', 'default' => 'fast'],
                    ],
                    'steps' => [['name' => 's', 'agent' => 'a', 'prompt' => 'p']],
                ],
            ],
        ]);

        $pipeline = $config->getPipeline('test');
        $inputs = $pipeline->applyInputDefaults(['files' => 'src/']);

        $this->assertSame('src/', $inputs['files']);
        $this->assertSame('fast', $inputs['mode']);
    }

    // ── Empty Config ───────────────────────────────────────────────

    public function test_empty_config(): void
    {
        $config = PipelineConfig::fromArray([]);

        $this->assertSame('1.0', $config->getVersion());
        $this->assertEmpty($config->getPipelines());
        $this->assertEmpty($config->validate());
    }
}
