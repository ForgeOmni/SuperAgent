<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;

/**
 * Contract for all pipeline step types.
 */
interface StepInterface
{
    /**
     * Unique name of this step within the pipeline.
     */
    public function getName(): string;

    /**
     * Execute this step within the given pipeline context.
     */
    public function execute(PipelineContext $context): StepResult;

    /**
     * Get the names of steps that must complete before this step runs.
     *
     * @return string[]
     */
    public function getDependencies(): array;

    /**
     * Get the failure strategy for this step.
     */
    public function getFailureStrategy(): FailureStrategy;

    /**
     * Optional timeout in seconds. Null means use pipeline default.
     */
    public function getTimeout(): ?int;

    /**
     * Describe this step for logging/debugging.
     */
    public function describe(): string;
}
