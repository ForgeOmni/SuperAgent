<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

/**
 * Outcome of a {@see Flow::gate()} — a checkpoint that an artifact must clear
 * before the flow treats it as "accepted" ("做完了到验收了 — accepted 必须经过
 * gate"). A gate that fails may carry the value produced by its `fallback`/
 * `relay` branch.
 */
final class GateResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $reason = '',
        public readonly mixed $value = null,
        public readonly bool $relayed = false,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'reason' => $this->reason,
            'relayed' => $this->relayed,
        ];
    }
}
