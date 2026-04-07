<?php

declare(strict_types=1);

namespace SuperAgent\State;

/**
 * Immutable value object representing the application state at a point in time.
 *
 * Create modified copies via with():
 *   $next = $state->with(['turnCount' => $state->turnCount + 1]);
 */
class AppState
{
    public function __construct(
        public readonly string $model = '',
        public readonly string $permissionMode = 'default',
        public readonly string $provider = '',
        public readonly string $cwd = '',
        public readonly ?string $theme = null,
        public readonly bool $fastMode = false,
        public readonly int $turnCount = 0,
        public readonly float $totalCostUsd = 0.0,
        public readonly int $tokenCount = 0,
        public readonly array $activeAgents = [],
        public readonly array $mcpServers = [],
        public readonly ?string $sessionId = null,
    ) {}

    /**
     * Return a new AppState with the given fields replaced.
     *
     * Keys in $updates must match constructor parameter names.
     */
    public function with(array $updates): self
    {
        return new self(...array_merge($this->toArray(), $updates));
    }

    /**
     * Serialise all properties to an associative array.
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'permissionMode' => $this->permissionMode,
            'provider' => $this->provider,
            'cwd' => $this->cwd,
            'theme' => $this->theme,
            'fastMode' => $this->fastMode,
            'turnCount' => $this->turnCount,
            'totalCostUsd' => $this->totalCostUsd,
            'tokenCount' => $this->tokenCount,
            'activeAgents' => $this->activeAgents,
            'mcpServers' => $this->mcpServers,
            'sessionId' => $this->sessionId,
        ];
    }
}
