<?php

declare(strict_types=1);

namespace SuperAgent\Spill;

/**
 * Local-filesystem spill store.
 *
 * Layout: <baseDir>/session-<sha256(sessionId)[0:16]>/<random>-<tool>.txt
 *
 * Hardening (deepseek-harness defensive patterns):
 *  - private 0700 session directory, 0600 files
 *  - random filenames, exclusive-create 'x' opens (no symlink-follow races)
 *  - read() resolves locators by strict pattern match, never as paths,
 *    and refuses symlinks
 *
 * Forked sessions inherit the parent's locators copy-free: a locator embeds
 * the owning session's key, so it stays readable from any descendant.
 */
class LocalSpillStore implements SpillStoreInterface
{
    private const LOCATOR_PATTERN = '#^spill://([a-f0-9]{16})/([A-Za-z0-9][A-Za-z0-9_.-]{0,120})$#';

    private string $sessionKey;

    public function __construct(
        private readonly string $baseDir,
        string $sessionId = 'default',
    ) {
        $this->sessionKey = substr(hash('sha256', $sessionId), 0, 16);
    }

    public static function fromConfig(string $sessionId = 'default'): self
    {
        $baseDir = null;
        try {
            $config = function_exists('config') ? (config('superagent.spill') ?? []) : [];
            $baseDir = $config['dir'] ?? null;
        } catch (\Throwable $e) {
            // Config unavailable (standalone CLI early boot) — use default dir.
        }

        if (!is_string($baseDir) || $baseDir === '') {
            $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
            $baseDir = $home . '/.superagent/spill';
        }

        return new self($baseDir, $sessionId);
    }

    public function save(string $toolName, string $toolUseId, string $content): ?SpillRef
    {
        try {
            $dir = $this->sessionDir($this->sessionKey);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return null;
            }

            $safeTool = substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $toolName) ?: 'tool', 0, 40);
            $filename = bin2hex(random_bytes(8)) . '-' . $safeTool . '.txt';
            $path = $dir . '/' . $filename;

            $handle = @fopen($path, 'x');
            if ($handle === false) {
                return null;
            }

            @chmod($path, 0600);
            $written = fwrite($handle, $content);
            fclose($handle);

            if ($written !== strlen($content)) {
                @unlink($path);
                return null;
            }

            return new SpillRef(
                locator: "spill://{$this->sessionKey}/{$filename}",
                bytes: strlen($content),
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function read(string $locator, int $offset = 0, int $limit = 20000): array
    {
        $path = $this->resolve($locator);

        if ($path === null) {
            throw new \InvalidArgumentException("Malformed spill locator: {$locator}");
        }

        if (!file_exists($path) || is_link($path) || !is_file($path)) {
            throw new \RuntimeException("Spill content not found for locator: {$locator}");
        }

        $total = filesize($path) ?: 0;
        $offset = max(0, $offset);
        $limit = max(1, min($limit, 100_000));

        $content = $offset >= $total
            ? ''
            : (string) file_get_contents($path, false, null, $offset, $limit);

        return [
            'content' => $content,
            'offset' => $offset,
            'length' => strlen($content),
            'total' => $total,
        ];
    }

    public function exists(string $locator): bool
    {
        $path = $this->resolve($locator);

        return $path !== null && !is_link($path) && is_file($path);
    }

    /**
     * Resolve a locator into an absolute path, or null when malformed.
     * The strict pattern (hex session key + conservative filename charset,
     * no slashes or leading dots) makes traversal impossible by construction.
     */
    private function resolve(string $locator): ?string
    {
        if (!preg_match(self::LOCATOR_PATTERN, $locator, $m)) {
            return null;
        }

        return $this->sessionDir($m[1]) . '/' . $m[2];
    }

    private function sessionDir(string $sessionKey): string
    {
        return rtrim($this->baseDir, '/') . '/session-' . $sessionKey;
    }
}
