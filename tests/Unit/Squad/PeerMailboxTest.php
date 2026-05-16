<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\PeerAnswerer;
use SuperAgent\Squad\PeerMailbox;
use SuperAgent\Squad\PeerMessage;
use SuperAgent\Squad\SquadRole;

class PeerMailboxTest extends TestCase
{
    /**
     * Deterministic answerer for tests — records who asked what and
     * returns a canned reply per (asker, askee) pair.
     */
    private function deterministicAnswerer(array $replies, array &$asked): PeerAnswerer
    {
        return new class($replies, $asked) implements PeerAnswerer {
            public function __construct(
                private readonly array $replies,
                private array &$asked,
            ) {}

            public function answer(SquadRole $peerRole, string $question, string $fromRole): string
            {
                $this->asked[] = ['from' => $fromRole, 'to' => $peerRole->name, 'q' => $question];
                $key = $fromRole . '→' . $peerRole->name;
                return $this->replies[$key] ?? '(no canned reply)';
            }
        };
    }

    private function role(string $name): SquadRole
    {
        return new SquadRole(
            name: $name,
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            tier: DifficultyClass::MODERATE,
            sessionId: "squad:test:role:{$name}",
        );
    }

    public function test_send_queues_in_recipient_inbox(): void
    {
        $asked = [];
        $mailbox = new PeerMailbox($this->deterministicAnswerer([], $asked));
        $mailbox->registerRoles([
            'researcher' => $this->role('researcher'),
            'designer'   => $this->role('designer'),
        ]);

        $mailbox->send('researcher', 'designer', 'FYI auth scheme is OAuth2 PKCE');

        $inbox = $mailbox->inbox('designer');
        $this->assertCount(1, $inbox);
        $this->assertSame('researcher', $inbox[0]->from);
        $this->assertSame(PeerMessage::KIND_TELL, $inbox[0]->kind);
        // Sender's inbox stays empty
        $this->assertEmpty($mailbox->inbox('researcher'));
    }

    public function test_ask_routes_through_answerer_and_returns_reply(): void
    {
        $asked = [];
        $mailbox = new PeerMailbox($this->deterministicAnswerer([
            'designer→researcher' => 'I used JWT with HS256.',
        ], $asked));
        $mailbox->registerRoles([
            'researcher' => $this->role('researcher'),
            'designer'   => $this->role('designer'),
        ]);

        $reply = $mailbox->ask('designer', 'researcher', 'what signing algo did you pick?');

        $this->assertSame('I used JWT with HS256.', $reply);
        $this->assertCount(1, $asked);
        $this->assertSame('researcher', $asked[0]['to']);

        // Both the ask and the reply are in the audit log.
        $log = $mailbox->log();
        $this->assertSame(PeerMessage::KIND_ASK, $log[0]->kind);
        $this->assertSame(PeerMessage::KIND_REPLY, $log[1]->kind);
        // Asker's inbox is NOT mutated (the reply was returned directly)
        $this->assertEmpty($mailbox->inbox('designer'));
    }

    public function test_broadcast_sends_to_every_peer_except_self(): void
    {
        $asked = [];
        $mailbox = new PeerMailbox($this->deterministicAnswerer([], $asked));
        $mailbox->registerRoles([
            'researcher' => $this->role('researcher'),
            'designer'   => $this->role('designer'),
            'verifier'   => $this->role('verifier'),
        ]);

        $mailbox->broadcast('researcher', 'tests live in tests/auth/*');

        $this->assertCount(1, $mailbox->inbox('designer'));
        $this->assertCount(1, $mailbox->inbox('verifier'));
        $this->assertEmpty($mailbox->inbox('researcher'));
    }

    public function test_send_to_unknown_peer_throws(): void
    {
        $asked = [];
        $mailbox = new PeerMailbox($this->deterministicAnswerer([], $asked));
        $mailbox->registerRoles(['a' => $this->role('a')]);

        $this->expectException(\InvalidArgumentException::class);
        $mailbox->send('a', 'ghost', 'hi');
    }

    public function test_drain_inbox_clears_it(): void
    {
        $asked = [];
        $mailbox = new PeerMailbox($this->deterministicAnswerer([], $asked));
        $mailbox->registerRoles(['a' => $this->role('a'), 'b' => $this->role('b')]);

        $mailbox->send('a', 'b', 'message 1');
        $mailbox->send('a', 'b', 'message 2');
        $this->assertCount(2, $mailbox->inbox('b'));

        $drained = $mailbox->drainInbox('b');
        $this->assertCount(2, $drained);
        $this->assertEmpty($mailbox->inbox('b'));
    }

    public function test_render_inbox_for_drains_and_formats_as_markdown(): void
    {
        $asked = [];
        $mailbox = new PeerMailbox($this->deterministicAnswerer([], $asked));
        $mailbox->registerRoles(['a' => $this->role('a'), 'b' => $this->role('b')]);
        $mailbox->send('a', 'b', 'use SHA-256');

        $rendered = $mailbox->renderInboxFor('b');

        $this->assertStringContainsString('## Peer messages', $rendered);
        $this->assertStringContainsString('**a** → you: use SHA-256', $rendered);
        $this->assertEmpty($mailbox->inbox('b'), 'render should drain');
    }
}
