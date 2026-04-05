<?php

declare(strict_types=1);

namespace SuperAgent\Performance;

/**
 * Streaming executor for Bash commands.
 *
 * Instead of waiting for the full command output, streams output
 * incrementally with timeout truncation and tail-based summarization.
 * Returns the last N lines + a summary header for long output.
 */
class StreamingBashExecutor
{
    public function __construct(
        private bool $enabled = true,
        private int $maxOutputLines = 500,     // Max lines to keep
        private int $tailLines = 100,          // Lines to show from end of long output
        private int $streamTimeoutMs = 30_000, // Stream collection timeout (30s)
    ) {}

    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.performance.streaming_bash') ?? [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxOutputLines: (int) ($config['max_output_lines'] ?? 500),
            tailLines: (int) ($config['tail_lines'] ?? 100),
            streamTimeoutMs: (int) ($config['stream_timeout_ms'] ?? 30_000),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Execute a command with streaming output collection.
     *
     * @param string $command  Shell command to execute
     * @param string|null $cwd  Working directory
     * @return array{output: string, exit_code: int, truncated: bool, total_lines: int}
     */
    public function execute(string $command, ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return [
                'output' => 'Error: Failed to start process',
                'exit_code' => 1,
                'truncated' => false,
                'total_lines' => 0,
            ];
        }

        fclose($pipes[0]); // Close stdin

        // Make pipes non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $allLines = [];
        $startTime = microtime(true) * 1000;
        $timedOut = false;

        while (true) {
            $elapsed = (microtime(true) * 1000) - $startTime;
            if ($elapsed >= $this->streamTimeoutMs) {
                $timedOut = true;
                break;
            }

            $status = proc_get_status($process);

            // Read available stdout
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                foreach (explode("\n", $chunk) as $line) {
                    if ($line !== '' || !empty($allLines)) {
                        $allLines[] = $line;
                    }
                }
            }

            // Read available stderr (merge with stdout)
            $chunk = fread($pipes[2], 65536);
            if ($chunk !== false && $chunk !== '') {
                foreach (explode("\n", $chunk) as $line) {
                    if ($line !== '') {
                        $allLines[] = "[stderr] {$line}";
                    }
                }
            }

            if (!$status['running']) {
                // Final drain
                $remaining = stream_get_contents($pipes[1]);
                if ($remaining !== false && $remaining !== '') {
                    foreach (explode("\n", $remaining) as $line) {
                        $allLines[] = $line;
                    }
                }
                break;
            }

            usleep(10_000); // 10ms
        }

        $exitCode = 0;
        if ($timedOut) {
            proc_terminate($process, SIGTERM);
            usleep(200_000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, SIGKILL);
            }
            $exitCode = 124; // Timeout exit code (like GNU timeout)
        } else {
            $status = proc_get_status($process);
            $exitCode = $status['exitcode'];
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $totalLines = count($allLines);
        $truncated = false;

        // Truncate if too long: keep header summary + tail
        if ($totalLines > $this->maxOutputLines) {
            $truncated = true;
            $tail = array_slice($allLines, -$this->tailLines);
            $skipped = $totalLines - $this->tailLines;
            $output = "[Output truncated: {$totalLines} total lines, showing last {$this->tailLines}]\n"
                . "[... {$skipped} lines omitted ...]\n"
                . implode("\n", $tail);
        } else {
            $output = implode("\n", $allLines);
        }

        if ($timedOut) {
            $output = "[Command timed out after {$this->streamTimeoutMs}ms]\n" . $output;
        }

        return [
            'output' => $output,
            'exit_code' => $exitCode,
            'truncated' => $truncated,
            'total_lines' => $totalLines,
        ];
    }
}
