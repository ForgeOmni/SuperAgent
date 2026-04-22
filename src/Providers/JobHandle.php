<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Opaque handle to a long-running provider job (agent swarm, video/music
 * generation, long-form TTS, batch submission, …).
 *
 * Held by the caller across `submit → poll → fetch` cycles. Immutable.
 *
 * The `kind` discriminant lets a single AsyncCapable implementation multiplex
 * several job types when the upstream API uses the same REST shape for all
 * of them (the MiniMax video + music endpoints, for example, share job IDs).
 *
 * `meta` exists so providers can stash provider-specific context needed at
 * poll time (e.g. Kimi swarm session id, MiniMax `X-GroupId`) without
 * leaking it into the callers signature. Treat the field as opaque.
 */
final class JobHandle
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $jobId,
        public readonly string $kind,
        public readonly int $createdAt,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function new(
        string $provider,
        string $jobId,
        string $kind,
        array $meta = [],
    ): self {
        return new self($provider, $jobId, $kind, time(), $meta);
    }

    /**
     * @return array{provider: string, job_id: string, kind: string, created_at: int, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'job_id' => $this->jobId,
            'kind' => $this->kind,
            'created_at' => $this->createdAt,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array{provider: string, job_id: string, kind: string, created_at?: int, meta?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'],
            jobId: $data['job_id'],
            kind: $data['kind'],
            createdAt: $data['created_at'] ?? time(),
            meta: $data['meta'] ?? [],
        );
    }
}
