<?php

declare(strict_types=1);

namespace SuperAgent\Security;

/**
 * Daily spend cap for tools declaring the `cost` attribute.
 *
 * Persists a tiny ledger at `~/.superagent/cost_ledger.json` keyed by
 * UTC date + tool name. The ledger is intentionally simple:
 *
 *     {
 *       "schema": 1,
 *       "date":   "2026-04-21",
 *       "spend":  {
 *         "minimax_video": 4.20,
 *         "minimax_tts":   0.15
 *       }
 *     }
 *
 * On a new UTC day the ledger resets automatically — this avoids the
 * user having to manage rollover and works fine for "don't let a runaway
 * agent loop burn my card" use cases. A future multi-day ledger can
 * layer on without changing the caller interface.
 *
 * Limits resolve with this precedence:
 *   1. `$options['per_call_usd']`        — hard cap on this single call
 *   2. `per_tool_daily_usd[<toolName>]`  — per-tool daily cap
 *   3. `global_daily_usd`                — cap across all cost-tagged tools
 *   4. unlimited                         — no cap set
 *
 * `check(tool, requested_usd)` returns `allow` / `ask` / `deny` without
 * writing to the ledger. Use `record(tool, usd)` after the call actually
 * runs so failed/cancelled calls don't count against the budget.
 */
final class CostLimiter
{
    /**
     * @param array<string, mixed> $options
     *   [
     *     'global_daily_usd'      => 10.0,
     *     'per_tool_daily_usd'    => ['minimax_video' => 5.0],
     *     'per_call_usd'          => 2.0,
     *     'ask_threshold_usd'     => 1.0,   // above → 'ask' verdict
     *     'ledger_path'           => null,  // override for tests
     *   ]
     */
    public function __construct(
        private readonly array $options = [],
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public static function ledgerPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/cost_ledger.json';
    }

    /**
     * Does this tool need a cost check at all?
     *
     * @param array<int, string> $attributes
     */
    public function isMetered(array $attributes): bool
    {
        return in_array('cost', $attributes, true);
    }

    /**
     * Ask whether `$requestedUsd` is within the caller's limits.
     *
     * @param array<int, string> $attributes Tool attributes (must contain 'cost' to be checked).
     */
    public function check(string $toolName, array $attributes, float $requestedUsd): SecurityDecision
    {
        if (! $this->isMetered($attributes)) {
            return SecurityDecision::allow();
        }

        // Per-call hard cap wins — a single call that's already over
        // budget is rejected regardless of the daily state.
        $perCallCap = isset($this->options['per_call_usd'])
            ? (float) $this->options['per_call_usd']
            : null;
        if ($perCallCap !== null && $requestedUsd > $perCallCap) {
            return SecurityDecision::deny(
                sprintf('cost $%.4f exceeds per-call cap $%.4f', $requestedUsd, $perCallCap),
                ['rule' => 'per_call_usd', 'tool' => $toolName],
            );
        }

        $ledger = $this->readLedger();
        $spentToday = (float) ($ledger['spend'][$toolName] ?? 0.0);
        $spentTotalToday = array_sum(array_map('floatval', $ledger['spend'] ?? []));

        $perToolCap = $this->options['per_tool_daily_usd'][$toolName] ?? null;
        if ($perToolCap !== null && ($spentToday + $requestedUsd) > (float) $perToolCap) {
            return SecurityDecision::deny(
                sprintf(
                    'tool %s would exceed daily cap: spent $%.4f + $%.4f > $%.4f',
                    $toolName,
                    $spentToday,
                    $requestedUsd,
                    (float) $perToolCap,
                ),
                ['rule' => 'per_tool_daily_usd', 'tool' => $toolName],
            );
        }

        $globalCap = isset($this->options['global_daily_usd'])
            ? (float) $this->options['global_daily_usd']
            : null;
        if ($globalCap !== null && ($spentTotalToday + $requestedUsd) > $globalCap) {
            return SecurityDecision::deny(
                sprintf(
                    'global daily spend would be exceeded: $%.4f + $%.4f > $%.4f',
                    $spentTotalToday,
                    $requestedUsd,
                    $globalCap,
                ),
                ['rule' => 'global_daily_usd', 'tool' => $toolName],
            );
        }

        $askThreshold = isset($this->options['ask_threshold_usd'])
            ? (float) $this->options['ask_threshold_usd']
            : null;
        if ($askThreshold !== null && $requestedUsd > $askThreshold) {
            return SecurityDecision::ask(
                sprintf('cost $%.4f exceeds ask threshold $%.4f', $requestedUsd, $askThreshold),
                ['rule' => 'ask_threshold_usd', 'tool' => $toolName],
            );
        }

        return SecurityDecision::allow();
    }

    /**
     * Post-call: log actual spend. Call this only when the tool succeeded
     * — a failed call shouldn't burn the budget.
     */
    public function record(string $toolName, float $usd): void
    {
        if ($usd <= 0.0) {
            return;
        }
        $ledger = $this->readLedger();
        $ledger['spend'][$toolName] = ((float) ($ledger['spend'][$toolName] ?? 0.0)) + $usd;
        $this->writeLedger($ledger);
    }

    /**
     * @return array{schema: int, date: string, spend: array<string, float>}
     */
    public function snapshot(): array
    {
        return $this->readLedger();
    }

    /**
     * Drop today's spend — convenience for tests and for users who want
     * to clear the cap after investigating a near-miss.
     */
    public function reset(): void
    {
        $this->writeLedger(['schema' => 1, 'date' => self::today(), 'spend' => []]);
    }

    /**
     * @return array{schema: int, date: string, spend: array<string, float>}
     */
    private function readLedger(): array
    {
        $path = $this->path();
        $today = self::today();
        $default = ['schema' => 1, 'date' => $today, 'spend' => []];

        if (! is_file($path)) {
            return $default;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $default;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1) {
            return $default;
        }
        // Auto-rollover on date mismatch.
        if (($data['date'] ?? null) !== $today) {
            return $default;
        }
        if (! isset($data['spend']) || ! is_array($data['spend'])) {
            $data['spend'] = [];
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeLedger(array $data): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;  // silent: cost tracking shouldn't fail the tool call
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") === false) {
            return;
        }
        @rename($tmp, $path);
        @chmod($path, 0600);  // ledger is private
    }

    private function path(): string
    {
        return (string) ($this->options['ledger_path'] ?? self::ledgerPath());
    }

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }
}
