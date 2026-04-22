<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Features;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Providers\Features\ThinkingAdapter;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\QwenProvider;

class ThinkingAdapterTest extends TestCase
{
    public function test_glm_emits_native_thinking_field(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        ThinkingAdapter::apply($p, ['budget' => 4000], $body);
        $this->assertSame(['type' => 'enabled'], $body['thinking']);
    }

    public function test_qwen_writes_into_parameters_subobject(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = ['parameters' => ['result_format' => 'message']];
        ThinkingAdapter::apply($p, ['budget' => 3000], $body);
        $this->assertTrue($body['parameters']['enable_thinking']);
        $this->assertSame(3000, $body['parameters']['thinking_budget']);
        // Existing parameters keys must be preserved by deep merge.
        $this->assertSame('message', $body['parameters']['result_format']);
    }

    public function test_anthropic_path_is_exercised_by_capability_check(): void
    {
        // We don't construct AnthropicProvider here (it requires a real-looking
        // api key and OAuth plumbing); the interface implementation is
        // asserted in CapabilityInterfaceContractTest. This test documents
        // the expectation.
        $this->assertTrue(
            class_implements(\SuperAgent\Providers\AnthropicProvider::class)[
                \SuperAgent\Providers\Capabilities\SupportsThinking::class
            ] === \SuperAgent\Providers\Capabilities\SupportsThinking::class,
            'AnthropicProvider must implement SupportsThinking',
        );
    }

    public function test_kimi_native_thinking_swaps_model_variant(): void
    {
        // As of Phase 10 (#5), Kimi implements SupportsThinking by switching
        // to the `kimi-k2-thinking-preview` model variant — Kimi doesn't
        // expose a request-level thinking field.
        $p = new KimiProvider(['api_key' => 'sk-x', 'model' => 'kimi-k2-6']);
        $body = ['model' => 'kimi-k2-6', 'messages' => [['role' => 'user', 'content' => 'hi']]];
        ThinkingAdapter::apply($p, [], $body);
        $this->assertSame('kimi-k2-thinking-preview', $body['model']);
        // No CoT prompt injected when the provider handled it natively.
        $this->assertSame('user', $body['messages'][0]['role']);
    }

    public function test_provider_without_native_thinking_falls_back_to_cot(): void
    {
        // OpenAI doesn't implement SupportsThinking — adapter must degrade
        // to CoT prompt injection rather than crash.
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        ThinkingAdapter::apply($p, [], $body);
        // CoT is injected as a new system message prepended.
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertStringContainsString('step-by-step', $body['messages'][0]['content']);
        $this->assertSame('user', $body['messages'][1]['role']);
    }

    public function test_cot_fallback_appends_to_existing_system_prompt(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = ['messages' => [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'hi'],
        ]];
        ThinkingAdapter::apply($p, [], $body);
        $this->assertStringContainsString('You are helpful.', $body['messages'][0]['content']);
        $this->assertStringContainsString('step-by-step', $body['messages'][0]['content']);
        $roles = array_column($body['messages'], 'role');
        $this->assertSame(['system', 'user'], $roles);
    }

    public function test_required_without_support_throws(): void
    {
        $this->expectException(FeatureNotSupportedException::class);
        $this->expectExceptionMessageMatches('/thinking/');

        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        ThinkingAdapter::apply($p, ['required' => true], $body);
    }

    public function test_disabled_is_noop(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        $before = $body;
        ThinkingAdapter::apply($p, ['enabled' => false, 'required' => true], $body);
        $this->assertSame($before, $body);
    }

    public function test_default_budget_is_used_when_spec_omits_it(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = ['parameters' => []];
        ThinkingAdapter::apply($p, [], $body);
        $this->assertSame(
            ThinkingAdapter::DEFAULT_BUDGET_TOKENS,
            $body['parameters']['thinking_budget'],
        );
    }
}
