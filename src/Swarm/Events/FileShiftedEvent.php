<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Events;

/**
 * Emitted when an agent in the swarm writes to a file that another agent
 * has previously read this session. Borrowed from jcode's coordinator
 * server, which sends a "code shifting under your feet" notification so
 * the affected agent can decide whether to re-read or proceed.
 *
 * Fields are deliberately small — receivers walk back to the canonical
 * file via `$path` and ask their own diff/read tool what changed. The
 * `summary` is best-effort and may be null when the change is too big or
 * the writer didn't give us a chance to capture before/after content.
 */
final class FileShiftedEvent
{
    public function __construct(
        /** Absolute (or worktree-relative) file path that was mutated. */
        public readonly string $path,
        /** Agent id that performed the write. */
        public readonly string $byAgent,
        /** UNIX seconds when the write was observed. */
        public readonly int $at,
        /** Best-effort one-line summary of the change ("class X gained method Y"); null when unknown. */
        public readonly ?string $summary = null,
        /** Hex sha hash of the file contents BEFORE the write (when known). */
        public readonly ?string $shaBefore = null,
        /** Hex sha hash of the file contents AFTER the write. */
        public readonly ?string $shaAfter = null,
    ) {
    }

    /**
     * Render as a structured tool-result-friendly array — receivers can
     * forward it through their own message channel without knowing the
     * concrete class.
     *
     * @return array{type:string, path:string, by_agent:string, at:int, summary:?string, sha_before:?string, sha_after:?string}
     */
    public function toArray(): array
    {
        return [
            'type'       => 'file_shifted',
            'path'       => $this->path,
            'by_agent'   => $this->byAgent,
            'at'         => $this->at,
            'summary'    => $this->summary,
            'sha_before' => $this->shaBefore,
            'sha_after'  => $this->shaAfter,
        ];
    }
}
