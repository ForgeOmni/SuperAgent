<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Helpers;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * A tool that hands its decision to a human the first time it is called, and
 * answers normally if it is ever called again — the shape of an approval tool
 * in a host application.
 */
class DeferringTool extends Tool
{
    public int $calls = 0;

    public function __construct(
        private readonly string $ticketId = 'ticket-1',
        private readonly array $meta = ['summary' => 'cancel order 42'],
        private readonly string $toolName = 'cancel_order',
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Cancels an order once a human has approved it.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['order_id' => ['type' => 'integer']]];
    }

    public function category(): string
    {
        return 'general';
    }

    public function execute(array $input): ToolResult
    {
        $this->calls++;

        return ToolResult::deferred($this->ticketId, $this->meta);
    }
}
