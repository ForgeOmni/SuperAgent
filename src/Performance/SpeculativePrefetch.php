<?php

declare(strict_types=1);

namespace SuperAgent\Performance;

class SpeculativePrefetch
{
    private const MAX_PREDICTIONS = 5;

    /** @var array<string, string> path => content cache */
    private array $cache = [];

    /** @var string[] insertion-order keys for LRU eviction */
    private array $cacheOrder = [];

    public function __construct(
        private bool $enabled = true,
        private int $maxCacheEntries = 50,
        private int $maxFileSize = 100_000,  // 100KB max per file
    ) {}

    /**
     * Create an instance from the application config.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.performance.speculative_prefetch') ?? []) : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: $config['enabled'] ?? true,
            maxCacheEntries: $config['max_cache_entries'] ?? 50,
            maxFileSize: $config['max_file_size'] ?? 100_000,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cached content for a file path. Returns null if not cached.
     */
    public function getCached(string $filePath): ?string
    {
        $resolved = $this->resolve($filePath);

        if (! isset($this->cache[$resolved])) {
            return null;
        }

        // Move to end of order (most recently accessed).
        $this->touchOrder($resolved);

        return $this->cache[$resolved];
    }

    /**
     * After a Read tool executes, prefetch related files in the same directory.
     * Call this asynchronously (fire-and-forget) after the Read result is returned.
     *
     * @param string $filePath  The file that was just read
     */
    public function prefetchRelated(string $filePath): void
    {
        if (! $this->enabled) {
            return;
        }

        $predictions = $this->predictRelated($filePath);

        foreach ($predictions as $predicted) {
            $resolved = $this->resolve($predicted);

            // Skip if already cached.
            if (isset($this->cache[$resolved])) {
                continue;
            }

            if (! is_file($resolved)) {
                continue;
            }

            $size = @filesize($resolved);

            if ($size === false || $size > $this->maxFileSize) {
                continue;
            }

            $content = @file_get_contents($resolved);

            if ($content === false) {
                continue;
            }

            $this->cache[$resolved] = $content;
            $this->cacheOrder[] = $resolved;

            $this->evictIfNeeded();
        }
    }

    /**
     * Predict related files for a given path.
     *
     * @return string[] File paths to prefetch
     */
    public function predictRelated(string $filePath): array
    {
        $resolved = $this->resolve($filePath);
        $predictions = [];

        $dir = dirname($resolved);
        $basename = basename($resolved);
        $extension = pathinfo($resolved, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($resolved, PATHINFO_FILENAME);

        // Rule 1: src file -> predict test files
        if ($this->isSourceFile($resolved)) {
            $predictions = array_merge($predictions, $this->predictTestsForSource($resolved));
        }

        // Rule 2: test file -> predict source file
        if ($this->isTestFile($resolved)) {
            $predictions = array_merge($predictions, $this->predictSourceForTest($resolved));
        }

        // Rule 3: PHP class -> predict interfaces in same directory (files starting with capital I)
        if ($extension === 'php') {
            $predictions = array_merge($predictions, $this->predictInterfaces($dir));
        }

        // Rule 4: config file -> predict .env or related config files
        if ($this->isConfigFile($resolved)) {
            $predictions = array_merge($predictions, $this->predictRelatedConfigs($resolved));
        }

        // Rule 5: scan same directory for files with similar names
        if ($extension === 'php') {
            $predictions = array_merge($predictions, $this->predictSimilarNames($dir, $nameWithoutExt, $resolved));
        }

        // Deduplicate, remove the original file, and cap at MAX_PREDICTIONS.
        $predictions = array_values(array_unique($predictions));
        $predictions = array_filter($predictions, fn (string $p) => $this->resolve($p) !== $resolved);
        $predictions = array_values($predictions);

        return array_slice($predictions, 0, self::MAX_PREDICTIONS);
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->cacheOrder = [];
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a path to its real (absolute) form.
     */
    private function resolve(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function isSourceFile(string $path): bool
    {
        return (bool) preg_match('#/src/.+\.php$#', $path);
    }

    private function isTestFile(string $path): bool
    {
        return (bool) preg_match('#/tests/.+Test\.php$#', $path);
    }

    private function isConfigFile(string $path): bool
    {
        $basename = basename($path);

        // Common config file patterns.
        if (preg_match('#/config/.+\.php$#', $path)) {
            return true;
        }

        return in_array($basename, [
            '.env',
            '.env.example',
            '.env.local',
            'config.php',
            'config.yaml',
            'config.yml',
            'config.json',
        ], true);
    }

    /**
     * For a source file like src/Foo/Bar.php, predict test paths.
     *
     * @return string[]
     */
    private function predictTestsForSource(string $path): array
    {
        $predictions = [];

        // Extract the class name from the path.
        if (preg_match('#/src/(.+)\.php$#', $path, $matches)) {
            $relativePath = $matches[1];
            $className = basename($relativePath);
            $projectRoot = $this->guessProjectRoot($path);

            $predictions[] = $projectRoot . '/tests/Unit/' . $className . 'Test.php';
            $predictions[] = $projectRoot . '/tests/Feature/' . $className . 'Test.php';
        }

        return $predictions;
    }

    /**
     * For a test file like tests/Unit/BarTest.php, predict the source file.
     *
     * @return string[]
     */
    private function predictSourceForTest(string $path): array
    {
        $predictions = [];

        if (preg_match('#/tests/(?:Unit|Feature)/(.+)Test\.php$#', $path, $matches)) {
            $relative = $matches[1];
            $projectRoot = $this->guessProjectRoot($path);

            $predictions[] = $projectRoot . '/src/' . $relative . '.php';
        }

        return $predictions;
    }

    /**
     * Find interface files (starting with capital I followed by another uppercase letter) in a directory.
     *
     * @return string[]
     */
    private function predictInterfaces(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $predictions = [];
        $entries = @scandir($dir);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if (preg_match('/^I[A-Z].*\.php$/', $entry)) {
                $predictions[] = $dir . '/' . $entry;
            }
        }

        return $predictions;
    }

    /**
     * Predict related config files.
     *
     * @return string[]
     */
    private function predictRelatedConfigs(string $path): array
    {
        $predictions = [];
        $projectRoot = $this->guessProjectRoot($path);

        // Always suggest .env from project root.
        $predictions[] = $projectRoot . '/.env';
        $predictions[] = $projectRoot . '/.env.example';

        // If inside a config directory, predict sibling config files.
        $dir = dirname($path);

        if (str_contains($dir, '/config')) {
            $entries = @scandir($dir);

            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $full = $dir . '/' . $entry;

                    if ($full !== $path && is_file($full)) {
                        $predictions[] = $full;
                    }
                }
            }
        }

        return $predictions;
    }

    /**
     * Scan a directory for files whose names share a common entity prefix.
     * E.g., reading UserController.php -> predict UserService.php, UserRepository.php.
     *
     * @return string[]
     */
    private function predictSimilarNames(string $dir, string $nameWithoutExt, string $excludePath): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        // Extract the entity prefix by stripping common suffixes.
        $suffixes = ['Controller', 'Service', 'Repository', 'Factory', 'Provider', 'Handler', 'Manager', 'Middleware', 'Observer', 'Policy', 'Request', 'Resource', 'Transformer', 'Validator'];
        $prefix = $nameWithoutExt;

        foreach ($suffixes as $suffix) {
            if (str_ends_with($prefix, $suffix) && strlen($prefix) > strlen($suffix)) {
                $prefix = substr($prefix, 0, -strlen($suffix));

                break;
            }
        }

        // If prefix is the same as the full name (no suffix stripped), skip scan.
        if ($prefix === $nameWithoutExt) {
            return [];
        }

        $predictions = [];
        $entries = @scandir($dir);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . '/' . $entry;

            if ($full === $excludePath) {
                continue;
            }

            if (str_starts_with($entry, $prefix) && str_ends_with($entry, '.php')) {
                $predictions[] = $full;
            }
        }

        return $predictions;
    }

    /**
     * Walk up from a file path to guess the project root (where composer.json lives).
     */
    private function guessProjectRoot(string $path): string
    {
        $dir = is_dir($path) ? $path : dirname($path);

        while ($dir !== '/' && $dir !== '') {
            if (file_exists($dir . '/composer.json')) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        // Fallback: try to infer from path structure.
        if (preg_match('#^(.+?)/(src|tests|config)/#', $path, $matches)) {
            return $matches[1];
        }

        return dirname($path);
    }

    /**
     * Move a key to the end of the insertion-order list.
     */
    private function touchOrder(string $key): void
    {
        $this->cacheOrder = array_values(array_filter(
            $this->cacheOrder,
            fn (string $k) => $k !== $key,
        ));
        $this->cacheOrder[] = $key;
    }

    /**
     * Evict oldest entries until the cache is within bounds.
     */
    private function evictIfNeeded(): void
    {
        while (count($this->cache) > $this->maxCacheEntries && $this->cacheOrder !== []) {
            $oldest = array_shift($this->cacheOrder);
            unset($this->cache[$oldest]);
        }
    }
}
