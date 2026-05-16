<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\PeerAnswerer;
use SuperAgent\Squad\PeerMailbox;
use SuperAgent\Squad\SquadRole;
use SuperAgent\Squad\Tools\PeerAskTool;
use SuperAgent\Squad\Tools\PeerInboxTool;
use SuperAgent\Squad\Tools\PeerSendTool;

class PeerToolsTest extends TestCase
{
    private function role(string $name): SquadRole
    {
        return new SquadRole($name, 'anthropic', 'claude-sonnet-4-6', DifficultyClass::MODERATE,
            sessionId: "squad:t:role:{$name}");
    }

    private function mailbox(array $replies = []): PeerMailbox
    {
        $answerer = new class($replies) implements PeerAnswerer {
            public function __construct(private readonly array $replies) {}
            public function answer(SquadRole $peerRole, string $question, string $fromRole): string
            {
                return $this->replies[$peerRole->name] ?? '';
            }
        };
        $mailbox = new PeerMailbox($answerer);
        $mailbox->registerRoles([
            'researcher' => $this->role('researcher'),
            'designer'   => $this->role('designer'),
            'verifier'   => $this->role('verifier'),
        ]);
        return $mailbox;
    }

    public function test_peer_ask_returns_reply(): void
    {
        $mailbox = $this->mailbox(['researcher' => 'auth uses OAuth2 PKCE']);
        $tool = new PeerAskTool($mailbox, selfRole: 'designer');

        $result = $tool->execute(['to' => 'researcher', 'question' => 'what auth?']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('auth uses OAuth2 PKCE', $result->contentAsString());
    }

    public function test_peer_ask_rejects_unknown_peer(): void
    {
        $mailbox = $this->mailbox();
        $tool = new PeerAskTool($mailbox, selfRole: 'designer');

        $result = $tool->execute(['to' => 'ghost', 'question' => 'are you there?']);

        $this->assertFalse($result->isSuccess());
    }

    public function test_peer_send_queues_in_recipient_inbox(): void
    {
        $mailbox = $this->mailbox();
        $tool = new PeerSendTool($mailbox, selfRole: 'designer');

        $result = $tool->execute(['to' => 'verifier', 'message' => 'cover the OAuth path']);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $mailbox->inbox('verifier'));
    }

    public function test_peer_send_broadcast_with_star(): void
    {
        $mailbox = $this->mailbox();
        $tool = new PeerSendTool($mailbox, selfRole: 'designer');

        $tool->execute(['to' => '*', 'message' => 'using PKCE flow']);

        $this->assertCount(1, $mailbox->inbox('researcher'));
        $this->assertCount(1, $mailbox->inbox('verifier'));
        $this->assertEmpty($mailbox->inbox('designer'));
    }

    public function test_peer_inbox_tool_returns_queued_messages_and_drains(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->send('researcher', 'designer', 'fact 1');
        $mailbox->send('researcher', 'designer', 'fact 2');

        $tool = new PeerInboxTool($mailbox, selfRole: 'designer');
        $result = $tool->execute([]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('fact 1', $result->contentAsString());
        $this->assertStringContainsString('fact 2', $result->contentAsString());
        $this->assertEmpty($mailbox->inbox('designer'));
    }

    public function test_peer_tools_are_read_only(): void
    {
        $mailbox = $this->mailbox();
        $this->assertTrue((new PeerAskTool($mailbox, 'a'))->isReadOnly());
        $this->assertTrue((new PeerSendTool($mailbox, 'a'))->isReadOnly());
        $this->assertTrue((new PeerInboxTool($mailbox, 'a'))->isReadOnly());
    }
}
