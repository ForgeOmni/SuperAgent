<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

/**
 * Tracks cumulative spending across sessions with daily/monthly periods.
 *
 * Persists to a JSON file in the project storage directory so spending
 * data survives across agent sessions and process restarts.
 *
 * Storage format:
 *   {
 *     "daily": {"2026-04-03": 1.25, "2026-04-02": 3.40},
 *     "monthly": {"2026-04": 4.65, "2026-03": 89.20},
 *     "total": 93.85,
 *     "last_updated": "2026-04-03T10:30:00+00:00"
 *   }
 */
class BudgetTracker
{
    private array $data;

    private ?string $storagePath;

    /** The last session cost recorded (for delta tracking) */
    private float $lastRecordedSessionCost = 0.0;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath;
        $this->data = $this->load();
    }

    /**
     * Record spending for the current session.
     *
     * Called with cumulative session cost — only the delta since the last
     * call is added to the persistent tracker.
     */
    public function recordSpend(float $sessionCostUsd): void
    {
        $delta = $sessionCostUsd - $this->lastRecordedSessionCost;

        if ($delta <= 0) {
            return;
        }

        $this->lastRecordedSessionCost = $sessionCostUsd;

        $today = date('Y-m-d');
        $month = date('Y-m');

        $this->data['daily'][$today] = ($this->data['daily'][$today] ?? 0.0) + $delta;
        $this->data['monthly'][$month] = ($this->data['monthly'][$month] ?? 0.0) + $delta;
        $this->data['total'] = ($this->data['total'] ?? 0.0) + $delta;
        $this->data['last_updated'] = date('c');

        $this->save();
    }

    /**
     * Get total spending for the current month.
     */
    public function getMonthlySpend(): float
    {
        $month = date('Y-m');

        return $this->data['monthly'][$month] ?? 0.0;
    }

    /**
     * Get total spending for today.
     */
    public function getDailySpend(): float
    {
        $today = date('Y-m-d');

        return $this->data['daily'][$today] ?? 0.0;
    }

    /**
     * Get all-time total spending.
     */
    public function getTotalSpend(): float
    {
        return $this->data['total'] ?? 0.0;
    }

    /**
     * Get spending for a specific month.
     */
    public function getSpendForMonth(string $yearMonth): float
    {
        return $this->data['monthly'][$yearMonth] ?? 0.0;
    }

    /**
     * Get spending for a specific date.
     */
    public function getSpendForDate(string $date): float
    {
        return $this->data['daily'][$date] ?? 0.0;
    }

    /**
     * Get a summary of spending data.
     *
     * @return array{today: float, this_month: float, total: float, last_updated: string|null}
     */
    public function getSummary(): array
    {
        return [
            'today' => $this->getDailySpend(),
            'this_month' => $this->getMonthlySpend(),
            'total' => $this->getTotalSpend(),
            'last_updated' => $this->data['last_updated'] ?? null,
        ];
    }

    /**
     * Prune old daily entries (keep last N days).
     */
    public function pruneDaily(int $keepDays = 90): void
    {
        $cutoff = date('Y-m-d', strtotime("-{$keepDays} days"));

        foreach ($this->data['daily'] ?? [] as $date => $amount) {
            if ($date < $cutoff) {
                unset($this->data['daily'][$date]);
            }
        }

        $this->save();
    }

    /**
     * Prune old monthly entries (keep last N months).
     */
    public function pruneMonthly(int $keepMonths = 12): void
    {
        $cutoff = date('Y-m', strtotime("-{$keepMonths} months"));

        foreach ($this->data['monthly'] ?? [] as $month => $amount) {
            if ($month < $cutoff) {
                unset($this->data['monthly'][$month]);
            }
        }

        $this->save();
    }

    /**
     * Reset all tracking data.
     */
    public function reset(): void
    {
        $this->data = ['daily' => [], 'monthly' => [], 'total' => 0.0];
        $this->lastRecordedSessionCost = 0.0;
        $this->save();
    }

    /**
     * Load data from storage.
     */
    private function load(): array
    {
        if ($this->storagePath === null || !file_exists($this->storagePath)) {
            return ['daily' => [], 'monthly' => [], 'total' => 0.0];
        }

        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return ['daily' => [], 'monthly' => [], 'total' => 0.0];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return ['daily' => [], 'monthly' => [], 'total' => 0.0];
        }

        return $data;
    }

    /**
     * Save data to storage.
     */
    private function save(): void
    {
        if ($this->storagePath === null) {
            return;
        }

        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->storagePath,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
