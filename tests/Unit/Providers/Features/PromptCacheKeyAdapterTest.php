<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Features;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Providers\Features\PromptCacheKeyAdapter;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\OpenAIProvider;

class PromptCacheKeyAdapterTest extends TestCase
{
    public function test_kimi_sets_top_level_prompt_cache_key(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $body = ['model' => 'kimi-k2-6', 'messages' => []];

        PromptCacheKeyAdapter::apply($p, ['session_id' => 'sess-abc'], $body);

        $this->assertSame('sess-abc', $body['prompt_cache_key']);
    }

    public function test_empty_session_id_is_a_noop(): void
    {
        // Templated options with no session id present — don't force
        // the caller to conditionally strip the feature entry.
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $body = ['model' => 'kimi-k2-6', 'messages' => []];

        PromptCacheKeyAdapter::apply($p, ['session_id' => ''], $body);

        $this->assertArrayNotHasKey('prompt_cache_key', $body);
    }

    public function test_provider_without_native_support_silently_skips(): void
    {
        // OpenAI standard model — no SupportsPromptCacheKey. Default
        // (not required) is a silent no-op: caching is a perf op, not
        // a correctness primitive.
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = ['model' => 'gpt-4o', 'messages' => []];

        PromptCacheKeyAdapter::apply($p, ['session_id' => 'sess-abc'], $body);

        $this->assertArrayNotHasKey('prompt_cache_key', $body);
    }

    public function test_required_raises_when_provider_lacks_support(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = ['model' => 'gpt-4o', 'messages' => []];

        $this->expectException(FeatureNotSupportedException::class);
        PromptCacheKeyAdapter::apply(
            $p,
            ['session_id' => 'sess-abc', 'required' => true],
            $body,
        );
    }

    public function test_enabled_false_disables_entirely(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $body = ['model' => 'kimi-k2-6', 'messages' => []];

        PromptCacheKeyAdapter::apply(
            $p,
            ['session_id' => 'sess-abc', 'enabled' => false],
            $body,
        );

        $this->assertArrayNotHasKey('prompt_cache_key', $body);
    }

    public function test_kimi_provider_exposes_interface(): void
    {
        // Guardrail: the Kimi class must declare it, otherwise the
        // adapter silently skips even when the spec is well-formed.
        $this->assertTrue(
            in_array(
                \SuperAgent\Providers\Capabilities\SupportsPromptCacheKey::class,
                class_implements(KimiProvider::class) ?: [],
                true,
            ),
        );
    }
}
