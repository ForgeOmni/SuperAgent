<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages git worktrees for agent isolation.
 *
 * Each agent can get its own worktree so file mutations don't conflict
 * with the main repository or other agents. Large directories
 * (node_modules, vendor, .venv, etc.) are symlinked to save space.
 *
 * Directory layout (under $baseDir):
 *   {slug}/             — worktree checkout
 *   {slug}.meta.json    — metadata (slug, branch, original_path, agent_id, created_at)
 *
 * Configuration (superagent.harness.worktree):
 *   enabled:           bool  (default: true)
 *   base_dir:          string (default: ~/.superagent/worktrees)
 *   symlink_dirs:      string[] (default: node_modules, vendor, .venv, __pycache__)
 *   max_slug_length:   int (default: 64)
 */
class WorktreeManager
{
    private string $baseDir;
    private LoggerInterface $logger;
    private array $symlinkDirs;
    private int $maxSlugLength;

    /** @var array<string, WorktreeInfo> In-memory registry */
    private array $worktrees = [];

    public function __construct(
        ?string $baseDir = null,
        ?LoggerInterface $logger = null,
        array $symlinkDirs = ['node_modules', 'vendor', '.venv', '__pycache__', '.tox'],
        int $maxSlugLength = 64,
    ) {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME') ?: '/tmp';
        $this->baseDir = $baseDir ?? ($home . '/.superagent/worktrees');
        $this->logger = $logger ?? new NullLogger();
        $this->symlinkDirs = $symlinkDirs;
        $this->maxSlugLength = $maxSlugLength;

        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    /**
     * Build from config, with optional parameter overrides.
     *
     * Priority: $overrides > config > defaults.
     * Returns null only when the resolved 'enabled' is false.
     *
     * @param array $overrides  Keys: enabled, base_dir, symlink_dirs, max_slug_length
     */
    public static function fromConfig(?LoggerInterface $logger = null, array $overrides = []): ?self
    {
        $config = self::resolveConfig();

        $enabled = $overrides['enabled'] ?? $config['enabled'] ?? true;

        if (!$enabled) {
            return null;
        }

        return new self(
            baseDir: $overrides['base_dir'] ?? $config['base_dir'] ?? null,
            logger: $logger,
            symlinkDirs: $overrides['symlink_dirs'] ?? $config['symlink_dirs'] ?? ['node_modules', 'vendor', '.venv', '__pycache__', '.tox'],
            maxSlugLength: (int) ($overrides['max_slug_length'] ?? $config['max_slug_length'] ?? 64),
        );
    }

    // ── Create ────────────────────────────────────────────────────

    /**
     * Create a new git worktree for an agent.
     *
     * @param string      $slug       Unique identifier (sanitized)
     * @param string      $repoDir    Path to the git repository
     * @param string|null $agentId    Agent that owns this worktree
     * @param string|null $baseBranch Branch/ref to base on (default: HEAD)
     * @return WorktreeInfo
     */
    public function create(
        string $slug,
        string $repoDir,
        ?string $agentId = null,
        ?string $baseBranch = null,
    ): WorktreeInfo {
        $slug = $this->sanitizeSlug($slug);
        $worktreePath = $this->baseDir . '/' . $slug;
        $branch = 'agent_' . $slug;

        // Resume if already exists
        if (is_dir($worktreePath)) {
            $existing = $this->loadMeta($slug);
            if ($existing !== null) {
                $this->worktrees[$slug] = $existing;
                $this->logger->info('Reusing existing worktree', ['slug' => $slug]);
                return $existing;
            }
        }

        // Create parent directory
        if (!is_dir(dirname($worktreePath))) {
            mkdir(dirname($worktreePath), 0755, true);
        }

        // Create worktree
        $baseRef = $baseBranch ?? 'HEAD';
        $cmd = sprintf(
            'cd %s && git worktree add -B %s %s %s 2>&1',
            escapeshellarg($repoDir),
            escapeshellarg($branch),
            escapeshellarg($worktreePath),
            escapeshellarg($baseRef),
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Failed to create worktree '{$slug}': " . implode("\n", $output)
            );
        }

        // Create symlinks for large directories
        $this->createSymlinks($repoDir, $worktreePath);

        $info = new WorktreeInfo(
            slug: $slug,
            path: $worktreePath,
            branch: $branch,
            originalPath: $repoDir,
            agentId: $agentId,
            createdAt: time(),
        );

        $this->worktrees[$slug] = $info;
        $this->saveMeta($info);

        $this->logger->info('Created worktree', [
            'slug' => $slug,
            'path' => $worktreePath,
            'agent_id' => $agentId,
        ]);

        return $info;
    }

    // ── Remove ────────────────────────────────────────────────────

    /**
     * Remove a worktree and clean up.
     */
    public function remove(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        $info = $this->worktrees[$slug] ?? $this->loadMeta($slug);

        if ($info === null) {
            return false;
        }

        $path = $info->path;

        // Remove symlinks first (before git worktree remove)
        $this->removeSymlinks($path);

        // Try git worktree remove
        exec(sprintf('git worktree remove --force %s 2>&1', escapeshellarg($path)), $output, $exitCode);

        if ($exitCode !== 0 && is_dir($path)) {
            // Fallback: delete directory directly
            $this->recursiveDelete($path);
        }

        // Remove metadata
        $metaPath = $this->baseDir . '/' . $slug . '.meta.json';
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }

        unset($this->worktrees[$slug]);

        $this->logger->info('Removed worktree', ['slug' => $slug]);

        return true;
    }

    // ── List ──────────────────────────────────────────────────────

    /**
     * List all known worktrees.
     *
     * @return WorktreeInfo[]
     */
    public function list(): array
    {
        $results = [];
        $pattern = $this->baseDir . '/*.meta.json';

        foreach (glob($pattern) as $metaPath) {
            $data = json_decode(file_get_contents($metaPath), true);
            if (is_array($data)) {
                $info = WorktreeInfo::fromArray($data);
                $results[] = $info;
                $this->worktrees[$info->slug] = $info;
            }
        }

        return $results;
    }

    /**
     * Get info for a specific worktree.
     */
    public function get(string $slug): ?WorktreeInfo
    {
        $slug = $this->sanitizeSlug($slug);
        return $this->worktrees[$slug] ?? $this->loadMeta($slug);
    }

    /**
     * Check if a worktree exists.
     */
    public function exists(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        return is_dir($this->baseDir . '/' . $slug);
    }

    // ── Prune ─────────────────────────────────────────────────────

    /**
     * Remove worktrees whose directory no longer exists (stale metadata).
     *
     * @return int Number pruned
     */
    public function prune(): int
    {
        $pruned = 0;
        $pattern = $this->baseDir . '/*.meta.json';

        foreach (glob($pattern) as $metaPath) {
            $data = json_decode(file_get_contents($metaPath), true);
            if (!is_array($data)) {
                unlink($metaPath);
                $pruned++;
                continue;
            }

            $path = $data['path'] ?? '';
            if ($path !== '' && !is_dir($path)) {
                unlink($metaPath);
                $slug = $data['slug'] ?? '';
                unset($this->worktrees[$slug]);
                $pruned++;
            }
        }

        return $pruned;
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getSymlinkDirs(): array
    {
        return $this->symlinkDirs;
    }

    // ── Internal helpers ──────────────────────────────────────────

    /**
     * Sanitize a slug: only allow [a-zA-Z0-9._-], truncate to max length.
     */
    public function sanitizeSlug(string $slug): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $slug);
        if (strlen($slug) > $this->maxSlugLength) {
            $slug = substr($slug, 0, $this->maxSlugLength);
        }
        return $slug;
    }

    private function createSymlinks(string $sourceDir, string $worktreePath): void
    {
        foreach ($this->symlinkDirs as $dir) {
            $sourcePath = $sourceDir . '/' . $dir;
            $linkPath = $worktreePath . '/' . $dir;

            if (is_dir($sourcePath) && !file_exists($linkPath)) {
                symlink($sourcePath, $linkPath);
                $this->logger->debug('Created symlink', [
                    'link' => $linkPath,
                    'target' => $sourcePath,
                ]);
            }
        }
    }

    private function removeSymlinks(string $worktreePath): void
    {
        foreach ($this->symlinkDirs as $dir) {
            $linkPath = $worktreePath . '/' . $dir;
            if (is_link($linkPath)) {
                unlink($linkPath);
            }
        }
    }

    private function saveMeta(WorktreeInfo $info): void
    {
        $path = $this->baseDir . '/' . $info->slug . '.meta.json';
        $json = json_encode($info->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json);
    }

    private function loadMeta(string $slug): ?WorktreeInfo
    {
        $path = $this->baseDir . '/' . $slug . '.meta.json';
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        $info = WorktreeInfo::fromArray($data);
        $this->worktrees[$slug] = $info;
        return $info;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    private static function resolveConfig(): array
    {
        $defaults = [
            'enabled' => true,
            'base_dir' => null,
            'symlink_dirs' => ['node_modules', 'vendor', '.venv', '__pycache__', '.tox'],
            'max_slug_length' => 64,
        ];

        try {
            if (function_exists('config')) {
                $config = config('superagent.harness.worktree', []);
                return array_replace($defaults, is_array($config) ? $config : []);
            }
        } catch (\Throwable $e) {
            // No Laravel
        }

        return $defaults;
    }
}

