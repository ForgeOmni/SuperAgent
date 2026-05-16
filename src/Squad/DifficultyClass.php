<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Five-band difficulty taxonomy for sub-tasks inside a Squad workflow.
 *
 * The bands are deliberately coarse — they map onto a small set of
 * (provider, model) tiers in `ModelTierMap`, so two scores landing in
 * the same band always run on the same model. Adding a sixth band
 * would force every default tier map to grow as well, which is more
 * brittle than useful.
 *
 * Bands correspond roughly to:
 *
 *   TRIVIAL  — single-shot extraction, formatting, file-read prompts
 *   EASY     — pattern-matched code edits, doc lookups, simple Q&A
 *   MODERATE — multi-file reads, mechanical refactors, summaries
 *   HARD     — architecture decisions, root-cause analysis, novel code
 *   EXPERT   — security audits, performance tuning, system design
 */
enum DifficultyClass: string
{
    case TRIVIAL = 'trivial';
    case EASY = 'easy';
    case MODERATE = 'moderate';
    case HARD = 'hard';
    case EXPERT = 'expert';

    /**
     * Map a 0..1 complexity score into a band.
     *
     * The thresholds line up with `SmartContext\TaskComplexity` so a
     * "complex enough to deserve deep thinking" prompt always lands
     * in HARD or EXPERT.
     */
    public static function fromScore(float $score): self
    {
        $score = max(0.0, min(1.0, $score));

        return match (true) {
            $score >= 0.85 => self::EXPERT,
            $score >= 0.70 => self::HARD,
            $score >= 0.45 => self::MODERATE,
            $score >= 0.25 => self::EASY,
            default => self::TRIVIAL,
        };
    }

    /**
     * Whether this band warrants a Human-in-the-Loop checkpoint by
     * default (callers can still override per-subtask).
     */
    public function defaultRequiresReview(): bool
    {
        return $this === self::HARD || $this === self::EXPERT;
    }
}
