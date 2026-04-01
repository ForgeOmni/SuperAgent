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

/**
 * Process backend for running agents in separate OS processes.
 */
class ProcessBackend implements BackendInterface
{
    private array $processes = [];
    private array $statuses = [];
    private LoggerInterface $logger;
    private string $agentScript;
    
    public function __construct(
        ?string $agentScript = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->agentScript = $agentScript ?? $this->getDefaultAgentScript();
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function getType(): BackendType
    {
        return BackendType::PROCESS;
    }
    
    public function isAvailable(): bool
    {
        // Check if we can spawn processes
        return function_exists('proc_open') && !ini_get('safe_mode');
    }
    
    public function spawn(AgentSpawnConfig $config): AgentSpawnResult
    {
        if (!$this->isAvailable()) {
            return new AgentSpawnResult(
                success: false,
                agentId: '',
                error: 'Process spawning not available (proc_open disabled or safe mode enabled)',
            );
        }
        
        try {
            $agentId = $this->generateAgentId($config->name);
            $taskId = uniqid('task_', true);
            
            // Prepare environment variables
            $env = array_merge($_ENV, $config->environment ?? [], [
                'SUPERAGENT_AGENT_ID' => $agentId,
                'SUPERAGENT_AGENT_NAME' => $config->name,
                'SUPERAGENT_TEAM_NAME' => $config->teamName ?? '',
                'SUPERAGENT_PROMPT' => $config->prompt,
                'SUPERAGENT_MODEL' => $config->model ?? '',
                'SUPERAGENT_PERMISSION_MODE' => $config->permissionMode?->value ?? '',
                'SUPERAGENT_PLAN_MODE' => $config->planModeRequired ? '1' : '0',
            ]);
            
            if ($config->systemPrompt !== null) {
                $env['SUPERAGENT_SYSTEM_PROMPT'] = $config->systemPrompt;
            }
            
            if ($config->allowedTools !== null) {
                $env['SUPERAGENT_ALLOWED_TOOLS'] = implode(',', $config->allowedTools);
            }
            
            // Set working directory
            $cwd = $config->workingDirectory;
            
            // Handle Git worktree isolation
            if ($config->isolation === \SuperAgent\Swarm\IsolationMode::WORKTREE) {
                $worktreePath = $this->createWorktree($agentId, $cwd);
                $cwd = $worktreePath;
                $env['SUPERAGENT_WORKTREE'] = $worktreePath;
            }
            
            // Prepare command
            $command = [
                PHP_BINARY,
                $this->agentScript,
                '--agent-id', $agentId,
                '--task-id', $taskId,
            ];
            
            // Spawn the process
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];
            
            $process = proc_open(
                $command,
                $descriptors,
                $pipes,
                $cwd,
                $env,
            );
            
            if (!is_resource($process)) {
                throw new \RuntimeException('Failed to spawn process');
            }
            
            // Make pipes non-blocking
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            // Get process info
            $status = proc_get_status($process);
            $pid = $status['pid'] ?? null;
            
            // Store process info
            $this->processes[$agentId] = [
                'process' => $process,
                'pipes' => $pipes,
                'pid' => $pid,
                'task_id' => $taskId,
                'name' => $config->name,
                'worktree' => $cwd !== $config->workingDirectory ? $cwd : null,
            ];
            
            $this->statuses[$agentId] = AgentStatus::RUNNING;
            
            $this->logger->info("Spawned process agent", [
                'agent_id' => $agentId,
                'pid' => $pid,
                'name' => $config->name,
            ]);
            
            return new AgentSpawnResult(
                success: true,
                agentId: $agentId,
                taskId: $taskId,
                pid: $pid,
                worktreePath: $this->processes[$agentId]['worktree'],
            );
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to spawn process agent", [
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
    
    public function sendMessage(string $agentId, AgentMessage $message): void
    {
        if (!isset($this->processes[$agentId])) {
            throw new \RuntimeException("Agent $agentId not found");
        }
        
        $pipes = $this->processes[$agentId]['pipes'];
        
        // Send message via stdin as JSON
        $json = json_encode($message->toArray()) . "\n";
        fwrite($pipes[0], $json);
        fflush($pipes[0]);
        
        $this->logger->debug("Message sent to process agent", [
            'agent_id' => $agentId,
            'from' => $message->from,
        ]);
    }
    
    public function requestShutdown(string $agentId, ?string $reason = null): void
    {
        if (!isset($this->processes[$agentId])) {
            return;
        }
        
        // Send shutdown message
        $message = new AgentMessage(
            from: 'system',
            to: $agentId,
            content: json_encode([
                'type' => 'shutdown_request',
                'reason' => $reason,
            ]),
        );
        
        $this->sendMessage($agentId, $message);
        
        // Wait a bit for graceful shutdown
        usleep(500000); // 500ms
        
        // Check if still running
        if ($this->isRunning($agentId)) {
            // Force kill if still running
            $this->kill($agentId);
        }
    }
    
    public function kill(string $agentId): void
    {
        if (!isset($this->processes[$agentId])) {
            return;
        }
        
        $info = $this->processes[$agentId];
        
        // Close pipes
        foreach ($info['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        
        // Terminate process
        if (is_resource($info['process'])) {
            proc_terminate($info['process'], SIGTERM);
            usleep(100000); // 100ms
            
            // Force kill if still running
            $status = proc_get_status($info['process']);
            if ($status['running']) {
                proc_terminate($info['process'], SIGKILL);
            }
            
            proc_close($info['process']);
        }
        
        $this->statuses[$agentId] = AgentStatus::CANCELLED;
        
        $this->logger->info("Killed process agent", [
            'agent_id' => $agentId,
            'pid' => $info['pid'],
        ]);
        
        $this->cleanup($agentId);
    }
    
    public function getStatus(string $agentId): ?AgentStatus
    {
        if (!isset($this->processes[$agentId])) {
            return null;
        }
        
        // Update status from process
        $info = $this->processes[$agentId];
        if (is_resource($info['process'])) {
            $status = proc_get_status($info['process']);
            if (!$status['running']) {
                if ($status['exitcode'] === 0) {
                    $this->statuses[$agentId] = AgentStatus::COMPLETED;
                } else {
                    $this->statuses[$agentId] = AgentStatus::FAILED;
                }
            }
        }
        
        return $this->statuses[$agentId] ?? null;
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
        
        // Clean up worktree if exists
        if ($info['worktree'] && is_dir($info['worktree'])) {
            $this->removeWorktree($info['worktree']);
        }
        
        unset($this->processes[$agentId]);
        unset($this->statuses[$agentId]);
        
        $this->logger->debug("Cleaned up process agent", [
            'agent_id' => $agentId,
        ]);
    }
    
    /**
     * Read output from agent processes.
     */
    public function readOutput(string $agentId): ?array
    {
        if (!isset($this->processes[$agentId])) {
            return null;
        }
        
        $pipes = $this->processes[$agentId]['pipes'];
        $output = [
            'stdout' => '',
            'stderr' => '',
        ];
        
        // Read stdout
        while ($line = fgets($pipes[1])) {
            $output['stdout'] .= $line;
        }
        
        // Read stderr
        while ($line = fgets($pipes[2])) {
            $output['stderr'] .= $line;
        }
        
        return $output;
    }
    
    /**
     * Generate a unique agent ID.
     */
    private function generateAgentId(string $name): string
    {
        return sprintf(
            '%s_%s_%s',
            preg_replace('/[^a-zA-Z0-9]/', '_', $name),
            uniqid(),
            bin2hex(random_bytes(4))
        );
    }
    
    /**
     * Get the default agent script path.
     */
    private function getDefaultAgentScript(): string
    {
        // Look for the agent runner script
        $candidates = [
            __DIR__ . '/../../../bin/agent-runner.php',
            dirname(__DIR__, 3) . '/bin/agent-runner.php',
            getcwd() . '/vendor/superagent/superagent/bin/agent-runner.php',
        ];
        
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        throw new \RuntimeException('Agent runner script not found');
    }
    
    /**
     * Create a Git worktree for isolation.
     */
    private function createWorktree(string $agentId, ?string $baseDir = null): string
    {
        $baseDir = $baseDir ?? getcwd();
        $worktreePath = sys_get_temp_dir() . '/superagent_worktrees/' . $agentId;
        
        // Ensure worktree parent directory exists
        $parentDir = dirname($worktreePath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        
        // Create worktree
        $branch = 'agent_' . $agentId;
        exec(sprintf(
            'cd %s && git worktree add -b %s %s 2>&1',
            escapeshellarg($baseDir),
            escapeshellarg($branch),
            escapeshellarg($worktreePath)
        ), $output, $exitCode);
        
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Failed to create worktree: ' . implode("\n", $output)
            );
        }
        
        $this->logger->debug("Created worktree for agent", [
            'agent_id' => $agentId,
            'worktree' => $worktreePath,
            'branch' => $branch,
        ]);
        
        return $worktreePath;
    }
    
    /**
     * Remove a Git worktree.
     */
    private function removeWorktree(string $worktreePath): void
    {
        if (!is_dir($worktreePath)) {
            return;
        }
        
        // Remove worktree
        exec(sprintf(
            'git worktree remove --force %s 2>&1',
            escapeshellarg($worktreePath)
        ), $output, $exitCode);
        
        if ($exitCode !== 0) {
            $this->logger->warning("Failed to remove worktree cleanly", [
                'worktree' => $worktreePath,
                'output' => implode("\n", $output),
            ]);
            
            // Force remove directory
            exec(sprintf('rm -rf %s', escapeshellarg($worktreePath)));
        }
        
        $this->logger->debug("Removed worktree", [
            'worktree' => $worktreePath,
        ]);
    }
}