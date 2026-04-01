<?php

namespace SuperAgent\Tools;

use Closure;

/**
 * A tool defined inline via closures, for quick prototyping.
 *
 * Usage:
 *   $weather = new ClosureTool(
 *       name: 'get_weather',
 *       description: 'Get the current weather for a location.',
 *       inputSchema: [
 *           'type' => 'object',
 *           'properties' => [
 *               'location' => ['type' => 'string', 'description' => 'City name'],
 *           ],
 *           'required' => ['location'],
 *       ],
 *       handler: fn(array $input) => ToolResult::success("Sunny, 22°C in {$input['location']}"),
 *   );
 */
class ClosureTool extends Tool
{
    public function __construct(
        protected readonly string $toolName,
        protected readonly string $toolDescription,
        protected readonly array $toolInputSchema,
        protected readonly Closure $handler,
        protected readonly bool $readOnly = false,
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
        return $this->toolInputSchema;
    }

    public function execute(array $input): ToolResult
    {
        return ($this->handler)($input);
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
