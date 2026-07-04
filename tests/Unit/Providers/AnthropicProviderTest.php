<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Thinking\ThinkingConfig;

/**
 * Request-shaping guarantees for the Anthropic path, with a focus on the
 * Fable 5 / Opus 4.7-4.8 adaptive-only surface (no budget_tokens, no sampling
 * params, no assistant prefill) and the output_config.effort dial.
 */
class AnthropicProviderTest extends TestCase
{
    public function test_name_is_anthropic(): void
    {
        $this->assertSame('anthropic', $this->provider()->name());
    }

    public function test_fable_thinking_is_adaptive_without_budget(): void
    {
        $body = $this->buildBody(
            $this->provider('claude-fable-5'),
            ['thinking' => ThinkingConfig::adaptive()],
        );
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
    }

    public function test_fable_drops_sampling_params(): void
    {
        $body = $this->buildBody(
            $this->provider('claude-fable-5'),
            ['temperature' => 0.7, 'top_p' => 0.9, 'top_k' => 40],
        );
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('top_p', $body);
        $this->assertArrayNotHasKey('top_k', $body);
    }

    public function test_fable_drops_assistant_prefill(): void
    {
        $body = $this->buildBody(
            $this->provider('claude-fable-5'),
            ['assistant_prefill' => 'Sure, here'],
        );
        $this->assertSame('user', end($body['messages'])['role']);
    }

    public function test_fable_effort_maps_to_output_config(): void
    {
        $body = $this->buildBody($this->provider('claude-fable-5'), ['reasoning_effort' => 'xhigh']);
        $this->assertSame(['effort' => 'xhigh'], $body['output_config']);

        $max = $this->buildBody($this->provider('claude-fable-5'), ['reasoning_effort' => 'max']);
        $this->assertSame(['effort' => 'max'], $max['output_config']);

        // "off"/unknown never poison the request.
        foreach (['off', 'bogus', ''] as $e) {
            $b = $this->buildBody($this->provider('claude-fable-5'), ['reasoning_effort' => $e]);
            $this->assertArrayNotHasKey('output_config', $b, "effort '{$e}' should be a no-op");
        }
    }

    public function test_effort_ignored_on_unsupported_model(): void
    {
        $body = $this->buildBody(
            $this->provider('claude-haiku-4-5-20251001'),
            ['reasoning_effort' => 'max'],
        );
        $this->assertArrayNotHasKey('output_config', $body);
    }

    public function test_legacy_model_keeps_sampling_and_prefill(): void
    {
        $body = $this->buildBody(
            $this->provider('claude-3-5-sonnet-20241022'),
            [
                'temperature' => 0.5,
                'top_p' => 0.8,
                'assistant_prefill' => 'Sure',
                'thinking' => ThinkingConfig::disabled(),
            ],
        );
        $this->assertSame(0.5, $body['temperature']);
        $this->assertSame(0.8, $body['top_p']);
        $this->assertSame('assistant', end($body['messages'])['role']);
    }

    public function test_thinking_request_fragment_is_model_aware(): void
    {
        $this->assertSame(
            ['thinking' => ['type' => 'adaptive']],
            $this->provider('claude-fable-5')->thinkingRequestFragment(8000),
        );
        $this->assertSame(
            ['thinking' => ['type' => 'enabled', 'budget_tokens' => 8000]],
            $this->provider('claude-3-5-sonnet-20241022')->thinkingRequestFragment(8000),
        );
    }

    public function test_reasoning_effort_fragment_tiers(): void
    {
        $p = $this->provider('claude-fable-5');
        $this->assertSame(['output_config' => ['effort' => 'low']], $p->reasoningEffortFragment('low'));
        $this->assertSame(['output_config' => ['effort' => 'high']], $p->reasoningEffortFragment('high'));
        $this->assertSame(['output_config' => ['effort' => 'max']], $p->reasoningEffortFragment('max'));
        $this->assertSame([], $p->reasoningEffortFragment('off'));
        $this->assertSame([], $p->reasoningEffortFragment('bogus'));
        // Unsupported model → always empty.
        $this->assertSame([], $this->provider('claude-haiku-4-5-20251001')->reasoningEffortFragment('max'));
    }

    private function provider(string $model = 'claude-fable-5'): AnthropicProvider
    {
        return new AnthropicProvider(['api_key' => 'k', 'model' => $model]);
    }

    private function buildBody(AnthropicProvider $p, array $options): array
    {
        $m = new \ReflectionMethod($p, 'buildRequestBody');
        $m->setAccessible(true);

        return $m->invoke($p, [new UserMessage('hi')], [], 'sys', $options);
    }
}
