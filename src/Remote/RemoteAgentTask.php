<?php

declare(strict_types=1);

namespace SuperAgent\Remote;

/**
 * Remote agent task management ported from Claude Code.
 *
 * Supports out-of-process agent execution via API triggers.
 * Remote agents run as fully isolated sessions with independent
 * git checkouts, tools, and optional MCP connections.
 *
 * Actions: create, list, get, run, update, delete
 * Scheduling: cron expressions (minimum 1 hour interval)
 */
class RemoteAgentTask
{
    public function __construct(
        /** Unique trigger ID */
        public readonly string $id,
        /** Human-readable name */
        public readonly string $name,
        /** Cron expression (UTC) */
        public readonly ?string $cronExpression,
        /** Whether the trigger is enabled */
        public readonly bool $enabled,
        /** Task type */
        public readonly string $taskType,
        /** Job configuration */
        public readonly array $jobConfig,
        /** Current status */
        public readonly string $status = 'idle',
        /** Creation timestamp */
        public readonly ?string $createdAt = null,
        /** Last run timestamp */
        public readonly ?string $lastRunAt = null,
        /** MCP connections */
        public readonly array $mcpConnections = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            cronExpression: $data['cron_expression'] ?? null,
            enabled: $data['enabled'] ?? false,
            taskType: $data['task_type'] ?? 'remote-agent',
            jobConfig: $data['job_config'] ?? [],
            status: $data['status'] ?? 'idle',
            createdAt: $data['created_at'] ?? null,
            lastRunAt: $data['last_run_at'] ?? null,
            mcpConnections: $data['mcp_connections'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cron_expression' => $this->cronExpression,
            'enabled' => $this->enabled,
            'task_type' => $this->taskType,
            'job_config' => $this->jobConfig,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'last_run_at' => $this->lastRunAt,
            'mcp_connections' => $this->mcpConnections,
        ];
    }
}

/**
 * Manager for remote agent tasks (API client).
 */
class RemoteAgentManager
{
    /** Default allowed tools for remote agents */
    private const DEFAULT_ALLOWED_TOOLS = [
        'Bash', 'Read', 'Write', 'Edit', 'Glob', 'Grep',
    ];

    public function __construct(
        private string $apiBaseUrl = 'https://api.anthropic.com',
        private ?string $apiKey = null,
        private ?string $organizationId = null,
    ) {}

    /**
     * Create a new remote agent trigger.
     */
    public function create(
        string $name,
        string $prompt,
        ?string $cronExpression = null,
        string $model = 'claude-sonnet-4-6',
        array $allowedTools = self::DEFAULT_ALLOWED_TOOLS,
        ?string $gitRepoUrl = null,
        array $mcpConnections = [],
    ): RemoteAgentTask {
        $body = [
            'name' => $name,
            'enabled' => true,
            'job_config' => [
                'ccr' => [
                    'session_context' => [
                        'model' => $model,
                        'allowed_tools' => $allowedTools,
                    ],
                    'events' => [
                        ['data' => ['type' => 'user', 'content' => $prompt]],
                    ],
                ],
            ],
        ];

        if ($cronExpression !== null) {
            $body['cron_expression'] = $cronExpression;
        }

        if ($gitRepoUrl !== null) {
            $body['job_config']['ccr']['session_context']['sources'] = [
                ['git_repository' => ['url' => $gitRepoUrl]],
            ];
        }

        if (!empty($mcpConnections)) {
            $body['mcp_connections'] = $mcpConnections;
        }

        $response = $this->apiRequest('POST', '/v1/code/triggers', $body);

        return RemoteAgentTask::fromArray($response);
    }

    /**
     * List all remote agent triggers.
     */
    public function list(): array
    {
        $response = $this->apiRequest('GET', '/v1/code/triggers');
        $triggers = $response['triggers'] ?? $response['data'] ?? [];

        return array_map(
            fn(array $t) => RemoteAgentTask::fromArray($t),
            $triggers,
        );
    }

    /**
     * Get a specific trigger by ID.
     */
    public function get(string $triggerId): RemoteAgentTask
    {
        $response = $this->apiRequest('GET', "/v1/code/triggers/{$triggerId}");
        return RemoteAgentTask::fromArray($response);
    }

    /**
     * Update a trigger.
     */
    public function update(string $triggerId, array $updates): RemoteAgentTask
    {
        $response = $this->apiRequest('POST', "/v1/code/triggers/{$triggerId}", $updates);
        return RemoteAgentTask::fromArray($response);
    }

    /**
     * Run a trigger immediately (bypass cron schedule).
     */
    public function run(string $triggerId): array
    {
        return $this->apiRequest('POST', "/v1/code/triggers/{$triggerId}/run");
    }

    /**
     * Delete a trigger.
     */
    public function delete(string $triggerId): bool
    {
        $this->apiRequest('DELETE', "/v1/code/triggers/{$triggerId}");
        return true;
    }

    /**
     * Convert local timezone cron expression to UTC.
     */
    public static function cronToUtc(string $localCron, string $timezone = 'UTC'): string
    {
        if ($timezone === 'UTC') {
            return $localCron;
        }

        // Parse the cron expression
        $parts = preg_split('/\s+/', trim($localCron));
        if (count($parts) !== 5) {
            return $localCron; // Can't convert non-standard cron
        }

        // Simple hour offset for fixed-hour crons
        $hour = $parts[1];
        if (is_numeric($hour)) {
            try {
                $local = new \DateTimeImmutable("today {$hour}:00", new \DateTimeZone($timezone));
                $utc = $local->setTimezone(new \DateTimeZone('UTC'));
                $parts[1] = $utc->format('G');
            } catch (\Exception $e) {
                // Can't convert, return as-is
            }
        }

        return implode(' ', $parts);
    }

    private function apiRequest(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim($this->apiBaseUrl, '/') . $path;

        $headers = [
            'Content-Type: application/json',
            'anthropic-beta: ccr-triggers-2026-01-30',
        ];

        if ($this->apiKey !== null) {
            $headers[] = "x-api-key: {$this->apiKey}";
        }

        if ($this->organizationId !== null) {
            $headers[] = "anthropic-organization: {$this->organizationId}";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException(
                "Remote API error ({$httpCode}): " . ($response ?: 'No response'),
            );
        }

        return json_decode($response ?: '{}', true) ?? [];
    }
}
