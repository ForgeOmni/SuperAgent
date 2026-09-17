<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Resume;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Hooks\CallbackHook;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookMatcher;
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookResult;
use SuperAgent\QueryEngine;
use SuperAgent\Exceptions\ResumeException;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\GeminiProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Resume\ResumeEnvelope;
use SuperAgent\Tests\Helpers\DeferringTool;
use SuperAgent\Tests\Helpers\ProviderMockHelper;
use SuperAgent\Tools\ToolResult;

/**
 * Deferred tool results, end to end and across a process boundary (1.2.0).
 *
 * The interesting part is not that a turn can stop — it is that it can be
 * picked up again somewhere else. Each case runs the first half against a
 * mocked provider, throws the agent away, rebuilds everything from the
 * envelope's JSON as a second process would, and checks that what goes back
 * on the wire is a well-formed answer to the original tool call **in that
 * provider's own shape**. A resume that only works on Anthropic is not a
 * resume.
 */
class DeferredToolResumeTest extends TestCase
{
    public function test_a_deferring_tool_ends_the_turn_and_hands_back_an_envelope(): void
    {
        $tool = new DeferringTool();
        [$agent] = $this->anthropicAgent([$this->anthropicToolUse()], $history);

        $agent->addTool($tool);
        $result = $agent->run('cancel order 42');

        $this->assertTrue($result->isAwaitingHuman());
        $this->assertCount(1, $result->deferrals());
        $this->assertSame('ticket-1', $result->deferrals()[0]->ticketId);
        $this->assertSame('cancel_order', $result->deferrals()[0]->toolName);
        $this->assertSame(['summary' => 'cancel order 42'], $result->deferrals()[0]->meta);
        $this->assertSame(1, $tool->calls);

        // One request made, and nothing half-answered left in the transcript.
        $this->assertCount(1, $history);
        $messages = $result->messages;
        $this->assertNotInstanceOf(\SuperAgent\Messages\ToolResultMessage::class, end($messages));
    }

    #[DataProvider('providerFamilies')]
    public function test_the_answer_reaches_the_model_in_each_provider_shape(string $family): void
    {
        [$agent, $requests] = $this->agentFor($family, [$this->toolUseResponse($family)], $firstHistory);
        $agent->addTool(new DeferringTool());

        $result = $agent->run('cancel order 42');
        $this->assertTrue($result->isAwaitingHuman());

        // ── process boundary ────────────────────────────────────────────
        $json = $result->resume->toJson();
        unset($agent, $result);

        [$resumed, $secondHistory] = $this->agentFor($family, [$this->finalTextResponse($family)], $history2);
        $resumed->addTool(new DeferringTool());

        $final = $resumed->resume($json, 'ticket-1', ToolResult::success('Order 42 cancelled.'));

        $this->assertFalse($final->isAwaitingHuman());
        $this->assertStringContainsString('cancelled', $final->text());

        $body = json_decode((string) $history2[0]['request']->getBody(), true);
        $this->assertToolAnswerIsOnTheWire($family, $body);
    }

    public static function providerFamilies(): array
    {
        return [
            'anthropic' => ['anthropic'],
            'openai chat completions' => ['openai'],
            'gemini' => ['gemini'],
        ];
    }

    public function test_an_unknown_ticket_is_refused_without_calling_the_model(): void
    {
        [$agent] = $this->anthropicAgent([$this->anthropicToolUse()], $history);
        $agent->addTool(new DeferringTool());
        $envelope = $agent->run('cancel order 42')->resume;

        $this->expectException(ResumeException::class);

        $agent->resume($envelope, 'not-a-ticket', ToolResult::success('x'));
    }

    public function test_resuming_the_same_ticket_twice_is_refused(): void
    {
        [$agent] = $this->anthropicAgent(
            [$this->anthropicToolUse(), $this->anthropicFinalText()],
            $history
        );
        $agent->addTool(new DeferringTool());
        $envelope = $agent->run('cancel order 42')->resume;

        $agent->resume($envelope, 'ticket-1', ToolResult::success('approved'));

        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches('/already been answered|not pending/');

        // The host replays the *same* stored envelope — the case a retry, a
        // duplicate queue delivery or a double click produces.
        $agent->resume($envelope->withAnswer('ticket-1', ToolResult::success('approved')),
            'ticket-1', ToolResult::success('approved'));
    }

    public function test_an_envelope_from_another_provider_is_refused(): void
    {
        [$agent] = $this->anthropicAgent([$this->anthropicToolUse()], $history);
        $agent->addTool(new DeferringTool());
        $envelope = $agent->run('cancel order 42')->resume;

        [$other] = $this->agentFor('openai', [], $unused);

        $this->expectException(ResumeException::class);
        $this->expectExceptionMessageMatches("/was created by the 'anthropic' provider/");

        $other->resume($envelope, 'ticket-1', ToolResult::success('approved'));
    }

    public function test_a_turn_that_is_never_resumed_leaves_nothing_half_written(): void
    {
        [$agent] = $this->anthropicAgent([$this->anthropicToolUse()], $history);
        $agent->addTool(new DeferringTool());

        $result = $agent->run('cancel order 42');

        // The transcript ends on the assistant's tool call. Nothing was
        // appended for the unanswered call, so the envelope — dropped or not
        // — is the only thing holding the turn open.
        $messages = $result->messages;
        $this->assertInstanceOf(\SuperAgent\Messages\AssistantMessage::class, end($messages));
        $this->assertCount(1, $history, 'no further provider call was made');
    }

    public function test_a_sibling_tool_result_waits_with_the_deferred_one(): void
    {
        [$agent] = $this->anthropicAgent([$this->anthropicTwoToolUses()], $history);
        $agent->addTool(new DeferringTool());
        $agent->addTool(new \SuperAgent\Tests\Helpers\FakePolicyTool('get_order', 'general', true));

        $result = $agent->run('look it up and cancel it');

        $this->assertTrue($result->isAwaitingHuman());

        // The completed sibling is held in the envelope, not sent on its own:
        // a provider rejects an assistant message whose tool calls are only
        // half answered.
        $completed = $result->resume->completedResults;
        $this->assertCount(1, $completed);
        $this->assertSame('call-0', $completed[0]['tool_use_id']);

        $ready = $result->resume->withAnswer('ticket-1', ToolResult::success('cancelled'));
        $this->assertSame(['call-0', 'call-1'], array_column($ready->toolResults(), 'tool_use_id'));
    }

    public function test_a_pre_tool_use_hook_can_hand_the_call_to_a_human(): void
    {
        // A PreToolUse hook could answer allow, deny, or "ask" — and ask fell
        // back to normal flow, because inside one synchronous loop there was
        // nobody to ask. This is the fourth answer: the tool never runs, and
        // the turn ends with a ticket instead.
        $provider = new AnthropicProvider(['api_key' => 'k', 'model' => 'claude-sonnet-4-6']);
        $history = [];
        ProviderMockHelper::injectMockClient(
            $provider,
            [$this->anthropicToolUse()],
            $history,
            'https://api.anthropic.com/'
        );

        $tool = new \SuperAgent\Tests\Helpers\FakePolicyTool('cancel_order', 'general');

        $registry = new HookRegistry();
        $registry->register(
            HookEvent::PRE_TOOL_USE,
            new HookMatcher(null, [new CallbackHook(
                static fn (): HookResult => HookResult::defer('approval-7', ['requested_by' => 'agent'])
            )])
        );

        $engine = new QueryEngine(
            provider: $provider,
            tools: [$tool],
            hookRegistry: $registry,
        );

        foreach ($engine->run('cancel order 42') as $ignored) {
            // drain
        }

        $this->assertTrue($engine->isAwaitingHuman());
        $this->assertSame('approval-7', $engine->getDeferrals()[0]->ticketId);
        $this->assertSame(['requested_by' => 'agent'], $engine->getDeferrals()[0]->meta);
        $this->assertSame(0, $tool->executions ?? 0, 'the tool must not have run');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /** @return array{0: Agent, 1: array} */
    private function anthropicAgent(array $responses, ?array &$history): array
    {
        return $this->agentFor('anthropic', $responses, $history);
    }

    /** @return array{0: Agent, 1: array} */
    private function agentFor(string $family, array $responses, ?array &$history): array
    {
        $history = [];

        $provider = match ($family) {
            'anthropic' => new AnthropicProvider(['api_key' => 'k', 'model' => 'claude-sonnet-4-6']),
            'openai' => new OpenAIProvider(['api_key' => 'k', 'model' => 'gpt-5']),
            'gemini' => new GeminiProvider(['api_key' => 'k', 'model' => 'gemini-3.8-flash']),
        };

        $baseUri = match ($family) {
            'anthropic' => 'https://api.anthropic.com/',
            'openai' => 'https://api.openai.com/',
            'gemini' => 'https://generativelanguage.googleapis.com/',
        };

        ProviderMockHelper::injectMockClient($provider, $responses, $history, $baseUri);

        $agent = Agent::embedded(['provider' => $provider, 'max_turns' => 5]);

        return [$agent, $history];
    }

    private function toolUseResponse(string $family): Response
    {
        return match ($family) {
            'anthropic' => $this->anthropicToolUse(),
            'openai' => $this->sse([
                ['choices' => [['delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call-1',
                    'type' => 'function',
                    'function' => ['name' => 'cancel_order', 'arguments' => '{"order_id":42}'],
                ]]]]]],
                ['choices' => [['finish_reason' => 'tool_calls']]],
            ], done: true),
            'gemini' => $this->sse([
                ['candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [[
                        'functionCall' => ['name' => 'cancel_order', 'args' => ['order_id' => 42]],
                    ]]],
                    'finishReason' => 'STOP',
                ]]],
            ]),
        };
    }

    private function finalTextResponse(string $family): Response
    {
        return match ($family) {
            'anthropic' => $this->anthropicFinalText(),
            'openai' => $this->sse([
                ['choices' => [['delta' => ['content' => 'Order 42 is cancelled.']]]],
                ['choices' => [['finish_reason' => 'stop']]],
            ], done: true),
            'gemini' => $this->sse([
                ['candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Order 42 is cancelled.']]],
                    'finishReason' => 'STOP',
                ]]],
            ]),
        };
    }

    /** Every provider in this SDK streams, so every fixture is an SSE body. */
    private function sse(array $events, bool $done = false): Response
    {
        $body = '';
        foreach ($events as $event) {
            $body .= 'data: ' . json_encode($event) . "\n\n";
        }
        if ($done) {
            $body .= "data: [DONE]\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    /** @param list<array> $blocks each ['id' =>, 'name' =>, 'input' => ] or ['text' => ] */
    private function anthropicSse(array $blocks, string $stopReason): Response
    {
        $events = [['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]]];

        foreach ($blocks as $index => $block) {
            if (isset($block['text'])) {
                $events[] = ['type' => 'content_block_start', 'index' => $index,
                    'content_block' => ['type' => 'text', 'text' => '']];
                $events[] = ['type' => 'content_block_delta', 'index' => $index,
                    'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
            } else {
                $events[] = ['type' => 'content_block_start', 'index' => $index,
                    'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name']]];
                $events[] = ['type' => 'content_block_delta', 'index' => $index,
                    'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'])]];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $index];
        }

        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason],
            'usage' => ['output_tokens' => 5]];
        $events[] = ['type' => 'message_stop'];

        $body = '';
        foreach ($events as $event) {
            $body .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event) . "\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    private function anthropicToolUse(): Response
    {
        return $this->anthropicSse([
            ['text' => 'Cancelling now.'],
            ['id' => 'call-1', 'name' => 'cancel_order', 'input' => ['order_id' => 42]],
        ], 'tool_use');
    }

    private function anthropicTwoToolUses(): Response
    {
        return $this->anthropicSse([
            ['id' => 'call-0', 'name' => 'get_order', 'input' => ['id' => 42]],
            ['id' => 'call-1', 'name' => 'cancel_order', 'input' => ['order_id' => 42]],
        ], 'tool_use');
    }

    private function anthropicFinalText(): Response
    {
        return $this->anthropicSse([['text' => 'Order 42 is cancelled.']], 'end_turn');
    }

    private function assertToolAnswerIsOnTheWire(string $family, array $body): void
    {
        $json = json_encode($body);

        $this->assertStringContainsString('Order 42 cancelled.', $json, 'the human answer never reached the model');

        match ($family) {
            'anthropic' => $this->assertSame('tool_result', $this->lastUserBlock($body)['type'] ?? null),
            'openai' => $this->assertSame('tool', $this->lastMessage($body)['role'] ?? null),
            'gemini' => $this->assertArrayHasKey('functionResponse', $this->lastGeminiPart($body)),
        };
    }

    private function lastMessage(array $body): array
    {
        $messages = $body['messages'] ?? [];

        return (array) end($messages);
    }

    private function lastUserBlock(array $body): array
    {
        $last = $this->lastMessage($body);
        $content = is_array($last['content'] ?? null) ? $last['content'] : [];

        return (array) end($content);
    }

    private function lastGeminiPart(array $body): array
    {
        $contents = $body['contents'] ?? [];
        $last = (array) end($contents);
        $parts = $last['parts'] ?? [];

        return (array) end($parts);
    }
}
