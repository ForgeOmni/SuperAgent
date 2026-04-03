<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Storage;

use SuperAgent\Swarm\AgentProgressTracker;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Persistent storage for agent progress tracking.
 * Allows recovery of agent state after restarts.
 */
class PersistentProgressStorage
{
    private ParallelAgentCoordinator $coordinator;
    private LoggerInterface $logger;
    private string $storagePath;
    private array $storageCache = [];
    private bool $autoSave;
    private float $lastSaveTime = 0;
    private float $saveInterval = 5.0; // seconds
    
    public function __construct(
        ?string $storagePath = null,
        ?ParallelAgentCoordinator $coordinator = null,
        ?LoggerInterface $logger = null,
        bool $autoSave = true
    ) {
        $this->storagePath = $storagePath ?? sys_get_temp_dir() . '/superagent_progress';
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
        $this->logger = $logger ?? new NullLogger();
        $this->autoSave = $autoSave;
        
        $this->ensureStorageDirectory();
    }
    
    /**
     * Save current agent progress to persistent storage.
     */
    public function save(): void
    {
        $progress = $this->coordinator->getConsolidatedProgress();
        $hierarchy = $this->coordinator->getHierarchicalDisplay();
        $trackers = $this->coordinator->getActiveTrackers();
        
        $data = [
            'version' => '1.0',
            'timestamp' => microtime(true),
            'progress' => $progress,
            'hierarchy' => $hierarchy,
            'trackers' => $this->serializeTrackers($trackers),
            'checksum' => null,
        ];
        
        // Add checksum for integrity
        $data['checksum'] = $this->calculateChecksum($data);
        
        // Save to main file
        $mainFile = $this->storagePath . '/progress.json';
        $this->writeJsonFile($mainFile, $data);
        
        // Save individual agent snapshots
        foreach ($trackers as $agentId => $tracker) {
            $this->saveAgentSnapshot($agentId, $tracker);
        }
        
        $this->lastSaveTime = microtime(true);
        
        $this->logger->info("Saved progress to persistent storage", [
            'agents' => count($trackers),
            'file' => $mainFile,
        ]);
    }
    
    /**
     * Load progress from persistent storage.
     */
    public function load(): array
    {
        $mainFile = $this->storagePath . '/progress.json';
        
        if (!file_exists($mainFile)) {
            $this->logger->warning("No saved progress found", ['file' => $mainFile]);
            return [];
        }
        
        $data = $this->readJsonFile($mainFile);
        
        if (!$this->validateChecksum($data)) {
            $this->logger->error("Progress file corrupted", ['file' => $mainFile]);
            return [];
        }
        
        // Restore trackers
        if (isset($data['trackers'])) {
            foreach ($data['trackers'] as $agentId => $trackerData) {
                $this->restoreTracker($agentId, $trackerData);
            }
        }
        
        $this->logger->info("Loaded progress from persistent storage", [
            'agents' => count($data['trackers'] ?? []),
            'timestamp' => $data['timestamp'] ?? 0,
        ]);
        
        return $data;
    }
    
    /**
     * Save an individual agent's progress snapshot.
     */
    public function saveAgentSnapshot(string $agentId, AgentProgressTracker $tracker): void
    {
        $snapshotDir = $this->storagePath . '/agents';
        $this->ensureDirectory($snapshotDir);
        
        $progress = $tracker->getProgress();
        $snapshot = [
            'agent_id' => $agentId,
            'timestamp' => microtime(true),
            'progress' => $progress,
            'checkpoint' => $this->createCheckpoint($progress),
        ];
        
        $file = $snapshotDir . '/' . $this->sanitizeAgentId($agentId) . '.json';
        $this->writeJsonFile($file, $snapshot);
        
        // Keep history
        $this->saveToHistory($agentId, $snapshot);
    }
    
    /**
     * Load an agent's progress snapshot.
     */
    public function loadAgentSnapshot(string $agentId): ?array
    {
        $file = $this->storagePath . '/agents/' . $this->sanitizeAgentId($agentId) . '.json';
        
        if (!file_exists($file)) {
            return null;
        }
        
        return $this->readJsonFile($file);
    }
    
    /**
     * Get agent execution history.
     */
    public function getAgentHistory(string $agentId, int $limit = 100): array
    {
        $historyFile = $this->storagePath . '/history/' . $this->sanitizeAgentId($agentId) . '.jsonl';
        
        if (!file_exists($historyFile)) {
            return [];
        }
        
        $history = [];
        $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -$limit);
        
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $history[] = $entry;
            }
        }
        
        return $history;
    }
    
    /**
     * Clean up old data.
     */
    public function cleanup(int $retentionDays = 7): void
    {
        $cutoff = time() - ($retentionDays * 86400);
        $cleaned = 0;
        
        // Clean snapshots
        $snapshotDir = $this->storagePath . '/agents';
        if (is_dir($snapshotDir)) {
            foreach (glob($snapshotDir . '/*.json') as $file) {
                if (filemtime($file) < $cutoff) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }
        
        // Clean history
        $historyDir = $this->storagePath . '/history';
        if (is_dir($historyDir)) {
            foreach (glob($historyDir . '/*.jsonl') as $file) {
                // Compact history files
                $this->compactHistoryFile($file, $cutoff);
            }
        }
        
        $this->logger->info("Cleaned up old progress data", [
            'files_removed' => $cleaned,
            'retention_days' => $retentionDays,
        ]);
    }
    
    /**
     * Export all data for backup.
     */
    public function export(string $exportPath): void
    {
        $exportData = [
            'version' => '1.0',
            'exported_at' => new \DateTimeImmutable(),
            'progress' => $this->load(),
            'agents' => [],
            'history' => [],
        ];
        
        // Export agent snapshots
        $snapshotDir = $this->storagePath . '/agents';
        if (is_dir($snapshotDir)) {
            foreach (glob($snapshotDir . '/*.json') as $file) {
                $agentId = basename($file, '.json');
                $exportData['agents'][$agentId] = $this->readJsonFile($file);
            }
        }
        
        // Export history
        $historyDir = $this->storagePath . '/history';
        if (is_dir($historyDir)) {
            foreach (glob($historyDir . '/*.jsonl') as $file) {
                $agentId = basename($file, '.jsonl');
                $exportData['history'][$agentId] = $this->getAgentHistory($agentId);
            }
        }
        
        // Write export file
        $this->writeJsonFile($exportPath, $exportData);
        
        $this->logger->info("Exported progress data", [
            'file' => $exportPath,
            'agents' => count($exportData['agents']),
        ]);
    }
    
    /**
     * Import data from backup.
     */
    public function import(string $importPath): void
    {
        if (!file_exists($importPath)) {
            throw new \RuntimeException("Import file not found: $importPath");
        }
        
        $data = $this->readJsonFile($importPath);
        
        if (!isset($data['version'])) {
            throw new \RuntimeException("Invalid import file format");
        }
        
        // Import progress
        if (isset($data['progress'])) {
            $mainFile = $this->storagePath . '/progress.json';
            $this->writeJsonFile($mainFile, $data['progress']);
        }
        
        // Import agent snapshots
        if (isset($data['agents'])) {
            $snapshotDir = $this->storagePath . '/agents';
            $this->ensureDirectory($snapshotDir);
            
            foreach ($data['agents'] as $agentId => $snapshot) {
                $file = $snapshotDir . '/' . $agentId . '.json';
                $this->writeJsonFile($file, $snapshot);
            }
        }
        
        // Import history
        if (isset($data['history'])) {
            $historyDir = $this->storagePath . '/history';
            $this->ensureDirectory($historyDir);
            
            foreach ($data['history'] as $agentId => $history) {
                $file = $historyDir . '/' . $agentId . '.jsonl';
                $lines = array_map('json_encode', $history);
                file_put_contents($file, implode("\n", $lines) . "\n");
            }
        }
        
        $this->logger->info("Imported progress data", [
            'file' => $importPath,
            'agents' => count($data['agents'] ?? []),
        ]);
    }
    
    /**
     * Enable auto-save with interval checking.
     */
    public function autoSaveIfNeeded(): void
    {
        if (!$this->autoSave) {
            return;
        }
        
        $now = microtime(true);
        if (($now - $this->lastSaveTime) >= $this->saveInterval) {
            $this->save();
        }
    }
    
    /**
     * Set auto-save interval.
     */
    public function setAutoSaveInterval(float $seconds): void
    {
        $this->saveInterval = max(1.0, $seconds);
    }
    
    /**
     * Serialize trackers for storage.
     */
    private function serializeTrackers(array $trackers): array
    {
        $serialized = [];
        
        foreach ($trackers as $agentId => $tracker) {
            $serialized[$agentId] = $tracker->getProgress();
        }
        
        return $serialized;
    }
    
    /**
     * Restore a tracker from storage.
     */
    private function restoreTracker(string $agentId, array $data): void
    {
        // In real implementation, would restore tracker state
        // For now, just log
        $this->logger->debug("Restoring tracker", [
            'agent_id' => $agentId,
            'tokens' => $data['tokenCount'] ?? 0,
        ]);
    }
    
    /**
     * Create a checkpoint from progress data.
     */
    private function createCheckpoint(array $progress): array
    {
        return [
            'tokens' => $progress['tokenCount'] ?? 0,
            'tools' => $progress['toolUseCount'] ?? 0,
            'duration_ms' => $progress['durationMs'] ?? 0,
            'activity' => $progress['currentActivity'] ?? null,
        ];
    }
    
    /**
     * Save to history file.
     */
    private function saveToHistory(string $agentId, array $snapshot): void
    {
        $historyDir = $this->storagePath . '/history';
        $this->ensureDirectory($historyDir);
        
        $file = $historyDir . '/' . $this->sanitizeAgentId($agentId) . '.jsonl';
        
        $entry = [
            'timestamp' => $snapshot['timestamp'],
            'checkpoint' => $snapshot['checkpoint'],
        ];
        
        file_put_contents($file, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Compact history file by removing old entries.
     */
    private function compactHistoryFile(string $file, int $cutoff): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $kept = [];
        
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['timestamp']) && $entry['timestamp'] >= $cutoff) {
                $kept[] = $line;
            }
        }
        
        if (count($kept) < count($lines)) {
            file_put_contents($file, implode("\n", $kept) . "\n");
        }
    }
    
    /**
     * Calculate checksum for data integrity.
     */
    private function calculateChecksum(array $data): string
    {
        unset($data['checksum']);
        return hash('sha256', json_encode($data));
    }
    
    /**
     * Validate data checksum.
     */
    private function validateChecksum(array $data): bool
    {
        if (!isset($data['checksum'])) {
            return false;
        }
        
        $checksum = $data['checksum'];
        return $this->calculateChecksum($data) === $checksum;
    }
    
    /**
     * Sanitize agent ID for filesystem.
     */
    private function sanitizeAgentId(string $agentId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentId);
    }
    
    /**
     * Ensure storage directory exists.
     */
    private function ensureStorageDirectory(): void
    {
        $this->ensureDirectory($this->storagePath);
        $this->ensureDirectory($this->storagePath . '/agents');
        $this->ensureDirectory($this->storagePath . '/history');
    }
    
    /**
     * Ensure directory exists.
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
    
    /**
     * Write JSON file with error handling.
     */
    private function writeJsonFile(string $file, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode JSON: " . json_last_error_msg());
        }
        
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write file: $file");
        }
    }
    
    /**
     * Read JSON file with error handling.
     */
    private function readJsonFile(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $file");
        }
        
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode JSON: " . json_last_error_msg());
        }
        
        return $data ?? [];
    }
}