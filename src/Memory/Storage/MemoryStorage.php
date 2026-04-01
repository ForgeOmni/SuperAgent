<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Storage;

use SuperAgent\Memory\Memory;
use SuperAgent\Memory\MemoryType;
use Symfony\Component\Yaml\Yaml;

class MemoryStorage implements MemoryStorageInterface
{
    private const MAX_INDEX_LINES = 200;
    private const MAX_INDEX_BYTES = 25000;
    private const INDEX_FILENAME = 'MEMORY.md';
    
    public function __construct(
        private string $basePath,
    ) {
        $this->ensureDirectoryExists();
    }
    
    /**
     * Ensure the memory directory exists
     */
    private function ensureDirectoryExists(): void
    {
        if (!file_exists($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
        
        $logsPath = $this->basePath . '/logs';
        if (!file_exists($logsPath)) {
            mkdir($logsPath, 0755, true);
        }
    }
    
    /**
     * Save a memory to disk
     */
    public function save(Memory $memory): void
    {
        $filename = $this->generateFilename($memory);
        $filepath = $this->basePath . '/' . $filename;
        
        file_put_contents($filepath, $memory->toMarkdown());
        
        // Update the index
        $this->updateIndex();
    }
    
    /**
     * Load a memory from disk
     */
    public function load(string $id): ?Memory
    {
        $files = glob($this->basePath . '/*.md') ?: [];
        
        foreach ($files as $file) {
            if (basename($file) === self::INDEX_FILENAME) {
                continue;
            }
            
            $content = file_get_contents($file);
            $parsed = $this->parseMarkdownFile($content);
            
            if ($parsed === null) {
                continue;
            }
            
            $fileId = pathinfo($file, PATHINFO_FILENAME);
            // Extract the actual ID from the filename (remove type prefix)
            $actualId = preg_replace('/^[^_]+_/', '', $fileId);
            if ($actualId === $id || $fileId === $id || ($parsed['frontmatter']['name'] ?? '') === $id) {
                return Memory::fromMarkdown(
                    id: $id,
                    frontmatter: $parsed['frontmatter'],
                    content: $parsed['content'],
                );
            }
        }
        
        return null;
    }
    
    /**
     * Load all memories
     */
    public function loadAll(): array
    {
        $memories = [];
        $files = glob($this->basePath . '/*.md') ?: [];
        
        foreach ($files as $file) {
            if (basename($file) === self::INDEX_FILENAME) {
                continue;
            }
            
            $content = file_get_contents($file);
            $parsed = $this->parseMarkdownFile($content);
            
            if ($parsed === null) {
                continue;
            }
            
            $fileId = pathinfo($file, PATHINFO_FILENAME);
            // Extract the actual ID from the filename (remove type prefix)
            $actualId = preg_replace('/^[^_]+_/', '', $fileId);
            $memories[] = Memory::fromMarkdown(
                id: $actualId,
                frontmatter: $parsed['frontmatter'],
                content: $parsed['content'],
            );
        }
        
        // Sort by updated_at desc  
        usort($memories, fn($a, $b) => $b->updatedAt <=> $a->updatedAt);
        
        return $memories;
    }
    
    /**
     * Delete a memory
     */
    public function delete(string $id): bool
    {
        $files = glob($this->basePath . '/*.md') ?: [];
        
        foreach ($files as $file) {
            $fileId = pathinfo($file, PATHINFO_FILENAME);
            // Extract the actual ID from the filename (remove type prefix)
            $actualId = preg_replace('/^[^_]+_/', '', $fileId);
            if ($actualId === $id || $fileId === $id) {
                unlink($file);
                $this->updateIndex();
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Search memories by type
     */
    public function findByType(MemoryType $type): array
    {
        $memories = $this->loadAll();
        
        return array_values(array_filter($memories, fn($m) => $m->type === $type));
    }
    
    /**
     * Get memory headers for scanning
     */
    public function scan(): array
    {
        $headers = [];
        $files = glob($this->basePath . '/*.md') ?: [];
        
        foreach ($files as $file) {
            if (basename($file) === self::INDEX_FILENAME) {
                continue;
            }
            
            $content = file_get_contents($file);
            $parsed = $this->parseMarkdownFile($content);
            
            if ($parsed === null) {
                continue;
            }
            
            $headers[] = [
                'filename' => basename($file),
                'filePath' => $file,
                'mtimeMs' => filemtime($file) * 1000,
                'description' => $parsed['frontmatter']['description'] ?? null,
                'type' => $parsed['frontmatter']['type'] ?? null,
                'name' => $parsed['frontmatter']['name'] ?? pathinfo($file, PATHINFO_FILENAME),
            ];
        }
        
        // Sort by mtime desc
        usort($headers, fn($a, $b) => $b['mtimeMs'] <=> $a['mtimeMs']);
        
        // Cap at 200 files
        return array_slice($headers, 0, 200);
    }
    
    /**
     * Update the MEMORY.md index file
     */
    public function updateIndex(): void
    {
        $indexPath = $this->basePath . '/' . self::INDEX_FILENAME;
        $memories = $this->loadAll();
        
        $content = "# Memory Index\n\n";
        $content .= "This file is auto-generated. Do not edit directly.\n\n";
        $content .= "## Memories (" . count($memories) . " total)\n\n";
        
        $byType = [];
        foreach ($memories as $memory) {
            $type = $memory->type->value;
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $memory;
        }
        
        foreach (MemoryType::cases() as $type) {
            if (!isset($byType[$type->value])) {
                continue;
            }
            
            $content .= "### " . ucfirst($type->value) . " Memories\n\n";
            
            foreach ($byType[$type->value] as $memory) {
                $filename = $this->generateFilename($memory);
                $entry = $memory->getIndexEntry($filename);
                $content .= $entry . "\n";
            }
            
            $content .= "\n";
        }
        
        // Truncate if too large
        $lines = explode("\n", $content);
        if (count($lines) > self::MAX_INDEX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_INDEX_LINES);
            $lines[] = "\n⚠️ Index truncated at " . self::MAX_INDEX_LINES . " lines";
            $content = implode("\n", $lines);
        }
        
        if (strlen($content) > self::MAX_INDEX_BYTES) {
            $content = substr($content, 0, self::MAX_INDEX_BYTES);
            $content .= "\n\n⚠️ Index truncated at " . self::MAX_INDEX_BYTES . " bytes";
        }
        
        file_put_contents($indexPath, $content);
    }
    
    /**
     * Parse a markdown file with frontmatter
     */
    private function parseMarkdownFile(string $content): ?array
    {
        // Check for frontmatter
        if (!str_starts_with($content, '---')) {
            return null;
        }
        
        // Find the end of frontmatter
        $endPos = strpos($content, "\n---\n", 4);
        if ($endPos === false) {
            $endPos = strpos($content, "\r\n---\r\n", 4);
        }
        
        if ($endPos === false) {
            return null;
        }
        
        $frontmatterStr = substr($content, 4, $endPos - 4);
        $bodyStart = $endPos + (str_contains($content, "\r\n") ? 7 : 5);
        $body = substr($content, $bodyStart);
        
        try {
            $frontmatter = Yaml::parse($frontmatterStr);
            if (!is_array($frontmatter)) {
                $frontmatter = [];
            }
        } catch (\Exception $e) {
            return null;
        }
        
        return [
            'frontmatter' => $frontmatter,
            'content' => trim($body),
        ];
    }
    
    /**
     * Generate a filename for a memory
     */
    private function generateFilename(Memory $memory): string
    {
        $base = $memory->type->value . '_' . $this->sanitizeFilename($memory->id);
        return $base . '.md';
    }
    
    /**
     * Sanitize a filename
     */
    private function sanitizeFilename(string $name): string
    {
        // Convert to lowercase and replace spaces with underscores
        $name = strtolower(str_replace(' ', '_', $name));
        
        // Remove invalid characters
        $name = preg_replace('/[^a-z0-9_-]/', '', $name);
        
        // Limit length
        if (strlen($name) > 50) {
            $name = substr($name, 0, 50);
        }
        
        return $name ?: 'memory';
    }
    
    /**
     * Write a daily log entry
     */
    public function writeDailyLog(string $content, ?\DateTime $date = null): void
    {
        $date = $date ?? new \DateTime();
        
        $year = $date->format('Y');
        $month = $date->format('m');
        $day = $date->format('Y-m-d');
        
        $dirPath = $this->basePath . '/logs/' . $year . '/' . $month;
        if (!file_exists($dirPath)) {
            mkdir($dirPath, 0755, true);
        }
        
        $filepath = $dirPath . '/' . $day . '.md';
        
        if (file_exists($filepath)) {
            $existing = file_get_contents($filepath);
            $content = $existing . "\n\n---\n\n" . $content;
        }
        
        file_put_contents($filepath, $content);
    }
    
    /**
     * Get daily logs for consolidation
     */
    public function getDailyLogs(int $daysBack = 7): array
    {
        $logs = [];
        $logsPath = $this->basePath . '/logs';
        
        if (!file_exists($logsPath)) {
            return [];
        }
        
        $files = $this->getAllFiles($logsPath);
        
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'md') {
                continue;
            }
            
            $mtime = filemtime($file);
            $daysOld = (time() - $mtime) / (60 * 60 * 24);
            
            if ($daysOld <= $daysBack) {
                $logs[] = [
                    'path' => $file,
                    'date' => basename($file, '.md'),
                    'content' => file_get_contents($file),
                ];
            }
        }
        
        // Sort by date desc
        usort($logs, fn($a, $b) => strcmp($b['date'], $a['date']));
        
        return $logs;
    }
    
    /**
     * Get all files recursively
     */
    private function getAllFiles(string $dir): array
    {
        $files = [];
        
        if (!is_dir($dir)) {
            return [];
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
}

interface MemoryStorageInterface
{
    public function save(Memory $memory): void;
    public function load(string $id): ?Memory;
    public function loadAll(): array;
    public function delete(string $id): bool;
    public function findByType(MemoryType $type): array;
    public function scan(): array;
    public function updateIndex(): void;
}