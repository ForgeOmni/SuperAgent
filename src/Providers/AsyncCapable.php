<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Contract for providers (or provider sub-features) whose work completes
 * asynchronously — agent swarms, batch jobs, media generation, long-form TTS.
 *
 * The four-method shape is deliberately the minimal set every upstream async
 * API exposes:
 *   - `submit`  kicks off the job and returns a handle the caller keeps.
 *   - `poll`    cheaply asks for the latest status.
 *   - `fetch`   retrieves the final payload (call only after the status is `Done`).
 *   - `cancel`  best-effort abort; returns false if the provider refuses or the
 *               job has already terminated.
 *
 * Callers are expected to drive the loop themselves (or delegate it to a
 * sync-wrapper Tool) — this interface never blocks. That keeps the agent loop
 * free to interleave other work or surface long-running job handles back to
 * the user.
 *
 * Specialised interfaces (SupportsSwarm, SupportsTTS, SupportsVideo, …) extend
 * this contract with submit-variants that take properly-typed inputs; the
 * poll/fetch/cancel side stays uniform.
 */
interface AsyncCapable
{
    public function poll(JobHandle $handle): JobStatus;

    /**
     * Retrieve the final payload. Only meaningful after `poll()` has returned
     * `JobStatus::Done`; implementations MAY throw if called earlier.
     *
     * The return type is intentionally `mixed` because the shape differs per
     * job kind (video URL, TTS audio bytes, swarm transcript, batch JSONL, …).
     * The specialised interface that declared the `submit*` method is the
     * authoritative source for what `fetch` returns.
     */
    public function fetch(JobHandle $handle): mixed;

    /**
     * Best-effort cancellation. Returns true when the provider acknowledged
     * the cancel request (status transitions to `Canceled` or terminal).
     * Returns false if the provider rejects the cancel or the job is already
     * terminal — callers should treat either outcome as "stop polling".
     */
    public function cancel(JobHandle $handle): bool;
}
