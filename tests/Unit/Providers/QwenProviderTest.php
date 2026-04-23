<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\Features\ThinkingAdapter;
use SuperAgent\Providers\QwenProvider;

/**
 * Locks down the **default** Qwen path: OpenAI-compatible endpoint at
 * `<region-host>/compatible-mode/v1/chat/completions`. This is the
 * endpoint Alibaba's own qwen-code CLI uses exclusively (see
 * `packages/core/src/core/openaiContentGenerator/constants.ts:5`).
 *
 * The legacy DashScope native body shape — `input.messages` +
 * `parameters.thinking_budget` + `text-generation/generation` URL —
 * lives on `QwenNativeProvider` and is covered by
 * `QwenNativeProviderTest`.
 */
class QwenProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/QWEN_API_KEY|API key/i');
        new QwenProvider([]);
    }

    public function test_default_region_is_intl_singapore(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $this->assertSame('intl', $p->getRegion());
        $this->assertSame('dashscope-intl.aliyuncs.com', $this->host($p));
    }

    public function test_us_region_maps_to_virginia(): void
    {
        $p = new QwenProvider(['api_key' => 'k', 'region' => 'us']);
        $this->assertSame('dashscope-us.aliyuncs.com', $this->host($p));
    }

    public function test_cn_region_maps_to_beijing(): void
    {
        $p = new QwenProvider(['api_key' => 'k', 'region' => 'cn']);
        $this->assertSame('dashscope.aliyuncs.com', $this->host($p));
    }

    public function test_hk_region_maps_to_hong_kong(): void
    {
        $p = new QwenProvider(['api_key' => 'k', 'region' => 'hk']);
        $this->assertSame('cn-hongkong.dashscope.aliyuncs.com', $this->host($p));
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/region/');
        new QwenProvider(['api_key' => 'k', 'region' => 'eu']);
    }

    public function test_name_is_qwen(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $this->assertSame('qwen', $p->name());
    }

    public function test_default_model_is_qwen3_6_max_preview(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $this->assertSame('qwen3.6-max-preview', $p->getModel());
    }

    public function test_authorization_header_is_bearer_api_key(): void
    {
        $p = new QwenProvider(['api_key' => 'sk-dashscope-1']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('Bearer sk-dashscope-1', $headers['authorization']);
    }

    public function test_base_url_includes_compatible_mode_v1(): void
    {
        // The compat-mode prefix is in the base URL so the chat path
        // can stay the inherited `chat/completions` (no per-region
        // path branching).
        $p = new QwenProvider(['api_key' => 'k']);
        $base = $this->baseUri($p);
        $this->assertStringContainsString('/compatible-mode/v1/', $base);
    }

    public function test_request_body_is_openai_chat_completions_shape(): void
    {
        // Top-level `messages` and `model`, NOT DashScope native's
        // `input.messages` + `parameters.*`. Body shape consistency
        // with every other ChatCompletionsProvider subclass.
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], 'you are helpful', []);

        $this->assertArrayHasKey('model', $body);
        $this->assertArrayHasKey('messages', $body);
        $this->assertArrayNotHasKey('input', $body, 'Native input.messages key must NOT appear on chat-completions path');
        $this->assertArrayNotHasKey('parameters', $body, 'Native parameters.* key must NOT appear on chat-completions path');
        $this->assertSame('qwen3.6-max-preview', $body['model']);
        // Role normalization — system prompt is set as plain string,
        // UserMessage emits Role enum.
        // Role can be a string (literal system prompt) or a backed enum
        // (UserMessage emits SuperAgent\Enums\Role). Both have a `value`
        // property on a backed enum.
        $roleStr = static fn ($r) => is_string($r)
            ? $r
            : (($r instanceof \BackedEnum) ? (string) $r->value : (string) $r);
        $this->assertSame('system', $roleStr($body['messages'][0]['role']));
        $this->assertSame('user', $roleStr($body['messages'][1]['role']));
    }

    public function test_thinking_emits_enable_thinking_top_level_no_budget(): void
    {
        // Per kimi-cli — Qwen's OpenAI-compatible endpoint accepts
        // `enable_thinking: true` at the body root (delivered via
        // OpenAI SDK's extra_body, but on the wire it's flat). There
        // is NO thinking_budget field on this endpoint — grep through
        // qwen-code shows zero hits.
        $p = new QwenProvider(['api_key' => 'k']);
        $body = ['model' => 'qwen3.6-max-preview', 'messages' => [['role' => 'user', 'content' => 'hi']]];
        ThinkingAdapter::apply($p, [], $body);

        $this->assertTrue($body['enable_thinking']);
        $this->assertArrayNotHasKey('thinking_budget', $body);
        $this->assertArrayNotHasKey('parameters', $body);
        // Same model — no swap to a thinking-only variant (matches
        // kimi-cli's same-model approach).
        $this->assertSame('qwen3.6-max-preview', $body['model']);
    }

    public function test_thinking_budget_is_silently_ignored_at_runtime(): void
    {
        // Caller passes a budget; we accept it for SupportsThinking
        // contract compatibility but the wire body must NOT carry it.
        // Migrating callers from QwenNativeProvider get a debug warning
        // when SUPERAGENT_DEBUG=1 (out of test scope to assert).
        $p = new QwenProvider(['api_key' => 'k']);
        $body = ['model' => 'qwen3.6-max-preview', 'messages' => []];
        ThinkingAdapter::apply($p, ['budget' => 8000], $body);

        $this->assertTrue($body['enable_thinking']);
        $this->assertArrayNotHasKey('thinking_budget', $body);
    }

    // ── Phase 7: DashScope metadata + vision flag + UserAgent ─────

    public function test_x_dashscope_useragent_header_is_sent(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $h = $this->clientHeaders($p);
        $this->assertArrayHasKey('x-dashscope-useragent', $h);
        $this->assertStringStartsWith('SuperAgent/', $h['x-dashscope-useragent']);
    }

    public function test_metadata_envelope_carries_channel_and_optional_ids(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, [
            'session_id' => 'sess-42',
            'prompt_id'  => 'p-77',
        ]);
        $this->assertArrayHasKey('metadata', $body);
        $this->assertSame('sess-42', $body['metadata']['sessionId']);
        $this->assertSame('p-77', $body['metadata']['promptId']);
        $this->assertSame('superagent', $body['metadata']['channel']);
    }

    public function test_metadata_present_even_without_session_or_prompt_ids(): void
    {
        // `channel` is always set so DashScope-side dashboards always
        // see traffic from us as superagent.
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, []);
        $this->assertSame(['channel' => 'superagent'], $body['metadata']);
    }

    public function test_vision_models_get_high_res_image_flag(): void
    {
        // Match qwen-code's detection: qwen-vl* / qwen3-vl* /
        // qwen3.5-plus* / qwen3-omni*. Default qwen3.6-max-preview is
        // NOT vision-capable.
        foreach (['qwen-vl-plus', 'qwen-vl-ocr', 'qwen3-vl-plus', 'qwen3.5-plus', 'qwen3-omni'] as $id) {
            $this->assertTrue(
                QwenProvider::isVisionModel($id),
                "{$id} should be classed as vision-capable",
            );
        }
        foreach (['qwen3.6-max-preview', 'qwen3-max', 'qwen-plus', 'qwen-turbo'] as $id) {
            $this->assertFalse(
                QwenProvider::isVisionModel($id),
                "{$id} must NOT be classed as vision-capable (would force unwanted HD downsampling)",
            );
        }
    }

    public function test_vision_flag_lands_in_request_body_for_vision_model(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody(
            $p, [new UserMessage('describe')], [], null,
            ['model' => 'qwen3-vl-plus'],
        );
        $this->assertTrue($body['vl_high_resolution_images']);
    }

    public function test_vision_flag_absent_for_non_vision_model(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, []);
        $this->assertArrayNotHasKey('vl_high_resolution_images', $body);
    }

    // ── helpers ───────────────────────────────────────────────────

    private function buildBody(
        QwenProvider $p,
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

    private function host(QwenProvider $p): string
    {
        return parse_url($this->baseUri($p), PHP_URL_HOST);
    }

    private function baseUri(QwenProvider $p): string
    {
        $rc = new ReflectionClass($p);
        while ($rc && ! $rc->hasProperty('client')) {
            $rc = $rc->getParentClass();
        }
        $prop = $rc->getProperty('client');
        $prop->setAccessible(true);
        return (string) $prop->getValue($p)->getConfig('base_uri');
    }

    private function clientHeaders(QwenProvider $p): array
    {
        $rc = new ReflectionClass($p);
        while ($rc && ! $rc->hasProperty('client')) {
            $rc = $rc->getParentClass();
        }
        $prop = $rc->getProperty('client');
        $prop->setAccessible(true);
        $headers = $prop->getValue($p)->getConfig()['headers'] ?? [];
        return array_change_key_case($headers, CASE_LOWER);
    }
}
