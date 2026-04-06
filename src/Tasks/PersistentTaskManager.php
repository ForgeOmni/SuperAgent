<?php

declare(strict_types=1);

namespace SuperAgent\Tasks;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * File-backed TaskManager that persists task metadata to a JSON index
 * and task output to individual log files.
 *
 * Directory layout (under $storageDir):
 *   tasks.json          — JSON array of TaskRecord metadata
 *   {task_id}.log       — stdout/stderr output for each task
 *
 * When persistence is disabled via config, falls back to the
 * in-memory-only parent TaskManager behaviour.
 */
class PersistentTaskManager extends TaskManager
{
    private string $storageDir;
    private LoggerInterface $logger;

    /** @var array<string, resource|null> Open file handles for task output logs */
    private array $logHandles = [];

    /** @var array<string, resource|null> Process resources being watched */
    private array $watchedProcesses = [];

    /** @var array<string, int> Generation counter to prevent restart races */
    private array $generations = [];

    private int $maxOutputReadBytes;
    private int $pruneAfterDays;

    public function __construct(
        string $storageDir,
        ?LoggerInterface $logger = null,
        int $maxOutputReadBytes = 12000,
        int $pruneAfterDays = 30,
    ) {
        parent::__construct();

        $this->storageDir = rtrim($storageDir, '/');
        $this->logger = $logger ?? new NullLogger();
        $this->maxOutputReadBytes = $maxOutputReadBytes;
        $this->pruneAfterDays = $pruneAfterDays;

        $this->ensureDirectory();
        $this->restoreIndex();
    }

    /**
     * Build from config, with optional parameter overrides.
     *
     * Priority: $overrides > config > defaults.
     * Returns null only when the resolved 'enabled' is false.
     *
     * @param array $overrides  Keys: enabled, storage_path, max_output_read_bytes, prune_after_days
     */
    public static function fromConfig(?LoggerInterface $logger = null, array $overrides = []): ?self
    {
        $config = self::resolveConfig();
        $tasksConfig = $config['tasks'] ?? [];

        // Resolve enabled: override > tasks.enabled > persistence.enabled > default(false)
        $enabled = $overrides['enabled']
            ?? $tasksConfig['enabled']
            ?? $config['enabled']
            ?? false;

        if (!$enabled) {
            return null;
        }

        $storageDir = $overrides['storage_path']
            ?? self::resolveStorageDir($config);

        return new self(
            storageDir: rtrim($storageDir, '/') . '/tasks',
            logger: $logger,
            maxOutputReadBytes: (int) ($overrides['max_output_read_bytes'] ?? $tasksConfig['max_output_read_bytes'] ?? 12000),
            pruneAfterDays: (int) ($overrides['prune_after_days'] ?? $tasksConfig['prune_after_days'] ?? 30),
        );
    }

    // ── Overrides ─────────────────────────────────────────────────

    public function createTask(array $data, string $listId = 'default'): Task
    {
        $task = parent::createTask($data, $listId);
        $this->persistIndex();
        return $task;
    }

    public function updateTask(string $taskId, array $updates): bool
    {
        $result = parent::updateTask($taskId, $updates);
        if ($result) {
            $this->persistIndex();
        }
        return $result;
    }

    public function stopTask(string $taskId): bool
    {
        // Terminate watched process if running
        $this->terminateProcess($taskId);

        $result = parent::stopTask($taskId);
        if ($result) {
            $this->persistIndex();
        }
        return $result;
    }

    public function deleteTask(string $taskId): bool
    {
        $result = parent::deleteTask($taskId);
        if ($result) {
            $this->cleanupTaskFiles($taskId);
            $this->persistIndex();
        }
        return $result;
    }

    public function setTaskOutput(string $taskId, array $output): bool
    {
        $result = parent::setTaskOutput($taskId, $output);
        if ($result) {
            $this->persistIndex();
        }
        return $result;
    }

    // ── Output log file management ────────────────────────────────

    /**
     * Get the log file path for a task.
     */
    public function getOutputFilePath(string $taskId): string
    {
        return $this->storageDir . '/' . $taskId . '.log';
    }

    /**
     * Append data to a task's output log file.
     */
    public function appendOutput(string $taskId, string $data): void
    {
        if ($data === '') {
            return;
        }

        $handle = $this->getLogHandle($taskId);
        fwrite($handle, $data);
        fflush($handle);
    }

    /**
     * Read the tail of a task's output log file.
     */
    public function readOutput(string $taskId, ?int $maxBytes = null): string
    {
        $maxBytes = $maxBytes ?? $this->maxOutputReadBytes;
        $path = $this->getOutputFilePath($taskId);

        if (!file_exists($path)) {
            return '';
        }

        $size = filesize($path);
        if ($size <= $maxBytes) {
            return file_get_contents($path);
        }

        // Read tail
        $handle = fopen($path, 'r');
        fseek($handle, -$maxBytes, SEEK_END);
        $content = fread($handle, $maxBytes);
        fclose($handle);

        return "[...truncated, showing last {$maxBytes} bytes...]\n" . $content;
    }

    // ── Process watching ──────────────────────────────────────────

    /**
     * Register a process resource to watch for a given task.
     * When the process exits, the task status is automatically updated.
     *
     * @param string $taskId
     * @param resource $process  A proc_open resource
     * @param resource|null $stdout  Stdout pipe to drain
     * @param resource|null $stderr  Stderr pipe to drain
     */
    public function watchProcess(string $taskId, $process, $stdout = null, $stderr = null): void
    {
        $gen = ($this->generations[$taskId] ?? 0) + 1;
        $this->generations[$taskId] = $gen;

        $this->watchedProcesses[$taskId] = [
            'process' => $process,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'generation' => $gen,
        ];

        $this->logger->debug('Watching process for task', [
            'task_id' => $taskId,
            'generation' => $gen,
        ]);
    }

    /**
     * Poll all watched processes once, draining output and detecting exits.
     *
     * @return array<string, string> taskId => status for tasks that changed
     */
    public function pollProcesses(): array
    {
        $changed = [];

        foreach ($this->watchedProcesses as $taskId => $info) {
            $process = $info['process'];
            $gen = $info['generation'];

            // Check generation hasn't been superseded
            if (($this->generations[$taskId] ?? 0) !== $gen) {
                unset($this->watchedProcesses[$taskId]);
                continue;
            }

            // Drain stdout/stderr into log file
            $this->drainPipe($taskId, $info['stdout'] ?? null);
            $this->drainPipe($taskId, $info['stderr'] ?? null);

            if (!is_resource($process)) {
                $this->finalizeTask($taskId, 1);
                $changed[$taskId] = 'failed';
                unset($this->watchedProcesses[$taskId]);
                continue;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain remaining output
                $this->drainPipeToEnd($taskId, $info['stdout'] ?? null);
                $this->drainPipeToEnd($taskId, $info['stderr'] ?? null);

                $exitCode = $status['exitcode'];
                $this->finalizeTask($taskId, $exitCode);
                $changed[$taskId] = $exitCode === 0 ? 'completed' : 'failed';

                // Close resources
                $this->closePipe($info['stdout'] ?? null);
                $this->closePipe($info['stderr'] ?? null);
                proc_close($process);

                unset($this->watchedProcesses[$taskId]);
            }
        }

        return $changed;
    }

    /**
     * Check if any processes are still being watched.
     */
    public function hasWatchedProcesses(): bool
    {
        return !empty($this->watchedProcesses);
    }

    // ── Pruning ───────────────────────────────────────────────────

    /**
     * Remove completed/failed task logs older than pruneAfterDays.
     *
     * @return int Number of pruned tasks
     */
    public function prune(): int
    {
        if ($this->pruneAfterDays <= 0) {
            return 0;
        }

        $cutoff = time() - ($this->pruneAfterDays * 86400);
        $pruned = 0;
        $allTasks = parent::listTasks();

        foreach ($allTasks as $task) {
            if (!$task->status->isTerminal()) {
                continue;
            }

            $endedTs = $task->endedAt ? $task->endedAt->getTimestamp() : 0;
            if ($endedTs > 0 && $endedTs < $cutoff) {
                $this->cleanupTaskFiles($task->id);
                parent::deleteTask($task->id);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->persistIndex();
            $this->logger->info('Pruned old tasks', ['count' => $pruned]);
        }

        return $pruned;
    }

    // ── Persistence ───────────────────────────────────────────────

    /**
     * Persist the task index to disk.
     */
    public function persistIndex(): void
    {
        $allTasks = parent::listTasks();
        $data = [];

        foreach ($allTasks as $task) {
            $data[] = $task->toArray();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $path = $this->storageDir . '/tasks.json';

        // Atomic write
        $tmpPath = $path . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $json);
        rename($tmpPath, $path);
    }

    /**
     * Restore task index from disk.
     */
    public function restoreIndex(): void
    {
        $path = $this->storageDir . '/tasks.json';

        if (!file_exists($path)) {
            return;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            $this->logger->warning('Invalid tasks.json, skipping restore');
            return;
        }

        $restored = 0;
        foreach ($data as $taskData) {
            try {
                // Mark previously running tasks as failed (stale process)
                if (in_array($taskData['status'] ?? '', ['pending', 'in_progress'])) {
                    $taskData['status'] = 'failed';
                    $taskData['metadata'] = array_merge(
                        $taskData['metadata'] ?? [],
                        ['_stale_reason' => 'Process died before completion'],
                    );
                }

                // Restore task with original timestamps (bypass createTask)
                $this->restoreTask($taskData);
                $restored++;
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to restore task', [
                    'task_id' => $taskData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($restored > 0) {
            $this->logger->info('Restored tasks from disk', ['count' => $restored]);
        }
    }

    /**
     * Restore a single task from serialized data, preserving original timestamps.
     */
    private function restoreTask(array $data): void
    {
        $status = $data['status'] ?? 'pending';
        if (is_string($status)) {
            $status = TaskStatus::from($status);
        }

        $task = new Task(
            id: $data['id'] ?? ('t' . \Illuminate\Support\Str::random(8)),
            subject: $data['subject'],
            description: $data['description'],
            status: $status,
            activeForm: $data['activeForm'] ?? null,
            metadata: $data['metadata'] ?? [],
            owner: $data['owner'] ?? null,
            blocks: $data['blocks'] ?? [],
            blockedBy: $data['blockedBy'] ?? [],
            createdAt: $this->parseDate($data['createdAt'] ?? null),
            updatedAt: $this->parseDate($data['updatedAt'] ?? null),
            startedAt: $this->parseDate($data['startedAt'] ?? null),
            endedAt: $this->parseDate($data['endedAt'] ?? null),
            output: $data['output'] ?? null,
        );

        // Put directly into parent's collection via createTask-like logic
        $this->injectTask($task);
    }

    private function parseDate(?string $dateStr): \DateTimeInterface
    {
        if ($dateStr === null) {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($dateStr);
        } catch (\Throwable $e) {
            return new \DateTimeImmutable();
        }
    }

    /**
     * Get the storage directory path.
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function ensureDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * @return resource
     */
    private function getLogHandle(string $taskId)
    {
        if (!isset($this->logHandles[$taskId]) || !is_resource($this->logHandles[$taskId])) {
            $path = $this->getOutputFilePath($taskId);
            $this->logHandles[$taskId] = fopen($path, 'a');
        }

        return $this->logHandles[$taskId];
    }

    private function closeLogHandle(string $taskId): void
    {
        if (isset($this->logHandles[$taskId]) && is_resource($this->logHandles[$taskId])) {
            fclose($this->logHandles[$taskId]);
        }
        unset($this->logHandles[$taskId]);
    }

    /**
     * @param resource|null $pipe
     */
    private function drainPipe(string $taskId, $pipe): void
    {
        if ($pipe === null || !is_resource($pipe)) {
            return;
        }

        $chunk = fread($pipe, 65536);
        if ($chunk !== false && $chunk !== '') {
            $this->appendOutput($taskId, $chunk);
        }
    }

    /**
     * @param resource|null $pipe
     */
    private function drainPipeToEnd(string $taskId, $pipe): void
    {
        if ($pipe === null || !is_resource($pipe)) {
            return;
        }

        $remaining = stream_get_contents($pipe);
        if ($remaining !== false && $remaining !== '') {
            $this->appendOutput($taskId, $remaining);
        }
    }

    /**
     * @param resource|null $pipe
     */
    private function closePipe($pipe): void
    {
        if ($pipe !== null && is_resource($pipe)) {
            fclose($pipe);
        }
    }

    private function finalizeTask(string $taskId, int $exitCode): void
    {
        $newStatus = $exitCode === 0 ? 'completed' : 'failed';

        parent::updateTask($taskId, [
            'status' => $newStatus,
            'metadata' => ['exit_code' => (string) $exitCode],
        ]);

        $this->closeLogHandle($taskId);
        $this->persistIndex();

        $this->logger->info('Task process finished', [
            'task_id' => $taskId,
            'exit_code' => $exitCode,
            'status' => $newStatus,
        ]);
    }

    private function terminateProcess(string $taskId): void
    {
        if (!isset($this->watchedProcesses[$taskId])) {
            return;
        }

        $info = $this->watchedProcesses[$taskId];
        $process = $info['process'];

        if (is_resource($process)) {
            proc_terminate($process, SIGTERM);
            usleep(500_000); // 500ms grace

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, SIGKILL);
            }

            $this->closePipe($info['stdout'] ?? null);
            $this->closePipe($info['stderr'] ?? null);
            proc_close($process);
        }

        $this->closeLogHandle($taskId);
        unset($this->watchedProcesses[$taskId]);
    }

    private function cleanupTaskFiles(string $taskId): void
    {
        $this->closeLogHandle($taskId);

        $logPath = $this->getOutputFilePath($taskId);
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        unset($this->watchedProcesses[$taskId]);
        unset($this->generations[$taskId]);
    }

    private static function resolveConfig(): array
    {
        $defaults = [
            'enabled' => false,
            'storage_path' => null,
            'tasks' => ['enabled' => true, 'max_output_read_bytes' => 12000, 'prune_after_days' => 30],
            'sessions' => ['enabled' => true, 'max_sessions' => 50, 'prune_after_days' => 90],
        ];

        try {
            if (function_exists('config')) {
                $config = config('superagent.persistence', []);
                return array_replace_recursive($defaults, is_array($config) ? $config : []);
            }
        } catch (\Throwable $e) {
            // No Laravel — use defaults
        }

        return $defaults;
    }

    private static function resolveStorageDir(array $config): string
    {
        if (!empty($config['storage_path'])) {
            return rtrim($config['storage_path'], '/');
        }

        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME') ?: '/tmp';
        return $home . '/.superagent';
    }

    public function __destruct()
    {
        // Close all open log handles
        foreach ($this->logHandles as $taskId => $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->logHandles = [];
    }
}
