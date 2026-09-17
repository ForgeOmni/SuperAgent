<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Resume;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ResumeException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Resume\Deferral;
use SuperAgent\Resume\ResumeEnvelope;
use SuperAgent\Tools\ToolResult;

/**
 * The envelope is the only state between a deferred turn and its resume, so
 * these tests are about what survives serialisation and what is refused.
 */
class ResumeEnvelopeTest extends TestCase
{
    private function envelope(?array $pending = null, array $completed = []): ResumeEnvelope
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::text('I will cancel it once approved.'),
            ContentBlock::toolUse('call-1', 'cancel_order', ['order_id' => 42]),
        ];

        return new ResumeEnvelope(
            id: 'env-1',
            messages: [new UserMessage('cancel order 42'), $assistant],
            completedResults: $completed,
            pending: $pending ?? [new Deferral('ticket-1', 'call-1', 'cancel_order', ['summary' => 's'])],
            providerName: 'anthropic',
            model: 'claude-sonnet-4-6',
            turnCount: 1,
            totalCostUsd: 0.25,
            createdAt: date('c'),
        );
    }

    public function test_round_trips_through_json_unchanged(): void
    {
        $envelope = $this->envelope();

        $restored = ResumeEnvelope::fromJson($envelope->toJson());

        $this->assertSame($envelope->toArray(), $restored->toArray());
        $this->assertCount(2, $restored->messages);
        $this->assertInstanceOf(AssistantMessage::class, $restored->messages[1]);
        $this->assertSame('call-1', $restored->messages[1]->toolUseBlocks()[0]->toolUseId);
    }

    public function test_a_tool_result_message_survives_as_a_tool_result_message(): void
    {
        // toArray() calls both of these `role: user`; only the tagged
        // encoding can tell them apart, and a transcript that comes back with
        // a tool result flattened into a user message is one no provider
        // accepts on the next call.
        $envelope = new ResumeEnvelope(
            id: 'env-2',
            messages: [
                new UserMessage('hi'),
                ToolResultMessage::fromResult('call-0', 'done'),
            ],
            completedResults: [],
            pending: [new Deferral('t', 'call-1', 'x')],
        );

        $restored = ResumeEnvelope::fromJson($envelope->toJson());

        $this->assertInstanceOf(UserMessage::class, $restored->messages[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $restored->messages[1]);
    }

    public function test_answering_the_only_ticket_makes_it_ready(): void
    {
        $envelope = $this->envelope();

        $this->assertFalse($envelope->isReady());
        $this->assertCount(1, $envelope->awaiting());

        $answered = $envelope->withAnswer('ticket-1', ToolResult::success('cancelled'));

        $this->assertTrue($answered->isReady());
        $this->assertSame([], $answered->awaiting());
        $this->assertFalse($envelope->isReady(), 'the original envelope is not mutated');
    }

    public function test_an_unknown_ticket_is_refused(): void
    {
        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches("/not pending/");

        $this->envelope()->withAnswer('nope', ToolResult::success('x'));
    }

    public function test_answering_twice_is_refused(): void
    {
        $answered = $this->envelope()->withAnswer('ticket-1', ToolResult::success('cancelled'));

        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches("/already been answered/");

        $answered->withAnswer('ticket-1', ToolResult::success('cancelled again'));
    }

    public function test_an_expired_envelope_is_refused(): void
    {
        $expired = new ResumeEnvelope(
            id: 'env-3',
            messages: [],
            completedResults: [],
            pending: [new Deferral('ticket-1', 'call-1', 'cancel_order')],
            expiresAt: date('c', time() - 60),
        );

        $this->assertTrue($expired->isExpired());

        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches('/expired/');

        $expired->withAnswer('ticket-1', ToolResult::success('too late'));
    }

    public function test_a_human_may_not_hand_the_decision_back(): void
    {
        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches('/another deferral/');

        $this->envelope()->withAnswer('ticket-1', ToolResult::deferred('ticket-2'));
    }

    public function test_results_are_ordered_the_way_the_model_asked_for_them(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::toolUse('call-1', 'get_order', ['id' => 42]),
            ContentBlock::toolUse('call-2', 'cancel_order', ['id' => 42]),
        ];

        $envelope = new ResumeEnvelope(
            id: 'env-4',
            messages: [new UserMessage('cancel it'), $assistant],
            // The sibling completed while call-2 went to a human.
            completedResults: [['tool_use_id' => 'call-1', 'content' => '{"id":42}', 'is_error' => false]],
            pending: [new Deferral('ticket-1', 'call-2', 'cancel_order')],
        );

        $ready = $envelope->withAnswer('ticket-1', ToolResult::success('cancelled'));
        $results = $ready->toolResults();

        $this->assertSame(['call-1', 'call-2'], array_column($results, 'tool_use_id'));
        $this->assertSame('cancelled', $results[1]['content']);
    }

    public function test_results_cannot_be_assembled_while_a_ticket_is_outstanding(): void
    {
        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches('/unanswered tickets/');

        $this->envelope()->toolResults();
    }

    public function test_an_envelope_from_a_future_version_is_refused(): void
    {
        $data = $this->envelope()->toArray();
        $data['version'] = ResumeEnvelope::VERSION + 1;

        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches('/Unsupported resume envelope version/');

        ResumeEnvelope::fromArray($data);
    }
}
