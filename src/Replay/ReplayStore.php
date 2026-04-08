<?php

declare(strict_types=1);

namespace SuperAgent\Replay;

use RuntimeException;

final class ReplayStore
{
    public function __construct(
        private readonly string $storageDir,
    ) {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Save a trace as an NDJSON file.
     */
    public function save(ReplayTrace $trace): void
    {
        $filePath = $this->getFilePath($trace->sessionId);
        $handle = fopen($filePath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot open replay file for writing: {$filePath}");
        }

        // First line: trace metadata
        fwrite($handle, json_encode($trace->toArray(), JSON_THROW_ON_ERROR) . "\n");

        // Subsequent lines: one event per line
        foreach ($trace->events as $event) {
            fwrite($handle, json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n");
        }

        fclose($handle);
    }

    /**
     * Load a trace from an NDJSON file.
     */
    public function load(string $sessionId): ReplayTrace
    {
        $filePath = $this->getFilePath($sessionId);

        if (!file_exists($filePath)) {
            throw new RuntimeException("Replay trace not found: {$sessionId}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open replay file: {$filePath}");
        }

        // First line: metadata
        $metaLine = fgets($handle);
        if ($metaLine === false) {
            fclose($handle);
            throw new RuntimeException("Empty replay file: {$sessionId}");
        }

        $meta = json_decode(trim($metaLine), true, 512, JSON_THROW_ON_ERROR);
        $events = [];

        // Read events line by line
        $lineNum = 1;
        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $eventData = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            // Validate required fields
            if (!$this->validateEventSchema($eventData, $lineNum)) {
                continue; // Skip malformed events
            }

            $events[] = ReplayEvent::fromArray($eventData);
        }

        fclose($handle);

        return ReplayTrace::fromArray($meta, $events);
    }

    /**
     * List available replay sessions.
     */
    public function list(int $limit = 20, int $offset = 0): array
    {
        $files = glob($this->storageDir . '/*.ndjson');
        if ($files === false) {
            return [];
        }

        // Sort by modification time, newest first
        usort($files, fn(string $a, string $b) => filemtime($b) - filemtime($a));

        $sessions = [];
        $sliced = array_slice($files, $offset, $limit);

        foreach ($sliced as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            $metaLine = fgets($handle);
            fclose($handle);

            if ($metaLine === false) {
                continue;
            }

            $meta = json_decode(trim($metaLine), true);
            if ($meta !== null) {
                $meta['file_size'] = filesize($file);
                $sessions[] = $meta;
            }
        }

        return $sessions;
    }

    /**
     * Delete a replay trace.
     */
    public function delete(string $sessionId): bool
    {
        $filePath = $this->getFilePath($sessionId);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Delete traces older than the given number of days.
     */
    public function prune(int $maxAgeDays = 30): int
    {
        $cutoff = time() - ($maxAgeDays * 86400);
        $files = glob($this->storageDir . '/*.ndjson');
        $pruned = 0;

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    public function exists(string $sessionId): bool
    {
        return file_exists($this->getFilePath($sessionId));
    }

    /**
     * Validate that an event has all required fields and correct types.
     */
    private function validateEventSchema(array $data, int $lineNum): bool
    {
        $requiredFields = ['step', 'type', 'agent_id', 'timestamp', 'duration_ms'];
        $validTypes = [
            ReplayEvent::TYPE_LLM_CALL,
            ReplayEvent::TYPE_TOOL_CALL,
            ReplayEvent::TYPE_AGENT_SPAWN,
            ReplayEvent::TYPE_AGENT_MESSAGE,
            ReplayEvent::TYPE_STATE_SNAPSHOT,
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {
                error_log("[SuperAgent] ReplayStore: missing field '{$field}' at line {$lineNum}, skipping event");
                return false;
            }
        }

        if (!is_int($data['step']) || $data['step'] < 0) {
            error_log("[SuperAgent] ReplayStore: invalid 'step' value at line {$lineNum}");
            return false;
        }

        if (!in_array($data['type'], $validTypes, true)) {
            error_log("[SuperAgent] ReplayStore: unknown event type '{$data['type']}' at line {$lineNum}");
            return false;
        }

        if (!is_string($data['agent_id']) || empty($data['agent_id'])) {
            error_log("[SuperAgent] ReplayStore: invalid 'agent_id' at line {$lineNum}");
            return false;
        }

        if (!is_numeric($data['duration_ms']) || $data['duration_ms'] < 0) {
            error_log("[SuperAgent] ReplayStore: invalid 'duration_ms' at line {$lineNum}");
            return false;
        }

        return true;
    }

    private function getFilePath(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
        return $this->storageDir . '/' . $safe . '.ndjson';
    }
}
