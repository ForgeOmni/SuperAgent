<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use SuperAgent\Agent\ForkContext;
use SuperAgent\Permissions\PermissionMode;
use SuperAgent\Support\Secrets;

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
     *
     * The provider config is redacted: this array is what gets logged, traced
     * and sent over a wire, and it carried the parent agent's API key in
     * clear text. Use {@see toArrayWithCredentials()} at the one place that
     * genuinely has to hand credentials to a child process.
     *
     * @since 1.5.0 redacted by default
     */
    public function toArray(): array
    {
        $data = $this->toArrayWithCredentials();
        $data['provider_config'] = Secrets::redact($this->providerConfig);

        return $data;
    }

    /**
     * The same array with credentials intact — for spawning a child that has
     * to authenticate, and for nothing else.
     *
     * @since 1.5.0
     */
    public function toArrayWithCredentials(): array
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