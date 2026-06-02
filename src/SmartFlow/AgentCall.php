<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

/**
 * An immutable description of a single cross-model agent invocation — the
 * normalized form of `$flow->agent($prompt, $opts)`. It is serializable so the
 * same call can be dispatched in-process or shipped over stdin to a
 * `bin/flow-agent-runner.php` worker for true-parallel execution.
 *
 * `schema` (when present) requests structured output and drives the three-layer
 * extraction ladder. `role` names a persona template; explicit provider/model/
 * system override whatever the persona supplies.
 */
final class AgentCall
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $label = 'agent',
        public readonly ?string $role = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $system = null,
        public readonly ?array $schema = null,
        public readonly ?float $temperature = null,
        public readonly int $maxTokens = 4096,
        public readonly bool $worktree = false,
        public readonly ?string $agentType = null,
        public readonly string $phase = '',
    ) {}

    /**
     * Build a call from a raw prompt + the loose opts array accepted by
     * {@see Flow::agent()}. Unknown keys are ignored.
     *
     * @param array<string, mixed> $opts
     */
    public static function fromOpts(string $prompt, array $opts = [], string $defaultLabel = 'agent'): self
    {
        return new self(
            prompt: $prompt,
            label: (string) ($opts['label'] ?? $defaultLabel),
            role: isset($opts['role']) ? (string) $opts['role'] : null,
            provider: isset($opts['provider']) ? (string) $opts['provider'] : null,
            model: isset($opts['model']) ? (string) $opts['model'] : null,
            system: isset($opts['system']) ? (string) $opts['system'] : null,
            schema: isset($opts['schema']) && is_array($opts['schema']) ? $opts['schema'] : null,
            temperature: isset($opts['temperature']) ? (float) $opts['temperature'] : null,
            maxTokens: (int) ($opts['max_tokens'] ?? 4096),
            worktree: (bool) ($opts['worktree'] ?? false),
            agentType: isset($opts['agentType']) ? (string) $opts['agentType'] : (isset($opts['agent_type']) ? (string) $opts['agent_type'] : null),
            phase: (string) ($opts['phase'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'label' => $this->label,
            'role' => $this->role,
            'provider' => $this->provider,
            'model' => $this->model,
            'system' => $this->system,
            'schema' => $this->schema,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'worktree' => $this->worktree,
            'agent_type' => $this->agentType,
            'phase' => $this->phase,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            prompt: (string) ($data['prompt'] ?? ''),
            label: (string) ($data['label'] ?? 'agent'),
            role: $data['role'] ?? null,
            provider: $data['provider'] ?? null,
            model: $data['model'] ?? null,
            system: $data['system'] ?? null,
            schema: isset($data['schema']) && is_array($data['schema']) ? $data['schema'] : null,
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            maxTokens: (int) ($data['max_tokens'] ?? 4096),
            worktree: (bool) ($data['worktree'] ?? false),
            agentType: $data['agent_type'] ?? null,
            phase: (string) ($data['phase'] ?? ''),
        );
    }
}
