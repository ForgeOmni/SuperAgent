<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use Generator;
use SuperAgent\Messages\Message;
use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;
use SuperAgent\Tools\Tool;

/**
 * Provider can run a turn **detached from the connection**: the request is
 * acknowledged immediately, the model keeps working server-side, and the
 * caller polls (or re-attaches to) a stored response later.
 *
 * This is the Responses-API `background: true` lifecycle — submit → poll →
 * fetch, plus cancel and an explicit delete, because a background response
 * is a *stored object* and leaving it behind is a data-retention decision
 * the caller should get to make.
 *
 * Distinct from {@see SupportsBatch}: a batch is many requests submitted as
 * a file for throughput and a discount; a background response is one
 * ordinary turn whose only difference is that it outlives the HTTP request.
 * Use it when a turn can run for many minutes (deep agentic work at high
 * effort) and holding a stream open for it is the wrong shape — a queue
 * worker, a webhook-driven job, a CLI that should survive being closed.
 *
 * `poll()` / `fetch()` / `cancel()` come from {@see AsyncCapable} and behave
 * as documented there. `fetch()` on a background response returns the
 * assistant {@see Message} for the completed turn.
 */
interface SupportsBackgroundResponses extends AsyncCapable
{
    /**
     * Submit a turn to run in the background. Returns as soon as the
     * server has queued it — nothing is generated yet.
     *
     * Implementations force whatever the upstream requires for a detached
     * run (no streaming, server-side storage on) regardless of what the
     * caller passed.
     *
     * @param  Message[]            $messages
     * @param  Tool[]               $tools
     * @param  array<string, mixed> $options Same option surface as `chat()`.
     */
    public function submitBackground(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): JobHandle;

    /**
     * Re-attach to a submitted job and stream what is left of it.
     *
     * Yields the same assistant {@see Message} shape `chat()` does, so a
     * caller that resumes a job renders it exactly like a live turn.
     * `$startingAfter` resumes from a sequence number when the upstream
     * supports replay; null starts from the beginning of what is stored.
     *
     * @return Generator<int, Message>
     */
    public function followBackground(JobHandle $handle, ?int $startingAfter = null): Generator;

    /**
     * Delete the stored response behind a handle.
     *
     * Returns true when the server acknowledged the delete, false when the
     * object was already gone. After this the job can no longer be fetched
     * or referenced as a conversation parent.
     */
    public function deleteBackground(JobHandle $handle): bool;
}
