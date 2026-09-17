<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Messages\UserMessage;
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

    public function test_background_is_refused_rather_than_silently_dropped(): void
    {
        $this->expectException(FeatureNotSupportedException::class);
        $this->expectExceptionMessageMatches('/background/');

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

    // ---------------- helpers ----------------

    /**
     * @param  array<string, mixed> $options
     * @param  Tool[]               $tools
     * @return array<string, mixed>
     */
    private function body(MetaResponsesProvider $p, array $options, array $tools = []): array
    {
        $m = new \ReflectionMethod($p, 'buildRequestBody');
        $m->setAccessible(true);

        return $m->invoke($p, [new UserMessage('hi')], $tools, null, $options);
    }

    private function call(object $p, string $method): mixed
    {
        $m = new \ReflectionMethod($p, $method);
        $m->setAccessible(true);

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

    private function host(object $provider): string
    {
        $r = new \ReflectionObject($provider);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        $client = $prop->getValue($provider);

        return (string) parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }
}
