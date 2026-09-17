<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Helpers;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * A tool whose name, category and read-only status are whatever a test needs.
 *
 * Used by the ToolPolicy and embedded-profile tests, which are about what a
 * tool *declares itself to be* rather than about what any real tool does.
 */
class FakePolicyTool extends Tool
{
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolCategory = 'general',
        private readonly bool $readOnly = false,
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'fixture';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): ToolResult
    {
        return ToolResult::success('ok');
    }

    public function category(): string
    {
        return $this->toolCategory;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
