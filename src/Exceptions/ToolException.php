<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * Tool execution errors.
 */
class ToolException extends SuperAgentException
{
    public function __construct(
        string $message,
        public readonly string $toolName,
        public readonly array $toolInput = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[Tool: {$toolName}] {$message}", 0, $previous);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'tool_name' => $this->toolName,
            'tool_input' => $this->toolInput,
        ]);
    }
}
