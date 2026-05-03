<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use SuperAgent\Swarm\Events\FileShiftedEvent;

/**
 * Per-team ledger of "agent X read file F at sha S". When agent Y later
 * writes file F, the ledger looks up every agent that read it (excluding
 * Y itself) and emits a `FileShiftedEvent` to each one through the
 * provided callback.
 *
 * Borrowed from jcode's coordinator server, with the storage scope
 * narrowed to a single in-process team. Hosts that need cross-process
 * notification swap in their own callback (HTTP push to a peer's
 * webhook, Redis pub/sub, websocket broadcast).
 *
 * Storage is intentionally a hash-map in PHP — a swarm typically has
 * tens of agents and hundreds of distinct files in flight; that's well
 * under the size where we'd want a database. The class is final so its
 * memory layout stays predictable.
 *
 * @phpstan-type ReadEntry array{agent_id:string, sha:string, at:int}
 */
final class FileLedger
{
    /** path => list<ReadEntry> */
    private array $reads = [];

    /** Optional per-emit hook; default to no-op. */
    private $emitter;

    public function __construct(?callable $emitter = null)
    {
        $this->emitter = $emitter ?? static fn (string $agentId, FileShiftedEvent $e) => null;
    }

    /**
     * Override the emitter at runtime (e.g. when a `WebSocketProgressServer`
     * boots after the ledger was constructed).
     */
    public function setEmitter(callable $emitter): void
    {
        $this->emitter = $emitter;
    }

    /**
     * Record that `$agentId` read `$path`. Optional `$sha` shortcut for
     * callers that already hashed the content; otherwise we hash on
     * disk. Cheap when the file fits in memory; for huge files (lockfiles,
     * generated code) callers should pass a `null` sha to opt out of the
     * collision detection.
     */
    public function recordRead(string $agentId, string $path, ?string $sha = null): void
    {
        $entry = [
            'agent_id' => $agentId,
            'sha'      => $sha ?? $this->shaFile($path),
            'at'       => time(),
        ];
        $this->reads[$path] ??= [];
        // De-dup by (agent_id, sha) so a tight-loop of reads doesn't blow
        // the ledger up; first record wins for the (agent_id, path, sha)
        // tuple.
        foreach ($this->reads[$path] as $existing) {
            if ($existing['agent_id'] === $agentId && $existing['sha'] === $entry['sha']) {
                return;
            }
        }
        $this->reads[$path][] = $entry;
    }

    /**
     * Record that `$agentId` wrote `$path`. For each peer with a stale
     * read on the path, emit a `FileShiftedEvent` and prune their ledger
     * entry so a follow-up write doesn't refire.
     *
     * `$summary` is forwarded onto the event for receivers; pass null
     * (default) when no cheap summary is available.
     */
    public function recordWrite(string $agentId, string $path, ?string $summary = null): array
    {
        $shaAfter = $this->shaFile($path);
        $eventsEmitted = [];

        $remaining = [];
        foreach ($this->reads[$path] ?? [] as $entry) {
            if ($entry['agent_id'] === $agentId) {
                // The writer's own read entry is no longer useful — they
                // know what they just wrote. Drop without emit.
                continue;
            }
            if ($entry['sha'] === $shaAfter) {
                // Bytes happen to match; no functional change for the
                // peer (e.g. write-back of identical content). Keep the
                // entry; do not emit.
                $remaining[] = $entry;
                continue;
            }
            $event = new FileShiftedEvent(
                path:      $path,
                byAgent:   $agentId,
                at:        time(),
                summary:   $summary,
                shaBefore: $entry['sha'],
                shaAfter:  $shaAfter,
            );
            ($this->emitter)($entry['agent_id'], $event);
            $eventsEmitted[] = $entry['agent_id'];
            // Drop the stale read entry so the next write to the same
            // path doesn't fire again until the peer re-reads.
        }
        $this->reads[$path] = $remaining;
        return $eventsEmitted;
    }

    /**
     * Inspect peer collisions WITHOUT emitting events. Useful for tests
     * and for hosts that want to render a "N peers will be notified
     * about this write" warning before committing.
     *
     * @return list<string>  agent ids that have stale reads on the path
     */
    public function peerCollisions(string $agentId, string $path): array
    {
        $shaCurrent = $this->shaFile($path);
        $stale = [];
        foreach ($this->reads[$path] ?? [] as $entry) {
            if ($entry['agent_id'] === $agentId) continue;
            if ($entry['sha'] === $shaCurrent) continue;
            $stale[] = $entry['agent_id'];
        }
        return $stale;
    }

    /** Forget every read entry for a path (worktree teardown). */
    public function clearPath(string $path): void
    {
        unset($this->reads[$path]);
    }

    /** Forget every read entry for an agent (agent shut down). */
    public function clearAgent(string $agentId): void
    {
        foreach ($this->reads as $path => $entries) {
            $remaining = array_values(array_filter(
                $entries,
                static fn (array $e) => $e['agent_id'] !== $agentId,
            ));
            if ($remaining === []) {
                unset($this->reads[$path]);
            } else {
                $this->reads[$path] = $remaining;
            }
        }
    }

    /** @return array<string, list<ReadEntry>>  full ledger snapshot (test-only seam) */
    public function snapshot(): array
    {
        return $this->reads;
    }

    private function shaFile(string $path): string
    {
        if (!is_file($path)) return '';
        // sha1_file is fine for our purposes — collision risk is
        // negligible at this scale and it's ~3× faster than sha256.
        $hash = @sha1_file($path);
        return $hash === false ? '' : $hash;
    }
}
