<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use InvalidArgumentException;
use SuperAgent\Swarm\IsolationMode;

/**
 * Parses YAML step definitions into StepInterface objects.
 *
 * Supports recursive parsing for parallel and conditional steps.
 */
class StepFactory
{
    /**
     * Parse a YAML step definition into a StepInterface.
     */
    public function fromArray(array $data): StepInterface
    {
        if (!isset($data['name'])) {
            throw new InvalidArgumentException('Each pipeline step must have a name');
        }

        $name = $data['name'];
        $failureStrategy = $this->parseFailureStrategy($data['on_failure'] ?? 'abort');
        $timeout = isset($data['timeout']) ? (int) $data['timeout'] : null;
        $maxRetries = (int) ($data['max_retries'] ?? 0);
        $dependsOn = (array) ($data['depends_on'] ?? []);

        // Determine step type by checking which key is present
        if (isset($data['loop'])) {
            return $this->parseLoopStep($name, $data, $failureStrategy, $timeout, $dependsOn);
        }

        if (isset($data['parallel'])) {
            return $this->parseParallelStep($name, $data, $failureStrategy, $timeout, $dependsOn);
        }

        if (isset($data['approval'])) {
            return $this->parseApprovalStep($name, $data, $failureStrategy, $timeout, $dependsOn);
        }

        if (isset($data['transform'])) {
            return $this->parseTransformStep($name, $data, $failureStrategy, $timeout, $dependsOn);
        }

        if (isset($data['agent'])) {
            return $this->parseAgentStep($name, $data, $failureStrategy, $timeout, $maxRetries, $dependsOn);
        }

        throw new InvalidArgumentException(
            "Step '{$name}' must specify one of: agent, parallel, approval, transform, loop"
        );
    }

    /**
     * Parse multiple step definitions.
     *
     * @param array[] $stepsData
     * @return StepInterface[]
     */
    public function fromArrayList(array $stepsData): array
    {
        $steps = [];
        foreach ($stepsData as $stepData) {
            $step = $this->fromArray($stepData);

            // Wrap with conditional if 'when' is present
            if (isset($stepData['when'])) {
                $step = new ConditionalStep(
                    name: $step->getName(),
                    innerStep: $step,
                    condition: $stepData['when'],
                    failureStrategy: $step->getFailureStrategy(),
                    timeout: $step->getTimeout(),
                    dependsOn: $step->getDependencies(),
                );
            }

            $steps[] = $step;
        }

        return $steps;
    }

    private function parseAgentStep(
        string $name,
        array $data,
        FailureStrategy $failureStrategy,
        ?int $timeout,
        int $maxRetries,
        array $dependsOn,
    ): AgentStep {
        if (!isset($data['prompt'])) {
            throw new InvalidArgumentException("Agent step '{$name}' must have a prompt");
        }

        $isolation = null;
        if (isset($data['isolation'])) {
            $isolation = IsolationMode::tryFrom($data['isolation']);
        }

        return new AgentStep(
            name: $name,
            agentType: $data['agent'],
            prompt: $data['prompt'],
            model: $data['model'] ?? null,
            systemPrompt: $data['system_prompt'] ?? null,
            isolation: $isolation,
            readOnly: (bool) ($data['read_only'] ?? false),
            allowedTools: $data['allowed_tools'] ?? null,
            inputFrom: $data['input_from'] ?? [],
            failureStrategy: $failureStrategy,
            timeout: $timeout,
            maxRetries: $maxRetries,
            dependsOn: $dependsOn,
        );
    }

    private function parseParallelStep(
        string $name,
        array $data,
        FailureStrategy $failureStrategy,
        ?int $timeout,
        array $dependsOn,
    ): ParallelStep {
        $subSteps = $this->fromArrayList($data['parallel']);

        return new ParallelStep(
            name: $name,
            steps: $subSteps,
            waitAll: (bool) ($data['wait_all'] ?? true),
            failureStrategy: $failureStrategy,
            timeout: $timeout,
            dependsOn: $dependsOn,
        );
    }

    private function parseApprovalStep(
        string $name,
        array $data,
        FailureStrategy $failureStrategy,
        ?int $timeout,
        array $dependsOn,
    ): ApprovalStep {
        $approval = $data['approval'];

        return new ApprovalStep(
            name: $name,
            message: $approval['message'] ?? 'Approval required to continue',
            requiredApprovers: (int) ($approval['required_approvers'] ?? 1),
            timeout: $timeout ?? (isset($approval['timeout']) ? (int) $approval['timeout'] : null),
            failureStrategy: $failureStrategy,
            dependsOn: $dependsOn,
        );
    }

    private function parseTransformStep(
        string $name,
        array $data,
        FailureStrategy $failureStrategy,
        ?int $timeout,
        array $dependsOn,
    ): TransformStep {
        $transform = $data['transform'];

        if (!isset($transform['type'])) {
            throw new InvalidArgumentException("Transform step '{$name}' must specify a type");
        }

        return new TransformStep(
            name: $name,
            type: $transform['type'],
            config: $transform,
            failureStrategy: $failureStrategy,
            timeout: $timeout,
            dependsOn: $dependsOn,
        );
    }

    private function parseLoopStep(
        string $name,
        array $data,
        FailureStrategy $failureStrategy,
        ?int $timeout,
        array $dependsOn,
    ): LoopStep {
        $loop = $data['loop'];

        if (!isset($loop['steps']) || empty($loop['steps'])) {
            throw new InvalidArgumentException("Loop step '{$name}' must have at least one body step");
        }

        if (!isset($loop['max_iterations'])) {
            throw new InvalidArgumentException(
                "Loop step '{$name}' must specify max_iterations to prevent infinite loops"
            );
        }

        $bodySteps = $this->fromArrayList($loop['steps']);

        return new LoopStep(
            name: $name,
            bodySteps: $bodySteps,
            maxIterations: (int) $loop['max_iterations'],
            exitWhen: $loop['exit_when'] ?? [],
            failureStrategy: $failureStrategy,
            timeout: $timeout,
            dependsOn: $dependsOn,
        );
    }

    private function parseFailureStrategy(string $value): FailureStrategy
    {
        $strategy = FailureStrategy::tryFrom($value);

        if ($strategy === null) {
            throw new InvalidArgumentException(
                "Invalid failure strategy: '{$value}'. "
                . 'Valid values: ' . implode(', ', array_column(FailureStrategy::cases(), 'value'))
            );
        }

        return $strategy;
    }
}
