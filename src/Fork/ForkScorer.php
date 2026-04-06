<?php

declare(strict_types=1);

namespace SuperAgent\Fork;

final class ForkScorer
{
    /**
     * Lower cost = higher score.
     */
    public static function costEfficiency(ForkBranch $branch): float
    {
        if ($branch->cost === null || $branch->cost <= 0) {
            return 1.0;
        }
        return 1.0 / $branch->cost;
    }

    /**
     * More tool calls completed (from result messages) = higher score.
     */
    public static function completeness(ForkBranch $branch): float
    {
        if ($branch->resultMessages === null) {
            return 0.0;
        }

        $toolUseCount = 0;
        foreach ($branch->resultMessages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : '';
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                        $toolUseCount++;
                    }
                }
            }
        }

        return (float) $toolUseCount;
    }

    /**
     * Fewer turns = higher score.
     */
    public static function brevity(ForkBranch $branch): float
    {
        if ($branch->turns === null || $branch->turns <= 0) {
            return 1.0;
        }
        return 1.0 / $branch->turns;
    }

    /**
     * Weighted combination of multiple scorers.
     *
     * @param callable[] $scorers Array of scorer callables
     * @param float[] $weights Corresponding weights
     */
    public static function composite(array $scorers, array $weights): callable
    {
        return function (ForkBranch $branch) use ($scorers, $weights): float {
            $totalWeight = array_sum($weights);
            if ($totalWeight <= 0) {
                return 0.0;
            }

            $score = 0.0;
            foreach ($scorers as $i => $scorer) {
                $weight = $weights[$i] ?? 0.0;
                $score += $scorer($branch) * $weight;
            }

            return $score / $totalWeight;
        };
    }

    /**
     * Wrap a user-defined scoring function.
     */
    public static function custom(callable $fn): callable
    {
        return $fn;
    }
}
