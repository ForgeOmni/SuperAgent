<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use SuperAgent\Permissions\PermissionMode;

/**
 * Configuration for spawning an agent.
 */
class AgentSpawnConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $prompt,
        public readonly ?string $teamName = null,
        public readonly ?string $model = null,
        public readonly ?string $systemPrompt = null,
        public readonly ?PermissionMode $permissionMode = null,
        public readonly ?BackendType $backend = null,
        public readonly ?IsolationMode $isolation = null,
        public readonly bool $runInBackground = false,
        public readonly ?array $allowedTools = null,
        public readonly ?array $deniedTools = null,
        public readonly ?string $workingDirectory = null,
        public readonly ?array $environment = null,
        public readonly ?string $color = null,
        public readonly bool $planModeRequired = false,
        public readonly bool $readOnly = false,
    ) {}
}