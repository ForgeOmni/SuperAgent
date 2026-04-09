<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

use RuntimeException;

/**
 * Base exception for all SuperAgent errors.
 *
 * Carries structured metadata so middleware and error handlers
 * can make informed decisions (retry, downgrade, abort).
 */
class SuperAgentException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Whether this error can be retried.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * Structured representation for logging/telemetry.
     */
    public function toArray(): array
    {
        return [
            'type' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'retryable' => $this->isRetryable(),
            'context' => $this->context,
        ];
    }
}
