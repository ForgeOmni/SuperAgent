<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Payload the `PeerOrchestrator` hands to the caller-supplied agent
 * dispatcher for one AgentStep execution.
 *
 * Keeping this as a value object — rather than a long parameter list
 * — means the integration code can grow new fields (cost budget,
 * trace ID, …) without forcing every dispatcher implementation to
 * change its signature.
 */
final class SquadDispatchRequest
{
    public function __construct(
        public readonly SquadRole $role,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $prompt,
        public readonly ?string $systemPrompt,
        public readonly ?string $sessionId,
        public readonly Blackboard $blackboard,
        /**
         * Per-squad peer message bus. Null when the orchestrator was
         * configured without messaging (e.g. tests that don't exercise
         * peer chatter). Dispatchers should check for null before
         * exposing PeerAsk/PeerSend tools to the agent.
         */
        public readonly ?PeerMailbox $mailbox = null,
    ) {}
}
