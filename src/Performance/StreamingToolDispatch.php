<?php

namespace SuperAgent\Performance;

use Fiber;
use SuperAgent\Messages\ContentBlock;

class StreamingToolDispatch
{
    /**
     * Tools that are safe for early dispatch (read-only, no side effects).
     */
    private const SAFE_TOOLS = [
        'read',
        'grep',
        'glob',
        'web_search',
        'web_fetch',
        'tool_search',
        'task_list',
        'task_get',
    ];

    /** @var array<string, Fiber> Pending tool execution fibers keyed by tool_use_id */
    private array $pendingFibers = [];

    /** @var array<string, array> Completed results keyed by tool_use_id */
    private array $completedResults = [];

    public function __construct(
        private bool $enabled = true,
    ) {
    }

    /**
     * Create an instance from application configuration.
     *
     * Uses the `config()` helper when available (Laravel environments),
     * otherwise falls back to sensible defaults.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.performance.streaming_tool_dispatch') ?? [])
                : [];
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Config unavailable for ' . static::class . ': ' . $e->getMessage());
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Called when a tool_use block is fully received during streaming.
     * Starts a Fiber to pre-execute the tool if it's safe (read-only).
     *
     * @param  ContentBlock  $block     The tool_use block
     * @param  callable      $executor  fn(ContentBlock): array{tool_use_id, content, is_error}
     * @return bool Whether pre-execution was started
     */
    public function dispatchEarly(ContentBlock $block, callable $executor): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! in_array($block->toolName, self::SAFE_TOOLS, true)) {
            return false;
        }

        $fiber = new Fiber(function () use ($executor, $block): array {
            return $executor($block);
        });

        $fiber->start();

        $this->pendingFibers[$block->toolUseId] = $fiber;

        return true;
    }

    /**
     * Check if a tool result is already available from early dispatch.
     * Returns the result if ready, null if not dispatched or still running.
     */
    public function getEarlyResult(string $toolUseId): ?array
    {
        $this->pump();

        if (isset($this->completedResults[$toolUseId])) {
            $result = $this->completedResults[$toolUseId];
            unset($this->completedResults[$toolUseId]);

            return $result;
        }

        return null;
    }

    /**
     * Resume all pending fibers and collect completed results.
     * Call this periodically during streaming or before executeTools().
     */
    public function pump(): void
    {
        foreach ($this->pendingFibers as $toolUseId => $fiber) {
            if ($fiber->isTerminated()) {
                $this->completedResults[$toolUseId] = $fiber->getReturn();
                unset($this->pendingFibers[$toolUseId]);

                continue;
            }

            if ($fiber->isSuspended()) {
                $fiber->resume();
            }

            // Check again after resume -- it may have completed
            if ($fiber->isTerminated()) {
                $this->completedResults[$toolUseId] = $fiber->getReturn();
                unset($this->pendingFibers[$toolUseId]);
            }
        }
    }

    /**
     * Clear all pending fibers and results.
     */
    public function reset(): void
    {
        $this->pendingFibers = [];
        $this->completedResults = [];
    }
}
