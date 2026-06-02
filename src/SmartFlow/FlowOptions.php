<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

/**
 * Per-run knobs for {@see FlowEngine::run()}.
 *
 * - rehearse:    use the deterministic zero-cost fake provider ("演练").
 * - dryRun:      like rehearse but keeps the ledger in memory (no file written).
 * - resumeRunId: load a prior run's ledger and replay its unchanged prefix.
 * - concurrency: max simultaneous parallel workers (process pool, Phase 3).
 * - budgetUsd / budgetTokens: hard ceilings enforced by {@see Budget}.
 */
final class FlowOptions
{
    /**
     * @param (callable(list<AgentCall>): list<AgentResult>)|null $batchRunner
     * @param array<string, list<callable>> $listeners
     */
    public function __construct(
        public bool $rehearse = false,
        public bool $dryRun = false,
        public ?string $resumeRunId = null,
        public ?int $concurrency = null,
        public ?float $budgetUsd = null,
        public ?int $budgetTokens = null,
        public ?string $defaultProvider = null,
        public ?string $defaultModel = null,
        public ?string $runId = null,
        public ?string $ledgerDir = null,
        public mixed $batchRunner = null,
        public array $listeners = [],
    ) {}

    public function isFake(): bool
    {
        if ($this->rehearse || $this->dryRun) {
            return true;
        }
        $env = getenv('MULTI_AI_FAKE_PROVIDER');
        return $env === '1' || $env === 'true';
    }
}
