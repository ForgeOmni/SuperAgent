<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\MetaProvider;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Wire-shape pins for the native Meta Model API provider (Muse Spark).
 *
 * Every assertion here stands for a request the Model API would reject:
 * `max_tokens` instead of `max_completion_tokens`, `reasoning_effort: none`,
 * a `max` tier on a 1.2 model, or any of the OpenAI parameters Meta lists
 * as unsupported.
 */
class MetaProviderTest extends TestCase
{
    // ---------------- construction ----------------

    public function test_constructor_requires_a_key(): void
    {
        $this->withoutMetaEnv(function (): void {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessageMatches('/META_API_KEY|MODEL_API_KEY/i');
            new MetaProvider([]);
        });
    }

    public function test_reads_meta_api_key_then_model_api_key_from_env(): void
    {
        $this->withoutMetaEnv(function (): void {
            putenv('MODEL_API_KEY=from-meta-docs');
            $p = new MetaProvider([]);
            $this->assertSame('Bearer from-meta-docs', $this->authHeader($p));

            putenv('META_API_KEY=ours-wins');
            $p = new MetaProvider([]);
            $this->assertSame('Bearer ours-wins', $this->authHeader($p));
        });
    }

    public function test_base_url_and_default_model(): void
    {
        $p = new MetaProvider(['api_key' => 'k']);
        $this->assertSame('api.meta.ai', $this->host($p));
        $this->assertSame('muse-spark-1.3', $p->getModel());
        $this->assertSame('meta', $p->name());
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/region/');
        new MetaProvider(['api_key' => 'k', 'region' => 'eu']);
    }

    // ---------------- wire shape ----------------

    public function test_completion_cap_uses_max_completion_tokens(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), ['max_tokens' => 4096]);

        $this->assertSame(4096, $body['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $body);
    }

    public function test_system_prompt_is_re_roled_to_developer(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), [], 'be precise');

        $this->assertSame('developer', $body['messages'][0]['role']);
        $this->assertSame('be precise', $body['messages'][0]['content']);
    }

    public function test_unsupported_openai_params_are_stripped_even_from_extra_body(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), [
            'extra_body' => [
                'stop' => ['\n'],
                'logprobs' => true,
                'logit_bias' => ['123' => 1],
                'prediction' => ['type' => 'content'],
                'modalities' => ['text'],
                'audio' => ['voice' => 'x'],
                'web_search_options' => ['k' => 'v'],
                'n' => 3,
                'seed' => 42,
            ],
        ]);

        foreach (['stop', 'logprobs', 'logit_bias', 'prediction', 'modalities', 'audio', 'web_search_options', 'n'] as $param) {
            $this->assertArrayNotHasKey($param, $body, "{$param} is a 400 on the Model API");
        }

        // Supported extras still ride through.
        $this->assertSame(42, $body['seed']);
    }

    public function test_n_equal_to_one_is_left_alone(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), ['extra_body' => ['n' => 1]]);
        $this->assertSame(1, $body['n']);
    }

    // ---------------- reasoning effort ----------------

    public function test_effort_floors_at_minimal_because_reasoning_cannot_be_disabled(): void
    {
        $p = new MetaProvider(['api_key' => 'k']);

        foreach (['off', 'none', 'disabled', 'minimal'] as $tier) {
            $this->assertSame(
                ['reasoning_effort' => 'minimal'],
                $p->reasoningEffortFragment($tier),
                "Muse Spark returns 400 for reasoning_effort: none — '{$tier}' must floor at minimal",
            );
        }
    }

    public function test_effort_tiers_pass_through_on_1_3(): void
    {
        $p = new MetaProvider(['api_key' => 'k']);

        $this->assertSame(['reasoning_effort' => 'low'], $p->reasoningEffortFragment('low'));
        $this->assertSame(['reasoning_effort' => 'medium'], $p->reasoningEffortFragment('mid'));
        $this->assertSame(['reasoning_effort' => 'high'], $p->reasoningEffortFragment('high'));
        $this->assertSame(['reasoning_effort' => 'xhigh'], $p->reasoningEffortFragment('xhigh'));
        $this->assertSame(['reasoning_effort' => 'max'], $p->reasoningEffortFragment('max'));
        $this->assertSame([], $p->reasoningEffortFragment('bogus'));
    }

    public function test_max_downgrades_to_xhigh_on_models_without_the_top_tier(): void
    {
        // `max` ("extended reasoning") is Standard-tier 1.3 only — the
        // contributor build returns 400 invalid_request_error for it while
        // xhigh succeeds.
        foreach (['muse-spark-1.3-contributor', 'muse-spark-1.2-contributor', 'muse-spark-1.2', 'muse-spark-1.1'] as $id) {
            $p = new MetaProvider(['api_key' => 'k', 'model' => $id]);
            $this->assertSame(['reasoning_effort' => 'xhigh'], $p->reasoningEffortFragment('max'), $id);
        }
    }

    public function test_reasoning_effort_option_lands_in_the_body(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), ['reasoning_effort' => 'off']);
        $this->assertSame('minimal', $body['reasoning_effort']);
    }

    // ---------------- Meta-specific options ----------------

    public function test_grounding_appends_the_server_side_web_search_tool(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), ['grounding' => true]);

        $this->assertContains(['type' => 'web_search'], $body['tools']);
        $this->assertSame('auto', $body['tool_choice']);
    }

    public function test_grounding_rides_alongside_function_tools(): void
    {
        $p = new MetaProvider(['api_key' => 'k']);
        $body = $this->body($p, ['web_search' => true], null, [$this->demoTool()]);

        $names = array_column($body['tools'], 'type');
        $this->assertContains('function', $names);
        $this->assertContains('web_search', $names);
    }

    public function test_cache_key_and_safety_identifier_pass_through(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), [
            'prompt_cache_key' => 'session-42',
            'safety_identifier' => 'user-abc',
        ]);

        $this->assertSame('session-42', $body['prompt_cache_key']);
        $this->assertSame('user-abc', $body['safety_identifier']);
    }

    public function test_no_meta_specific_fields_when_caller_asks_for_nothing(): void
    {
        $body = $this->body(new MetaProvider(['api_key' => 'k']), []);

        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertArrayNotHasKey('prompt_cache_key', $body);
        $this->assertArrayNotHasKey('tools', $body);
    }

    // ---------------- registry + catalog ----------------

    public function test_registry_resolves_the_meta_provider(): void
    {
        $p = ProviderRegistry::create('meta', ['api_key' => 'k']);
        $this->assertInstanceOf(MetaProvider::class, $p);
        $this->assertSame('muse-spark-1.3', $p->getModel());
    }

    public function test_catalog_carries_the_muse_spark_lineup(): void
    {
        $standard = ModelCatalog::pricing('muse-spark-1.3');
        $this->assertNotNull($standard);
        $this->assertSame(1.25, (float) $standard['input']);
        $this->assertSame(4.25, (float) $standard['output']);

        $contributor = ModelCatalog::pricing('muse-spark-1.3-contributor');
        $this->assertNotNull($contributor);
        $this->assertSame(0.10, (float) $contributor['input']);
        $this->assertSame(0.20, (float) $contributor['output']);
    }

    // ---------------- helpers ----------------

    /**
     * @param  array<string, mixed> $options
     * @param  Tool[]               $tools
     * @return array<string, mixed>
     */
    private function body(MetaProvider $p, array $options, ?string $system = null, array $tools = []): array
    {
        $m = new \ReflectionMethod($p, 'buildRequestBody');

        return $m->invoke($p, [new UserMessage('hi')], $tools, $system, $options);
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

    /**
     * Run a closure with both Meta key env vars cleared, then restore them.
     */
    private function withoutMetaEnv(callable $fn): void
    {
        $saved = [];
        foreach (['META_API_KEY', 'MODEL_API_KEY'] as $var) {
            $saved[$var] = getenv($var);
            putenv($var);
            unset($_ENV[$var]);
        }

        try {
            $fn();
        } finally {
            foreach ($saved as $var => $value) {
                if ($value === false) {
                    putenv($var);
                    unset($_ENV[$var]);
                } else {
                    putenv("{$var}={$value}");
                    $_ENV[$var] = $value;
                }
            }
        }
    }

    private function authHeader(object $provider): string
    {
        $headers = $this->extractClient($provider)->getConfig('headers');
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                return is_array($value) ? (string) $value[0] : (string) $value;
            }
        }

        return '';
    }

    private function host(object $provider): string
    {
        $client = $this->extractClient($provider);

        return (string) parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }

    private function extractClient(object $provider): \GuzzleHttp\Client
    {
        $r = new \ReflectionObject($provider);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');

        return $prop->getValue($provider);
    }
}
