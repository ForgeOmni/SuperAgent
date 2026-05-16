<?php

declare(strict_types=1);

namespace SuperAgent\Squad\Tools;

use SuperAgent\Squad\PeerMailbox;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Re-read the peer inbox mid-step. Useful when an agent wants to
 * check for incoming messages without ending its turn.
 *
 * The orchestrator already prepends queued messages to the agent's
 * initial prompt — this tool is for explicit re-checks during
 * long tool loops.
 */
final class PeerInboxTool extends Tool
{
    public function __construct(
        private readonly PeerMailbox $mailbox,
        private readonly string $selfRole,
    ) {}

    public function name(): string
    {
        return 'PeerInbox';
    }

    public function description(): string
    {
        return 'Read any peer messages queued for you since the start of this step.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $messages = $this->mailbox->drainInbox($this->selfRole);
        if ($messages === []) {
            return ToolResult::success('(inbox empty)');
        }
        $lines = [];
        foreach ($messages as $m) {
            $lines[] = sprintf('[%s] %s → you: %s', $m->kind, $m->from, $m->body);
        }
        return ToolResult::success(implode("\n", $lines));
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
