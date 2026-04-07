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
 * iTerm2 backend: runs each agent in a visible iTerm2 pane.
 *
 * Useful for development and debugging on macOS — each agent gets its own
 * pane in an iTerm2 tab, so you can watch their output in real time.
 *
 * Requires:
 *   - iTerm2 running ($ITERM_SESSION_ID env var set)
 *   - osascript binary on PATH (AppleScript — macOS only)
 *
 * When not available, isAvailable() returns false and the caller
 * should fall back to ProcessBackend.
 */
class ITermBackend implements BackendInterface
{
    /** @var array<string, array{session_id: string, name: string, status: AgentStatus}> */
    private array $agents = [];

    private LoggerInterface $logger;
    private string $agentScript;

    public function __construct(
        ?string $agentScript = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->agentScript = $agentScript ?? $this->findAgentScript();
        $this->logger = $logger ?? new NullLogger();
    }

    public function getType(): BackendType
    {
        return BackendType::ITERM2;
    }

    /**
     * Check if iTerm2 is available and we're inside an iTerm2 session.
     */
    public function isAvailable(): bool
    {
        return self::detect() && self::osascriptExists();
    }

    /**
     * Detect if the current environment is an iTerm2 session.
     */
    public static function detect(): bool
    {
        return !empty(getenv('ITERM_SESSION_ID'));
    }

    public function spawn(AgentSpawnConfig $config): AgentSpawnResult
    {
        if (!$this->isAvailable()) {
            return new AgentSpawnResult(
                success: false,
                agentId: '',
                error: 'iTerm2 is not available (not in an iTerm2 session or osascript not installed)',
            );
        }

        try {
            $agentId = $this->generateAgentId($config->name);
            $cwd = $config->workingDirectory ?? getcwd();

            // Build the stdin payload for the agent runner
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

            // Create a temp file for stdin payload
            $tmpFile = tempnam(sys_get_temp_dir(), 'sa_iterm_');
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

            // Use AppleScript to split the current iTerm2 session horizontally
            // and run the agent command in the new pane
            $appleScript = sprintf(
                'tell application "iTerm2" to tell current window to tell current session to split horizontally with default profile command %s',
                escapeshellarg($paneCmd),
            );

            $splitCmd = sprintf(
                'osascript -e %s 2>&1',
                escapeshellarg($appleScript),
            );

            $output = [];
            $exitCode = 0;
            exec($splitCmd, $output, $exitCode);

            if ($exitCode !== 0) {
                @unlink($tmpFile);
                throw new \RuntimeException('iTerm2 split-pane failed: ' . implode("\n", $output));
            }

            // The osascript output may contain the session ID of the new pane
            $sessionId = trim(implode('', $output));

            $this->agents[$agentId] = [
                'session_id' => $sessionId,
                'name' => $config->name,
                'status' => AgentStatus::RUNNING,
                'tmp_file' => $tmpFile,
            ];

            $this->logger->info('Spawned iTerm2 agent', [
                'agent_id' => $agentId,
                'session_id' => $sessionId,
                'name' => $config->name,
            ]);

            return new AgentSpawnResult(
                success: true,
                agentId: $agentId,
            );

        } catch (\Throwable $e) {
            $this->logger->error('Failed to spawn iTerm2 agent', [
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
        // iTerm2 panes don't support bidirectional communication
        $this->logger->warning('sendMessage not supported for iTerm2 backend', [
            'agent_id' => $agentId,
        ]);
    }

    public function requestShutdown(string $agentId, ?string $reason = null): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }

        // Send Ctrl+C via AppleScript (ASCII character 3)
        $appleScript = 'tell application "iTerm2" to tell current window to tell current session to write text (ASCII character 3)';
        exec(sprintf('osascript -e %s 2>/dev/null', escapeshellarg($appleScript)));

        $this->agents[$agentId]['status'] = AgentStatus::CANCELLED;
    }

    public function kill(string $agentId): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }

        $info = $this->agents[$agentId];

        // Close the iTerm2 session via AppleScript
        $appleScript = 'tell application "iTerm2" to tell current window to tell current session to close';
        exec(sprintf('osascript -e %s 2>/dev/null', escapeshellarg($appleScript)));

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
     * @return array<string, array{session_id: string, name: string, status: AgentStatus}>
     */
    public function getAgents(): array
    {
        return $this->agents;
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function generateAgentId(string $name): string
    {
        return sprintf(
            'iterm_%s_%s',
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

    private static function osascriptExists(): bool
    {
        exec('which osascript 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }
}
