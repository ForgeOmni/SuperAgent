<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TerminalCaptureTool extends Tool
{
    private static array $captures = [];
    private static int $nextId = 1;

    public function name(): string
    {
        return 'terminal_capture';
    }

    public function description(): string
    {
        return 'Capture and replay terminal sessions including commands and outputs.';
    }

    public function category(): string
    {
        return 'terminal';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['start', 'stop', 'add', 'replay', 'list', 'get', 'export'],
                    'description' => 'Capture action.',
                ],
                'capture_id' => [
                    'type' => 'integer',
                    'description' => 'Capture session ID.',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Command to add to capture.',
                ],
                'output' => [
                    'type' => 'string',
                    'description' => 'Output to add to capture.',
                ],
                'exit_code' => [
                    'type' => 'integer',
                    'description' => 'Exit code of the command.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'json', 'script'],
                    'description' => 'Export format. Default: text.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Description of the capture session.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'start':
                return $this->startCapture($input);
            case 'stop':
                return $this->stopCapture($input);
            case 'add':
                return $this->addToCapture($input);
            case 'replay':
                return $this->replayCapture($input);
            case 'list':
                return $this->listCaptures();
            case 'get':
                return $this->getCapture($input);
            case 'export':
                return $this->exportCapture($input);
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function startCapture(array $input): ToolResult
    {
        $description = $input['description'] ?? 'Terminal capture session';
        
        $captureId = self::$nextId++;
        
        self::$captures[$captureId] = [
            'id' => $captureId,
            'description' => $description,
            'status' => 'recording',
            'started_at' => date('Y-m-d H:i:s'),
            'stopped_at' => null,
            'commands' => [],
        ];

        return ToolResult::success([
            'message' => 'Capture started',
            'capture_id' => $captureId,
            'description' => $description,
        ]);
    }

    private function stopCapture(array $input): ToolResult
    {
        $captureId = $input['capture_id'] ?? null;

        if ($captureId === null) {
            return ToolResult::error('Capture ID is required.');
        }

        if (!isset(self::$captures[$captureId])) {
            return ToolResult::error("Capture {$captureId} not found.");
        }

        if (self::$captures[$captureId]['status'] !== 'recording') {
            return ToolResult::error("Capture {$captureId} is not recording.");
        }

        self::$captures[$captureId]['status'] = 'stopped';
        self::$captures[$captureId]['stopped_at'] = date('Y-m-d H:i:s');

        return ToolResult::success([
            'message' => 'Capture stopped',
            'capture_id' => $captureId,
            'commands_recorded' => count(self::$captures[$captureId]['commands']),
        ]);
    }

    private function addToCapture(array $input): ToolResult
    {
        $captureId = $input['capture_id'] ?? null;
        $command = $input['command'] ?? '';
        $output = $input['output'] ?? '';
        $exitCode = $input['exit_code'] ?? 0;

        if ($captureId === null) {
            // Find active capture
            foreach (self::$captures as $capture) {
                if ($capture['status'] === 'recording') {
                    $captureId = $capture['id'];
                    break;
                }
            }
            
            if ($captureId === null) {
                return ToolResult::error('No active capture session. Start one first.');
            }
        }

        if (!isset(self::$captures[$captureId])) {
            return ToolResult::error("Capture {$captureId} not found.");
        }

        if (self::$captures[$captureId]['status'] !== 'recording') {
            return ToolResult::error("Capture {$captureId} is not recording.");
        }

        $entry = [
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'timestamp' => date('Y-m-d H:i:s'),
            'duration_ms' => rand(100, 5000), // Simulated
        ];

        self::$captures[$captureId]['commands'][] = $entry;

        return ToolResult::success([
            'message' => 'Command added to capture',
            'capture_id' => $captureId,
            'entry_number' => count(self::$captures[$captureId]['commands']),
        ]);
    }

    private function replayCapture(array $input): ToolResult
    {
        $captureId = $input['capture_id'] ?? null;

        if ($captureId === null) {
            return ToolResult::error('Capture ID is required.');
        }

        if (!isset(self::$captures[$captureId])) {
            return ToolResult::error("Capture {$captureId} not found.");
        }

        $capture = self::$captures[$captureId];
        $replay = [];
        
        foreach ($capture['commands'] as $index => $cmd) {
            $replay[] = [
                'step' => $index + 1,
                'command' => $cmd['command'],
                'would_execute' => true,
                'expected_output' => substr($cmd['output'], 0, 100) . (strlen($cmd['output']) > 100 ? '...' : ''),
            ];
        }

        return ToolResult::success([
            'message' => 'Capture replay simulation',
            'capture_id' => $captureId,
            'total_commands' => count($replay),
            'replay_steps' => $replay,
        ]);
    }

    private function listCaptures(): ToolResult
    {
        $summary = [];
        
        foreach (self::$captures as $capture) {
            $summary[] = [
                'id' => $capture['id'],
                'description' => $capture['description'],
                'status' => $capture['status'],
                'commands' => count($capture['commands']),
                'started_at' => $capture['started_at'],
                'stopped_at' => $capture['stopped_at'],
            ];
        }

        return ToolResult::success([
            'count' => count($summary),
            'captures' => $summary,
        ]);
    }

    private function getCapture(array $input): ToolResult
    {
        $captureId = $input['capture_id'] ?? null;

        if ($captureId === null) {
            return ToolResult::error('Capture ID is required.');
        }

        if (!isset(self::$captures[$captureId])) {
            return ToolResult::error("Capture {$captureId} not found.");
        }

        return ToolResult::success(self::$captures[$captureId]);
    }

    private function exportCapture(array $input): ToolResult
    {
        $captureId = $input['capture_id'] ?? null;
        $format = $input['format'] ?? 'text';

        if ($captureId === null) {
            return ToolResult::error('Capture ID is required.');
        }

        if (!isset(self::$captures[$captureId])) {
            return ToolResult::error("Capture {$captureId} not found.");
        }

        $capture = self::$captures[$captureId];

        switch ($format) {
            case 'json':
                return ToolResult::success([
                    'format' => 'json',
                    'content' => $capture,
                ]);
                
            case 'script':
                $script = "#!/bin/bash\n";
                $script .= "# Terminal capture: {$capture['description']}\n";
                $script .= "# Captured at: {$capture['started_at']}\n\n";
                
                foreach ($capture['commands'] as $cmd) {
                    $script .= "# [{$cmd['timestamp']}]\n";
                    $script .= "{$cmd['command']}\n";
                    if ($cmd['exit_code'] !== 0) {
                        $script .= "# Exit code: {$cmd['exit_code']}\n";
                    }
                    $script .= "\n";
                }
                
                return ToolResult::success([
                    'format' => 'script',
                    'content' => $script,
                ]);
                
            case 'text':
            default:
                $text = "Terminal Capture: {$capture['description']}\n";
                $text .= str_repeat('=', 60) . "\n";
                
                foreach ($capture['commands'] as $index => $cmd) {
                    $text .= "\n[{$index}] $ {$cmd['command']}\n";
                    $text .= $cmd['output'];
                    if (!str_ends_with($cmd['output'], "\n")) {
                        $text .= "\n";
                    }
                }
                
                return ToolResult::success([
                    'format' => 'text',
                    'content' => $text,
                ]);
        }
    }

    public static function clearCaptures(): void
    {
        self::$captures = [];
        self::$nextId = 1;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}