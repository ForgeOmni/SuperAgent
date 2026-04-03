<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

class Comparator
{
    /**
     * Compare an actual value against an expected value using the given operator.
     */
    public static function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'eq' => $actual === $expected,
            'contains' => is_string($actual) && is_string($expected)
                && str_contains(strtolower($actual), strtolower($expected)),
            'starts_with' => is_string($actual) && is_string($expected)
                && str_starts_with($actual, $expected),
            'matches' => is_string($actual) && is_string($expected)
                && fnmatch($expected, $actual),
            'any_of' => is_array($expected) && in_array($actual, $expected, false),
            default => false,
        };
    }

    /**
     * Get the list of supported comparison operators.
     *
     * @return string[]
     */
    public static function supportedOperators(): array
    {
        return ['gt', 'gte', 'lt', 'lte', 'eq', 'contains', 'starts_with', 'matches', 'any_of'];
    }
}
