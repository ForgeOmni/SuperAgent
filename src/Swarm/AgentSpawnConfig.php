<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use SuperAgent\Agent\ForkContext;
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
        public readonly ?ForkContext $forkContext = null,
        /** Parent agent's provider config so sub-agents share the same LLM credentials. */
        public readonly array $providerConfig = [],
    ) {}

    /**
     * Whether this spawn is a fork (inherits parent context).
     */
    public function isFork(): bool
    {
        return $this->forkContext !== null;
    }

    /**
     * Serialize to array for cross-process/network transport.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'prompt' => $this->prompt,
            'team_name' => $this->teamName,
            'model' => $this->model,
            'system_prompt' => $this->systemPrompt,
            'permission_mode' => $this->permissionMode?->value,
            'backend' => $this->backend?->value,
            'isolation' => $this->isolation?->value,
            'run_in_background' => $this->runInBackground,
            'allowed_tools' => $this->allowedTools,
            'denied_tools' => $this->deniedTools,
            'working_directory' => $this->workingDirectory,
            'environment' => $this->environment,
            'color' => $this->color,
            'plan_mode_required' => $this->planModeRequired,
            'read_only' => $this->readOnly,
            'provider_config' => $this->providerConfig,
        ];
    }
}