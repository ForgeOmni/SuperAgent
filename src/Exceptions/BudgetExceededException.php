<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * Budget/cost limit exceeded.
 */
class BudgetExceededException extends AgentException
{
    public function __construct(
        public readonly float $spent = 0.0,
        public readonly float $budget = 0.0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Budget exceeded: $%.4f spent of $%.4f budget', $spent, $budget),
            previous: $previous,
            context: ['spent' => $spent, 'budget' => $budget],
        );
    }
}
