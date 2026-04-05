<?php

namespace SuperAgent\Performance;

use Fiber;
use SuperAgent\Messages\ContentBlock;

class ParallelToolExecutor
{
    /**
     * Tools that are safe to execute in parallel (read-only, no side effects).
     */
    private const READ_ONLY_TOOLS = [
        'read',
        'grep',
        'glob',
        'web_search',
        'web_fetch',
        'tool_search',
        'task_list',
        'task_get',
    ];

    public function __construct(
        private bool $enabled = true,
        private int $maxParallel = 5,
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
                ? (config('superagent.performance.parallel_tool_execution') ?? [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxParallel: (int) ($config['max_parallel'] ?? 5),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Classify tool blocks into groups that can run in parallel vs sequentially.
     *
     * Tools with side effects (Write, Edit, Bash, etc.) must run sequentially.
     * Read-only tools (Read, Grep, Glob, WebSearch, etc.) can run in parallel.
     *
     * If there is only one block, or all blocks require sequential execution,
     * everything is returned in the sequential group.
     *
     * @param  ContentBlock[]  $toolBlocks
     * @return array{parallel: ContentBlock[], sequential: ContentBlock[]}
     */
    public function classify(array $toolBlocks): array
    {
        if (count($toolBlocks) <= 1) {
            return ['parallel' => [], 'sequential' => $toolBlocks];
        }

        $parallel = [];
        $sequential = [];

        foreach ($toolBlocks as $block) {
            if (in_array($block->toolName, self::READ_ONLY_TOOLS, true)) {
                $parallel[] = $block;
            } else {
                $sequential[] = $block;
            }
        }

        // If nothing qualifies for parallel execution, return all as sequential
        if (count($parallel) <= 1) {
            return ['parallel' => [], 'sequential' => $toolBlocks];
        }

        return ['parallel' => $parallel, 'sequential' => $sequential];
    }

    /**
     * Execute multiple tool blocks in parallel using PHP Fibers.
     *
     * Each fiber runs the provided executor callable for a single block.
     * Fibers are started in batches up to maxParallel, then resumed in
     * round-robin fashion until all complete. Results are returned in
     * the same order as the input blocks.
     *
     * @param  ContentBlock[]  $blocks    Tool use blocks to execute in parallel
     * @param  callable        $executor  fn(ContentBlock $block): array{tool_use_id: string, content: string, is_error: bool}
     * @return array  Results in same order as input blocks
     */
    public function executeParallel(array $blocks, callable $executor): array
    {
        if (empty($blocks)) {
            return [];
        }

        // For a single block, just execute directly -- no fiber overhead needed
        if (count($blocks) === 1) {
            return [$executor($blocks[0])];
        }

        $results = array_fill(0, count($blocks), null);
        $pending = $blocks;

        // Process in batches of maxParallel
        while (! empty($pending)) {
            $batch = array_splice($pending, 0, $this->maxParallel);
            $fibers = [];

            // Create and start one fiber per block in this batch
            foreach ($batch as $index => $block) {
                // Compute the original index for result ordering
                $originalIndex = array_search($block, $blocks, true);

                $fiber = new Fiber(function () use ($executor, $block): array {
                    return $executor($block);
                });

                $fibers[] = [
                    'fiber' => $fiber,
                    'index' => $originalIndex,
                ];

                $fiber->start();
            }

            // Round-robin resume until all fibers in this batch complete
            while (! empty($fibers)) {
                foreach ($fibers as $key => $entry) {
                    /** @var Fiber $fiber */
                    $fiber = $entry['fiber'];

                    if ($fiber->isTerminated()) {
                        $results[$entry['index']] = $fiber->getReturn();
                        unset($fibers[$key]);

                        continue;
                    }

                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                }

                // Re-index to avoid gaps after unset
                $fibers = array_values($fibers);
            }
        }

        return $results;
    }
}
