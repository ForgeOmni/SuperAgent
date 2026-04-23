<?php

namespace SuperAgent\Auth;

/**
 * File-based credential storage for API keys and OAuth tokens.
 *
 * Files live under ~/.superagent/credentials/ with mode 0600 (owner-read+write only).
 * Since v0.8.7, each file is encrypted at rest with AES-256-GCM via CredentialCipher.
 * Plaintext files left over from older versions are read transparently and
 * auto-migrated to encrypted form on the next write.
 *
 * Set SUPERAGENT_CREDENTIAL_KEY in the environment to override the on-disk key.
 */
class CredentialStore
{
    private string $baseDir;
    private ?CredentialCipher $cipher = null;
    private bool $encryptionEnabled;

    public function __construct(?string $baseDir = null, ?CredentialCipher $cipher = null, ?bool $encryptionEnabled = null)
    {
        if ($baseDir !== null) {
            $this->baseDir = $baseDir;
        } else {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
            if (! $home && PHP_OS_FAMILY === 'Windows') {
                $home = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? '');
            }
            $this->baseDir = rtrim((string) $home, "\\/") . '/.superagent/credentials';
        }

        // Allow opting out via env (tests, debugging) but default ON.
        // NB: use `!== false` instead of `?: ''` because '0' is falsy in PHP.
        if ($encryptionEnabled !== null) {
            $this->encryptionEnabled = $encryptionEnabled;
        } else {
            $envValue = getenv('SUPERAGENT_CREDENTIAL_ENCRYPTION');
            $this->encryptionEnabled = ! ($envValue !== false
                && in_array(strtolower($envValue), ['0', 'false', 'off'], true));
        }

        if ($this->encryptionEnabled) {
            $this->cipher = $cipher ?? new CredentialCipher($this->baseDir . '/.key');
        }
    }

    public function store(string $provider, string $key, string $value): void
    {
        $dir = $this->baseDir;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $path = "{$dir}/{$provider}.json";
        $data = $this->loadFile($path);
        $data[$key] = $value;
        $this->writeFile($path, $data);
    }

    public function get(string $provider, string $key): ?string
    {
        $path = "{$this->baseDir}/{$provider}.json";
        $data = $this->loadFile($path);
        return $data[$key] ?? null;
    }

    public function delete(string $provider, ?string $key = null): void
    {
        $path = "{$this->baseDir}/{$provider}.json";
        if ($key === null) {
            @unlink($path);
            return;
        }
        $data = $this->loadFile($path);
        unset($data[$key]);
        $this->writeFile($path, $data);
    }

    public function has(string $provider, string $key): bool
    {
        return $this->get($provider, $key) !== null;
    }

    public function listProviders(): array
    {
        if (!is_dir($this->baseDir)) return [];
        $providers = [];
        foreach (glob("{$this->baseDir}/*.json") as $file) {
            $providers[] = basename($file, '.json');
        }
        return $providers;
    }

    /**
     * Run the given critical section under a cross-process file lock
     * scoped to one provider. Used to serialize OAuth token refreshes
     * so two parallel SuperAgent sessions don't race-write the same
     * credential file (one would win, the other's fresh token gets
     * overwritten with a stale cached copy).
     *
     * Mirrors the pattern in MoonshotAI/qwen-code
     * (`packages/core/src/qwen/sharedTokenManager.ts:1-200`): LOCK_EX
     * with a short-timeout acquisition, stale-lock detection by pid +
     * mtime, and the critical section runs under the lock.
     *
     * - Lock file path: `<baseDir>/<provider>.lock` (separate from the
     *   credential `.json` so the lock survives the atomic-replace
     *   rename that `writeFile` does).
     * - Acquisition: non-blocking `flock(LOCK_EX|LOCK_NB)` with
     *   exponential backoff up to `$timeoutSeconds` (default 5s). If
     *   we can't acquire, check whether the current holder is still
     *   alive (via its recorded pid); if not, steal the lock.
     * - Release: unlock + best-effort unlink. If a crash leaves the
     *   `.lock` file behind, the next `withLock` acquires cleanly
     *   because `flock` is OS-level.
     * - Return value: whatever the critical section returns.
     *
     * @template T
     * @param  \Closure(): T $critical  Runs under the lock.
     * @param  int $timeoutSeconds      Max wait before giving up.
     * @return T
     * @throws \RuntimeException on acquire timeout (after stale-steal attempt)
     */
    public function withLock(string $provider, \Closure $critical, int $timeoutSeconds = 5): mixed
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0700, true);
        }
        $lockPath = "{$this->baseDir}/" . preg_replace('/[^a-z0-9_.-]/i', '_', $provider) . '.lock';

        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("CredentialStore: cannot open lock file {$lockPath}");
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $sleep = 10_000;   // microseconds, starts at 10ms
        $stolenOnce = false;

        while (true) {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                // Last-ditch: is the current holder dead? If so, steal
                // exactly once before giving up.
                if (!$stolenOnce && self::holderIsStale($lockPath)) {
                    $stolenOnce = true;
                    @fclose($fh);
                    @unlink($lockPath);
                    $fh = @fopen($lockPath, 'c+');
                    if ($fh !== false && @flock($fh, LOCK_EX | LOCK_NB)) {
                        break;
                    }
                }
                if (is_resource($fh)) {
                    @fclose($fh);
                }
                throw new \RuntimeException(
                    "CredentialStore: timed out waiting for {$provider} lock after {$timeoutSeconds}s"
                );
            }
            usleep($sleep);
            $sleep = min($sleep * 2, 250_000);   // cap at 250ms per sleep
        }

        // Write our identity into the lock file so a future holder can
        // check `holderIsStale`. Best-effort — loss is recoverable.
        @ftruncate($fh, 0);
        @fwrite($fh, getmypid() . ':' . (gethostname() ?: 'unknown') . ':' . time() . "\n");
        @fflush($fh);

        try {
            return $critical();
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            // Drop the lock file so `ls -la ~/.superagent/credentials/`
            // doesn't show stale `.lock` files after clean runs. The
            // next holder gets a fresh file.
            @unlink($lockPath);
        }
    }

    /**
     * Does the lock file's recorded holder still exist?
     * Used by `withLock()` to steal a lock when a crashed process
     * left the lock orphaned.
     *
     * The heuristic: read `<pid>:<host>:<ts>`. If the timestamp is
     * older than 30s AND the pid no longer maps to a running process,
     * we consider it stale. We accept the tiny race window (pid
     * reused by an unrelated process) because the cost is at most a
     * redundant refresh, not data loss.
     */
    private static function holderIsStale(string $lockPath): bool
    {
        if (!is_readable($lockPath)) {
            return true;   // file gone entirely — safe to treat as stale
        }
        $contents = (string) @file_get_contents($lockPath);
        if ($contents === '') {
            return true;
        }
        $parts = explode(':', trim($contents), 3);
        if (count($parts) < 3) {
            return true;
        }
        [$pid, $_host, $tsStr] = $parts;
        $ts = (int) $tsStr;
        if ($ts === 0 || (time() - $ts) < 30) {
            return false;   // too fresh; don't steal
        }
        $pidNum = (int) $pid;
        if ($pidNum <= 0) {
            return true;
        }
        if (function_exists('posix_kill')) {
            // posix_kill($pid, 0) returns true if pid exists / we can signal.
            return ! @posix_kill($pidNum, 0);
        }
        // Fallback: no posix_kill on Windows. Treat anything older
        // than 2min as stale — worst case we steal prematurely once
        // and a redundant refresh happens.
        return (time() - $ts) > 120;
    }

    private function loadFile(string $path): array
    {
        if (!file_exists($path)) return [];
        $raw = file_get_contents($path);
        if ($raw === false) return [];

        if ($this->cipher !== null && CredentialCipher::isEncrypted($raw)) {
            try {
                $raw = $this->cipher->decrypt($raw);
            } catch (AuthenticationException $e) {
                // Surface decryption errors instead of silently returning empty data —
                // that would look like "logged out" but the user's credentials still
                // sit on disk. Prompt them to re-login or fix the key.
                throw $e;
            }
        }
        // else: treat as plaintext JSON (legacy file or encryption disabled).

        return json_decode($raw, true) ?: [];
    }

    private function writeFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $blob = $this->cipher !== null ? $this->cipher->encrypt($json) : $json;

        // Atomic write with restrictive permissions
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, $blob);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
