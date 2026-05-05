<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

use SuperAgent\Messages\Message;

/**
 * Ephemeral conversation fork — `git stash` for an agent thread.
 *
 * Maps the codex `/side` slash command into the SDK. Pattern:
 *
 *   1. **Snapshot the parent** — `Fork::from($parentMessages)` clones
 *      the parent's message list. The snapshot is read-only; the
 *      parent thread is never mutated by anything you do on the side.
 *
 *   2. **Work on the side** — `extend(...)` appends side-only turns.
 *      Try a different prompt, run a one-shot consult, ask the model
 *      to brainstorm an alternative — anything that would otherwise
 *      pollute the parent's context.
 *
 *   3. **Decide what survives** — when you're done, choose:
 *
 *      `discard()`           — throw the side away; parent is untouched.
 *      `promote(...$indexes)` — bring specific side messages back into
 *                               the parent (e.g. just the final
 *                               assistant answer).
 *      `promoteAll()`        — same but bring everything.
 *
 * This is a value object — no I/O, no LLM calls, no shared state.
 * Hosts wire it into their UI: codex's TUI returns to the parent on
 * Ctrl-D and offers `/promote` to fold selected side turns back in.
 *
 * The fork holds messages, not tokens. Cache-aware compaction still
 * works on a promoted thread — `CacheAwareCompressor` operates on
 * the assembled list and pins the head as usual.
 */
final class Fork
{
    /**
     * @param Message[] $parentSnapshot  Read-only snapshot of the parent.
     * @param Message[] $sideMessages    Turns added since the fork was made.
     */
    private function __construct(
        public readonly array $parentSnapshot,
        private array $sideMessages = [],
    ) {}

    /**
     * Open a fresh fork rooted at the given parent messages. The
     * parent list is shallow-cloned — Message objects are immutable
     * value objects so this is safe.
     *
     * @param Message[] $parentMessages
     */
    public static function from(array $parentMessages): self
    {
        return new self($parentMessages, []);
    }

    /**
     * Append one or more messages to the side conversation.
     */
    public function extend(Message ...$messages): self
    {
        foreach ($messages as $m) {
            $this->sideMessages[] = $m;
        }
        return $this;
    }

    /**
     * @return Message[]
     */
    public function sideMessages(): array
    {
        return $this->sideMessages;
    }

    /**
     * Full list a tool / model loop would see when running on the
     * side: parent + side.
     *
     * @return Message[]
     */
    public function assembled(): array
    {
        return array_merge($this->parentSnapshot, $this->sideMessages);
    }

    /**
     * Discard all side work and return the parent unchanged.
     *
     * @return Message[]
     */
    public function discard(): array
    {
        return $this->parentSnapshot;
    }

    /**
     * Bring specific side messages back into the parent. Useful when
     * the user wants to keep just the final answer / a particular
     * conclusion the side conversation produced. Indexes are
     * 0-based against `sideMessages()`; out-of-range entries are
     * silently skipped so a stale index from a TUI doesn't crash.
     *
     * @param int ...$indexes
     * @return Message[]
     */
    public function promote(int ...$indexes): array
    {
        $picked = [];
        foreach ($indexes as $i) {
            if (isset($this->sideMessages[$i])) {
                $picked[] = $this->sideMessages[$i];
            }
        }
        return array_merge($this->parentSnapshot, $picked);
    }

    /**
     * Bring every side message back into the parent.
     *
     * @return Message[]
     */
    public function promoteAll(): array
    {
        return array_merge($this->parentSnapshot, $this->sideMessages);
    }
}
