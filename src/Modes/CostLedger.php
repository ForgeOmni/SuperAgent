<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

/**
 * Append-mostly ledger tracking every mode-level dispatch's cost in
 * a cross-mode run. Shared by reference inside `ModeContext`, so a
 * child mode invocation contributes to the same running total the
 * parent reads back when it resumes.
 *
 * Each `record()` call appends one entry; `total()` returns the
 * running sum without re-walking. `byMode()` buckets the entries so
 * an envelope can render "auto: $0.02, smart: $0.18, squad: $0.31".
 */
final class CostLedger
{
    /** @var list<array{mode:string, step:?string, cost_usd:float, model:?string}> */
    private array $entries = [];

    private float $total = 0.0;

    public function record(string $mode, float $costUsd, ?string $step = null, ?string $model = null): void
    {
        $this->entries[] = [
            'mode'     => $mode,
            'step'     => $step,
            'cost_usd' => $costUsd,
            'model'    => $model,
        ];
        $this->total += $costUsd;
    }

    public function total(): float
    {
        return $this->total;
    }

    /**
     * @return array<string, float>
     */
    public function byMode(): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            $out[$e['mode']] = ($out[$e['mode']] ?? 0.0) + $e['cost_usd'];
        }
        return $out;
    }

    /**
     * @return list<array{mode:string, step:?string, cost_usd:float, model:?string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
