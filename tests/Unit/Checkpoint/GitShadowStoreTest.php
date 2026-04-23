<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Checkpoint;

use PHPUnit\Framework\TestCase;
use SuperAgent\Checkpoint\GitShadowStore;

/**
 * End-to-end exercise of GitShadowStore against a real `git` binary.
 *
 * The MVP contract we pin here:
 *   1. init() creates a bare shadow repo outside the project's own
 *      .git (if any), under <history>/<project-hash>/shadow.git.
 *   2. snapshot() returns a commit hash. List reflects it.
 *   3. restore() reverts tracked files; untracked files are left
 *      alone (so undo stays reversible by a subsequent snapshot).
 *   4. Two distinct project roots have distinct shadow dirs even
 *      when sharing the same parent history dir.
 *   5. Snapshot with --allow-empty succeeds on an unchanged worktree.
 *   6. has() is a cheap existence probe that doesn't throw.
 */
class GitShadowStoreTest extends TestCase
{
    private string $projectA;
    private string $projectB;
    private string $historyDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Skip cleanly when git isn't installed — CI runners usually
        // have it, but keep the test non-mandatory for minimal hosts.
        if (! self::gitAvailable()) {
            $this->markTestSkipped('git binary not found on PATH');
        }
        $root = sys_get_temp_dir() . '/superagent-gitshadow-' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        $this->projectA  = $root . '/project-a';
        $this->projectB  = $root . '/project-b';
        $this->historyDir = $root . '/history';
        mkdir($this->projectA, 0755, true);
        mkdir($this->projectB, 0755, true);
        mkdir($this->historyDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->projectA));
        parent::tearDown();
    }

    public function test_init_creates_bare_shadow_repo(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();

        $this->assertTrue(is_dir($store->shadowDir()));
        $this->assertFileExists($store->shadowDir() . '/HEAD');
        $this->assertFileExists($store->shadowDir() . '/superagent-project-root');
        $this->assertStringContainsString(
            basename($this->projectA),
            (string) file_get_contents($store->shadowDir() . '/superagent-project-root'),
        );
    }

    public function test_init_is_idempotent(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        $firstMtime = filemtime($store->shadowDir() . '/HEAD');
        // Sleep 1s so the filesystem mtime granularity can differentiate
        // if anything were to re-initialize. Then init again.
        clearstatcache();
        $store->init();
        $secondMtime = filemtime($store->shadowDir() . '/HEAD');
        $this->assertSame($firstMtime, $secondMtime, 'second init must NOT re-create the repo');
    }

    public function test_snapshot_and_restore_roundtrip(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();

        // Initial worktree state.
        file_put_contents($this->projectA . '/greeting.txt', 'hello v1');
        $hash1 = $store->snapshot('initial state');
        $this->assertMatchesRegularExpression('#^[0-9a-f]{40}$#', $hash1);

        // Modify and snapshot again.
        file_put_contents($this->projectA . '/greeting.txt', 'hello v2 — changed');
        file_put_contents($this->projectA . '/new.txt', 'new file');
        $hash2 = $store->snapshot('after edit');
        $this->assertNotSame($hash1, $hash2);

        // Restore to the initial snapshot. greeting.txt reverts.
        $store->restore($hash1);
        $this->assertSame('hello v1', file_get_contents($this->projectA . '/greeting.txt'));
    }

    public function test_restore_does_not_delete_files_added_after_snapshot(): void
    {
        // Safety property: restore only reverts tracked files. Files
        // created AFTER the snapshot stay in place so the user can
        // re-snapshot and recover if restore was a mistake.
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        file_put_contents($this->projectA . '/a.txt', 'one');
        $hash = $store->snapshot('baseline');

        file_put_contents($this->projectA . '/added-later.txt', 'user work');
        file_put_contents($this->projectA . '/a.txt', 'modified');

        $store->restore($hash);
        $this->assertSame('one', file_get_contents($this->projectA . '/a.txt'));
        $this->assertFileExists(
            $this->projectA . '/added-later.txt',
            'restore must NOT delete files added after the snapshot',
        );
    }

    public function test_list_returns_newest_first(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        file_put_contents($this->projectA . '/x.txt', '1');
        $h1 = $store->snapshot('first');
        sleep(1);   // ensure distinct commit timestamps
        file_put_contents($this->projectA . '/x.txt', '2');
        $h2 = $store->snapshot('second');
        sleep(1);
        file_put_contents($this->projectA . '/x.txt', '3');
        $h3 = $store->snapshot('third');

        $list = $store->list();
        $this->assertCount(3, $list);
        // Newest first.
        $this->assertSame($h3, $list[0]['hash']);
        $this->assertSame('third', $list[0]['label']);
        $this->assertSame($h2, $list[1]['hash']);
        $this->assertSame($h1, $list[2]['hash']);
        $this->assertIsInt($list[0]['timestamp']);
    }

    public function test_two_projects_have_distinct_shadow_dirs(): void
    {
        $a = new GitShadowStore($this->projectA, $this->historyDir);
        $b = new GitShadowStore($this->projectB, $this->historyDir);
        $this->assertNotSame($a->shadowDir(), $b->shadowDir());
        // Both under the same history dir but different hashes.
        $this->assertStringStartsWith($this->historyDir . '/', $a->shadowDir());
        $this->assertStringStartsWith($this->historyDir . '/', $b->shadowDir());
    }

    public function test_snapshot_with_no_changes_succeeds_via_allow_empty(): void
    {
        // Policy checkpoints (e.g. "before tool call X") should be
        // creatable even when the tool call didn't actually change
        // any files — the user may still want to restore to that
        // marker later.
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        file_put_contents($this->projectA . '/a.txt', 'x');
        $first = $store->snapshot('init');
        $second = $store->snapshot('same state — allow empty');
        $this->assertNotSame($first, $second, '--allow-empty should still create a fresh commit');
    }

    public function test_has_returns_false_for_unknown_hash(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        file_put_contents($this->projectA . '/x.txt', '1');
        $store->snapshot('only');
        $this->assertFalse($store->has('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function test_has_returns_true_for_known_hash(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        file_put_contents($this->projectA . '/x.txt', '1');
        $hash = $store->snapshot('known');
        $this->assertTrue($store->has($hash));
        $this->assertTrue($store->has(substr($hash, 0, 12)), 'short prefix should match');
    }

    public function test_restore_rejects_bogus_hash_strings(): void
    {
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid commit hash/');
        $store->restore('not-a-hash; rm -rf /');
    }

    public function test_constructor_rejects_nonexistent_project_root(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a directory/');
        new GitShadowStore('/definitely/not/a/real/path', $this->historyDir);
    }

    public function test_snapshot_respects_project_gitignore(): void
    {
        // The shadow repo uses the project as its worktree — so
        // `git add -A` reads the project's own .gitignore. Secrets
        // listed there are excluded from snapshots.
        $store = new GitShadowStore($this->projectA, $this->historyDir);
        $store->init();
        file_put_contents($this->projectA . '/.gitignore', "secrets.txt\n");
        file_put_contents($this->projectA . '/secrets.txt', 'hunter2');
        file_put_contents($this->projectA . '/public.txt', 'hi');
        $store->snapshot('with gitignore');

        // The shadow repo's show-tree should NOT include secrets.txt.
        $shadow = $store->shadowDir();
        $tree = trim((string) shell_exec('git --git-dir=' . escapeshellarg($shadow) . ' ls-tree -r HEAD --name-only 2>&1'));
        $files = array_values(array_filter(explode("\n", $tree)));
        $this->assertContains('.gitignore', $files);
        $this->assertContains('public.txt', $files);
        $this->assertNotContains(
            'secrets.txt',
            $files,
            '.gitignored files must NOT be captured by the shadow repo',
        );
    }

    // ── helpers ───────────────────────────────────────────────────

    private static function gitAvailable(): bool
    {
        $out = @shell_exec('command -v git 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
