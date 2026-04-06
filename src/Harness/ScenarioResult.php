<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\AgentResult;

/**
 * Result of executing a single E2E scenario.
 */
class ScenarioResult
{
    public function __construct(
        public readonly string $scenarioName,
        public readonly bool $passed,
        public readonly array $failures = [],
        public readonly ?AgentResult $agentResult = null,
        public readonly array $toolsUsed = [],
        public readonly float $durationMs = 0.0,
        public readonly ?string $error = null,
    ) {}

    public static function pass(
        string $name,
        AgentResult $result,
        array $toolsUsed,
        float $durationMs,
    ): self {
        return new self(
            scenarioName: $name,
            passed: true,
            agentResult: $result,
            toolsUsed: $toolsUsed,
            durationMs: $durationMs,
        );
    }

    public static function fail(
        string $name,
        array $failures,
        ?AgentResult $result = null,
        array $toolsUsed = [],
        float $durationMs = 0.0,
    ): self {
        return new self(
            scenarioName: $name,
            passed: false,
            failures: $failures,
            agentResult: $result,
            toolsUsed: $toolsUsed,
            durationMs: $durationMs,
        );
    }

    public static function error(string $name, string $error, float $durationMs = 0.0): self
    {
        return new self(
            scenarioName: $name,
            passed: false,
            failures: [$error],
            error: $error,
            durationMs: $durationMs,
        );
    }

    public function toArray(): array
    {
        return [
            'scenario' => $this->scenarioName,
            'passed' => $this->passed,
            'failures' => $this->failures,
            'tools_used' => $this->toolsUsed,
            'duration_ms' => round($this->durationMs, 2),
            'error' => $this->error,
            'final_text_length' => $this->agentResult ? strlen($this->agentResult->text()) : 0,
            'turns' => $this->agentResult?->turns() ?? 0,
        ];
    }
}
