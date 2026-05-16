<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Shared scratchpad agents read/write through during Squad execution.
 *
 * Replaces the master-slave channel: there is no orchestrator agent
 * relaying messages between workers. Instead each `AgentStep` reads
 * prior outputs off the pipeline `PipelineContext`, and optionally
 * leaves structured notes for later steps on this blackboard.
 *
 * The blackboard is intentionally append-mostly — writes are tagged
 * with the writer's role name and a monotonic revision so a resume
 * from step N can re-apply writes from steps 0..N-1 deterministically.
 *
 * NB this is NOT a thread-safe in-memory queue; the pipeline engine
 * already serialises step execution per phase, so adding locks would
 * be cargo-culted complexity. If we move to truly parallel writes we
 * revisit it then.
 */
final class Blackboard
{
    /** @var array<int, array{role: string, key: string, value: mixed}> */
    private array $entries = [];

    /** @var array<string, mixed> Last-write-wins view, indexed by key. */
    private array $latest = [];

    public function write(string $role, string $key, mixed $value): void
    {
        $this->entries[] = ['role' => $role, 'key' => $key, 'value' => $value];
        $this->latest[$key] = $value;
    }

    public function read(string $key, mixed $default = null): mixed
    {
        return $this->latest[$key] ?? $default;
    }

    /**
     * Full write log — for replay or audit.
     *
     * @return array<int, array{role: string, key: string, value: mixed}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Rehydrate from a saved entries list (used by SquadResumeManager).
     *
     * @param array<int, array{role: string, key: string, value: mixed}> $entries
     */
    public static function fromEntries(array $entries): self
    {
        $b = new self();
        foreach ($entries as $e) {
            $b->write($e['role'], $e['key'], $e['value']);
        }
        return $b;
    }
}
