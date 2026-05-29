<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

/**
 * claude-octopus-borrowed reaction event payload.
 *
 * Emitted by `SuperAICore\Console\Commands\GhWatchCommand` (or any host
 * that wants to plug into the same flow) and consumed by SuperAgent
 * hooks registered for `HookEvent::PR_EVENT`. Stays a pure DTO — no I/O,
 * no behaviour, so it can ride wire-formats other than HTTP if the host
 * pipes events through a queue.
 *
 * Fields mirror the GhWatchCommand event shape so hosts that integrate
 * directly with the GitHub API don't need a translation layer.
 */
final class PrWatchHookData
{
    public function __construct(
        public readonly string $kind,       // pr_comment | ci_failure | review_requested | …
        public readonly string $owner,
        public readonly string $repo,
        public readonly int    $prNumber,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        /** @var array<string,mixed> Free-form watcher-supplied context. */
        public readonly array  $context = [],
    ) {}

    public function toArray(): array
    {
        return [
            'kind'      => $this->kind,
            'owner'     => $this->owner,
            'repo'      => $this->repo,
            'pr_number' => $this->prNumber,
            'title'     => $this->title,
            'body'      => $this->body,
            'url'       => $this->url,
            'context'   => $this->context,
        ];
    }
}
