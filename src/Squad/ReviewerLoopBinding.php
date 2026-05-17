<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Binds a writer step to its reviewer (gate) step so the executor
 * can run "write → review → re-write on rejection" loops without
 * each YAML re-spelling the wiring.
 *
 * The triplet:
 *
 *   - `writer`     — step name that produces the artefact
 *   - `reviewer`   — step name that votes approve / reject + feedback
 *   - `feedback_key` — blackboard key where the reviewer drops its
 *                     feedback text on rejection; the executor reads
 *                     this and prepends it to the writer's next-run
 *                     prompt as a `## Reviewer feedback` block.
 *   - `max_retries` — hard cap so a stubborn rejection doesn't loop
 *                     forever (default 3). When exceeded the loop
 *                     terminates with the last writer output and a
 *                     `loop_aborted: true` flag in the blackboard
 *                     under `feedback_key.reason`.
 */
final class ReviewerLoopBinding
{
    public function __construct(
        public readonly string $writer,
        public readonly string $reviewer,
        public readonly string $feedbackKey,
        public readonly int $maxRetries = 3,
    ) {}

    public function toArray(): array
    {
        return [
            'writer'       => $this->writer,
            'reviewer'     => $this->reviewer,
            'feedback_key' => $this->feedbackKey,
            'max_retries'  => $this->maxRetries,
        ];
    }
}
