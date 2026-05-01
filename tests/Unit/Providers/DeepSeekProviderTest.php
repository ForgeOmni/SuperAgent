<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\Capabilities\SupportsThinking;
use SuperAgent\Providers\DeepSeekProvider;

class DeepSeekProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        new DeepSeekProvider([]);
    }

    public function test_default_region_is_default_endpoint(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $this->assertSame('default', $p->getRegion());
        $this->assertSame('api.deepseek.com', $this->host($p));
    }

    public function test_beta_region_routes_to_beta_subpath(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k', 'region' => 'beta']);
        $client = $this->extractClient($p);
        $uri = (string) $client->getConfig('base_uri');
        $this->assertStringContainsString('api.deepseek.com/beta', $uri);
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        new DeepSeekProvider(['api_key' => 'k', 'region' => 'eu']);
    }

    public function test_name_is_deepseek(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $this->assertSame('deepseek', $p->name());
    }

    public function test_default_model_is_v4_flash(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $this->assertSame('deepseek-v4-flash', $p->getModel());
    }

    public function test_implements_supports_thinking(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $this->assertInstanceOf(SupportsThinking::class, $p);
    }

    public function test_thinking_fragment_returns_enabled_shape(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        // V4 server controls budget server-side; we just turn on the
        // reasoning channel.
        $this->assertSame(
            ['thinking' => ['type' => 'enabled']],
            $p->thinkingRequestFragment(4000),
        );
    }

    public function test_thinking_option_injects_body_field(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, ['thinking' => true]);
        $this->assertSame(['type' => 'enabled'], $body['thinking']);
    }

    public function test_thinking_absent_by_default(): void
    {
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, []);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_uses_v1_chat_completions_path(): void
    {
        // DeepSeek wires the OpenAI-compat endpoint at v1/chat/completions —
        // same as the base default. This test doubles as a guard against a
        // future refactor accidentally moving the path.
        $p = new DeepSeekProvider(['api_key' => 'k']);
        $rc = new ReflectionClass($p);
        while ($rc && ! $rc->hasMethod('chatCompletionsPath')) {
            $rc = $rc->getParentClass();
        }
        $m = $rc->getMethod('chatCompletionsPath');
        $m->setAccessible(true);
        $this->assertSame('v1/chat/completions', $m->invoke($p));
    }

    /**
     * V4-thinking and R1 stream the model's reasoning chain on a separate
     * `delta.reasoning_content` channel. The shared base parser must
     * surface it as a `thinking` ContentBlock so callers can render
     * (or hide) it deliberately rather than mixing it into the answer.
     */
    public function test_reasoning_content_surfaces_as_thinking_block(): void
    {
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['reasoning_content' => 'Let me ']]),
            $this->chunk(['delta' => ['reasoning_content' => 'think...']]),
            $this->chunk(['delta' => ['content' => 'The answer is 42.']]),
            $this->chunk(['finish_reason' => 'stop']),
            'data: [DONE]',
        ]);

        $msg = $this->runParser($sse);

        $thinkingBlocks = array_values(array_filter(
            $msg->content,
            fn ($b) => $b->type === 'thinking',
        ));
        $textBlocks = array_values(array_filter(
            $msg->content,
            fn ($b) => $b->type === 'text',
        ));

        $this->assertCount(1, $thinkingBlocks);
        $this->assertSame('Let me think...', $thinkingBlocks[0]->thinking);
        $this->assertCount(1, $textBlocks);
        $this->assertSame('The answer is 42.', $textBlocks[0]->text);
        // Thinking block must precede text so renderers iterate in
        // the natural reasoning-then-answer order.
        $this->assertSame('thinking', $msg->content[0]->type);
        $this->assertSame('text', $msg->content[1]->type);
    }

    /**
     * DeepSeek's historical V3 usage shape ships `prompt_cache_hit_tokens`
     * and `prompt_cache_miss_tokens` rather than the OpenAI-compat
     * `prompt_tokens_details.cached_tokens`. The parser must accept it.
     */
    public function test_prompt_cache_hit_tokens_populates_cache_read(): void
    {
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['content' => 'ok']]),
            'data: ' . json_encode([
                'choices' => [['finish_reason' => 'stop']],
                'usage' => [
                    'prompt_tokens' => 1000,
                    'completion_tokens' => 50,
                    'prompt_cache_hit_tokens' => 800,
                    'prompt_cache_miss_tokens' => 200,
                ],
            ]),
            'data: [DONE]',
        ]);

        $msg = $this->runParser($sse);

        $this->assertNotNull($msg->usage);
        $this->assertSame(800, $msg->usage->cacheReadInputTokens);
        // OpenAI-compat semantics: prompt_tokens is gross. The parser
        // must subtract cache hits so CostCalculator doesn't bill the
        // cached portion at full price + 10% (effectively 110%).
        $this->assertSame(200, $msg->usage->inputTokens);
        $this->assertSame(50, $msg->usage->outputTokens);
    }

    public function test_openai_compat_prompt_tokens_details_still_works(): void
    {
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['content' => 'ok']]),
            'data: ' . json_encode([
                'choices' => [['finish_reason' => 'stop']],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 10,
                    'prompt_tokens_details' => ['cached_tokens' => 400],
                ],
            ]),
            'data: [DONE]',
        ]);
        $msg = $this->runParser($sse);
        $this->assertSame(400, $msg->usage->cacheReadInputTokens);
        $this->assertSame(100, $msg->usage->inputTokens);
    }

    // ── helpers ───────────────────────────────────────────────────

    private function buildBody(
        DeepSeekProvider $p,
        array $messages,
        array $tools,
        ?string $system,
        array $options,
    ): array {
        $rc = new ReflectionClass($p);
        while ($rc && ! $rc->hasMethod('buildRequestBody')) {
            $rc = $rc->getParentClass();
        }
        $m = $rc->getMethod('buildRequestBody');
        $m->setAccessible(true);
        return $m->invoke($p, $messages, $tools, $system, $options);
    }

    private function host(DeepSeekProvider $p): string
    {
        $client = $this->extractClient($p);
        return parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }

    private function extractClient(DeepSeekProvider $p): \GuzzleHttp\Client
    {
        $r = new ReflectionClass($p);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        return $prop->getValue($p);
    }

    private function chunk(array $choice): string
    {
        return 'data: ' . json_encode(['choices' => [$choice]]);
    }

    private function sseOf(array $frames): string
    {
        return implode("\n", $frames) . "\n";
    }

    private function runParser(string $sseText): AssistantMessage
    {
        $stream = Utils::streamFor($sseText);
        $p = new DeepSeekProvider(['api_key' => 'sk-test']);
        $rc = new ReflectionClass($p);
        while ($rc && ! $rc->hasMethod('parseSSEStream')) {
            $rc = $rc->getParentClass();
        }
        $m = $rc->getMethod('parseSSEStream');
        $m->setAccessible(true);
        $gen = $m->invoke($p, $stream, null);
        return $gen->current();
    }
}
