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
 * Tmux backend: runs each agent in a visible tmux pane.
 *
 * Useful for development and debugging — each agent gets its own
 * pane in a tmux window, so you can watch their output in real time.
 *
 * Requires:
 *   - tmux binary on PATH
 *   - Running inside a tmux session ($TMUX env var set)
 *
 * When not available, isAvailable() returns false and the caller
 * should fall back to ProcessBackend.
 *
 * Configuration (superagent.harness.tmux):
 *   enabled:      bool   (default: true)
 *   layout:       string (default: 'tiled')
 *   session_name: string (default: auto-detect from $TMUX)
 */
class TmuxBackend implements BackendInterface
{
    /** @var array<string, array{pane_id: string, name: string, status: AgentStatus}> */
    private array $agents = [];

    private LoggerInterface $logger;
    private string $agentScript;
    private string $layout;

    public function __construct(
        ?string $agentScript = null,
        ?LoggerInterface $logger = null,
        string $layout = 'tiled',
    ) {
        $this->agentScript = $agentScript ?? $this->findAgentScript();
        $this->logger = $logger ?? new NullLogger();
        $this->layout = $layout;
    }

    public function getType(): BackendType
    {
        return BackendType::TMUX;
    }

    /**
     * Check if tmux is available and we're inside a tmux session.
     */
    public function isAvailable(): bool
    {
        // Must be inside a tmux session
        if (empty(getenv('TMUX'))) {
            return false;
        }

        // tmux binary must exist
        $exitCode = 0;
        exec('which tmux 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Detect if the current environment supports tmux backend.
     */
    public static function detect(): bool
    {
        return !empty(getenv('TMUX')) && self::tmuxBinaryExists();
    }

    public function spawn(AgentSpawnConfig $config): AgentSpawnResult
    {
        if (!$this->isAvailable()) {
            return new AgentSpawnResult(
                success: false,
                agentId: '',
                error: 'Tmux is not available (not in a tmux session or tmux not installed)',
            );
        }

        try {
            $agentId = $this->generateAgentId($config->name);
            $cwd = $config->workingDirectory ?? getcwd();

            // Build the command that will run in the tmux pane
            $stdinPayload = json_encode([
                'agent_id' => $agentId,
                'agent_name' => $config->name,
                'prompt' => $config->prompt,
                'agent_config' => array_merge($config->providerConfig, array_filter([
                    'model' => $config->model,
                    'system_prompt' => $config->systemPrompt,
                    'load_tools' => $config->allowedTools ?? 'all',
                ])),
            ], JSON_UNESCAPED_UNICODE);

            // Create a temp file for stdin payload (tmux can't pipe stdin easily)
            $tmpFile = tempnam(sys_get_temp_dir(), 'sa_tmux_');
            file_put_contents($tmpFile, $stdinPayload);

            $paneCmd = sprintf(
                'cd %s && %s %s < %s; rm -f %s; echo "[Agent %s finished]"; read',
                escapeshellarg($cwd),
                escapeshellarg(PHP_BINARY),
                escapeshellarg($this->agentScript),
                escapeshellarg($tmpFile),
                escapeshellarg($tmpFile),
                escapeshellarg($config->name),
            );

            // Split the current window to create a new pane
            $splitCmd = sprintf(
                'tmux split-window -h -d %s 2>&1',
                escapeshellarg($paneCmd),
            );

            exec($splitCmd, $output, $exitCode);

            if ($exitCode !== 0) {
                @unlink($tmpFile);
                throw new \RuntimeException('tmux split-window failed: ' . implode("\n", $output));
            }

            // Get the pane ID of the newly created pane
            $paneId = trim(shell_exec('tmux display-message -p "#{pane_id}"') ?? '');

            // Re-tile the layout
            exec(sprintf('tmux select-layout %s 2>/dev/null', escapeshellarg($this->layout)));

            $this->agents[$agentId] = [
                'pane_id' => $paneId,
                'name' => $config->name,
                'status' => AgentStatus::RUNNING,
                'tmp_file' => $tmpFile,
            ];

            $this->logger->info('Spawned tmux agent', [
                'agent_id' => $agentId,
                'pane_id' => $paneId,
                'name' => $config->name,
            ]);

            return new AgentSpawnResult(
                success: true,
                agentId: $agentId,
            );

        } catch (\Throwable $e) {
            $this->logger->error('Failed to spawn tmux agent', [
                'error' => $e->getMessage(),
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
        // Tmux panes don't support bidirectional communication
        $this->logger->warning('sendMessage not supported for tmux backend', [
            'agent_id' => $agentId,
        ]);
    }

    public function requestShutdown(string $agentId, ?string $reason = null): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }

        $info = $this->agents[$agentId];

        // Send Ctrl+C to the pane
        exec(sprintf(
            'tmux send-keys -t %s C-c 2>/dev/null',
            escapeshellarg($info['pane_id']),
        ));

        $this->agents[$agentId]['status'] = AgentStatus::CANCELLED;
    }

    public function kill(string $agentId): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }

        $info = $this->agents[$agentId];

        // Kill the pane
        exec(sprintf(
            'tmux kill-pane -t %s 2>/dev/null',
            escapeshellarg($info['pane_id']),
        ));

        // Clean up temp file
        if (isset($info['tmp_file']) && file_exists($info['tmp_file'])) {
            @unlink($info['tmp_file']);
        }

        $this->agents[$agentId]['status'] = AgentStatus::CANCELLED;
    }

    public function getStatus(string $agentId): ?AgentStatus
    {
        return $this->agents[$agentId]['status'] ?? null;
    }

    public function isRunning(string $agentId): bool
    {
        $status = $this->getStatus($agentId);
        return $status === AgentStatus::RUNNING;
    }

    public function cleanup(string $agentId): void
    {
        if (isset($this->agents[$agentId])) {
            $info = $this->agents[$agentId];
            if (isset($info['tmp_file']) && file_exists($info['tmp_file'])) {
                @unlink($info['tmp_file']);
            }
        }
        unset($this->agents[$agentId]);
    }

    /**
     * Get all tracked agents and their statuses.
     *
     * @return array<string, array{pane_id: string, name: string, status: AgentStatus}>
     */
    public function getAgents(): array
    {
        return $this->agents;
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function generateAgentId(string $name): string
    {
        return sprintf(
            'tmux_%s_%s',
            preg_replace('/[^a-zA-Z0-9]/', '_', $name),
            bin2hex(random_bytes(4)),
        );
    }

    private function findAgentScript(): string
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

        return 'agent-runner.php'; // will fail at spawn time
    }

    private static function tmuxBinaryExists(): bool
    {
        exec('which tmux 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }
}
