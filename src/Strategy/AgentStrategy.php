<?php

declare(strict_types=1);

namespace SuperAgent\Strategy;

/**
 * Declarative agent strategy — the gs-quant pattern adapted for agent runs.
 *
 * Wave 5 / SA-9 motivation:
 * ------------------------
 * gs-quant gives quants three orthogonal primitives:
 *
 *   Strategy        — the rules (entry, exit, position sizing)
 *   Backtest        — replays a Strategy against historical data, scores it
 *   RiskMeasure     — observable property of a position / strategy (VaR, IV, …)
 *
 * SuperAgent has analogous needs at the agent layer:
 *
 *   AgentStrategy   — the rules (which tools, when to escalate, when to stop)
 *   AgentBacktest   — replays a Strategy against historical transcripts
 *   AgentRiskMeasure— observable property of a run (cost, latency, success rate)
 *
 * Why declarative?
 *   - Strategies become diffable artifacts (commit / review / rollback)
 *   - Backtests answer "if we'd had this strategy a month ago, would it have
 *     handled the 47 incidents better?" without re-running production
 *   - RiskMeasures expose stable scalars dashboards can chart
 *
 * SuperAgent already has DebateConfig / RedTeamConfig / EnsembleConfig that
 * fill similar slots for multi-agent protocols. AgentStrategy generalizes —
 * any agent run, any pattern, any model.
 */
final class AgentStrategy
{
    /**
     * @param string $name       short kebab-case identifier
     * @param string $description human-readable purpose
     * @param list<string> $allowedTools tool names the agent may invoke
     * @param list<string> $deniedTools  tool names the agent must not invoke
     * @param string|null $modelHint  preferred model (CostAutopilot may override)
     * @param int $maxTurns       hard turn limit
     * @param float $maxCostUsd   hard cost ceiling
     * @param array<string,mixed> $stopConditions  declarative early-exit rules
     *                                              (e.g. ['has_phrase' => 'shipped'])
     * @param array<string,mixed> $escalationRules when to escalate to a stronger model
     *                                              (e.g. ['after_turn' => 5, 'to' => 'claude-opus-4-7'])
     * @param array<string,mixed> $metadata        free-form for hosts
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $allowedTools = [],
        public readonly array $deniedTools = [],
        public readonly ?string $modelHint = null,
        public readonly int $maxTurns = 10,
        public readonly float $maxCostUsd = 1.0,
        public readonly array $stopConditions = [],
        public readonly array $escalationRules = [],
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name'             => $this->name,
            'description'      => $this->description,
            'allowed_tools'    => $this->allowedTools,
            'denied_tools'     => $this->deniedTools,
            'model_hint'       => $this->modelHint,
            'max_turns'        => $this->maxTurns,
            'max_cost_usd'     => $this->maxCostUsd,
            'stop_conditions'  => $this->stopConditions,
            'escalation_rules' => $this->escalationRules,
            'metadata'         => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name:             (string) ($data['name'] ?? 'unnamed'),
            description:      (string) ($data['description'] ?? ''),
            allowedTools:     (array) ($data['allowed_tools'] ?? []),
            deniedTools:      (array) ($data['denied_tools'] ?? []),
            modelHint:        $data['model_hint'] ?? null,
            maxTurns:         (int) ($data['max_turns'] ?? 10),
            maxCostUsd:       (float) ($data['max_cost_usd'] ?? 1.0),
            stopConditions:   (array) ($data['stop_conditions'] ?? []),
            escalationRules:  (array) ($data['escalation_rules'] ?? []),
            metadata:         (array) ($data['metadata'] ?? []),
        );
    }

    public static function fromYamlFile(string $path): self
    {
        if (!function_exists('yaml_parse_file')) {
            throw new \RuntimeException('yaml extension required (pecl install yaml). Strategies can also be loaded from JSON via fromJsonFile().');
        }
        $data = \yaml_parse_file($path);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid YAML at {$path}");
        }
        return self::fromArray($data);
    }

    public static function fromJsonFile(string $path): self
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON at {$path}");
        }
        return self::fromArray($data);
    }
}
