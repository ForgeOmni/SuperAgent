<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

class MemoryConfig
{
    public function __construct(
        // Token thresholds for extraction
        public readonly int $minimumMessageTokensToInit = 8000,
        public readonly int $minimumTokensBetweenUpdate = 4000,
        public readonly int $toolCallsBetweenUpdates = 5,
        
        // AutoDream settings
        public readonly int $autoDreamMinHours = 24,
        public readonly int $autoDreamMinSessions = 5,
        public readonly int $autoDreamScanThrottleMinutes = 10,
        
        // Storage limits
        public readonly int $maxMemoryFiles = 200,
        public readonly int $maxEntrypointLines = 200,
        public readonly int $maxEntrypointBytes = 25000,
        
        // Aging settings
        public readonly int $staleMemoryDays = 30,
        public readonly int $expireMemoryDays = 90,
        
        // Retrieval settings
        public readonly int $defaultMaxRelevantMemories = 5,
        
        // Paths
        public readonly ?string $basePath = null,
    ) {}
    
    /**
     * Create config from array
     */
    public static function fromArray(array $config): self
    {
        return new self(
            minimumMessageTokensToInit: $config['minimum_message_tokens_to_init'] ?? 8000,
            minimumTokensBetweenUpdate: $config['minimum_tokens_between_update'] ?? 4000,
            toolCallsBetweenUpdates: $config['tool_calls_between_updates'] ?? 5,
            autoDreamMinHours: $config['auto_dream_min_hours'] ?? 24,
            autoDreamMinSessions: $config['auto_dream_min_sessions'] ?? 5,
            autoDreamScanThrottleMinutes: $config['auto_dream_scan_throttle_minutes'] ?? 10,
            maxMemoryFiles: $config['max_memory_files'] ?? 200,
            maxEntrypointLines: $config['max_entrypoint_lines'] ?? 200,
            maxEntrypointBytes: $config['max_entrypoint_bytes'] ?? 25000,
            staleMemoryDays: $config['stale_memory_days'] ?? 30,
            expireMemoryDays: $config['expire_memory_days'] ?? 90,
            defaultMaxRelevantMemories: $config['default_max_relevant_memories'] ?? 5,
            basePath: $config['base_path'] ?? null,
        );
    }
    
    /**
     * Get the memory base path
     */
    public function getBasePath(string $projectPath): string
    {
        // Check for environment override
        $override = $_ENV['CLAUDE_COWORK_MEMORY_PATH_OVERRIDE'] ?? null;
        if ($override !== null) {
            return $this->expandTilde($override);
        }
        
        // Use configured path
        if ($this->basePath !== null) {
            return $this->expandTilde($this->basePath);
        }
        
        // Default path
        $sanitized = $this->sanitizeProjectPath($projectPath);
        return $this->expandTilde("~/.claude/projects/{$sanitized}/memory");
    }
    
    /**
     * Expand tilde in path
     */
    private function expandTilde(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = $_ENV['HOME'] ?? $_ENV['USERPROFILE'] ?? '/tmp';
            return $home . substr($path, 1);
        }
        
        return $path;
    }
    
    /**
     * Sanitize project path for use in filesystem
     */
    private function sanitizeProjectPath(string $path): string
    {
        // Remove leading/trailing slashes
        $path = trim($path, '/\\');
        
        // Replace path separators with underscores
        $path = str_replace(['/', '\\'], '_', $path);
        
        // Remove dangerous characters
        $path = preg_replace('/[^a-zA-Z0-9_-]/', '', $path);
        
        // Limit length
        if (strlen($path) > 100) {
            $path = substr($path, 0, 100);
        }
        
        return $path ?: 'default';
    }
}