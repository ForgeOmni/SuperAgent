<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;
use SuperAgent\Pipeline\StepStatus;

/**
 * Pauses the pipeline until user approval is received.
 *
 * YAML example:
 *   - name: deploy-gate
 *     approval:
 *       message: "All checks passed. Deploy to production?"
 *       timeout: 3600
 *       required_approvers: 1
 */
class ApprovalStep extends AbstractStep
{
    public function __construct(
        string $name,
        private readonly string $message,
        private readonly int $requiredApprovers = 1,
        ?int $timeout = null,
        FailureStrategy $failureStrategy = FailureStrategy::ABORT,
        array $dependsOn = [],
    ) {
        parent::__construct($name, $failureStrategy, $timeout, dependsOn: $dependsOn);
    }

    public function execute(PipelineContext $context): StepResult
    {
        $resolvedMessage = $context->resolveTemplate($this->message);

        // Check if approval was pre-set (e.g., by auto-approve config or test)
        $preApproved = $context->getVariable("approval.{$this->name}");

        if ($preApproved === true) {
            return StepResult::success(
                stepName: $this->name,
                output: 'Auto-approved',
                metadata: ['auto_approved' => true],
            );
        }

        if ($preApproved === false) {
            return StepResult::failure(
                stepName: $this->name,
                error: 'Approval denied',
                metadata: ['denied' => true],
            );
        }

        // In real execution, PipelineEngine handles the approval callback.
        // Return a waiting status that the engine interprets.
        return new StepResult(
            stepName: $this->name,
            status: StepStatus::WAITING_APPROVAL,
            output: $resolvedMessage,
            metadata: [
                'message' => $resolvedMessage,
                'required_approvers' => $this->requiredApprovers,
            ],
        );
    }

    public function describe(): string
    {
        return "Approval gate '{$this->name}': \"{$this->message}\"";
    }

    /**
     * Get the approval message template.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the number of required approvers.
     */
    public function getRequiredApprovers(): int
    {
        return $this->requiredApprovers;
    }
}
