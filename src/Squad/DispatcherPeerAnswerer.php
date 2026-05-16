<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Default `PeerAnswerer` — routes peer questions through the same
 * agent dispatcher the orchestrator uses for ordinary step execution.
 *
 * The recipient sees the question framed as a normal user turn, but
 * with `from_peer` and `is_peer_question` flags stamped on the role
 * so it knows this isn't the original task. The peer's stable session
 * ID is preserved, so its prompt cache and conversation history
 * survive the question/reply detour.
 */
final class DispatcherPeerAnswerer implements PeerAnswerer
{
    /** @param callable(SquadDispatchRequest): mixed $dispatcher */
    public function __construct(
        private $dispatcher,
        private readonly ?Blackboard $blackboard = null,
    ) {}

    public function answer(SquadRole $peerRole, string $question, string $fromRole): string
    {
        $framed = sprintf(
            "[peer-question from=%s]\n%s\n\nReply concisely. If you don't know, say so plainly.",
            $fromRole,
            $question,
        );

        $request = new SquadDispatchRequest(
            role: $peerRole,
            provider: $peerRole->provider,
            model: $peerRole->model,
            prompt: $framed,
            systemPrompt: $peerRole->systemPrompt,
            sessionId: $peerRole->sessionId,
            blackboard: $this->blackboard ?? new Blackboard(),
        );

        $output = ($this->dispatcher)($request);

        if (is_array($output) && isset($output['output'])) {
            return (string) $output['output'];
        }

        return (string) $output;
    }
}
