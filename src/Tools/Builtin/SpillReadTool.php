<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Spill\LocalSpillStore;
use SuperAgent\Spill\SpillStoreInterface;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Reads back tool output that the SpillPolicy persisted to storage.
 * Counterpart of the `spill://` locators embedded in spilled previews.
 */
class SpillReadTool extends Tool
{
    public function __construct(
        private ?SpillStoreInterface $store = null,
    ) {
    }

    public function name(): string
    {
        return 'spill_read';
    }

    public function description(): string
    {
        return 'Read back a spilled (disk-persisted) tool output by its spill:// locator. '
            . 'Large tool results are automatically spilled with a preview; use this tool to '
            . 'retrieve the full content in chunks via offset/limit.';
    }

    public function category(): string
    {
        return 'search';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'locator' => [
                    'type' => 'string',
                    'description' => 'The spill:// locator from a spilled tool result.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Byte offset to start reading from (default 0).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum bytes to read (default 20000, max 100000).',
                ],
            ],
            'required' => ['locator'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $locator = $input['locator'] ?? '';
        if (!is_string($locator) || $locator === '') {
            return ToolResult::error('locator is required.');
        }

        $store = $this->store ?? LocalSpillStore::fromConfig();

        try {
            $chunk = $store->read(
                $locator,
                (int) ($input['offset'] ?? 0),
                (int) ($input['limit'] ?? 20_000),
            );
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        $end = $chunk['offset'] + $chunk['length'];
        $header = "{$locator} bytes {$chunk['offset']}-{$end} of {$chunk['total']}";
        if ($end < $chunk['total']) {
            $remaining = $chunk['total'] - $end;
            $header .= " ({$remaining} bytes remaining — call spill_read again with offset={$end})";
        }

        return ToolResult::success($header . "\n" . $chunk['content']);
    }
}
