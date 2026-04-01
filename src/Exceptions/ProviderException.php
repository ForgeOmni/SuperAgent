<?php

namespace SuperAgent\Exceptions;

class ProviderException extends SuperAgentException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly int $statusCode = 0,
        public readonly ?array $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", $statusCode, $previous);
    }
}
