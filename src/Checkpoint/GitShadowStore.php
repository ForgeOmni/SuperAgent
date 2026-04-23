<?php

declare(strict_types=1);

namespace SuperAgent\Checkpoint;

/**
 * File-level snapshot + restore backed by a *shadow* git repository.
 *
 * The shadow repo is a bare git repo at
 * `~/.superagent/history/<project-hash>/shadow.git` — a completely
 * separate repo from the user's own `.git`. We use git only as a
 * cheap content-addressed storage layer:
 *
 *   - `snapshot(label)` runs `git add -A && git commit` with the
 *     user's project directory as the worktree, producing a fresh
 *     commit in the shadow repo. `.gitignore` in the project is
 *     respected because `git add -A` reads it from the worktree.
 *   - `restore(hash)` runs `git checkout --force <hash> -- .` against
 *     the user's worktree, reverting every tracked file to the
 *     state captured at snapshot time. Untracked files (anything
 *     added since the snapshot) are NOT deleted — intentional, it
 *     keeps restore reversible by a subsequent snapshot.
 *   - `list()` walks `git log` and yields commit metadata.
 *
 * Inspired by qwen-code's checkpointing
 * (`docs/users/features/checkpointing.md` + `services/gitService.ts`).
 * Same "never touch the user's .git" discipline: the shadow repo
 * is outside the project dir, keyed by a hash of the absolute
 * project path so two distinct projects never collide.
 *
 * Limitations called out for callers:
 *   - Shells out to `git` CLI. If the binary isn't on PATH,
 *     `init()` throws. We don't ship libgit2 bindings.
 *   - Snapshots include any files git would normally add — which
 *     means `.env` files, secrets, etc. are captured in the shadow
 *     repo IF they're not gitignored. Consider a project-level
 *     `.gitignore` entry before wiring checkpoints into secret-bearing
 *     workflows.
 *   - No LFS / large-file handling. Multi-GB repos will make
 *     snapshots slow; this is a design smell for heavy users, but
 *     tolerable for the usual agent-editing-a-codebase scenario.
 */
final class GitShadowStore
{
    private readonly string $projectRoot;
    private readonly string $shadowDir;

    public function __construct(string $projectRoot, ?string $baseHistoryDir = null)
    {
        $resolved = realpath($projectRoot);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new \RuntimeException("GitShadowStore: project root not a directory: {$projectRoot}");
        }
        $this->projectRoot = $resolved;

        $base = $baseHistoryDir ?? self::defaultBaseDir();
        // Project path hash — sha256 for collision resistance,
        // truncated to 16 hex chars for a readable dir name.
        $hash = substr(hash('sha256', $this->projectRoot), 0, 16);
        $this->shadowDir = rtrim($base, '/\\') . '/' . $hash . '/shadow.git';
    }

    public static function defaultBaseDir(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/history';
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function shadowDir(): string
    {
        return $this->shadowDir;
    }

    /**
     * Create the bare shadow repo if it doesn't exist. Idempotent —
     * a second init() against an existing shadow repo is a no-op.
     * Also writes a `superagent-project-root` sidecar file inside
     * the shadow dir so a human debugging the history directory
     * can tell which project this repo shadows.
     */
    public function init(): void
    {
        if (self::looksInitialized($this->shadowDir)) {
            return;
        }
        $parent = dirname($this->shadowDir);
        if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new \RuntimeException("GitShadowStore: cannot create {$parent}");
        }
        $this->runGit(['init', '--bare', $this->shadowDir], cwd: $parent);
        // Configure the shadow repo so snapshots don't require
        // user-level `git config --global user.email/name` to be set.
        $this->runGit(['-C', $this->shadowDir, 'config', 'user.email', 'superagent@localhost']);
        $this->runGit(['-C', $this->shadowDir, 'config', 'user.name',  'SuperAgent Shadow']);
        // Debug breadcrumb — not required, purely informational.
        @file_put_contents(
            $this->shadowDir . '/superagent-project-root',
            $this->projectRoot . "\n",
        );
    }

    /**
     * Snapshot the current worktree state as a new commit on the
     * shadow repo's default branch. Always creates a commit, even
     * if the worktree hasn't changed since the last snapshot — uses
     * `--allow-empty` so callers can pin policy-level checkpoints
     * (e.g. "before tool call X") regardless of whether any file
     * actually changed.
     *
     * Returns the commit SHA of the new snapshot.
     */
    public function snapshot(string $label): string
    {
        $this->ensureInitialized();

        // Stage everything that git would normally add — respects the
        // project's `.gitignore` because the worktree is the project.
        $this->runGit($this->gitCmd(['add', '-A']));
        // Commit with --allow-empty so policy checkpoints survive a
        // no-op save. Label goes into the commit message for easy
        // `git log` inspection by humans.
        $this->runGit($this->gitCmd([
            'commit',
            '--allow-empty',
            '--no-verify',   // user hooks in the project's .git should NOT fire on shadow commits
            '-m',
            $this->sanitizeLabel($label),
        ]));

        // Grab HEAD of the shadow repo.
        $head = trim($this->runGit(['-C', $this->shadowDir, 'rev-parse', 'HEAD']));
        if ($head === '' || strlen($head) < 7) {
            throw new \RuntimeException('GitShadowStore: snapshot succeeded but rev-parse HEAD returned nothing');
        }
        return $head;
    }

    /**
     * Revert the project worktree to the state captured by `$hash`.
     *
     * Semantics (deliberately asymmetric with `snapshot`):
     *   - Tracked files are restored to the snapshot state.
     *   - Untracked files added since the snapshot are LEFT in
     *     place. That way a subsequent `snapshot()` captures them
     *     again — undo stays undoable.
     *
     * Callers that want hard reset (delete untracked) can do a
     * `restore()` followed by their own `git clean` or equivalent;
     * we don't do it automatically because losing user work is
     * irreversible.
     */
    public function restore(string $hash): void
    {
        $this->ensureInitialized();
        if (! preg_match('#^[0-9a-f]{7,40}$#i', $hash)) {
            throw new \RuntimeException("GitShadowStore: invalid commit hash '{$hash}'");
        }
        $this->runGit($this->gitCmd(['checkout', '--force', $hash, '--', '.']));
    }

    /**
     * List commits in the shadow repo, newest first.
     *
     * @return list<array{hash:string, timestamp:int, label:string}>
     */
    public function list(): array
    {
        if (! self::looksInitialized($this->shadowDir)) {
            return [];
        }
        $raw = $this->runGit(['-C', $this->shadowDir, 'log', '--format=%H%x00%at%x00%s']);
        $out = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\x00", $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $out[] = [
                'hash' => $parts[0],
                'timestamp' => (int) $parts[1],
                'label' => $parts[2],
            ];
        }
        return $out;
    }

    /**
     * Is there a snapshot with the given hash (even a prefix)?
     * Cheaper than `list()` when the caller just needs a sanity
     * check before attempting restore.
     */
    public function has(string $hash): bool
    {
        if (! self::looksInitialized($this->shadowDir)) {
            return false;
        }
        try {
            $this->runGit(['-C', $this->shadowDir, 'cat-file', '-e', $hash . '^{commit}']);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    // ── Internals ─────────────────────────────────────────────────

    private function ensureInitialized(): void
    {
        if (! self::looksInitialized($this->shadowDir)) {
            throw new \RuntimeException(
                'GitShadowStore: shadow repo not initialized. Call init() first.'
            );
        }
    }

    /**
     * Bare repos have a `HEAD` file + `refs/` / `objects/` subdirs at
     * the top level (no `.git` wrapper). We probe for `HEAD` as the
     * cheapest signal the repo exists without shelling out to git.
     */
    private static function looksInitialized(string $shadowDir): bool
    {
        return is_dir($shadowDir) && is_file($shadowDir . '/HEAD');
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function gitCmd(array $args): array
    {
        // Every worktree-touching command needs both --git-dir and
        // --work-tree; centralized here so we don't forget one.
        return array_merge(
            ['--git-dir', $this->shadowDir, '--work-tree', $this->projectRoot],
            $args,
        );
    }

    private function sanitizeLabel(string $label): string
    {
        // Collapse newlines so one-line `git log --oneline` stays readable.
        $sanitized = preg_replace('/\s+/', ' ', trim($label));
        $sanitized = (string) $sanitized;
        if ($sanitized === '') {
            $sanitized = 'checkpoint';
        }
        // Hard cap — long labels make `git log` ugly and eat disk
        // when multiplied across thousands of snapshots.
        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 200) . '…';
        }
        return $sanitized;
    }

    /**
     * Execute git with the given args. Returns stdout. Throws
     * RuntimeException on non-zero exit with stderr in the message.
     *
     * @param list<string> $args
     */
    private function runGit(array $args, ?string $cwd = null): string
    {
        $cmd = array_merge(['git'], $args);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptorSpec, $pipes, $cwd ?? $this->projectRoot);
        if (! is_resource($proc)) {
            throw new \RuntimeException("GitShadowStore: failed to spawn `git " . implode(' ', array_slice($args, 0, 3)) . "…`");
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException(
                "git " . ($args[0] ?? '?') . " failed (exit {$exit}): " . trim($stderr)
            );
        }
        return $stdout;
    }
}
