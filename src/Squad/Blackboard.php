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
 *
 * Typed entries (added 1.1):
 *
 *   - Every entry now carries a `kind` field with default `'note'`.
 *   - Pre-typed convenience kinds: `claim` (an agent's assertion),
 *     `evidence` (data/citation supporting a claim), `risk` (something
 *     to watch out for), `decision` (final call), `note` (everything
 *     else). Hosts can introduce their own kinds — the blackboard
 *     doesn't validate the set, just buckets by it.
 *   - `entriesBy(kind)` filters; `entriesByKind()` returns the
 *     `kind => entries[]` map for templates that want to render
 *     "claims / evidence / risks" sections separately.
 *   - Backwards compatible: callers that already used `write()` see
 *     no behaviour change (kind defaults to `'note'`), and existing
 *     `entries()` shape still works — it just gained a `kind` field
 *     on every row.
 */
class Blackboard
{
    public const KIND_NOTE     = 'note';
    public const KIND_CLAIM    = 'claim';
    public const KIND_EVIDENCE = 'evidence';
    public const KIND_RISK     = 'risk';
    public const KIND_DECISION = 'decision';

    /** @var array<int, array{role: string, key: string, value: mixed, kind: string}> */
    protected array $entries = [];

    /** @var array<string, mixed> Last-write-wins view, indexed by key. */
    protected array $latest = [];

    public function write(string $role, string $key, mixed $value, string $kind = self::KIND_NOTE): void
    {
        $this->entries[] = ['role' => $role, 'key' => $key, 'value' => $value, 'kind' => $kind];
        $this->latest[$key] = $value;
    }

    /**
     * Convenience aliases for the five well-known kinds. Hosts that
     * want to enforce a structured workflow (a researcher posts
     * claims with evidence; a verifier files risks; a decider stamps
     * decisions) get a typed API instead of stringly-typed kinds at
     * every call site.
     */
    public function claim(string $role, string $key, mixed $value): void
    {
        $this->write($role, $key, $value, self::KIND_CLAIM);
    }

    public function evidence(string $role, string $key, mixed $value): void
    {
        $this->write($role, $key, $value, self::KIND_EVIDENCE);
    }

    public function risk(string $role, string $key, mixed $value): void
    {
        $this->write($role, $key, $value, self::KIND_RISK);
    }

    public function decision(string $role, string $key, mixed $value): void
    {
        $this->write($role, $key, $value, self::KIND_DECISION);
    }

    public function read(string $key, mixed $default = null): mixed
    {
        return $this->latest[$key] ?? $default;
    }

    /**
     * Full write log — for replay or audit. Each row carries the
     * `kind` field (defaulting to `'note'` for pre-1.1 writes).
     *
     * @return array<int, array{role: string, key: string, value: mixed, kind: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Filter the log by kind. Useful when a template needs to render
     * "all claims" or "all risks" sections without re-walking the
     * full entries array.
     *
     * @return array<int, array{role: string, key: string, value: mixed, kind: string}>
     */
    public function entriesBy(string $kind): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $e) => $e['kind'] === $kind,
        ));
    }

    /**
     * Bucket the full log by `kind`. Empty buckets are omitted so
     * downstream renderers can iterate without empty-section noise.
     *
     * @return array<string, list<array{role:string, key:string, value:mixed, kind:string}>>
     */
    public function entriesByKind(): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            $out[$e['kind']] ??= [];
            $out[$e['kind']][] = $e;
        }
        return $out;
    }

    /**
     * Rehydrate from a saved entries list (used by SquadResumeManager).
     * Tolerates pre-1.1 entries without a `kind` field — those get
     * back-filled to `'note'`.
     *
     * @param array<int, array{role: string, key: string, value: mixed, kind?: string}> $entries
     */
    public static function fromEntries(array $entries): self
    {
        $b = new self();
        foreach ($entries as $e) {
            $b->write(
                (string) $e['role'],
                (string) $e['key'],
                $e['value'],
                (string) ($e['kind'] ?? self::KIND_NOTE),
            );
        }
        return $b;
    }
}
