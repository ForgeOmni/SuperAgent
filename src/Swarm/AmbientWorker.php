<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Memory\Palace\MemoryDeduplicator;
use SuperAgent\Memory\Palace\PalaceStorage;

/**
 * Long-lived low-priority worker that does background memory hygiene
 * while the user-facing agents are idle. Borrowed from jcode's "ambient
 * mode" — same idea: run dedup / staleness / re-extraction on a tick, so
 * the next user turn doesn't pay for it.
 *
 * Token cost incurred by this worker is tagged `usage_source: 'ambient'`
 * via the `$tagUsage` callback so dashboards can split user-facing vs
 * background spend (e.g. SuperAICore's CostDashboard renders an "ambient"
 * stacked-bar slice without extra plumbing).
 *
 * Lifecycle: hosts construct one per team, call `tick()` from a long-lived
 * loop (cron, swoole worker, react event loop, plain `while (true)
 * sleep`). Each tick walks the configured passes; a pass that finishes
 * its work updates `lastRanAt` so the next tick can space out work.
 *
 * The worker NEVER blocks the agent — every pass yields after its
 * configured budget elapses. That means a pass may complete across many
 * ticks; that's fine, ambient is best-effort.
 */
final class AmbientWorker
{
    public const SOURCE_AMBIENT = 'ambient';

    /** @var array<string, int>  pass name → unix seconds last ran */
    private array $lastRanAt = [];

    /** @var (callable(string $passName, array $stats): void)|null */
    private $tagUsage;

    /**
     * @param array{
     *   dedup_interval_seconds?: int,
     *   stale_check_interval_seconds?: int,
     *   pass_budget_seconds?: int,
     * } $config
     * @param (callable(string $passName, array $stats): void)|null $tagUsage  invoked once per completed pass
     */
    public function __construct(
        private readonly PalaceStorage $storage,
        private readonly MemoryDeduplicator $deduplicator,
        private readonly array $config = [],
        ?callable $tagUsage = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->tagUsage = $tagUsage;
    }

    /**
     * Run one polling tick. Cheap: returns immediately when no pass is
     * due. Hosts call this on a 30s-1m schedule; each pass internally
     * caps its own runtime via `pass_budget_seconds`.
     *
     * @return array<string, array{ran:bool, stats?:array}>  per-pass outcome
     */
    public function tick(): array
    {
        $report = [];
        $now = time();

        foreach ($this->scheduledPasses() as $pass => $intervalSeconds) {
            if (($now - ($this->lastRanAt[$pass] ?? 0)) < $intervalSeconds) {
                $report[$pass] = ['ran' => false];
                continue;
            }
            $stats = $this->runPass($pass);
            $this->lastRanAt[$pass] = $now;
            $report[$pass] = ['ran' => true, 'stats' => $stats];
            if ($this->tagUsage !== null) {
                ($this->tagUsage)($pass, $stats);
            }
        }
        return $report;
    }

    /**
     * @return array<string, int>  pass name → interval in seconds
     */
    private function scheduledPasses(): array
    {
        return [
            'dedup'         => (int) ($this->config['dedup_interval_seconds']         ?? 600),  // 10 min
            'stale_review'  => (int) ($this->config['stale_check_interval_seconds']   ?? 3600), // 1 hour
        ];
    }

    /** @return array<string, mixed> */
    private function runPass(string $pass): array
    {
        $budget = (int) ($this->config['pass_budget_seconds'] ?? 5);
        $deadline = microtime(true) + $budget;

        return match ($pass) {
            'dedup'        => $this->dedupPass($deadline),
            'stale_review' => $this->stalenessPass($deadline),
            default        => ['skipped' => 'unknown_pass'],
        };
    }

    /**
     * Sweep the palace storage looking for near-duplicate drawers and
     * mark them with `metadata.ambient_dedup_marked = true`. Doesn't
     * delete — humans curate the actual removal — but the marker lets
     * the next retrieval-time filter skip them.
     */
    private function dedupPass(float $deadline): array
    {
        $checked = 0;
        $duplicates = 0;
        try {
            foreach ($this->storage->iterateDrawers(null, null, null) as $drawer) {
                $checked++;
                if ($this->deduplicator->isDuplicate($drawer)) {
                    $duplicates++;
                    // Tag the drawer in storage when the API supports it; otherwise just count.
                    if (method_exists($this->storage, 'tagDrawer')) {
                        try {
                            $this->storage->tagDrawer($drawer->id, ['ambient_dedup_marked' => true]);
                        } catch (\Throwable $e) {
                            $this->logger->debug('AmbientWorker: tagDrawer failed: ' . $e->getMessage());
                        }
                    }
                }
                if (microtime(true) >= $deadline) break;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AmbientWorker dedup pass failed: ' . $e->getMessage());
        }
        return ['checked' => $checked, 'duplicates' => $duplicates];
    }

    /**
     * Light-touch staleness scan — flag drawers whose `metadata.expires_at`
     * has passed. Hosts that don't use the field pay nothing extra.
     */
    private function stalenessPass(float $deadline): array
    {
        $checked = 0;
        $stale = 0;
        $now = time();
        try {
            foreach ($this->storage->iterateDrawers(null, null, null) as $drawer) {
                $checked++;
                $expiresAt = $drawer->metadata['expires_at'] ?? null;
                if (is_string($expiresAt)) {
                    $ts = strtotime($expiresAt);
                    if ($ts !== false && $ts < $now) {
                        $stale++;
                        if (method_exists($this->storage, 'tagDrawer')) {
                            try {
                                $this->storage->tagDrawer($drawer->id, ['ambient_stale_marked' => true]);
                            } catch (\Throwable) {
                                // best effort
                            }
                        }
                    }
                }
                if (microtime(true) >= $deadline) break;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AmbientWorker stale pass failed: ' . $e->getMessage());
        }
        return ['checked' => $checked, 'stale' => $stale];
    }

    /** @return array<string,int>  pass name → seconds since last run */
    public function ages(): array
    {
        $ages = [];
        $now = time();
        foreach ($this->lastRanAt as $pass => $at) {
            $ages[$pass] = $now - $at;
        }
        return $ages;
    }
}
