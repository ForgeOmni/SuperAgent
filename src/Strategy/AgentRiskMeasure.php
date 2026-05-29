<?php

declare(strict_types=1);

namespace SuperAgent\Strategy;

/**
 * Observable scalar property of an agent run (or strategy backtest).
 *
 * gs-quant defines risk measures as named functions you apply to a position;
 * we apply them to a run record (an array carrying turn count, cost, tools
 * used, final response, latency, etc.).
 *
 * Built-in measures:
 *   - `cost_usd`         — total dollars spent
 *   - `latency_ms`       — wall-clock duration
 *   - `turn_count`       — how many agent turns
 *   - `tool_diversity`   — unique tools invoked / total tool calls
 *   - `success_rate`     — over a backtest, % of runs that hit success criteria
 *   - `escalation_count` — how often the strategy escalated to a stronger model
 *
 * Hosts register custom measures by name → callable.
 */
final class AgentRiskMeasure
{
    /** @var array<string, callable(array): float> */
    private static array $registry = [];

    public static function register(string $name, callable $compute): void
    {
        self::$registry[$name] = $compute;
    }

    public static function compute(string $name, array $runRecord): float
    {
        $fn = self::$registry[$name] ?? self::builtin($name);
        if ($fn === null) {
            throw new \InvalidArgumentException("Unknown risk measure: {$name}");
        }
        return (float) $fn($runRecord);
    }

    public static function list(): array
    {
        return array_unique(array_merge(
            array_keys(self::$registry),
            ['cost_usd', 'latency_ms', 'turn_count', 'tool_diversity', 'escalation_count'],
        ));
    }

    private static function builtin(string $name): ?callable
    {
        return match ($name) {
            'cost_usd'         => fn(array $r) => (float) ($r['cost_usd'] ?? 0),
            'latency_ms'       => fn(array $r) => (float) ($r['latency_ms'] ?? 0),
            'turn_count'       => fn(array $r) => (float) ($r['turn_count'] ?? 0),
            'tool_diversity'   => fn(array $r) => self::computeToolDiversity($r),
            'escalation_count' => fn(array $r) => (float) (count($r['escalations'] ?? [])),
            default            => null,
        };
    }

    private static function computeToolDiversity(array $run): float
    {
        $calls = $run['tool_calls'] ?? [];
        if (empty($calls)) return 0.0;
        $names = array_map(fn($c) => $c['name'] ?? '?', $calls);
        return count(array_unique($names)) / max(count($names), 1);
    }
}
