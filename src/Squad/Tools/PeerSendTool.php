<?php

declare(strict_types=1);

namespace SuperAgent\Squad\Tools;

use SuperAgent\Squad\PeerMailbox;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Fire-and-forget message to a peer (or broadcast). Backed by
 * `PeerMailbox::send()` / `broadcast()`.
 *
 * Use for context-alignment messages that don't need an answer:
 *   "FYI I'm assuming OAuth2 PKCE, not implicit grant"
 *   "blacklist these endpoints when you test"
 *
 * Recipients see queued tells at the top of their next step (the
 * orchestrator prepends them via `PeerMailbox::renderInboxFor()`).
 */
final class PeerSendTool extends Tool
{
    public function __construct(
        private readonly PeerMailbox $mailbox,
        private readonly string $selfRole,
    ) {}

    public function name(): string
    {
        return 'PeerSend';
    }

    public function description(): string
    {
        $peers = implode(', ', $this->mailbox->peersOf($this->selfRole));
        return "Send a fire-and-forget note to one peer or broadcast to all. "
            . "Use for context alignment, FYI signals, decisions you've already made. "
            . "Recipients read it at the start of their next step. Known peers: {$peers}.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type'        => 'string',
                    'description' => 'Peer role name, or "*" to broadcast to all peers',
                ],
                'message' => [
                    'type'        => 'string',
                    'description' => 'The note body',
                ],
            ],
            'required' => ['to', 'message'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $to  = (string) ($input['to'] ?? '');
        $msg = trim((string) ($input['message'] ?? ''));

        if ($to === '' || $msg === '') {
            return ToolResult::error('PeerSend requires both "to" and "message".');
        }

        try {
            if ($to === '*') {
                $this->mailbox->broadcast($this->selfRole, $msg);
                return ToolResult::success('Broadcast queued.');
            }
            $this->mailbox->send($this->selfRole, $to, $msg);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success("Message queued for {$to}.");
    }

    public function isReadOnly(): bool
    {
        // Doesn't touch files / external systems — purely in-squad signal.
        return true;
    }

    public function category(): string
    {
        return 'squad';
    }
}
