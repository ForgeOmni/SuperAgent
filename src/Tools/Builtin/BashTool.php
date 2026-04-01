<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class BashTool extends Tool
{
    public function __construct(
        protected readonly ?string $workingDirectory = null,
        protected readonly int $timeout = 120,
        protected readonly array $envVars = [],
    ) {
    }

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Execute a bash command and return its stdout/stderr output. Use this for system commands, file operations, running scripts, etc.';
    }

    public function category(): string
    {
        return 'execution';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The bash command to execute.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Optional timeout in seconds (default: 120).',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $command = $input['command'] ?? '';
        $timeout = $input['timeout'] ?? $this->timeout;

        if (empty($command)) {
            return ToolResult::error('Command cannot be empty.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, $this->envVars);
        $cwd = $this->workingDirectory ?? getcwd();

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);

        if (! is_resource($process)) {
            return ToolResult::error("Failed to execute command: {$command}");
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if ((time() - $startTime) >= $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ToolResult::error("Command timed out after {$timeout}s. Partial output:\n{$stdout}\n{$stderr}");
            }

            usleep(50_000); // 50ms
        }

        // Read any remaining output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $status['exitcode'] ?? proc_close($process);

        $output = trim($stdout);
        if (! empty($stderr)) {
            $output .= ($output ? "\n" : '') . trim($stderr);
        }

        if ($exitCode !== 0) {
            return ToolResult::error("Exit code {$exitCode}\n{$output}");
        }

        return ToolResult::success($output ?: '(no output)');
    }
}
