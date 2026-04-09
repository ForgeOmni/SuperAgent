<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * Input/output validation errors (guardrails, schema validation).
 */
class ValidationException extends AgentException
{
    public function __construct(
        string $message = '',
        public readonly array $violations = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            previous: $previous,
            context: ['violations' => $violations],
        );
    }
}
