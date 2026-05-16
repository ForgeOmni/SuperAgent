<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Persistent per-squad checkpoint: a JSON snapshot of completed steps,
 * captured after every successful step so a crash mid-pipeline can
 * resume without re-running prior work.
 *
 * Deliberately NOT using `Checkpoint\CheckpointManager` directly —
 * that one is shaped around per-turn LLM message arrays, which is
 * the wrong granularity here. A squad checkpoint is "what each step
 * produced", which is much smaller and addressable by squad ID + step.
 *
 * Storage layout (default):
 *   {storageDir}/{squadId}.json
 *
 * The store is intentionally append-mostly per squad — `recordStep()`
 * rewrites the whole file, but since payloads are bounded (one entry
 * per step) the cost is negligible against checkpoint locality and
 * the simplicity of a single JSON read for recovery.
 */
final class SquadCheckpointStore
{
    public function __construct(
        private readonly string $storageDir = '',
    ) {}

    /**
     * Persist a step's outcome. Idempotent on (squadId, stepName).
     */
    public function recordStep(string $squadId, string $stepName, mixed $output, string $status): void
    {
        $path = $this->pathFor($squadId);
        $state = $this->loadFile($path);

        $state['squad_id'] = $squadId;
        $state['updated_at'] = date('c');
        $state['steps'][$stepName] = [
            'output' => $output,
            'status' => $status,
        ];

        $this->writeFile($path, $state);
    }

    /**
     * Load a prior checkpoint for resume. Returns null when none exists.
     *
     * @return array{squad_id: string, updated_at: string, steps: array<string, array{output: mixed, status: string}>}|null
     */
    public function load(string $squadId): ?array
    {
        $path = $this->pathFor($squadId);
        $data = $this->loadFile($path);
        if (empty($data['steps'])) {
            return null;
        }
        return $data;
    }

    public function delete(string $squadId): void
    {
        $path = $this->pathFor($squadId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pathFor(string $squadId): string
    {
        $dir = $this->storageDir !== ''
            ? $this->storageDir
            : sys_get_temp_dir() . '/superagent-squad-checkpoints';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $squadId);
        return $dir . '/' . $safe . '.json';
    }

    /**
     * @return array{squad_id?: string, updated_at?: string, steps?: array<string, array{output: mixed, status: string}>}
     */
    private function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return ['steps' => []];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['steps' => []];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['steps' => []];
    }

    private function writeFile(string $path, array $data): void
    {
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @rename($tmp, $path);
    }
}
