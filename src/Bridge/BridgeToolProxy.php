<?php

declare(strict_types=1);

namespace SuperAgent\Bridge;

use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Tools\ToolResult;

/**
 * Lightweight proxy that wraps an external tool definition (from OpenAI format)
 * so it can be passed through the EnhancedProvider pipeline.
 *
 * The bridge never executes tools — the client (e.g. Codex) does that.
 * execute() throws to catch accidental invocations.
 */
class BridgeToolProxy implements ToolInterface
{
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $parameters,
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function inputSchema(): array
    {
        return $this->parameters;
    }

    public function execute(array $input): ToolResult
    {
        throw new \LogicException(
            "BridgeToolProxy::execute() should never be called. "
            . "The bridge is a pass-through proxy; tool execution is the client's responsibility."
        );
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
