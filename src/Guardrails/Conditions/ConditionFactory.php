<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use InvalidArgumentException;

/**
 * Parses YAML condition arrays into ConditionInterface trees.
 *
 * Supported top-level keys:
 *   all_of, any_of, not — logical combinators
 *   tool       — tool name matching
 *   tool_content — extracted content matching (file_path, command, etc.)
 *   tool_input — specific input field matching
 *   session    — session-level metrics (cost_usd, budget_pct, elapsed_ms, etc.)
 *   agent      — agent state (turn_count, model, etc.)
 *   token      — token statistics
 *   rate       — sliding window rate limiting
 */
class ConditionFactory
{
    /**
     * Parse a YAML condition config array into a ConditionInterface.
     *
     * If multiple top-level keys are present, they are combined with AND logic.
     */
    public function fromArray(array $config): ConditionInterface
    {
        if (empty($config)) {
            throw new InvalidArgumentException('Condition config must not be empty');
        }

        $conditions = [];

        foreach ($config as $key => $value) {
            $conditions[] = $this->parseKey($key, $value);
        }

        if (count($conditions) === 1) {
            return $conditions[0];
        }

        return new AllOfCondition($conditions);
    }

    private function parseKey(string $key, mixed $value): ConditionInterface
    {
        return match ($key) {
            'all_of' => $this->parseAllOf($value),
            'any_of' => $this->parseAnyOf($value),
            'not' => $this->parseNot($value),
            'tool' => $this->parseTool($value),
            'tool_content' => $this->parseToolContent($value),
            'tool_input' => $this->parseToolInput($value),
            'session' => $this->parseMetricCondition($value, SessionCondition::class),
            'agent' => $this->parseMetricCondition($value, AgentCondition::class),
            'token' => $this->parseMetricCondition($value, TokenCondition::class),
            'rate' => $this->parseRate($value),
            default => throw new InvalidArgumentException("Unknown condition key: '{$key}'"),
        };
    }

    private function parseAllOf(array $items): AllOfCondition
    {
        $conditions = array_map(fn (array $item) => $this->fromArray($item), $items);

        return new AllOfCondition($conditions);
    }

    private function parseAnyOf(array $items): AnyOfCondition
    {
        $conditions = array_map(fn (array $item) => $this->fromArray($item), $items);

        return new AnyOfCondition($conditions);
    }

    private function parseNot(array $config): NotCondition
    {
        return new NotCondition($this->fromArray($config));
    }

    /**
     * Parse tool condition.
     *
     * Formats:
     *   tool: { name: "Bash" }
     *   tool: { name: { any_of: ["Bash", "Read"] } }
     */
    private function parseTool(array $config): ConditionInterface
    {
        if (!isset($config['name'])) {
            throw new InvalidArgumentException("Tool condition requires 'name' key");
        }

        $name = $config['name'];

        if (is_array($name) && isset($name['any_of'])) {
            return new ToolNameCondition($name['any_of']);
        }

        if (is_string($name)) {
            return new ToolNameCondition($name);
        }

        throw new InvalidArgumentException('Tool name must be a string or { any_of: [...] }');
    }

    /**
     * Parse tool_content condition.
     *
     * Format: tool_content: { contains: ".git/" }
     *         tool_content: { starts_with: "/etc" }
     *         tool_content: { matches: "*.env*" }
     */
    private function parseToolContent(array $config): ToolContentCondition
    {
        [$operator, $value] = $this->extractOperatorAndValue($config, 'tool_content');

        return new ToolContentCondition($operator, $value);
    }

    /**
     * Parse tool_input condition.
     *
     * Format: tool_input: { field: "command", matches: "rm -rf *" }
     */
    private function parseToolInput(array $config): ConditionInterface
    {
        if (!isset($config['field'])) {
            throw new InvalidArgumentException("tool_input condition requires 'field' key");
        }

        $field = $config['field'];
        $remaining = $config;
        unset($remaining['field']);

        // Handle starts_with with any_of nested
        if (isset($remaining['starts_with']) && is_array($remaining['starts_with'])) {
            $nested = $remaining['starts_with'];
            if (isset($nested['any_of'])) {
                $conditions = array_map(
                    fn (string $prefix) => new ToolInputCondition($field, 'starts_with', $prefix),
                    $nested['any_of'],
                );

                return new AnyOfCondition($conditions);
            }
        }

        [$operator, $value] = $this->extractOperatorAndValue($remaining, "tool_input[{$field}]");

        return new ToolInputCondition($field, $operator, $value);
    }

    /**
     * Parse metric-style conditions (session, agent, token).
     *
     * Format: session: { cost_usd: { gt: 5.00 } }
     *         agent: { turn_count: { gt: 40 } }
     *
     * @param class-string $conditionClass
     */
    private function parseMetricCondition(array $config, string $conditionClass): ConditionInterface
    {
        $conditions = [];

        foreach ($config as $field => $comparison) {
            if (is_array($comparison)) {
                foreach ($comparison as $operator => $value) {
                    $conditions[] = new $conditionClass($field, $operator, $value);
                }
            } else {
                // Shorthand: field: value → field eq value
                $conditions[] = new $conditionClass($field, 'eq', $comparison);
            }
        }

        if (count($conditions) === 1) {
            return $conditions[0];
        }

        return new AllOfCondition($conditions);
    }

    /**
     * Parse rate condition.
     *
     * Format: rate: { window_seconds: 60, max_calls: 30 }
     */
    private function parseRate(array $config): RateCondition
    {
        if (!isset($config['window_seconds']) || !isset($config['max_calls'])) {
            throw new InvalidArgumentException("Rate condition requires 'window_seconds' and 'max_calls'");
        }

        return new RateCondition(
            windowSeconds: (int) $config['window_seconds'],
            maxCalls: (int) $config['max_calls'],
            toolFilter: $config['tool'] ?? null,
        );
    }

    /**
     * Extract a single operator => value pair from a config array.
     *
     * @return array{0: string, 1: mixed}
     */
    private function extractOperatorAndValue(array $config, string $contextLabel): array
    {
        $operators = Comparator::supportedOperators();

        foreach ($config as $key => $value) {
            if (in_array($key, $operators, true)) {
                return [$key, $value];
            }
        }

        throw new InvalidArgumentException(
            "No valid comparison operator found in {$contextLabel}. "
            . 'Supported: ' . implode(', ', $operators)
        );
    }
}
