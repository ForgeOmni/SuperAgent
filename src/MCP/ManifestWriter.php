<?php

declare(strict_types=1);

namespace SuperAgent\MCP;

/**
 * Non-destructive sync skeleton for writers that materialise
 * SuperAgent-owned files under host-owned directories — project
 * `.mcp.json`, etc.
 *
 * Subclasses implement {@see self::render()} (how to serialise the
 * target state for a given input). Everything else — on-disk
 * comparison, user-edit detection, manifest round-trips, dry-run,
 * stale cleanup — lives in the base.
 *
 * Invariants preserved across every concrete subclass:
 *
 *   1. Byte-equal file on disk → status `unchanged`, no write.
 *   2. File differs AND manifest says we wrote the previous version →
 *      user has edited it; we do NOT overwrite. Manifest entry for
 *      this path is retained so the "we originally wrote it" evidence
 *      persists across the next sync.
 *   3. Source entry disappears → we delete the file we previously
 *      wrote, unless the user edited it (then `stale_kept`).
 *   4. User deleted our file → recreated on the next sync (the
 *      manifest treats "missing" as "ours to create").
 *   5. `dryRun=true` never touches disk or manifest.
 *
 * Status constants match SuperAICore's `AbstractManifestWriter`
 * vocabulary — consumers that already parse that taxonomy work
 * without translation.
 */
abstract class ManifestWriter
{
    public const STATUS_WRITTEN     = 'written';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user-edited';
    public const STATUS_REMOVED     = 'removed';
    public const STATUS_STALE_KEPT  = 'stale-kept';

    public function __construct(protected readonly Manifest $manifest) {}

    /**
     * Multi-target sync. Use when the subclass spits out N files
     * (e.g. a writer that materialises one agent-frontmatter file per
     * agent). Single-file writers can use {@see self::applyOne()} as
     * a fast path.
     *
     * @param  array<string, array{contents:string, source:?string}> $targets path → {contents, source}
     * @return array{written:list<string>, unchanged:list<string>, user_edited:list<string>, removed:list<string>, stale_kept:list<string>}
     */
    protected function applyTargets(array $targets, bool $dryRun): array
    {
        $report = [
            'written'     => [],
            'unchanged'   => [],
            'user_edited' => [],
            'removed'     => [],
            'stale_kept'  => [],
        ];

        $previousEntries = $this->manifest->read();
        $nextEntries     = [];

        foreach ($targets as $path => $target) {
            $contents = (string) $target['contents'];
            $hash = hash('sha256', $contents);

            if (is_file($path)) {
                $onDisk  = (string) @file_get_contents($path);
                $current = hash('sha256', $onDisk);
                $ours    = $previousEntries[$path] ?? null;

                if ($current === $hash) {
                    $report['unchanged'][] = $path;
                    $nextEntries[$path]    = $hash;
                    continue;
                }

                if ($ours !== null && $ours !== $current) {
                    // We wrote it previously, the user has since edited it.
                    // Preserve their edit; keep our manifest entry so the
                    // "we were the original author" provenance survives.
                    $report['user_edited'][] = $path;
                    $nextEntries[$path]      = $ours;
                    continue;
                }
            }

            if (! $dryRun) {
                $dir = dirname($path);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($path, $contents);
            }
            $report['written'][] = $path;
            $nextEntries[$path]  = $hash;
        }

        // Stale cleanup: files we wrote in a previous sync that no longer
        // have a corresponding target.
        foreach ($previousEntries as $oldPath => $oldHash) {
            if (isset($targets[$oldPath])) continue;
            if (! is_file($oldPath)) continue;

            $current = hash('sha256', (string) @file_get_contents($oldPath));
            if ($current !== $oldHash) {
                $report['stale_kept'][] = $oldPath;
                $nextEntries[$oldPath]  = $oldHash;
                continue;
            }

            if (! $dryRun) {
                @unlink($oldPath);
            }
            $report['removed'][] = $oldPath;
        }

        if (! $dryRun) {
            $this->manifest->write($nextEntries);
        }

        return $report;
    }

    /**
     * Single-target fast path. Used by writers that emit one fixed
     * file (e.g. a project's `.mcp.json`).
     *
     * @return array{status:string, path:string}
     */
    protected function applyOne(string $path, string $contents, bool $dryRun = false): array
    {
        $hash = hash('sha256', $contents);
        $manifest = $this->manifest->read();

        if (is_file($path)) {
            $current = hash('sha256', (string) @file_get_contents($path));
            if ($current === $hash) {
                return ['status' => self::STATUS_UNCHANGED, 'path' => $path];
            }
            $ours = $manifest[$path] ?? null;
            if ($ours !== null && $ours !== $current) {
                return ['status' => self::STATUS_USER_EDITED, 'path' => $path];
            }
        }

        if (! $dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($path, $contents);

            $manifest[$path] = $hash;
            $this->manifest->write($manifest);
        }

        return ['status' => self::STATUS_WRITTEN, 'path' => $path];
    }
}
