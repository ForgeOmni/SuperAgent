<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

use SuperAgent\Squad\Blackboard;
use SuperAgent\Squad\PeerMailbox;

/**
 * Cross-mode shared state — the single object that flows through
 * every level of `auto/smart/squad` nesting so blackboards, costs,
 * sessions, and recursion bookkeeping never get reset by a child
 * call.
 *
 * Why it's a value object with `descend()` instead of mutable state:
 *
 *   - Recursion is explicit. Parent code calls `$ctx->descend('smart')`
 *     to produce the child context — that's the only point depth and
 *     mode_stack advance. Any leaked mutation of the parent is
 *     impossible because `Blackboard`, `PeerMailbox`, and
 *     `CostLedger` are shared *by reference* (intentional — they're
 *     append-mostly), while the depth/stack/policy fields are
 *     immutable per instance.
 *   - Easy to thread through dispatcher closures: the closure
 *     captures `$ctx`, calls `$ctx->descend(...)` to spawn a child
 *     mode invocation, the child writes to the same blackboard and
 *     ledger, parent reads back when the child returns.
 *
 * Constructing a fresh root context: `ModeContext::root('squad')`.
 * Inside a mode, when recursing: `$child = $ctx->descend($nextMode)`.
 */
final class ModeContext
{
    public function __construct(
        public readonly Blackboard $blackboard,
        public readonly ?PeerMailbox $mailbox,
        public readonly CostLedger $costLedger,
        public readonly string $rootSessionId,
        public readonly int $depth,
        /** @var list<string> */
        public readonly array $modeStack,
        public readonly CrossModePolicy $policy,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {}

    /**
     * Fresh root context. Hosts call this once at the top-level
     * dispatch site; everything downstream descends from it.
     *
     * @param array<string, mixed> $metadata
     */
    public static function root(
        string $entryMode,
        ?Blackboard $blackboard = null,
        ?CrossModePolicy $policy = null,
        ?string $sessionId = null,
        array $metadata = [],
    ): self {
        return new self(
            blackboard:    $blackboard ?? new Blackboard(),
            mailbox:       null,
            costLedger:    new CostLedger(),
            rootSessionId: $sessionId ?? bin2hex(random_bytes(6)),
            depth:         0,
            modeStack:     [$entryMode],
            policy:        $policy ?? new CrossModePolicy(),
            metadata:      $metadata,
        );
    }

    /**
     * Spawn a child context for a recursive mode call. Reuses every
     * shared collaborator (blackboard, mailbox, costLedger) — depth
     * +1 and the new mode appended to the stack.
     *
     * Throws when:
     *   - depth would exceed `policy->maxDepth`
     *   - cycle detected per `policy->hasCycle()`
     *   - budget cap already exceeded
     */
    public function descend(string $childMode): self
    {
        $newStack = [...$this->modeStack, $childMode];
        if ($this->depth + 1 > $this->policy->maxDepth) {
            throw new ModeDepthExceededException(
                "Cross-mode recursion exceeded max depth ({$this->policy->maxDepth}). "
                . "Stack: " . implode(' → ', $newStack)
            );
        }
        if ($this->policy->hasCycle($newStack)) {
            throw new ModeCycleException(
                "Cross-mode recursion cycle detected: " . implode(' → ', $newStack)
            );
        }
        if ($this->policy->budgetCapUsd !== null && $this->costLedger->total() >= $this->policy->budgetCapUsd) {
            throw new ModeBudgetExceededException(
                "Cross-mode budget cap reached (\$" . $this->costLedger->total() . " >= \$" . $this->policy->budgetCapUsd . ")"
            );
        }
        return new self(
            blackboard:    $this->blackboard,
            mailbox:       $this->mailbox,
            costLedger:    $this->costLedger,
            rootSessionId: $this->rootSessionId,
            depth:         $this->depth + 1,
            modeStack:     $newStack,
            policy:        $this->policy,
            metadata:      $this->metadata,
        );
    }

    /**
     * Attach a `PeerMailbox` (the squad orchestrator builds one and
     * wants nested smart/auto calls to see it). Returns a new
     * context preserving everything else.
     */
    public function withMailbox(PeerMailbox $mailbox): self
    {
        return new self(
            blackboard:    $this->blackboard,
            mailbox:       $mailbox,
            costLedger:    $this->costLedger,
            rootSessionId: $this->rootSessionId,
            depth:         $this->depth,
            modeStack:     $this->modeStack,
            policy:        $this->policy,
            metadata:      $this->metadata,
        );
    }

    /**
     * Merge in additional metadata. Useful for "stamp this run with
     * tags X and Y" without mutating the parent.
     *
     * @param array<string, mixed> $extra
     */
    public function withMetadata(array $extra): self
    {
        return new self(
            blackboard:    $this->blackboard,
            mailbox:       $this->mailbox,
            costLedger:    $this->costLedger,
            rootSessionId: $this->rootSessionId,
            depth:         $this->depth,
            modeStack:     $this->modeStack,
            policy:        $this->policy,
            metadata:      array_merge($this->metadata, $extra),
        );
    }

    /**
     * The current mode (top of the stack). Convenience for logging /
     * blackboard role-tagging.
     */
    public function currentMode(): string
    {
        return $this->modeStack[count($this->modeStack) - 1] ?? '';
    }
}
