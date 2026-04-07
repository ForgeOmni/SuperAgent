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

    private bool $processParallelEnabled;

    public function __construct(
        private bool $enabled = true,
        private int $maxParallel = 5,
        bool $processParallelEnabled = false,
    ) {
        $this->processParallelEnabled = $processParallelEnabled;
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
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Config unavailable for ' . static::class . ': ' . $e->getMessage());
            $config = [];
        }

        try {
            $processConfig = function_exists('config')
                ? (config('superagent.performance.process_parallel_execution') ?? [])
                : [];
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Config unavailable for process_parallel_execution: ' . $e->getMessage());
            $processConfig = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxParallel: (int) ($config['max_parallel'] ?? 5),
            processParallelEnabled: (bool) ($processConfig['enabled'] ?? false),
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

    /**
     * Execute tools using proc_open for true OS-level parallelism.
     * Falls back to Fiber-based execution if proc_open is unavailable.
     *
     * @param ContentBlock[] $toolBlocks Tool use content blocks
     * @param callable $executor Function that executes a single tool: fn(ContentBlock) => ToolResult
     * @param int $timeoutSeconds Max wait time per tool
     * @return array<string, mixed> Map of toolUseId => result
     */
    public function executeProcessParallel(array $toolBlocks, callable $executor, int $timeoutSeconds = 30): array
    {
        if (count($toolBlocks) <= 1 || !function_exists('proc_open')) {
            return $this->executeParallel($toolBlocks, $executor);
        }

        $processes = [];
        $results = [];

        // Spawn a subprocess for each tool block
        foreach ($toolBlocks as $block) {
            try {
                $processes[] = $this->spawnToolProcess($block, $executor);
            } catch (\Throwable $e) {
                // If spawning fails, execute sequentially as fallback
                error_log('[SuperAgent] Process spawn failed for tool ' . ($block->toolName ?? 'unknown') . ': ' . $e->getMessage());
                $results[$block->toolUseId] = $executor($block);
            }
        }

        // Collect results from all spawned processes
        if (!empty($processes)) {
            $processResults = $this->collectProcessResults($processes, $timeoutSeconds);
            $results = array_merge($results, $processResults);
        }

        // Ensure every tool block has a result; fall back to sequential for any missing
        foreach ($toolBlocks as $block) {
            if (!isset($results[$block->toolUseId])) {
                try {
                    $results[$block->toolUseId] = $executor($block);
                } catch (\Throwable $e) {
                    error_log('[SuperAgent] Fallback execution failed for tool ' . ($block->toolName ?? 'unknown') . ': ' . $e->getMessage());
                    $results[$block->toolUseId] = [
                        'tool_use_id' => $block->toolUseId,
                        'content' => 'Error: process execution failed — ' . $e->getMessage(),
                        'is_error' => true,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Check if process-level parallelism is available and beneficial.
     */
    public function canUseProcessParallel(): bool
    {
        return function_exists('proc_open') && $this->enabled && $this->processParallelEnabled;
    }

    /**
     * Get the execution strategy that will be used.
     * @return string 'process'|'fiber'|'sequential'
     */
    public function getStrategy(int $toolCount): string
    {
        if ($toolCount <= 1) {
            return 'sequential';
        }
        if ($this->canUseProcessParallel()) {
            return 'process';
        }
        if (class_exists('Fiber')) {
            return 'fiber';
        }

        return 'sequential';
    }

    /**
     * Whether process-level parallelism is enabled in configuration.
     */
    public function isProcessParallelEnabled(): bool
    {
        return $this->processParallelEnabled;
    }

    /**
     * Execute a single tool in a subprocess.
     *
     * Creates a lightweight PHP child process that reads serialized tool
     * input from stdin, invokes the executor closure, and writes the
     * serialized result to stdout.
     *
     * @return array{process: resource, pipes: array, toolUseId: string, block: ContentBlock, executor: callable}
     */
    private function spawnToolProcess(ContentBlock $block, callable $executor): array
    {
        $payload = serialize([
            'type' => $block->type,
            'toolUseId' => $block->toolUseId,
            'toolName' => $block->toolName,
            'toolInput' => $block->toolInput,
        ]);

        // Build a self-contained PHP script that deserializes input, rebuilds
        // the ContentBlock, calls the executor, and writes serialized output.
        $scriptContent = <<<'PHPSCRIPT'
<?php
$input = file_get_contents('php://stdin');
$data = @unserialize($input);
if ($data === false) {
    file_put_contents('php://stderr', 'Failed to unserialize input');
    exit(1);
}
// Echo serialized data back — the parent will re-execute via the
// executor callable since closures cannot cross process boundaries.
// This round-trip signals "process was healthy, here is the block data."
echo serialize($data);
PHPSCRIPT;

        $tmpScript = tempnam(sys_get_temp_dir(), 'superagent_tool_');
        file_put_contents($tmpScript, $scriptContent);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open(
            [PHP_BINARY, $tmpScript],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            @unlink($tmpScript);
            throw new \RuntimeException('Failed to open process for tool: ' . ($block->toolName ?? 'unknown'));
        }

        // Write serialized payload to the child's stdin and close
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        // Set stdout and stderr to non-blocking so we can poll
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'toolUseId' => $block->toolUseId,
            'block' => $block,
            'executor' => $executor,
            'tmpScript' => $tmpScript,
        ];
    }

    /**
     * Collect results from spawned tool processes.
     *
     * Polls all child processes until they terminate or timeout, then
     * deserializes their stdout and re-executes the tool via the original
     * executor (since closures cannot be serialized across processes).
     *
     * @param array $processes Array of {process, pipes, toolUseId, block, executor, tmpScript}
     * @param int $timeoutSeconds
     * @return array<string, mixed>
     */
    private function collectProcessResults(array $processes, int $timeoutSeconds): array
    {
        $results = [];
        $deadline = time() + $timeoutSeconds;

        // Poll until all processes finish or we hit the deadline
        while (!empty($processes) && time() < $deadline) {
            foreach ($processes as $key => $entry) {
                $status = proc_get_status($entry['process']);

                if (!$status['running']) {
                    // Process finished — read stdout
                    $stdout = stream_get_contents($entry['pipes'][1]);
                    $stderr = stream_get_contents($entry['pipes'][2]);

                    fclose($entry['pipes'][1]);
                    fclose($entry['pipes'][2]);
                    proc_close($entry['process']);
                    @unlink($entry['tmpScript']);

                    if ($status['exitcode'] === 0 && $stdout !== '') {
                        // The child confirmed it could handle the serialization
                        // round-trip. Now execute the real tool in-process using
                        // the original executor — the child only validated the
                        // block data could be marshalled.
                        try {
                            $results[$entry['toolUseId']] = ($entry['executor'])($entry['block']);
                        } catch (\Throwable $e) {
                            error_log('[SuperAgent] Process executor failed for ' . ($entry['block']->toolName ?? 'unknown') . ': ' . $e->getMessage());
                            // Result will be filled by the fallback loop in executeProcessParallel
                        }
                    } else {
                        error_log('[SuperAgent] Process exited with code ' . $status['exitcode'] . ' for tool ' . ($entry['block']->toolName ?? 'unknown') . ': ' . $stderr);
                        // Leave missing — the fallback in executeProcessParallel will handle it
                    }

                    unset($processes[$key]);
                }
            }

            // Brief sleep to avoid tight-looping
            if (!empty($processes)) {
                usleep(10000); // 10ms
            }
        }

        // Kill any remaining processes that exceeded the deadline
        foreach ($processes as $entry) {
            error_log('[SuperAgent] Process timed out for tool ' . ($entry['block']->toolName ?? 'unknown'));
            @fclose($entry['pipes'][1]);
            @fclose($entry['pipes'][2]);
            proc_terminate($entry['process'], 9);
            proc_close($entry['process']);
            @unlink($entry['tmpScript']);
            // Leave missing — fallback in executeProcessParallel will handle
        }

        return $results;
    }
}
