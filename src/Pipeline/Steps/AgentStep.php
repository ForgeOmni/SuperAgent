<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\IsolationMode;

/**
 * Executes a named agent with a prompt and collects its output.
 *
 * YAML example:
 *   - name: security-scan
 *     agent: security-scanner
 *     prompt: "Scan {{inputs.files}} for vulnerabilities"
 *     model: claude-haiku-4-5-20251001
 *     on_failure: abort
 *     timeout: 120
 */
class AgentStep extends AbstractStep
{
    public function __construct(
        string $name,
        private readonly string $agentType,
        private readonly string $prompt,
        private readonly ?string $model = null,
        private readonly ?string $systemPrompt = null,
        private readonly ?IsolationMode $isolation = null,
        private readonly bool $readOnly = false,
        private readonly ?array $allowedTools = null,
        private readonly array $inputFrom = [],
        FailureStrategy $failureStrategy = FailureStrategy::ABORT,
        ?int $timeout = null,
        int $maxRetries = 0,
        array $dependsOn = [],
    ) {
        parent::__construct($name, $failureStrategy, $timeout, $maxRetries, $dependsOn);
    }

    public function execute(PipelineContext $context): StepResult
    {
        $resolvedPrompt = $this->buildPrompt($context);

        [$output, $durationMs] = $this->timed(function () use ($resolvedPrompt) {
            return $this->runAgent($resolvedPrompt);
        });

        return StepResult::success(
            stepName: $this->name,
            output: $output,
            durationMs: $durationMs,
            metadata: [
                'agent_type' => $this->agentType,
                'model' => $this->model,
            ],
        );
    }

    public function describe(): string
    {
        return "Agent step '{$this->name}': run '{$this->agentType}' agent";
    }

    /**
     * Get the agent type name.
     */
    public function getAgentType(): string
    {
        return $this->agentType;
    }

    /**
     * Get the raw (unresolved) prompt template.
     */
    public function getPromptTemplate(): string
    {
        return $this->prompt;
    }

    /**
     * Build the spawn configuration for this agent step.
     */
    public function buildSpawnConfig(PipelineContext $context): AgentSpawnConfig
    {
        return new AgentSpawnConfig(
            name: $this->agentType,
            prompt: $this->buildPrompt($context),
            model: $this->model,
            systemPrompt: $this->systemPrompt,
            isolation: $this->isolation,
            readOnly: $this->readOnly,
            allowedTools: $this->allowedTools,
        );
    }

    /**
     * Build the full prompt with resolved templates and input_from context.
     */
    private function buildPrompt(PipelineContext $context): string
    {
        $resolvedPrompt = $context->resolveTemplate($this->prompt);

        // Append input_from context
        if (!empty($this->inputFrom)) {
            $contextParts = [];
            foreach ($this->inputFrom as $label => $template) {
                $resolved = $context->resolveTemplate($template);
                $contextParts[] = "## {$label}\n{$resolved}";
            }
            $resolvedPrompt .= "\n\n# Context from previous steps\n" . implode("\n\n", $contextParts);
        }

        return $resolvedPrompt;
    }

    /**
     * Execute the agent and return its output.
     *
     * This is the integration point with the Swarm backend system.
     * In production, this spawns an agent via BackendInterface.
     * For testing, this method can be overridden.
     */
    protected function runAgent(string $prompt): string
    {
        // This will be called by PipelineEngine which injects the actual agent runner.
        // The default implementation stores the prompt for the engine to pick up.
        // PipelineEngine overrides execution via the agentRunner callback.
        throw new \RuntimeException(
            "AgentStep::runAgent() should not be called directly. "
            . "Use PipelineEngine to execute pipelines."
        );
    }
}
