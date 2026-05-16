<?php

declare(strict_types=1);

namespace SuperAgent\Squad\Tools;

use SuperAgent\Squad\PeerMailbox;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Synchronous ask-a-peer tool. Backed by `PeerMailbox::ask()`.
 *
 * Why this exists: an agent mid-step often needs a single fact from
 * a peer — "what auth library did you pick?", "is the migration
 * idempotent?". Without this tool the agent would have to escalate
 * to a master agent or guess. With it, the agent stays in its OWN
 * context and gets exactly the answer it needs in one round-trip.
 *
 * Wired in by the dispatcher when `SquadDispatchRequest::$mailbox` is
 * present. Hosts that don't want peer-asking in a given role simply
 * omit the tool from that role's tool grant.
 */
final class PeerAskTool extends Tool
{
    public function __construct(
        private readonly PeerMailbox $mailbox,
        private readonly string $selfRole,
    ) {}

    public function name(): string
    {
        return 'PeerAsk';
    }

    public function description(): string
    {
        $peers = implode(', ', $this->mailbox->peersOf($this->selfRole));
        return "Ask a peer agent a single question. Blocks until they answer. "
            . "Use when you need a fact only another role would know. Known peers: {$peers}.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type'        => 'string',
                    'description' => 'Peer role name to ask',
                ],
                'question' => [
                    'type'        => 'string',
                    'description' => 'Single concise question for the peer',
                ],
            ],
            'required' => ['to', 'question'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $to = (string) ($input['to'] ?? '');
        $q  = trim((string) ($input['question'] ?? ''));

        if ($to === '' || $q === '') {
            return ToolResult::error('PeerAsk requires both "to" and "question".');
        }

        try {
            $reply = $this->mailbox->ask($this->selfRole, $to, $q);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success($reply);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'squad';
    }
}
