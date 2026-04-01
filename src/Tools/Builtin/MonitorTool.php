<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class MonitorTool extends Tool
{
    private static array $monitors = [];
    private static int $nextId = 1;

    public function name(): string
    {
        return 'monitor';
    }

    public function description(): string
    {
        return 'Monitor system resources, processes, or custom metrics over time.';
    }

    public function category(): string
    {
        return 'system';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['start', 'stop', 'status', 'get', 'list'],
                    'description' => 'Monitor action: start, stop, status, get, or list.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['cpu', 'memory', 'disk', 'process', 'custom'],
                    'description' => 'Type of monitor.',
                ],
                'monitor_id' => [
                    'type' => 'integer',
                    'description' => 'Monitor ID (for stop/status/get actions).',
                ],
                'target' => [
                    'type' => 'string',
                    'description' => 'Target to monitor (e.g., process name, file path).',
                ],
                'interval' => [
                    'type' => 'integer',
                    'description' => 'Monitoring interval in seconds. Default: 5.',
                ],
                'duration' => [
                    'type' => 'integer',
                    'description' => 'Total monitoring duration in seconds. Default: 60.',
                ],
                'threshold' => [
                    'type' => 'object',
                    'description' => 'Alert thresholds (e.g., {"cpu": 80, "memory": 90}).',
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
                return $this->startMonitor($input);
            case 'stop':
                return $this->stopMonitor($input);
            case 'status':
                return $this->getMonitorStatus($input);
            case 'get':
                return $this->getMonitorData($input);
            case 'list':
                return $this->listMonitors();
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function startMonitor(array $input): ToolResult
    {
        $type = $input['type'] ?? 'cpu';
        $target = $input['target'] ?? null;
        $interval = $input['interval'] ?? 5;
        $duration = $input['duration'] ?? 60;
        $threshold = $input['threshold'] ?? [];

        $monitorId = self::$nextId++;
        
        $monitor = [
            'id' => $monitorId,
            'type' => $type,
            'target' => $target,
            'interval' => $interval,
            'duration' => $duration,
            'threshold' => $threshold,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'data' => [],
            'alerts' => [],
        ];

        // Simulate initial data collection
        $monitor['data'][] = $this->collectData($type, $target);

        self::$monitors[$monitorId] = $monitor;

        return ToolResult::success([
            'message' => 'Monitor started',
            'monitor_id' => $monitorId,
            'type' => $type,
            'target' => $target,
        ]);
    }

    private function stopMonitor(array $input): ToolResult
    {
        $monitorId = $input['monitor_id'] ?? null;

        if ($monitorId === null) {
            return ToolResult::error('Monitor ID is required.');
        }

        if (!isset(self::$monitors[$monitorId])) {
            return ToolResult::error("Monitor {$monitorId} not found.");
        }

        self::$monitors[$monitorId]['status'] = 'stopped';
        self::$monitors[$monitorId]['stopped_at'] = date('Y-m-d H:i:s');

        return ToolResult::success([
            'message' => 'Monitor stopped',
            'monitor_id' => $monitorId,
        ]);
    }

    private function getMonitorStatus(array $input): ToolResult
    {
        $monitorId = $input['monitor_id'] ?? null;

        if ($monitorId === null) {
            return ToolResult::error('Monitor ID is required.');
        }

        if (!isset(self::$monitors[$monitorId])) {
            return ToolResult::error("Monitor {$monitorId} not found.");
        }

        $monitor = self::$monitors[$monitorId];
        
        return ToolResult::success([
            'monitor_id' => $monitorId,
            'status' => $monitor['status'],
            'type' => $monitor['type'],
            'target' => $monitor['target'],
            'started_at' => $monitor['started_at'],
            'data_points' => count($monitor['data']),
            'alerts' => count($monitor['alerts']),
        ]);
    }

    private function getMonitorData(array $input): ToolResult
    {
        $monitorId = $input['monitor_id'] ?? null;

        if ($monitorId === null) {
            return ToolResult::error('Monitor ID is required.');
        }

        if (!isset(self::$monitors[$monitorId])) {
            return ToolResult::error("Monitor {$monitorId} not found.");
        }

        return ToolResult::success(self::$monitors[$monitorId]);
    }

    private function listMonitors(): ToolResult
    {
        $summary = [];
        
        foreach (self::$monitors as $monitor) {
            $summary[] = [
                'id' => $monitor['id'],
                'type' => $monitor['type'],
                'target' => $monitor['target'],
                'status' => $monitor['status'],
                'started_at' => $monitor['started_at'],
            ];
        }

        return ToolResult::success([
            'count' => count($summary),
            'monitors' => $summary,
        ]);
    }

    private function collectData(string $type, ?string $target): array
    {
        // Simulate data collection based on type
        switch ($type) {
            case 'cpu':
                return [
                    'timestamp' => time(),
                    'value' => rand(10, 90),
                    'unit' => 'percent',
                ];
            case 'memory':
                return [
                    'timestamp' => time(),
                    'value' => rand(1000, 8000),
                    'unit' => 'MB',
                ];
            case 'disk':
                return [
                    'timestamp' => time(),
                    'value' => rand(10, 500),
                    'unit' => 'GB',
                ];
            case 'process':
                return [
                    'timestamp' => time(),
                    'value' => $target ? rand(0, 1) : 0,
                    'unit' => 'running',
                ];
            default:
                return [
                    'timestamp' => time(),
                    'value' => rand(0, 100),
                    'unit' => 'custom',
                ];
        }
    }

    public static function clearMonitors(): void
    {
        self::$monitors = [];
        self::$nextId = 1;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}