<?php

declare(strict_types=1);

namespace SuperAgent\CostPrediction;

use RuntimeException;

final class CostHistoryStore
{
    private array $cache = [];

    public function __construct(
        private readonly string $storageDir,
    ) {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Record an actual execution result.
     */
    public function record(string $taskHash, string $model, array $metrics): void
    {
        $data = $this->loadModelData($model);

        if (!isset($data[$taskHash])) {
            $data[$taskHash] = [];
        }

        $data[$taskHash][] = array_merge($metrics, [
            'recorded_at' => date('c'),
            'timestamp' => time(),
        ]);

        // Keep only last 50 records per hash
        if (count($data[$taskHash]) > 50) {
            $data[$taskHash] = array_slice($data[$taskHash], -50);
        }

        $this->saveModelData($model, $data);
    }

    /**
     * Find similar historical executions.
     */
    public function findSimilar(string $taskHash, string $model, int $limit = 10): array
    {
        $data = $this->loadModelData($model);
        $records = $data[$taskHash] ?? [];

        // Sort by timestamp descending (newest first)
        usort($records, fn(array $a, array $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        return array_slice($records, 0, $limit);
    }

    /**
     * Get average metrics for a task type across all hashes.
     */
    public function getAverageForType(string $taskType, string $model): ?array
    {
        $data = $this->loadModelData($model);

        $allRecords = [];
        foreach ($data as $records) {
            foreach ($records as $record) {
                if (($record['task_type'] ?? '') === $taskType) {
                    $allRecords[] = $record;
                }
            }
        }

        if (empty($allRecords)) {
            return null;
        }

        $count = count($allRecords);
        return [
            'avg_cost' => array_sum(array_column($allRecords, 'cost')) / $count,
            'avg_tokens' => (int) (array_sum(array_column($allRecords, 'tokens')) / $count),
            'avg_turns' => (int) (array_sum(array_column($allRecords, 'turns')) / $count),
            'avg_duration_ms' => array_sum(array_column($allRecords, 'duration_ms')) / $count,
            'sample_size' => $count,
        ];
    }

    /**
     * Get overall statistics.
     */
    public function getStats(): array
    {
        $files = glob($this->storageDir . '/*.json');
        $totalRecords = 0;
        $models = [];

        foreach ($files ?: [] as $file) {
            $model = pathinfo($file, PATHINFO_FILENAME);
            $models[] = $model;
            $data = $this->loadModelData($model);
            foreach ($data as $records) {
                $totalRecords += count($records);
            }
        }

        return [
            'total_records' => $totalRecords,
            'models' => $models,
            'storage_dir' => $this->storageDir,
        ];
    }

    /**
     * Remove records older than maxAgeDays.
     */
    public function prune(int $maxAgeDays = 90): int
    {
        $cutoff = time() - ($maxAgeDays * 86400);
        $pruned = 0;
        $files = glob($this->storageDir . '/*.json');

        foreach ($files ?: [] as $file) {
            $model = pathinfo($file, PATHINFO_FILENAME);
            $data = $this->loadModelData($model);
            $changed = false;

            foreach ($data as $hash => &$records) {
                $original = count($records);
                $records = array_filter(
                    $records,
                    fn(array $r) => ($r['timestamp'] ?? 0) >= $cutoff,
                );
                $removed = $original - count($records);
                $pruned += $removed;
                if ($removed > 0) {
                    $changed = true;
                }
                if (empty($records)) {
                    unset($data[$hash]);
                }
            }
            unset($records);

            if ($changed) {
                $this->saveModelData($model, $data);
            }
        }

        return $pruned;
    }

    private function loadModelData(string $model): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $model);

        if (isset($this->cache[$safe])) {
            return $this->cache[$safe];
        }

        $file = $this->storageDir . '/' . $safe . '.json';

        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true) ?? [];
        $this->cache[$safe] = $data;

        return $data;
    }

    private function saveModelData(string $model, array $data): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $model);
        $file = $this->storageDir . '/' . $safe . '.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->cache[$safe] = $data;
    }
}
