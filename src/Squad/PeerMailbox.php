<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Per-squad message bus for peer-to-peer agent communication.
 *
 * Replaces the master-agent context-relay pattern: instead of a
 * coordinator LLM passing summaries between workers, agents talk
 * directly through this bus. Each role keeps its own context — what
 * it learns from peers comes via `ask()` (synchronous Q&A) or
 * `inbox()` (queued fire-and-forget messages it reads at the top of
 * its step).
 *
 * Two communication modes:
 *
 *   1. **Tell** (`send`, `broadcast`) — fire-and-forget, queued in
 *      the recipient's inbox. The recipient sees it on its next step.
 *
 *   2. **Ask** (`ask`) — synchronous request/reply. The caller blocks
 *      until the peer answers. Routed through a `PeerAnswerer` that
 *      dispatches a one-shot turn against the peer's stable session
 *      (so the peer sees the question in its OWN context — no master
 *      agent interpreting it for them).
 *
 * Both modes use stable per-role identifiers, so:
 *   - the peer's prompt-cache prefix survives the Q&A
 *   - the conversation history is preserved across questions
 *   - cross-process / cross-model communication works the same way as
 *     in-process (the answerer is the only thing that changes)
 */
final class PeerMailbox
{
    /** @var array<string, PeerMessage[]> Role name → queued inbox. */
    private array $inboxes = [];

    /** @var PeerMessage[] Full audit log — useful for resume and tests. */
    private array $log = [];

    /** @var array<string, SquadRole> Known roles, keyed by name. */
    private array $roles = [];

    public function __construct(
        private readonly PeerAnswerer $answerer,
    ) {}

    /**
     * Register the roles that exist in this squad so the mailbox can
     * validate `to:` targets, list peers for an asker, and resolve
     * sessions when answering.
     *
     * @param array<string, SquadRole> $roles
     */
    public function registerRoles(array $roles): void
    {
        $this->roles = $roles;
        foreach (array_keys($roles) as $name) {
            $this->inboxes[$name] ??= [];
        }
    }

    /**
     * Names of peers a given role is allowed to address (everyone but
     * itself, by default).
     *
     * @return string[]
     */
    public function peersOf(string $fromRole): array
    {
        return array_values(array_filter(array_keys($this->roles), fn ($n) => $n !== $fromRole));
    }

    /**
     * Fire-and-forget message. Queued in the recipient's inbox; the
     * peer sees it on its next dispatch.
     */
    public function send(string $from, string $to, string $body): void
    {
        $this->assertKnown($to);
        $msg = PeerMessage::tell($from, $to, $body);
        $this->inboxes[$to][] = $msg;
        $this->log[] = $msg;
    }

    /**
     * Broadcast to every peer except `$from`.
     */
    public function broadcast(string $from, string $body): void
    {
        foreach ($this->peersOf($from) as $to) {
            $this->send($from, $to, $body);
        }
    }

    /**
     * Synchronous question. Blocks until the peer's answerer returns.
     * The answer is also logged + appended to the asker's audit trail
     * (NOT injected into the asker's inbox — the caller already has
     * the reply in hand).
     */
    public function ask(string $from, string $to, string $question): string
    {
        $this->assertKnown($to);
        $peerRole = $this->roles[$to];

        $ask = PeerMessage::ask($from, $to, $question);
        $this->log[] = $ask;

        $body = $this->answerer->answer($peerRole, $question, $from);

        $reply = PeerMessage::reply($to, $from, $body, inReplyTo: $ask->from . ':' . $ask->createdAt);
        $this->log[] = $reply;

        return $body;
    }

    /**
     * Drain (and clear) the inbox for `$role`. Called by the dispatcher
     * at the top of each step so the agent receives queued messages.
     *
     * @return PeerMessage[]
     */
    public function drainInbox(string $role): array
    {
        $messages = $this->inboxes[$role] ?? [];
        $this->inboxes[$role] = [];
        return $messages;
    }

    /**
     * Peek without draining — used by tools and tests.
     *
     * @return PeerMessage[]
     */
    public function inbox(string $role): array
    {
        return $this->inboxes[$role] ?? [];
    }

    /** @return PeerMessage[] */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Render queued inbox messages as a Markdown context block the
     * dispatcher can prepend to the agent's prompt. Returns an empty
     * string when the inbox is empty.
     */
    public function renderInboxFor(string $role): string
    {
        $messages = $this->drainInbox($role);
        if ($messages === []) {
            return '';
        }
        $lines = ["## Peer messages"];
        foreach ($messages as $m) {
            $lines[] = sprintf('- **%s** → you: %s', $m->from, $m->body);
        }
        return implode("\n", $lines) . "\n";
    }

    private function assertKnown(string $role): void
    {
        if (!isset($this->roles[$role])) {
            throw new \InvalidArgumentException(
                "Unknown peer role '{$role}'. Known: " . implode(', ', array_keys($this->roles))
            );
        }
    }
}
