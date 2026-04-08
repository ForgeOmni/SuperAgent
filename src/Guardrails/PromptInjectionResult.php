<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

/**
 * Result of a prompt injection scan.
 */
class PromptInjectionResult
{
    public function __construct(
        public readonly bool $hasThreat,
        public readonly array $threats,
        public readonly string $source,
    ) {}

    /**
     * Get threats filtered by severity.
     */
    public function getThreatsAbove(string $minSeverity): array
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        $minLevel = $order[$minSeverity] ?? 0;

        return array_filter($this->threats, function ($threat) use ($order, $minLevel) {
            return ($order[$threat['severity'] ?? 'low'] ?? 0) >= $minLevel;
        });
    }

    /**
     * Get the highest severity level found.
     */
    public function getMaxSeverity(): string
    {
        if (empty($this->threats)) {
            return 'none';
        }

        $order = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        $max = 'low';

        foreach ($this->threats as $threat) {
            $sev = $threat['severity'] ?? 'low';
            if (($order[$sev] ?? 0) > ($order[$max] ?? 0)) {
                $max = $sev;
            }
        }

        return $max;
    }

    /**
     * Get all unique threat categories.
     */
    public function getCategories(): array
    {
        return array_unique(array_column($this->threats, 'category'));
    }

    /**
     * Get a human-readable summary.
     */
    public function getSummary(): string
    {
        if (!$this->hasThreat) {
            return 'No threats detected.';
        }

        $count = count($this->threats);
        $maxSev = $this->getMaxSeverity();
        $categories = implode(', ', $this->getCategories());

        return "{$count} threat(s) detected (max severity: {$maxSev}). Categories: {$categories}. Source: {$this->source}";
    }
}
