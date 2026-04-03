<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

use InvalidArgumentException;
use SuperAgent\Pipeline\Steps\FailureStrategy;
use SuperAgent\Pipeline\Steps\StepFactory;
use SuperAgent\Pipeline\Steps\StepInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and validates pipeline YAML configuration.
 *
 * YAML format:
 *   version: "1.0"
 *   defaults:
 *     failure_strategy: abort
 *     timeout: 300
 *     max_retries: 3
 *   pipelines:
 *     pipeline-name:
 *       description: "Human-readable description"
 *       steps: [...]
 *       inputs: [...]
 *       outputs: {...}
 */
class PipelineConfig
{
    /** @var PipelineDefinition[] keyed by pipeline name */
    private array $pipelines = [];

    private string $version = '1.0';

    private string $defaultFailureStrategy = 'abort';

    private int $defaultTimeout = 300;

    private int $defaultMaxRetries = 0;

    private function __construct() {}

    /**
     * Load pipeline configuration from a YAML file.
     */
    public static function fromYamlFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Pipeline config file not found: {$path}");
        }

        $data = Yaml::parseFile($path);

        if (!is_array($data)) {
            throw new InvalidArgumentException("Pipeline config file must contain a YAML mapping: {$path}");
        }

        return self::fromArray($data);
    }

    /**
     * Load pipeline configuration from an array.
     */
    public static function fromArray(array $data): self
    {
        $config = new self();
        $config->version = (string) ($data['version'] ?? '1.0');

        if (isset($data['defaults'])) {
            $config->defaultFailureStrategy = $data['defaults']['failure_strategy'] ?? 'abort';
            $config->defaultTimeout = (int) ($data['defaults']['timeout'] ?? 300);
            $config->defaultMaxRetries = (int) ($data['defaults']['max_retries'] ?? 0);
        }

        $stepFactory = new StepFactory();

        if (isset($data['pipelines']) && is_array($data['pipelines'])) {
            foreach ($data['pipelines'] as $pipelineName => $pipelineData) {
                $config->pipelines[$pipelineName] = self::parsePipeline(
                    $pipelineName,
                    $pipelineData,
                    $stepFactory,
                    $config,
                );
            }
        }

        return $config;
    }

    /**
     * Merge multiple config files (later files take precedence for same pipeline names).
     *
     * @param string[] $paths
     */
    public static function fromYamlFiles(array $paths): self
    {
        $merged = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $data = Yaml::parseFile($path);
            if (!is_array($data)) {
                continue;
            }

            if (isset($data['pipelines'])) {
                $merged['pipelines'] = array_merge($merged['pipelines'] ?? [], $data['pipelines']);
            }

            if (isset($data['defaults'])) {
                $merged['defaults'] = $data['defaults'];
            }

            if (isset($data['version'])) {
                $merged['version'] = $data['version'];
            }
        }

        return self::fromArray($merged);
    }

    /**
     * Validate the configuration and return any errors.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];

        $validStrategies = array_column(FailureStrategy::cases(), 'value');
        if (!in_array($this->defaultFailureStrategy, $validStrategies, true)) {
            $errors[] = "Invalid default failure_strategy: '{$this->defaultFailureStrategy}'";
        }

        if ($this->defaultTimeout <= 0) {
            $errors[] = "Default timeout must be positive, got: {$this->defaultTimeout}";
        }

        $pipelineNames = [];
        foreach ($this->pipelines as $name => $pipeline) {
            if (in_array($name, $pipelineNames, true)) {
                $errors[] = "Duplicate pipeline name: '{$name}'";
            }
            $pipelineNames[] = $name;

            // Validate step names are unique within pipeline
            $stepNames = [];
            foreach ($pipeline->steps as $step) {
                $this->collectStepNames($step, $stepNames, $name, $errors);
            }

            // Validate dependencies reference existing steps
            foreach ($pipeline->steps as $step) {
                $this->validateDependencies($step, $stepNames, $name, $errors);
            }

            // Validate required inputs are defined
            foreach ($pipeline->inputs as $input) {
                if (!isset($input['name'])) {
                    $errors[] = "Pipeline '{$name}': input definition missing 'name'";
                }
            }

            // Validate output templates reference valid steps
            foreach ($pipeline->outputs as $key => $template) {
                if (is_string($template) && preg_match_all('/\{\{steps\.(\w+)\./', $template, $matches)) {
                    foreach ($matches[1] as $refStep) {
                        if (!in_array($refStep, $stepNames, true)) {
                            $errors[] = "Pipeline '{$name}': output '{$key}' references unknown step '{$refStep}'";
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Get a pipeline definition by name.
     */
    public function getPipeline(string $name): ?PipelineDefinition
    {
        return $this->pipelines[$name] ?? null;
    }

    /**
     * Get all pipeline definitions.
     *
     * @return PipelineDefinition[]
     */
    public function getPipelines(): array
    {
        return $this->pipelines;
    }

    /**
     * Get all pipeline names.
     *
     * @return string[]
     */
    public function getPipelineNames(): array
    {
        return array_keys($this->pipelines);
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDefaultTimeout(): int
    {
        return $this->defaultTimeout;
    }

    public function getDefaultFailureStrategy(): string
    {
        return $this->defaultFailureStrategy;
    }

    private static function parsePipeline(
        string $name,
        array $data,
        StepFactory $stepFactory,
        self $config,
    ): PipelineDefinition {
        $steps = [];
        if (isset($data['steps']) && is_array($data['steps'])) {
            // Inject defaults into step data
            foreach ($data['steps'] as &$stepData) {
                $stepData['on_failure'] ??= $config->defaultFailureStrategy;
                $stepData['timeout'] ??= $config->defaultTimeout;
                $stepData['max_retries'] ??= $config->defaultMaxRetries;
            }
            unset($stepData);

            $steps = $stepFactory->fromArrayList($data['steps']);
        }

        return new PipelineDefinition(
            name: $name,
            steps: $steps,
            description: $data['description'] ?? null,
            inputs: $data['inputs'] ?? [],
            outputs: $data['outputs'] ?? [],
            triggers: $data['triggers'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Recursively collect step names for validation.
     */
    private function collectStepNames(StepInterface $step, array &$names, string $pipeline, array &$errors): void
    {
        if (in_array($step->getName(), $names, true)) {
            $errors[] = "Pipeline '{$pipeline}': duplicate step name '{$step->getName()}'";
        }
        $names[] = $step->getName();

        if ($step instanceof Steps\ParallelStep) {
            foreach ($step->getSteps() as $subStep) {
                $this->collectStepNames($subStep, $names, $pipeline, $errors);
            }
        }

        if ($step instanceof Steps\LoopStep) {
            foreach ($step->getBodySteps() as $subStep) {
                $this->collectStepNames($subStep, $names, $pipeline, $errors);
            }
        }

        if ($step instanceof Steps\ConditionalStep) {
            // Inner step already counted via the conditional's name
        }
    }

    /**
     * Validate that step dependencies reference existing steps.
     */
    private function validateDependencies(StepInterface $step, array $validNames, string $pipeline, array &$errors): void
    {
        foreach ($step->getDependencies() as $dep) {
            if (!in_array($dep, $validNames, true)) {
                $errors[] = "Pipeline '{$pipeline}': step '{$step->getName()}' depends on unknown step '{$dep}'";
            }
        }
    }
}
