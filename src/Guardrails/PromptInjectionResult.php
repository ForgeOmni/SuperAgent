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
     * How strong the signal is, from 0.0 (nothing) to 1.0 (several critical
     * matches) — the "annotate" reading of a scan.
     *
     * A boolean invites a host to treat a pattern list as a gate. It is not
     * one: it is English-and-friends regexes against text an attacker writes,
     * so it will miss things, and it will fire on an innocent order note that
     * happens to say "ignore the previous instructions, deliver to the back
     * door". A score lets a host route the middle of that range to a human
     * instead of choosing between blocking and ignoring.
     *
     * @since 1.2.0
     */
    public function score(): float
    {
        if ($this->threats === []) {
            return 0.0;
        }

        $weights = ['low' => 0.1, 'medium' => 0.25, 'high' => 0.5, 'critical' => 0.8];
        $score = 0.0;

        foreach ($this->threats as $threat) {
            $score += $weights[$threat['severity'] ?? 'low'] ?? 0.1;
        }

        return round(min(1.0, $score), 3);
    }

    /**
     * Matches per category, for a host that logs what was noticed rather than
     * acting on it.
     *
     * @return array<string,int>
     *
     * @since 1.2.0
     */
    public function categoryCounts(): array
    {
        $counts = [];

        foreach ($this->threats as $threat) {
            $category = $threat['category'] ?? 'unknown';
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Which pattern packs produced the matches.
     *
     * @return list<string>
     *
     * @since 1.2.0
     */
    public function languages(): array
    {
        return array_values(array_unique(array_filter(
            array_column($this->threats, 'language')
        )));
    }

    /** @return array<string,mixed> @since 1.2.0 */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'has_threat' => $this->hasThreat,
            'score' => $this->score(),
            'max_severity' => $this->getMaxSeverity(),
            'categories' => $this->categoryCounts(),
            'languages' => $this->languages(),
        ];
    }

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
