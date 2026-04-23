<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\OpenAIProvider;

/**
 * Locks down the `$options['extra_body']` escape hatch contract on
 * ChatCompletionsProvider.
 *
 * The contract (tested against the base class via two concrete subclasses
 * so we know it works uniformly across Kimi / OpenAI / GLM / MiniMax / etc.):
 *
 *   1. No extra_body key → no-op. Body byte-exact.
 *   2. Scalar fields are placed at the top level of the request body.
 *   3. Nested associative fields are deep-merged (leaf-wins) with any
 *      existing value (e.g. whatever `customizeRequestBody` or
 *      FeatureDispatcher already wrote).
 *   4. Indexed arrays are replaced wholesale — we don't concatenate lists.
 *   5. extra_body always wins over adapters and customizeRequestBody
 *      because it runs last. This is deliberate: it's a power-user escape
 *      hatch for exactly the case where we shipped a wrong default.
 *
 * These assertions go through the real `buildRequestBody()` path via
 * reflection so the test stays honest — we don't just exercise the
 * helper in isolation.
 */
class ExtraBodyMergingTest extends TestCase
{
    private function invokeBuildRequestBody(object $provider, array $options): array
    {
        $rc = new ReflectionClass($provider);
        while ($rc && ! $rc->hasMethod('buildRequestBody')) {
            $rc = $rc->getParentClass();
        }
        $m = $rc->getMethod('buildRequestBody');
        $m->setAccessible(true);

        // Minimal valid inputs — we only care about extra_body merging.
        return $m->invoke($provider, [], [], null, $options);
    }

    public function test_no_extra_body_key_is_a_noop(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, []);

        $this->assertArrayNotHasKey('extra_body', $body);
        // No unexpected keys leaked in; the body should only have the
        // standard chat-completions envelope.
        $this->assertSame(
            ['model', 'messages', 'max_tokens', 'stream', 'temperature'],
            array_keys($body),
        );
    }

    public function test_scalar_extra_body_fields_land_at_top_level(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, [
            'extra_body' => [
                'seed' => 42,
                'logprobs' => true,
                'user' => 'tenant-A',
            ],
        ]);

        $this->assertSame(42, $body['seed']);
        $this->assertTrue($body['logprobs']);
        $this->assertSame('tenant-A', $body['user']);
    }

    public function test_nested_associative_fields_deep_merge_leaf_wins(): void
    {
        // Kimi's Phase-1 thinking fragment writes `thinking: {type:
        // "enabled"}` at top level. A user passing extra_body adding
        // `thinking.budget` should merge the two fields — not overwrite.
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, [
            'features' => ['thinking' => ['budget' => 4000]],   // writes thinking.type
            'extra_body' => ['thinking' => ['budget_tokens' => 8000]],
        ]);

        $this->assertIsArray($body['thinking']);
        $this->assertSame('enabled', $body['thinking']['type']);  // from adapter
        $this->assertSame(8000, $body['thinking']['budget_tokens']); // from extra_body
    }

    public function test_indexed_lists_are_replaced_wholesale(): void
    {
        // Stop-sequences as an indexed list: user override must replace,
        // not concatenate.
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, [
            'extra_body' => ['stop' => ['<END>', '<<<']],
        ]);

        $this->assertSame(['<END>', '<<<'], $body['stop']);
    }

    public function test_extra_body_wins_over_feature_dispatcher(): void
    {
        // FeatureDispatcher's ThinkingAdapter sets reasoning_effort based
        // on budget; extra_body must be able to override it after the fact.
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, [
            'features' => ['thinking' => ['budget' => 4000]],  // → medium
            'extra_body' => ['reasoning_effort' => 'high'],
        ]);

        $this->assertSame('high', $body['reasoning_effort']);
    }

    public function test_non_array_extra_body_is_ignored(): void
    {
        // Defensive: if a caller passes a scalar / null, we silently skip
        // rather than fatally error — the `is_array` guard lives in
        // buildRequestBody().
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, ['extra_body' => 'oops']);

        $this->assertArrayNotHasKey('extra_body', $body);
        $this->assertSame(
            ['model', 'messages', 'max_tokens', 'stream', 'temperature'],
            array_keys($body),
        );
    }

    public function test_empty_extra_body_array_is_a_noop(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $body = $this->invokeBuildRequestBody($p, ['extra_body' => []]);

        $this->assertSame(
            ['model', 'messages', 'max_tokens', 'stream', 'temperature'],
            array_keys($body),
        );
    }
}
