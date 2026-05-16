<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Strategy for turning "agent A asks agent B a question" into a real
 * one-shot LLM call against B's session.
 *
 * Decoupling the policy from the mailbox lets:
 *   - tests substitute a deterministic answerer
 *   - production wire up the same dispatcher the orchestrator uses, so
 *     the peer sees the question on its OWN session_id (cache hit)
 *   - hosts running cross-process (queue worker, etc.) inject a remote
 *     answerer that round-trips through a job queue
 *
 * Contract: returns a plain-text reply. Empty string means the peer
 * declined / couldn't help — the asker decides what to do with that.
 */
interface PeerAnswerer
{
    public function answer(SquadRole $peerRole, string $question, string $fromRole): string;
}
