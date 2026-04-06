<?php

declare(strict_types=1);

namespace SuperAgent\Replay;

final class ReplayEvent
{
    public const TYPE_LLM_CALL = 'llm_call';
    public const TYPE_TOOL_CALL = 'tool_call';
    public const TYPE_AGENT_SPAWN = 'agent_spawn';
    public const TYPE_AGENT_MESSAGE = 'agent_message';
    public const TYPE_STATE_SNAPSHOT = 'state_snapshot';

    public function __construct(
        public readonly int $step,
        public readonly string $type,
        public readonly string $agentId,
        public readonly string $timestamp,
        public readonly float $durationMs,
        public readonly array $data,
    ) {}

    public function isLlmCall(): bool
    {
        return $this->type === self::TYPE_LLM_CALL;
    }

    public function isToolCall(): bool
    {
        return $this->type === self::TYPE_TOOL_CALL;
    }

    public function isAgentSpawn(): bool
    {
        return $this->type === self::TYPE_AGENT_SPAWN;
    }

    public function isAgentMessage(): bool
    {
        return $this->type === self::TYPE_AGENT_MESSAGE;
    }

    public function isStateSnapshot(): bool
    {
        return $this->type === self::TYPE_STATE_SNAPSHOT;
    }

    public function getData(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'type' => $this->type,
            'agent_id' => $this->agentId,
            'timestamp' => $this->timestamp,
            'duration_ms' => $this->durationMs,
            'data' => $this->data,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            step: $data['step'],
            type: $data['type'],
            agentId: $data['agent_id'],
            timestamp: $data['timestamp'],
            durationMs: (float) $data['duration_ms'],
            data: $data['data'] ?? [],
        );
    }
}
