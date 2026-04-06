<?php

declare(strict_types=1);

namespace SuperAgent\Fork;

use RuntimeException;

final class ForkExecutor
{
    private string $agentRunnerPath;
    private int $defaultTimeout;

    public function __construct(
        ?string $agentRunnerPath = null,
        int $defaultTimeout = 300,
    ) {
        $this->agentRunnerPath = $agentRunnerPath
            ?? dirname(__DIR__, 2) . '/bin/agent-runner.php';
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * Execute all branches in a fork session in parallel.
     */
    public function executeAll(ForkSession $session): ForkResult
    {
        $branches = $session->getBranches();
        if (empty($branches)) {
            return new ForkResult(
                sessionId: $session->id,
                branches: [],
                totalCost: 0.0,
                totalDurationMs: 0.0,
                completedCount: 0,
                failedCount: 0,
            );
        }

        $processes = [];
        $tempFiles = [];
        $startTime = microtime(true);

        // Launch all branches in parallel
        foreach ($branches as $branch) {
            $branch->markRunning();

            $inputFile = tempnam(sys_get_temp_dir(), 'fork_input_');
            $outputFile = tempnam(sys_get_temp_dir(), 'fork_output_');
            $tempFiles[$branch->id] = ['input' => $inputFile, 'output' => $outputFile];

            $payload = json_encode([
                'base_messages' => $session->getBaseMessages(),
                'prompt' => $branch->prompt,
                'config' => $branch->config,
                'branch_id' => $branch->id,
                'output_file' => $outputFile,
            ], JSON_THROW_ON_ERROR);

            file_put_contents($inputFile, $payload);

            $cmd = sprintf(
                'php %s --fork-branch --input=%s 2>&1',
                escapeshellarg($this->agentRunnerPath),
                escapeshellarg($inputFile),
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($proc)) {
                $branch->markFailed('Failed to spawn process', 0.0);
                continue;
            }

            fclose($pipes[0]);
            $processes[$branch->id] = [
                'proc' => $proc,
                'pipes' => $pipes,
                'branch' => $branch,
                'start' => microtime(true),
            ];
        }

        // Wait for all processes to complete
        $timeout = $session->config['timeout'] ?? $this->defaultTimeout;
        $this->waitForProcesses($processes, $timeout);

        // Collect results
        $totalCost = 0.0;
        $completed = 0;
        $failed = 0;

        foreach ($branches as $branch) {
            $files = $tempFiles[$branch->id] ?? null;
            if ($files && file_exists($files['output'])) {
                $resultData = @json_decode(file_get_contents($files['output']), true);

                if ($resultData !== null && isset($resultData['success']) && $resultData['success']) {
                    $branch->markCompleted(
                        messages: $resultData['messages'] ?? [],
                        cost: (float) ($resultData['cost'] ?? 0.0),
                        turns: (int) ($resultData['turns'] ?? 0),
                        durationMs: (float) ($resultData['duration_ms'] ?? 0.0),
                    );
                    $totalCost += $branch->cost ?? 0.0;
                    $completed++;
                } elseif (!$branch->isFailed()) {
                    $branch->markFailed(
                        $resultData['error'] ?? 'Unknown error',
                        (float) ($resultData['duration_ms'] ?? 0.0),
                    );
                    $failed++;
                }
            } elseif (!$branch->isFailed()) {
                $branch->markFailed('No output produced', 0.0);
                $failed++;
            }

            // Cleanup temp files
            if ($files) {
                @unlink($files['input']);
                @unlink($files['output']);
            }
        }

        $totalDurationMs = (microtime(true) - $startTime) * 1000;

        return new ForkResult(
            sessionId: $session->id,
            branches: $branches,
            totalCost: $totalCost,
            totalDurationMs: $totalDurationMs,
            completedCount: $completed,
            failedCount: $failed,
        );
    }

    private function waitForProcesses(array &$processes, int $timeout): void
    {
        $deadline = time() + $timeout;

        while (!empty($processes) && time() < $deadline) {
            foreach ($processes as $branchId => $procInfo) {
                $status = proc_get_status($procInfo['proc']);

                if (!$status['running']) {
                    // Process finished
                    $stdout = stream_get_contents($procInfo['pipes'][1]);
                    $stderr = stream_get_contents($procInfo['pipes'][2]);
                    fclose($procInfo['pipes'][1]);
                    fclose($procInfo['pipes'][2]);
                    proc_close($procInfo['proc']);

                    $elapsed = (microtime(true) - $procInfo['start']) * 1000;

                    if ($status['exitcode'] !== 0) {
                        $procInfo['branch']->markFailed(
                            'Process exited with code ' . $status['exitcode'] . ': ' . substr($stderr ?: $stdout, 0, 500),
                            $elapsed,
                        );
                    }

                    unset($processes[$branchId]);
                }
            }

            if (!empty($processes)) {
                usleep(50_000); // 50ms poll interval
            }
        }

        // Kill any remaining processes that exceeded timeout
        foreach ($processes as $branchId => $procInfo) {
            $elapsed = (microtime(true) - $procInfo['start']) * 1000;
            @fclose($procInfo['pipes'][1]);
            @fclose($procInfo['pipes'][2]);
            proc_terminate($procInfo['proc'], 9);
            proc_close($procInfo['proc']);
            $procInfo['branch']->markFailed('Timeout after ' . $timeout . 's', $elapsed);
        }
    }
}
