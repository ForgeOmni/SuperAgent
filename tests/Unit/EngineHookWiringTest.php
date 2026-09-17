<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Hooks\CallbackHook;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookMatcher;
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\QueryEngine;
use SuperAgent\Tests\Helpers\FakePolicyTool;
use SuperAgent\Tests\Helpers\ProviderMockHelper;

/**
 * Hooks attached to a QueryEngine actually run.
 *
 * All four hook call sites in the engine built their HookInput with an
 * `event:` argument the class does not have, and omitted the two it requires
 * — so attaching any registry turned every tool call into
 * `Error: Unknown named parameter $event`. The hook classes had tests; the
 * wiring between them and the engine did not. Fixed in 1.4.0.
 */
class EngineHookWiringTest extends TestCase
{
    public function test_a_pre_tool_use_hook_runs_and_can_deny_the_call(): void
    {
        $tool = new FakePolicyTool('cancel_order', 'general');
        $seen = [];

        $registry = new HookRegistry();
        $registry->register(HookEvent::PRE_TOOL_USE, new HookMatcher(null, [
            new CallbackHook(function (HookInput $input) use (&$seen): HookResult {
                $seen[] = $input->additionalData['tool_name'] ?? null;

                return new HookResult(permissionBehavior: 'deny', permissionReason: 'not today');
            }),
        ]));

        $engine = $this->engine($registry, [$tool]);

        $this->drain($engine);

        $this->assertSame(['cancel_order'], $seen, 'the PreToolUse hook never ran');

        $messages = $engine->getMessages();
        $toolResult = end($messages);
        $this->assertStringContainsString('denied by hook', $toolResult->content[0]->content ?? '');
        $this->assertStringContainsString('not today', $toolResult->content[0]->content ?? '');
    }

    public function test_the_hook_input_carries_a_session_id_and_a_cwd(): void
    {
        $captured = null;

        $registry = new HookRegistry();
        $registry->register(HookEvent::PRE_TOOL_USE, new HookMatcher(null, [
            new CallbackHook(function (HookInput $input) use (&$captured): HookResult {
                $captured = $input;

                return new HookResult(permissionBehavior: 'deny');
            }),
        ]));

        $engine = $this->engine($registry, [new FakePolicyTool('cancel_order', 'general')], ['session_id' => 's-42']);

        $this->drain($engine);

        $this->assertNotNull($captured);
        $this->assertSame('s-42', $captured->sessionId);
        $this->assertSame(HookEvent::PRE_TOOL_USE, $captured->hookEvent);
        $this->assertNotSame('', $captured->cwd);
    }

    /**
     * Runs the turn. The denied tool call sends the loop back to the provider,
     * whose mock queue holds exactly one response — so the second call raises,
     * which is the assertion that no further turn was expected.
     */
    private function drain(QueryEngine $engine): void
    {
        try {
            foreach ($engine->run('cancel order 42') as $ignored) {
                // drain
            }
        } catch (\OutOfBoundsException) {
            // "Mock queue is empty" — the loop asked for a second turn, which
            // is what a denied tool call is supposed to do.
        }
    }

    private function engine(HookRegistry $registry, array $tools, array $options = []): QueryEngine
    {
        $provider = new AnthropicProvider(['api_key' => 'k', 'model' => 'claude-sonnet-4-6']);
        $history = [];

        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]],
            ['type' => 'content_block_start', 'index' => 0,
                'content_block' => ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'cancel_order']],
            ['type' => 'content_block_delta', 'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"order_id":42}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
            ['type' => 'message_stop'],
        ];

        $body = '';
        foreach ($events as $event) {
            $body .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event) . "\n\n";
        }

        // Only one response is queued: after the denied tool call the loop
        // would call the provider again, and the empty mock queue would say so.
        ProviderMockHelper::injectMockClient(
            $provider,
            [new Response(200, ['Content-Type' => 'text/event-stream'], $body)],
            $history,
            'https://api.anthropic.com/'
        );

        return new QueryEngine(
            provider: $provider,
            tools: $tools,
            maxTurns: 2,
            options: $options,
            hookRegistry: $registry,
        );
    }
}
