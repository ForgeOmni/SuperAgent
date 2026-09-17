<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\JobHandle;
use SuperAgent\Providers\JobStatus;
use SuperAgent\Providers\MetaResponsesProvider;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Wire-shape pins for Meta's Responses route — the one that can replay
 * reasoning across turns.
 *
 * The interesting cases are all about what must NOT reach the wire:
 * OpenAI-only knobs the shared base class emits (`reasoning.mode`,
 * `text.verbosity`, `service_tier`, `prompt_cache_options`), and the
 * mutually exclusive pair `include` + `previous_response_id`.
 */
class MetaResponsesProviderTest extends TestCase
{
    // ---------------- endpoint + defaults ----------------

    public function test_targets_the_meta_responses_endpoint(): void
    {
        $p = new MetaResponsesProvider(['api_key' => 'k']);

        $this->assertSame('api.meta.ai', $this->host($p));
        $this->assertSame('v1/responses', $this->call($p, 'chatCompletionsPath'));
        $this->assertSame('muse-spark-1.3', $p->getModel());
        $this->assertSame('meta-responses', $p->name());
    }

    public function test_registry_resolves_the_responses_route(): void
    {
        $p = ProviderRegistry::create('meta-responses', ['api_key' => 'k']);

        $this->assertInstanceOf(MetaResponsesProvider::class, $p);
        $this->assertSame('muse-spark-1.3', $p->getModel());
    }

    // ---------------- effort dial ----------------

    public function test_effort_floors_at_minimal_not_none(): void
    {
        $p = new MetaResponsesProvider(['api_key' => 'k']);

        foreach (['off', 'none', 'disabled'] as $tier) {
            $this->assertSame(
                ['reasoning' => ['effort' => 'minimal']],
                $p->reasoningEffortFragment($tier),
                "Muse Spark has no `none` tier — '{$tier}' must floor at minimal",
            );
        }
    }

    public function test_max_is_standard_tier_1_3_only(): void
    {
        $standard = new MetaResponsesProvider(['api_key' => 'k', 'model' => 'muse-spark-1.3']);
        $this->assertSame(['reasoning' => ['effort' => 'max']], $standard->reasoningEffortFragment('max'));

        // The contributor build 400s on `max`; xhigh is its ceiling.
        foreach (['muse-spark-1.3-contributor', 'muse-spark-1.2', 'muse-spark-1.1'] as $id) {
            $p = new MetaResponsesProvider(['api_key' => 'k', 'model' => $id]);
            $this->assertSame(['reasoning' => ['effort' => 'xhigh']], $p->reasoningEffortFragment('max'), $id);
        }
    }

    public function test_effort_lands_under_reasoning_effort_in_the_body(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), ['reasoning_effort' => 'xhigh']);
        $this->assertSame('xhigh', $body['reasoning']['effort']);
    }

    // ---------------- reasoning carry-over ----------------

    public function test_reasoning_replay_sets_include_and_forces_store_off(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), ['reasoning_replay' => true]);

        $this->assertSame(['reasoning.encrypted_content'], $body['include']);
        $this->assertFalse($body['store']);
    }

    public function test_include_and_previous_response_id_are_never_sent_together(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), [
            'reasoning_replay' => true,
            'previous_response_id' => 'resp_abc123',
        ]);

        $this->assertArrayHasKey('include', $body);
        $this->assertArrayNotHasKey(
            'previous_response_id',
            $body,
            'Meta rejects include + previous_response_id in one request',
        );
    }

    public function test_previous_response_id_still_works_on_its_own(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), [
            'previous_response_id' => 'resp_abc123',
        ]);

        $this->assertSame('resp_abc123', $body['previous_response_id']);
        $this->assertArrayNotHasKey('include', $body);
    }

    // ---------------- stripped OpenAI-only surface ----------------

    public function test_openai_only_reasoning_knobs_are_stripped(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), [
            'reasoning_effort' => 'high',
            'reasoning_mode' => 'pro',
            'reasoning_context' => 'all_turns',
        ]);

        $this->assertSame('high', $body['reasoning']['effort']);
        $this->assertArrayNotHasKey('mode', $body['reasoning']);
        $this->assertArrayNotHasKey('context', $body['reasoning']);
    }

    public function test_text_verbosity_is_stripped_but_structured_output_survives(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), [
            'verbosity' => 'low',
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'Out', 'strict' => true, 'schema' => ['type' => 'object']],
            ],
        ]);

        $this->assertArrayNotHasKey('verbosity', $body['text'] ?? []);
        // response_format is not a Meta field — it must arrive as text.format.
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame('json_schema', $body['text']['format']['type']);
    }

    public function test_unsupported_params_are_stripped_even_from_extra_body(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), [
            'service_tier' => 'flex',
            'prompt_cache_options' => ['mode' => 'explicit'],
            'extra_body' => [
                'logprobs' => true,
                'stop' => ['\n'],
                'logit_bias' => ['1' => 1],
                'prediction' => ['type' => 'content'],
                'modalities' => ['text'],
                'audio' => ['voice' => 'x'],
                'web_search_options' => ['k' => 'v'],
                'response_format' => ['type' => 'json_object'],
                'n' => 4,
            ],
        ]);

        foreach ([
            'logprobs', 'stop', 'logit_bias', 'prediction', 'modalities',
            'audio', 'web_search_options', 'response_format',
            'prompt_cache_options', 'service_tier', 'n',
        ] as $param) {
            $this->assertArrayNotHasKey($param, $body, "{$param} is not part of Meta's Responses surface");
        }
    }

    // ---------------- Meta extras ----------------

    public function test_grounding_appends_the_web_search_tool(): void
    {
        $p = new MetaResponsesProvider(['api_key' => 'k']);

        $body = $this->body($p, ['grounding' => true]);
        $this->assertContains(['type' => 'web_search'], $body['tools']);

        $withFns = $this->body($p, ['web_search' => true], [$this->demoTool()]);
        $types = array_column($withFns['tools'], 'type');
        $this->assertContains('function', $types);
        $this->assertContains('web_search', $types);
    }

    public function test_safety_identifier_passes_through(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), ['safety_identifier' => 'user-abc']);
        $this->assertSame('user-abc', $body['safety_identifier']);
    }

    public function test_background_on_the_streaming_path_points_at_the_right_method(): void
    {
        // chat() always streams and Meta 400s background + stream, so the
        // flag must route the caller to submitBackground() rather than be
        // dropped (which would stream and look like it worked).
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/submitBackground/');

        $this->body(new MetaResponsesProvider(['api_key' => 'k']), ['background' => true]);
    }

    public function test_plain_request_carries_no_meta_extras(): void
    {
        $body = $this->body(new MetaResponsesProvider(['api_key' => 'k']), []);

        $this->assertSame('muse-spark-1.3', $body['model']);
        $this->assertTrue($body['stream']);
        $this->assertArrayNotHasKey('include', $body);
        $this->assertArrayNotHasKey('safety_identifier', $body);
        $this->assertArrayNotHasKey('tools', $body);
    }

    // ---------------- background lifecycle ----------------

    public function test_submit_background_forces_the_detached_shape(): void
    {
        $history = [];
        $p = $this->withTransport([
            new Response(200, [], json_encode([
                'id' => 'resp_bg1', 'status' => 'queued', 'background' => true, 'model' => 'muse-spark-1.3',
            ])),
        ], $history);

        $job = $p->submitBackground([new UserMessage('long job')], [], null, [
            'reasoning_effort' => 'max',
            // A caller asking for stateless replay must not win here — a
            // background job IS server-side state.
            'reasoning_replay' => true,
        ]);

        $this->assertSame('resp_bg1', $job->jobId);
        $this->assertSame('meta-responses', $job->provider);
        $this->assertSame('response', $job->kind);
        $this->assertSame('queued', $job->meta['status']);

        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('/v1/responses', $history[0]['request']->getUri()->getPath());
        $this->assertTrue($sent['background']);
        $this->assertFalse($sent['stream'], 'background + stream is a 400');
        $this->assertTrue($sent['store'], 'without store the response is dropped after ~10 min');
        $this->assertArrayNotHasKey('include', $sent, 'encrypted replay is the stateless mode');
        $this->assertArrayNotHasKey('stream_options', $sent);
        $this->assertSame('max', $sent['reasoning']['effort']);
    }

    public function test_submit_background_without_an_id_is_an_error(): void
    {
        $p = $this->withTransport([new Response(200, [], json_encode(['status' => 'queued']))]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/no response id/');
        $p->submitBackground([new UserMessage('hi')]);
    }

    public function test_poll_maps_every_status_meta_reports(): void
    {
        $map = [
            'queued' => JobStatus::Pending,
            'in_progress' => JobStatus::Running,
            'completed' => JobStatus::Done,
            // Terminal with partial output — Done, so fetch() can return it.
            'incomplete' => JobStatus::Done,
            'failed' => JobStatus::Failed,
            'cancelled' => JobStatus::Canceled,
        ];

        foreach ($map as $status => $expected) {
            $p = $this->withTransport([new Response(200, [], json_encode(['id' => 'resp_1', 'status' => $status]))]);
            $this->assertSame($expected, $p->poll($this->handle()), $status);
        }
    }

    public function test_fetch_converts_a_stored_response_into_an_assistant_message(): void
    {
        $history = [];
        $p = $this->withTransport([
            new Response(200, [], json_encode([
                'id' => 'resp_1',
                'status' => 'completed',
                'output' => [
                    ['type' => 'reasoning', 'summary' => []],
                    ['type' => 'message', 'role' => 'assistant', 'content' => [
                        ['type' => 'output_text', 'text' => 'the answer'],
                    ]],
                ],
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 40,
                    'input_tokens_details' => ['cached_tokens' => 80],
                ],
            ])),
        ], $history);

        $message = $p->fetch($this->handle());

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('the answer', $message->content[0]->text);
        $this->assertSame(StopReason::EndTurn, $message->stopReason);
        $this->assertSame(120, $message->usage->inputTokens);
        $this->assertSame(80, $message->usage->cacheReadInputTokens);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/v1/responses/resp_1', $history[0]['request']->getUri()->getPath());
    }

    public function test_fetch_surfaces_tool_calls(): void
    {
        $p = $this->withTransport([
            new Response(200, [], json_encode([
                'id' => 'resp_1',
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_9', 'name' => 'demo', 'arguments' => '{"a":1}'],
                ],
            ])),
        ]);

        $message = $p->fetch($this->handle());

        $this->assertSame(StopReason::ToolUse, $message->stopReason);
        $this->assertSame('tool_use', $message->content[0]->type);
        $this->assertSame('call_9', $message->content[0]->toolUseId);
        $this->assertSame('demo', $message->content[0]->toolName);
        $this->assertSame(['a' => 1], $message->content[0]->toolInput);
    }

    public function test_fetch_returns_truncated_output_for_an_incomplete_job(): void
    {
        $p = $this->withTransport([
            new Response(200, [], json_encode([
                'id' => 'resp_1',
                'status' => 'incomplete',
                'output' => [
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'half an ans']]],
                ],
            ])),
        ]);

        $message = $p->fetch($this->handle());

        $this->assertSame('half an ans', $message->content[0]->text);
        $this->assertSame(StopReason::MaxTokens, $message->stopReason);
    }

    public function test_fetch_throws_on_a_failed_job(): void
    {
        $p = $this->withTransport([
            new Response(200, [], json_encode([
                'id' => 'resp_1',
                'status' => 'failed',
                'error' => ['message' => 'model exploded'],
            ])),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/model exploded/');
        $p->fetch($this->handle());
    }

    public function test_cancel_acknowledges_a_completed_race(): void
    {
        // Meta returns the completed response when a cancel lands in the
        // same instant the turn finishes — the caller should stop polling
        // either way, so that still counts as acknowledged.
        $cancelled = $this->withTransport([new Response(200, [], json_encode(['status' => 'cancelled']))]);
        $this->assertTrue($cancelled->cancel($this->handle()));

        $raced = $this->withTransport([new Response(200, [], json_encode(['status' => 'completed']))]);
        $this->assertTrue($raced->cancel($this->handle()));

        $running = $this->withTransport([new Response(200, [], json_encode(['status' => 'in_progress']))]);
        $this->assertFalse($running->cancel($this->handle()));
    }

    public function test_cancel_and_delete_hit_the_right_endpoints(): void
    {
        $history = [];
        $p = $this->withTransport([
            new Response(200, [], json_encode(['status' => 'cancelled'])),
            new Response(200, [], json_encode(['id' => 'resp_1', 'deleted' => true])),
        ], $history);

        $p->cancel($this->handle());
        $this->assertTrue($p->deleteBackground($this->handle()));

        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('/v1/responses/resp_1/cancel', $history[0]['request']->getUri()->getPath());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertSame('/v1/responses/resp_1', $history[1]['request']->getUri()->getPath());
    }

    public function test_delete_reports_false_when_the_object_is_already_gone(): void
    {
        $p = $this->withTransport([new Response(404, [], json_encode(['error' => ['message' => 'not found']]))]);

        $this->assertFalse($p->deleteBackground($this->handle()));
    }

    public function test_follow_background_streams_the_stored_response(): void
    {
        $sse = "event: response.created\n"
            . 'data: ' . json_encode(['response' => ['id' => 'resp_1']]) . "\n\n"
            . "event: response.completed\n"
            . 'data: ' . json_encode([
                'response' => [
                    'id' => 'resp_1',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ]) . "\n\n"
            . "data: [DONE]\n\n";

        $history = [];
        $p = $this->withTransport([new Response(200, [], $sse)], $history);

        $messages = iterator_to_array($p->followBackground($this->handle(), startingAfter: 7));

        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/v1/responses/resp_1', $history[0]['request']->getUri()->getPath());
        $this->assertSame('stream=true&starting_after=7', $history[0]['request']->getUri()->getQuery());
    }

    public function test_count_input_tokens_uses_the_sizing_endpoint(): void
    {
        $history = [];
        $p = $this->withTransport([new Response(200, [], json_encode(['input_tokens' => 4242]))], $history);

        $this->assertSame(4242, $p->countInputTokens([new UserMessage('how big is this?')]));

        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('/v1/responses/input_tokens', $history[0]['request']->getUri()->getPath());
        $this->assertArrayNotHasKey('stream', $sent);
        $this->assertArrayNotHasKey('store', $sent);
    }

    // ---------------- helpers ----------------

    /**
     * @param  array<string, mixed> $options
     * @param  Tool[]               $tools
     * @return array<string, mixed>
     */
    private function body(MetaResponsesProvider $p, array $options, array $tools = []): array
    {
        $m = new \ReflectionMethod($p, 'buildRequestBody');

        return $m->invoke($p, [new UserMessage('hi')], $tools, null, $options);
    }

    private function call(object $p, string $method): mixed
    {
        $m = new \ReflectionMethod($p, $method);

        return $m->invoke($p);
    }

    private function demoTool(): Tool
    {
        return new class extends Tool {
            public function name(): string { return 'demo'; }
            public function description(): string { return 'demo tool'; }
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }
            public function execute(array $input): ToolResult
            {
                return ToolResult::success('ok');
            }
            public function isReadOnly(): bool { return true; }
        };
    }

    private function handle(string $id = 'resp_1'): JobHandle
    {
        return JobHandle::new('meta-responses', $id, 'response');
    }

    /**
     * @param  Response[]                        $responses
     * @param  array<int, array<string, mixed>>  $history
     */
    private function withTransport(array $responses, array &$history = []): MetaResponsesProvider
    {
        $provider = new MetaResponsesProvider(['api_key' => 'k']);

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.meta.ai/']);

        $r = new \ReflectionObject($provider);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setValue($provider, $client);

        return $provider;
    }

    private function host(object $provider): string
    {
        $r = new \ReflectionObject($provider);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $client = $prop->getValue($provider);

        return (string) parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }
}
