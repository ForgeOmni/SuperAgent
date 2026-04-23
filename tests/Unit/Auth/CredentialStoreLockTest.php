<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\CredentialStore;

/**
 * Cross-process lock contract for `CredentialStore::withLock()`.
 *
 * The mission: two SuperAgent sessions refreshing an OAuth token at
 * the same time must not race-write each other's state. We can't
 * easily spawn a second PHP process from a unit test (pcntl_fork
 * isn't universally available), so we simulate concurrency by
 * manipulating the lock file directly.
 */
class CredentialStoreLockTest extends TestCase
{
    private string $tmpDir;
    private CredentialStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/superagent-lock-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->store = new CredentialStore(
            baseDir: $this->tmpDir,
            encryptionEnabled: false,
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_happy_path_runs_closure_and_returns_value(): void
    {
        $result = $this->store->withLock('kimi-code', fn () => 'returned-value');
        $this->assertSame('returned-value', $result);
    }

    public function test_lock_file_is_cleaned_up_after_critical_section(): void
    {
        $this->store->withLock('kimi-code', fn () => null);
        $this->assertFileDoesNotExist($this->tmpDir . '/kimi-code.lock');
    }

    public function test_exception_in_critical_section_still_releases_lock(): void
    {
        try {
            $this->store->withLock('kimi-code', function () {
                throw new \RuntimeException('oops');
            });
            $this->fail('Exception should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertSame('oops', $e->getMessage());
        }
        // Subsequent lock must acquire immediately — if we leaked the
        // flock, this would hang (or timeout).
        $result = $this->store->withLock('kimi-code', fn () => 'recovered');
        $this->assertSame('recovered', $result);
    }

    public function test_held_lock_times_out_with_runtimeexception(): void
    {
        // Simulate another process holding the lock by opening the
        // lock file and flock'ing it here, keeping the handle live
        // while we call withLock with a tight timeout.
        $lockPath = $this->tmpDir . '/kimi-code.lock';
        $holder = fopen($lockPath, 'c+');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        // Write a fresh pid/ts so stale-steal doesn't kick in.
        ftruncate($holder, 0);
        fwrite($holder, getmypid() . ':self:' . time() . "\n");
        fflush($holder);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/timed out/');
            $this->store->withLock('kimi-code', fn () => 'unreachable', timeoutSeconds: 1);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    public function test_stale_lock_is_stolen_after_timeout(): void
    {
        // Write a lock file recording a dead pid (we pick one that
        // definitely doesn't exist) with an old timestamp. withLock
        // should try the normal acquire, time out, notice the holder
        // is stale, steal, and run the critical section.
        $lockPath = $this->tmpDir . '/kimi-code.lock';
        // pid 999999 is virtually guaranteed to not be running; ts
        // 2 minutes ago → past the 30s freshness window.
        file_put_contents($lockPath, "999999:ghost:" . (time() - 120) . "\n");
        // DO NOT flock this file — the holder is "dead", so the lock
        // should be acquirable by our flock immediately. But we also
        // need to make sure the mtime reflects the stale timestamp.
        @touch($lockPath, time() - 120);

        // Short timeout since we expect immediate acquisition (no
        // real flock contention — we only simulated the metadata).
        $result = $this->store->withLock('kimi-code', fn () => 'stole-it', timeoutSeconds: 1);
        $this->assertSame('stole-it', $result);
    }

    public function test_separate_providers_have_independent_locks(): void
    {
        // Holding kimi-code's lock must not block anthropic's.
        $lockPath = $this->tmpDir . '/kimi-code.lock';
        $holder = fopen($lockPath, 'c+');
        flock($holder, LOCK_EX | LOCK_NB);
        ftruncate($holder, 0);
        fwrite($holder, getmypid() . ':self:' . time() . "\n");

        try {
            $result = $this->store->withLock('anthropic', fn () => 'isolated', timeoutSeconds: 1);
            $this->assertSame('isolated', $result);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    public function test_exotic_provider_names_are_sanitized_into_lock_filename(): void
    {
        // Provider name with a slash (hypothetical attacker trying
        // path traversal) must not escape the baseDir.
        $result = $this->store->withLock('../../../evil', fn () => 'safe');
        $this->assertSame('safe', $result);
        // No lock file landed outside baseDir.
        $this->assertFileDoesNotExist($this->tmpDir . '/../../../evil.lock');
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
