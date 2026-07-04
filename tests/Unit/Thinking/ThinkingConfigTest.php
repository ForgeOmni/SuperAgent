<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Thinking;

use PHPUnit\Framework\TestCase;
use SuperAgent\Thinking\ThinkingConfig;

class ThinkingConfigTest extends TestCase
{
    public function test_fable_is_a_thinking_model(): void
    {
        $this->assertTrue(ThinkingConfig::modelSupportsThinking('claude-fable-5'));
    }

    public function test_fable_uses_adaptive_surface(): void
    {
        $this->assertTrue(ThinkingConfig::modelSupportsAdaptiveThinking('claude-fable-5'));
    }

    public function test_opus_4_7_and_4_8_are_adaptive(): void
    {
        $this->assertTrue(ThinkingConfig::modelSupportsAdaptiveThinking('claude-opus-4-7'));
        $this->assertTrue(ThinkingConfig::modelSupportsAdaptiveThinking('claude-opus-4-8'));
    }

    public function test_sonnet_5_is_adaptive(): void
    {
        $this->assertTrue(ThinkingConfig::modelSupportsThinking('claude-sonnet-5'));
        $this->assertTrue(ThinkingConfig::modelSupportsAdaptiveThinking('claude-sonnet-5'));
    }

    public function test_legacy_sonnet_is_not_adaptive(): void
    {
        $this->assertFalse(ThinkingConfig::modelSupportsAdaptiveThinking('claude-3-5-sonnet-20241022'));
    }

    /**
     * Fable 5 / Opus 4.7 / 4.8 reject an explicit budget_tokens (400); the
     * thinking param must be the bare adaptive shape.
     */
    public function test_adaptive_models_emit_adaptive_without_budget(): void
    {
        $cfg = ThinkingConfig::adaptive();

        foreach (['claude-fable-5', 'claude-sonnet-5', 'claude-opus-4-8', 'claude-opus-4-7', 'claude-sonnet-4-6'] as $model) {
            $param = $cfg->toApiParameter($model);
            $this->assertSame(['type' => 'adaptive'], $param, "unexpected shape for {$model}");
            $this->assertArrayNotHasKey('budget_tokens', $param, "budget_tokens leaked for {$model}");
        }
    }

    /**
     * Even when the caller asked for a fixed budget, an adaptive-only model must
     * not receive budget_tokens — switch to adaptive rather than 400.
     */
    public function test_enabled_mode_on_adaptive_model_falls_back_to_adaptive(): void
    {
        $cfg = ThinkingConfig::enabled(20000);
        $this->assertSame(['type' => 'adaptive'], $cfg->toApiParameter('claude-fable-5'));
    }

    public function test_legacy_model_keeps_budget_tokens(): void
    {
        $cfg = ThinkingConfig::enabled(8000);
        $this->assertSame(
            ['type' => 'enabled', 'budget_tokens' => 8000],
            $cfg->toApiParameter('claude-3-5-sonnet-20241022'),
        );
    }

    public function test_disabled_returns_null(): void
    {
        $this->assertNull(ThinkingConfig::disabled()->toApiParameter('claude-fable-5'));
    }

    public function test_unknown_model_returns_null(): void
    {
        $this->assertNull(ThinkingConfig::adaptive()->toApiParameter('gpt-4o'));
    }
}
