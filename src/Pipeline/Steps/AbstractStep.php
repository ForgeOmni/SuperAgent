<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

/**
 * Base implementation for pipeline steps.
 */
abstract class AbstractStep implements StepInterface
{
    /** @var string[] */
    protected array $dependencies = [];

    public function __construct(
        protected readonly string $name,
        protected readonly FailureStrategy $failureStrategy = FailureStrategy::ABORT,
        protected readonly ?int $timeout = null,
        protected readonly int $maxRetries = 0,
        array $dependsOn = [],
    ) {
        $this->dependencies = $dependsOn;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getFailureStrategy(): FailureStrategy
    {
        return $this->failureStrategy;
    }

    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Measure execution time of a callable.
     *
     * @return array{0: mixed, 1: float} [result, durationMs]
     */
    protected function timed(callable $fn): array
    {
        $start = hrtime(true);
        $result = $fn();
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return [$result, $durationMs];
    }
}
