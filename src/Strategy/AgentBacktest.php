<?php

declare(strict_types=1);

namespace SuperAgent\Strategy;

/**
 * Replay a Strategy against a corpus of historical transcripts and score it.
 *
 * gs-quant runs strategies against historical price series; we run agent
 * strategies against historical conversation transcripts (read from
 * `~/.claude/projects/<hash>/<uuid>.jsonl` and equivalents, or from
 * the SuperAICore ai_usage_logs export).
 *
 * Backtest does NOT call live LLMs by default — it uses recorded final
 * responses + tool calls to compute risk measures. Set `replay_mode: 'live'`
 * if you actually want to re-run each prompt against the new strategy
 * (expensive — typically for regression on a small N).
 *
 * Output: a BacktestResult carrying per-run + aggregate risk measures so the
 * operator can answer "is the new strategy better than the old one?"
 */
final class AgentBacktest
{
    /**
     * @param list<array<string,mixed>> $transcripts each entry is one historical run
     *      record carrying at minimum: prompt, final_response, tool_calls[],
     *      cost_usd, latency_ms, turn_count, escalations[]
     * @param list<string> $measures risk measure names to compute (see AgentRiskMeasure::list())
     */
    public function __construct(
        public readonly AgentStrategy $strategy,
        public readonly array $transcripts,
        public readonly array $measures = ['cost_usd', 'latency_ms', 'turn_count', 'tool_diversity'],
        public readonly bool $liveReplay = false,
    ) {}

    /**
     * @return array{
     *     strategy: string,
     *     transcript_count: int,
     *     aggregate: array<string, array{mean: float, median: float, p95: float, total: float}>,
     *     per_run: list<array<string,mixed>>,
     * }
     */
    public function run(): array
    {
        if ($this->liveReplay) {
            throw new \RuntimeException(
                'Live replay not implemented in this skeleton — wire a callable '
              . 'via AgentBacktest::useReplayRunner(callable) to drive an Agent loop '
              . 'against each transcript prompt under the new strategy.'
            );
        }

        $perRun = [];
        $measureValues = array_fill_keys($this->measures, []);

        foreach ($this->transcripts as $idx => $tx) {
            $row = ['index' => $idx];
            foreach ($this->measures as $m) {
                try {
                    $val = AgentRiskMeasure::compute($m, $tx);
                } catch (\Throwable $e) {
                    $val = 0.0;
                }
                $row[$m] = $val;
                $measureValues[$m][] = $val;
            }
            $perRun[] = $row;
        }

        $aggregate = [];
        foreach ($measureValues as $m => $vals) {
            $aggregate[$m] = $this->summarize($vals);
        }

        return [
            'strategy'         => $this->strategy->name,
            'transcript_count' => count($this->transcripts),
            'aggregate'        => $aggregate,
            'per_run'          => $perRun,
        ];
    }

    /**
     * Compare two backtest results — produces a delta table the operator can
     * use to say "strategy B beats A on cost by 18% with no regression in
     * success rate".
     */
    public static function compare(array $a, array $b): array
    {
        $delta = [];
        foreach ($a['aggregate'] as $measure => $aStats) {
            $bStats = $b['aggregate'][$measure] ?? null;
            if (!$bStats) continue;
            $delta[$measure] = [
                'a_mean'    => $aStats['mean'],
                'b_mean'    => $bStats['mean'],
                'delta_pct' => $aStats['mean'] != 0
                    ? ($bStats['mean'] - $aStats['mean']) / $aStats['mean'] * 100
                    : null,
            ];
        }
        return [
            'a_strategy' => $a['strategy'],
            'b_strategy' => $b['strategy'],
            'delta'      => $delta,
        ];
    }

    /**
     * @param list<float> $vals
     */
    private function summarize(array $vals): array
    {
        if (empty($vals)) {
            return ['mean' => 0.0, 'median' => 0.0, 'p95' => 0.0, 'total' => 0.0];
        }
        sort($vals);
        $n = count($vals);
        $total = array_sum($vals);
        return [
            'mean'   => $total / $n,
            'median' => $vals[(int) ($n / 2)],
            'p95'    => $vals[(int) ($n * 0.95)] ?? $vals[$n - 1],
            'total'  => $total,
        ];
    }
}
