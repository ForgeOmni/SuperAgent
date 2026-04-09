<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * LLM provider errors (API failures, rate limits, auth errors).
 */
class ProviderException extends SuperAgentException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly int $statusCode = 0,
        public readonly ?array $responseBody = null,
        public readonly bool $retryable = false,
        public readonly ?float $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", $statusCode, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Create from an HTTP status code with sensible defaults.
     */
    public static function fromHttpStatus(
        int $statusCode,
        string $message,
        string $provider = '',
        ?\Throwable $previous = null,
    ): self {
        $retryable = in_array($statusCode, [429, 500, 502, 503, 529]);
        $retryAfter = $statusCode === 429 ? 60.0 : null;

        return new self(
            message: $message,
            provider: $provider,
            statusCode: $statusCode,
            retryable: $retryable,
            retryAfterSeconds: $retryAfter,
            previous: $previous,
        );
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'provider' => $this->provider,
            'status_code' => $this->statusCode,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ]);
    }
}
