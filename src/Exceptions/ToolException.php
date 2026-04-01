<?php

namespace SuperAgent\Exceptions;

class ToolException extends SuperAgentException
{
    public function __construct(
        string $message,
        public readonly string $toolName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[Tool: {$toolName}] {$message}", 0, $previous);
    }
}
