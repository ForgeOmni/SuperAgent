<?php

namespace SuperAgent\FileHistory;

use Illuminate\Support\Collection;

class SensitiveFileProtection
{
    private static ?self $instance = null;
    private Collection $protectedPatterns;
    private Collection $protectedFiles;
    private bool $enabled = true;
    private array $violations = [];

    private function __construct()
    {
        // Default protected patterns
        $this->protectedPatterns = collect([
            // Environment files
            '*.env',
            '.env.*',
            
            // Credentials and keys
            '*.key',
            '*.pem',
            '*.p12',
            '*.pfx',
            '*_rsa',
            '*_dsa',
            '*_ecdsa',
            '*_ed25519',
            'id_rsa*',
            
            // Config files with sensitive data
            'config/database.php',
            'config/mail.php',
            'config/services.php',
            '.aws/credentials',
            '.ssh/*',
            
            // Password files
            '.htpasswd',
            'passwd',
            'shadow',
            
            // Token files
            '.npmrc',
            '.pypirc',
            '.docker/config.json',
            
            // Database files
            '*.sqlite',
            '*.sqlite3',
            '*.db',
            
            // Backup files that might contain secrets
            '*.bak',
            '*.backup',
            '*.old',
            
            // Git files
            '.git/config',
            
            // Other sensitive files
            'secrets.*',
            'credentials.*',
            'auth.*',
        ]);

        $this->protectedFiles = collect();
        $this->loadProtectedFiles();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if a file is protected.
     */
    public function isProtected(string $filePath): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Check exact file match
        if ($this->protectedFiles->contains($filePath)) {
            return true;
        }

        // Check pattern match
        $fileName = basename($filePath);
        $relativePath = $this->getRelativePath($filePath);

        foreach ($this->protectedPatterns as $pattern) {
            if ($this->matchesPattern($fileName, $pattern) || 
                $this->matchesPattern($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file operation is allowed.
     */
    public function checkOperation(string $operation, string $filePath, array $context = []): ProtectionResult
    {
        if (!$this->enabled) {
            return new ProtectionResult(true, 'Protection disabled');
        }

        // For write operations, always check for secrets in content
        if ($operation === 'write' && isset($context['content'])) {
            $secrets = $this->detectSecrets($context['content']);
            if (!empty($secrets)) {
                $result = new ProtectionResult(
                    false,
                    'Cannot write secrets to file',
                    ['detected_secrets' => $secrets]
                );
                $this->logViolation($operation, $filePath, $result->reason, $context);
                return $result;
            }
        }

        if (!$this->isProtected($filePath)) {
            return new ProtectionResult(true, 'File not protected');
        }

        // Analyze the operation
        $result = match ($operation) {
            'read' => $this->checkReadOperation($filePath, $context),
            'write' => $this->checkWriteOperation($filePath, $context),
            'delete' => $this->checkDeleteOperation($filePath, $context),
            'rename' => $this->checkRenameOperation($filePath, $context),
            default => new ProtectionResult(false, 'Unknown operation'),
        };

        // Log violation if denied
        if (!$result->allowed) {
            $this->logViolation($operation, $filePath, $result->reason, $context);
        }

        return $result;
    }

    /**
     * Check read operation.
     */
    private function checkReadOperation(string $filePath, array $context): ProtectionResult
    {
        // Check if it's a known sensitive file type
        if ($this->containsSensitiveContent($filePath)) {
            // Allow read with warning
            return new ProtectionResult(
                true, 
                'Reading sensitive file - content should be redacted',
                ['redact' => true]
            );
        }

        return new ProtectionResult(true, 'Read allowed');
    }

    /**
     * Check write operation.
     */
    private function checkWriteOperation(string $filePath, array $context): ProtectionResult
    {
        // Secrets are already checked in checkOperation, so skip here
        
        // Warn but allow for certain files
        if ($this->isEnvironmentFile($filePath)) {
            return new ProtectionResult(
                true,
                'Writing to environment file - ensure secrets are properly managed',
                ['warning' => true]
            );
        }

        return new ProtectionResult(false, 'Cannot modify protected file');
    }

    /**
     * Check delete operation.
     */
    private function checkDeleteOperation(string $filePath, array $context): ProtectionResult
    {
        return new ProtectionResult(false, 'Cannot delete protected file');
    }

    /**
     * Check rename operation.
     */
    private function checkRenameOperation(string $filePath, array $context): ProtectionResult
    {
        $newPath = $context['new_path'] ?? null;
        
        if (!$newPath) {
            return new ProtectionResult(false, 'New path not specified');
        }

        // Check if new path is also protected
        if (!$this->isProtected($newPath)) {
            return new ProtectionResult(
                false,
                'Cannot rename protected file to unprotected location'
            );
        }

        return new ProtectionResult(true, 'Rename allowed within protected scope');
    }

    /**
     * Detect secrets in content.
     */
    public function detectSecrets(string $content): array
    {
        $secrets = [];
        
        // Pattern matching for common secret formats
        $patterns = [
            'api_key' => '/(?:api[_-]?key|apikey)\s*[:=]\s*["\']?([a-zA-Z0-9_\-]{20,})/i',
            'aws_key' => '/(?:aws[_-]?access[_-]?key[_-]?id|aws[_-]?secret[_-]?access[_-]?key)\s*[:=]\s*["\']?([A-Z0-9]{20,})/i',
            'private_key' => '/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/i',
            'token' => '/(?:token|bearer)\s*[:=]\s*["\']?([a-zA-Z0-9_\-\.]+)/i',
            'password' => '/(?:password|passwd|pwd)\s*[:=]\s*["\']?([^\s"\']+)/i',
            'database_url' => '/(?:mysql|postgresql|mongodb):\/\/[^:]+:[^@]+@/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $secrets[] = [
                    'type' => $type,
                    'pattern_matched' => true,
                    'position' => strpos($content, $matches[0]),
                ];
            }
        }

        return $secrets;
    }

    /**
     * Check if file contains sensitive content.
     */
    private function containsSensitiveContent(string $filePath): bool
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $sensitiveExtensions = ['env', 'key', 'pem', 'p12', 'pfx'];
        
        return in_array($extension, $sensitiveExtensions);
    }

    /**
     * Check if file is an environment file.
     */
    private function isEnvironmentFile(string $filePath): bool
    {
        $fileName = basename($filePath);
        return preg_match('/^\.env(\.|$)/', $fileName) === 1;
    }

    /**
     * Match file path against pattern.
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Convert glob pattern to regex
        // First escape regex special chars, then convert glob wildcards
        $regex = preg_quote($pattern, '/');
        $regex = str_replace(
            ['\*', '\?'],
            ['.*', '.'],
            $regex
        );

        return preg_match('/^' . $regex . '$/i', $path) === 1;
    }

    /**
     * Get relative path from project root.
     */
    private function getRelativePath(string $filePath): string
    {
        // Use current working directory as base
        $basePath = getcwd();
        if (str_starts_with($filePath, $basePath)) {
            return ltrim(substr($filePath, strlen($basePath)), '/\\');
        }
        return $filePath;
    }

    /**
     * Log a protection violation.
     */
    private function logViolation(string $operation, string $filePath, string $reason, array $context): void
    {
        $this->violations[] = [
            'timestamp' => time(),
            'operation' => $operation,
            'file_path' => $filePath,
            'reason' => $reason,
            'context' => $context,
        ];

        // Note: In production, you could log to Laravel log here
        // logger()->warning('Sensitive file protection violation', [...]);
    }

    /**
     * Load additional protected files from config.
     */
    private function loadProtectedFiles(): void
    {
        // Could load from config or database
        // For now, using defaults
    }

    /**
     * Add a protected pattern.
     */
    public function addProtectedPattern(string $pattern): void
    {
        if (!$this->protectedPatterns->contains($pattern)) {
            $this->protectedPatterns->push($pattern);
        }
    }

    /**
     * Add a protected file.
     */
    public function addProtectedFile(string $filePath): void
    {
        if (!$this->protectedFiles->contains($filePath)) {
            $this->protectedFiles->push($filePath);
        }
    }

    /**
     * Get violations log.
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * Clear violations log.
     */
    public function clearViolations(): void
    {
        $this->violations = [];
    }

    /**
     * Set enabled state.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

class ProtectionResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}