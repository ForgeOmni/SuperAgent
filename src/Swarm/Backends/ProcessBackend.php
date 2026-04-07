<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Backends;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentSpawnResult;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Agent\AgentManager;
use SuperAgent\MCP\MCPManager;
use SuperAgent\MCP\MCPBridge;

/**
 * Process backend: runs each agent in a separate OS process via proc_open().
 *
 * This is the only backend that provides true parallelism. Each sub-agent
 * gets its own PHP process with its own Guzzle connection, so blocking
 * HTTP I/O and tool execution in one agent never blocks another.
 *
 * Communication:
 *   Parent → child: JSON config blob written to stdin, then stdin is closed.
 *   Child → parent: JSON result line on stdout when done; stderr for logs.
 *   Parent polls all children with proc_get_status() in a non-blocking loop.
 */
class ProcessBackend implements BackendInterface
{
    /** @var array<string, array{process: resource, pipes: array, pid: ?int, name: string, worktree: ?string, stdout_buffer: string, stderr_buffer: string}> */
    private array $processes = [];
    /** @var array<string, AgentStatus> */
    private array $statuses = [];
    /** @var array<string, array> Parsed JSON result from completed agents */
    private array $results = [];
    /** @var array<string, array[]> Queued progress events parsed from child stderr */
    private array $progressEvents = [];
    private LoggerInterface $logger;
    private string $agentScript;
    private ?AgentManager $agentManager;
    private ?MCPManager $mcpManager;
    private ?MCPBridge $mcpBridge;

    public function __construct(
        ?string $agentScript = null,
        ?LoggerInterface $logger = null,
        ?AgentManager $agentManager = null,
        ?MCPManager $mcpManager = null,
        ?MCPBridge $mcpBridge = null,
    ) {
        $this->agentScript = $agentScript ?? $this->getDefaultAgentScript();
        $this->logger = $logger ?? new NullLogger();
        $this->agentManager = $agentManager;
        $this->mcpManager = $mcpManager;
        $this->mcpBridge = $mcpBridge;
    }

    public function getType(): BackendType
    {
        return BackendType::PROCESS;
    }

    public function isAvailable(): bool
    {
        return function_exists('proc_open');
    }

    public function spawn(AgentSpawnConfig $config): AgentSpawnResult
    {
        if (!$this->isAvailable()) {
            return new AgentSpawnResult(
                success: false,
                agentId: '',
                error: 'proc_open is not available',
            );
        }

        try {
            $agentId = $this->generateAgentId($config->name);
            $taskId = uniqid('task_', true);

            // Working directory — use worktree if isolation requested
            $cwd = $config->workingDirectory ?? getcwd();
            $worktreePath = null;

            if ($config->isolation === \SuperAgent\Swarm\IsolationMode::WORKTREE) {
                $worktreePath = $this->createWorktree($agentId, $cwd);
                $cwd = $worktreePath;
            }

            // Spawn the PHP process
            $command = [PHP_BINARY, $this->agentScript];
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            $env = array_merge($_ENV, $config->environment ?? []);

            $process = proc_open($command, $descriptors, $pipes, $cwd, $env);

            if (!is_resource($process)) {
                throw new \RuntimeException('proc_open failed');
            }

            // Build the config blob that agent-runner.php expects on stdin
            $agentConfig = $config->providerConfig;
            if ($config->model !== null) {
                $agentConfig['model'] = $config->model;
            }
            if ($config->systemPrompt !== null) {
                $agentConfig['system_prompt'] = $config->systemPrompt;
            }

            // Tool loading: if the spawn config specifies allowed tools, pass
            // them explicitly; otherwise load all tools so the child agent has
            // the same capabilities as the parent (agent, skill, mcp, etc.).
            if ($config->allowedTools !== null) {
                $agentConfig['load_tools'] = $config->allowedTools;
            } elseif (!isset($agentConfig['load_tools'])) {
                $agentConfig['load_tools'] = 'all';
            }

            // Denied tools
            if ($config->deniedTools !== null) {
                $agentConfig['denied_tools'] = $config->deniedTools;
            }

            // Export parent's agent definitions so child process has access
            // to all registered types (builtin + custom from .claude/agents/)
            // without needing Laravel config or filesystem access.
            $agentDefinitions = [];
            try {
                $agentDefinitions = ($this->agentManager ?? AgentManager::getInstance())->exportDefinitions();
            } catch (\Throwable $e) {
                error_log('[SuperAgent] AgentManager propagation skipped: ' . $e->getMessage());
            }

            // Export parent's MCP server configs so child can register
            // them without re-reading config files.
            $mcpServers = [];
            try {
                $mgr = $this->mcpManager ?? MCPManager::getInstance();
                foreach ($mgr->getServers() as $name => $serverConfig) {
                    $mcpServers[$name] = $serverConfig->toArray();
                }
            } catch (\Throwable $e) {
                error_log('[SuperAgent] MCPManager propagation skipped: ' . $e->getMessage());
            }

            $stdinPayload = json_encode([
                'agent_id' => $agentId,
                'agent_name' => $config->name,
                'prompt' => $config->prompt,
                'agent_config' => $agentConfig,
                'base_path' => $this->resolveLaravelBasePath(),
                // Serialized parent registrations — child imports these
                // so it has the same agent/MCP data without Laravel.
                'agent_definitions' => $agentDefinitions,
                'mcp_servers' => $mcpServers,
            ], JSON_UNESCAPED_UNICODE);

            // Write config then close stdin so the child can start
            fwrite($pipes[0], $stdinPayload);
            fclose($pipes[0]);

            // Make output pipes non-blocking for polling
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $pid = proc_get_status($process)['pid'] ?? null;

            $this->processes[$agentId] = [
                'process' => $process,
                'pipes' => $pipes,
                'pid' => $pid,
                'task_id' => $taskId,
                'name' => $config->name,
                'worktree' => $worktreePath,
                'stdout_buffer' => '',
                'stderr_buffer' => '',
            ];
            $this->statuses[$agentId] = AgentStatus::RUNNING;

            $this->logger->info('Spawned process agent', [
                'agent_id' => $agentId,
                'pid' => $pid,
                'name' => $config->name,
            ]);

            return new AgentSpawnResult(
                success: true,
                agentId: $agentId,
                taskId: $taskId,
                pid: $pid,
                worktreePath: $worktreePath,
            );

        } catch (\Exception $e) {
            $this->logger->error('Failed to spawn process agent', [
                'error' => $e->getMessage(),
                'name' => $config->name,
            ]);

            return new AgentSpawnResult(
                success: false,
                agentId: '',
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Poll all running processes once.
     *
     * Drains stdout/stderr buffers and detects process completion.
     * Call this in a loop from the parent until all agents finish.
     *
     * @return array<string, AgentStatus> Current status of every tracked agent.
     */
    public function poll(): array
    {
        // Also poll MCP bridge so child processes can talk to shared MCP servers
        try {
            ($this->mcpBridge ?? MCPBridge::getInstance())->poll();
        } catch (\Throwable $e) {
            error_log('[SuperAgent] MCPBridge poll skipped: ' . $e->getMessage());
        }

        foreach ($this->processes as $agentId => &$info) {
            if ($this->statuses[$agentId] !== AgentStatus::RUNNING) {
                continue;
            }

            // Drain stdout
            $chunk = fread($info['pipes'][1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $info['stdout_buffer'] .= $chunk;
            }

            // Drain stderr — parse CC-compatible NDJSON events for progress
            // monitoring. Each line from the child's NdjsonWriter is a JSON
            // object with a "type" field (assistant, user, result). Non-JSON
            // lines (e.g. [agent-runner] log messages) are forwarded to logger.
            $chunk = fread($info['pipes'][2], 65536);
            if ($chunk !== false && $chunk !== '') {
                $info['stderr_buffer'] .= $chunk;
                foreach (explode("\n", $chunk) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    // Try parsing as NDJSON (starts with '{')
                    if ($line[0] === '{') {
                        $event = json_decode($line, true);
                        if ($event && isset($event['type'])) {
                            $this->progressEvents[$agentId][] = $event;
                            continue;
                        }
                    }
                    // Legacy __PROGRESS__: prefix (backward compat)
                    if (str_starts_with($line, '__PROGRESS__:')) {
                        $json = substr($line, strlen('__PROGRESS__:'));
                        $event = json_decode($json, true);
                        if ($event) {
                            $this->progressEvents[$agentId][] = $event;
                        }
                        continue;
                    }
                    // Plain log line
                    $this->logger->debug("[{$agentId}] {$line}");
                }
            }

            // Check process status
            $status = proc_get_status($info['process']);
            if (!$status['running']) {
                // Process finished — parse result from stdout
                // Read any remaining data
                $remaining = stream_get_contents($info['pipes'][1]);
                if ($remaining !== false) {
                    $info['stdout_buffer'] .= $remaining;
                }

                $result = json_decode(trim($info['stdout_buffer']), true);
                $this->results[$agentId] = $result;

                if ($status['exitcode'] === 0 && $result && ($result['success'] ?? false)) {
                    $this->statuses[$agentId] = AgentStatus::COMPLETED;
                } else {
                    $this->statuses[$agentId] = AgentStatus::FAILED;
                }

                // Close pipes
                fclose($info['pipes'][1]);
                fclose($info['pipes'][2]);
                proc_close($info['process']);

                $this->logger->info('Process agent finished', [
                    'agent_id' => $agentId,
                    'status' => $this->statuses[$agentId]->value,
                    'exit_code' => $status['exitcode'],
                ]);
            }
        }
        unset($info);

        return $this->statuses;
    }

    /**
     * Block until all currently tracked agents finish.
     *
     * @param int $timeoutSeconds Maximum wait time (default: 5 minutes).
     * @return array<string, array|null> agentId → parsed JSON result.
     */
    public function waitAll(int $timeoutSeconds = 300): array
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $this->poll();

            $allDone = true;
            foreach ($this->statuses as $status) {
                if ($status === AgentStatus::RUNNING || $status === AgentStatus::PENDING) {
                    $allDone = false;
                    break;
                }
            }

            if ($allDone) {
                break;
            }

            usleep(50_000); // 50ms
        }

        return $this->results;
    }

    /**
     * Get the parsed JSON result for a completed agent.
     */
    public function getResult(string $agentId): ?array
    {
        return $this->results[$agentId] ?? null;
    }

    /**
     * Consume queued progress events for an agent.
     *
     * Returns all events since last call and clears the queue.
     * Events are structured JSON from the child's StreamingHandler:
     *   - type: 'tool_use' | 'tool_result' | 'turn'
     *   - agent_id, timestamp, data (type-specific payload)
     *
     * @return array[] Progress events
     */
    public function consumeProgressEvents(string $agentId): array
    {
        $events = $this->progressEvents[$agentId] ?? [];
        $this->progressEvents[$agentId] = [];
        return $events;
    }

    // ── BackendInterface methods ───────────────────────────────────

    public function sendMessage(string $agentId, AgentMessage $message): void
    {
        // stdin is already closed after spawn — message passing is not
        // supported in the one-shot execution model. Log and ignore.
        $this->logger->warning('sendMessage not supported for process backend (stdin closed)', [
            'agent_id' => $agentId,
        ]);
    }

    public function requestShutdown(string $agentId, ?string $reason = null): void
    {
        if (!isset($this->processes[$agentId])) {
            return;
        }

        // Send SIGTERM for graceful shutdown
        $info = $this->processes[$agentId];
        if (is_resource($info['process'])) {
            proc_terminate($info['process'], SIGTERM);
            usleep(500_000); // 500ms grace period

            $status = proc_get_status($info['process']);
            if ($status['running']) {
                proc_terminate($info['process'], SIGKILL);
            }
        }

        $this->statuses[$agentId] = AgentStatus::CANCELLED;
    }

    public function kill(string $agentId): void
    {
        if (!isset($this->processes[$agentId])) {
            return;
        }

        $info = $this->processes[$agentId];

        // Close pipes
        foreach ($info['pipes'] as $key => $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($info['process'])) {
            proc_terminate($info['process'], SIGKILL);
            proc_close($info['process']);
        }

        $this->statuses[$agentId] = AgentStatus::CANCELLED;
        $this->cleanup($agentId);
    }

    public function getStatus(string $agentId): ?AgentStatus
    {
        if (!isset($this->statuses[$agentId])) {
            return null;
        }

        // If still running, poll to refresh status
        if ($this->statuses[$agentId] === AgentStatus::RUNNING) {
            $this->poll();
        }

        return $this->statuses[$agentId];
    }

    public function isRunning(string $agentId): bool
    {
        $status = $this->getStatus($agentId);
        return $status === AgentStatus::RUNNING || $status === AgentStatus::PENDING;
    }

    public function cleanup(string $agentId): void
    {
        if (!isset($this->processes[$agentId])) {
            return;
        }

        $info = $this->processes[$agentId];

        // Clean up worktree
        if ($info['worktree'] && is_dir($info['worktree'])) {
            $this->removeWorktree($info['worktree']);
        }

        unset($this->processes[$agentId]);
        unset($this->statuses[$agentId]);
        unset($this->results[$agentId]);
        unset($this->progressEvents[$agentId]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function generateAgentId(string $name): string
    {
        return sprintf(
            '%s_%s_%s',
            preg_replace('/[^a-zA-Z0-9]/', '_', $name),
            uniqid(),
            bin2hex(random_bytes(4))
        );
    }

    private function getDefaultAgentScript(): string
    {
        $candidates = [
            __DIR__ . '/../../../bin/agent-runner.php',
            dirname(__DIR__, 3) . '/bin/agent-runner.php',
            getcwd() . '/vendor/forgeomni/superagent/bin/agent-runner.php',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }

        throw new \RuntimeException('Agent runner script not found');
    }

    /**
     * Resolve the Laravel application base path so the child process can
     * bootstrap the full app (config, service providers, AgentManager,
     * SkillManager, MCPManager, .claude/ directory loading, etc.).
     *
     * Returns null when running outside Laravel.
     */
    private function resolveLaravelBasePath(): ?string
    {
        // If Laravel is booted, base_path() is authoritative
        try {
            if (function_exists('base_path') && function_exists('app') && app()->bound('config')) {
                return base_path();
            }
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Laravel config unavailable in ProcessBackend: ' . $e->getMessage());
        }

        // Heuristic: walk up from cwd looking for artisan + bootstrap/app.php
        $dir = getcwd();
        for ($i = 0; $i < 5; $i++) {
            if (file_exists($dir . '/artisan') && file_exists($dir . '/bootstrap/app.php')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    private function createWorktree(string $agentId, ?string $baseDir = null): string
    {
        $baseDir = $baseDir ?? getcwd();
        $worktreePath = sys_get_temp_dir() . '/superagent_worktrees/' . $agentId;

        $parentDir = dirname($worktreePath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $branch = 'agent_' . $agentId;
        exec(sprintf(
            'cd %s && git worktree add -b %s %s 2>&1',
            escapeshellarg($baseDir),
            escapeshellarg($branch),
            escapeshellarg($worktreePath)
        ), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to create worktree: ' . implode("\n", $output));
        }

        return $worktreePath;
    }

    private function removeWorktree(string $worktreePath): void
    {
        if (!is_dir($worktreePath)) {
            return;
        }

        exec(sprintf(
            'git worktree remove --force %s 2>&1',
            escapeshellarg($worktreePath)
        ), $output, $exitCode);

        if ($exitCode !== 0) {
            exec(sprintf('rm -rf %s', escapeshellarg($worktreePath)));
        }
    }
}
