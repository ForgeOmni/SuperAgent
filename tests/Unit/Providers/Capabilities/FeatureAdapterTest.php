<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Capabilities;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Providers\Features\FeatureAdapter;

/**
 * Exercises the FeatureAdapter base class plumbing via a tiny concrete
 * subclass declared inline. Actual per-feature adapters (ThinkingAdapter,
 * WebSearchAdapter, …) land in Phase 3 and have their own tests.
 */
class FeatureAdapterTest extends TestCase
{
    public function test_required_feature_without_capability_throws(): void
    {
        $this->expectException(FeatureNotSupportedException::class);
        $this->expectExceptionMessageMatches('/test_feature/');

        $provider = new StubProvider('openai');
        $body = [];
        StubAdapter::apply($provider, ['required' => true], $body);
    }

    public function test_disabled_feature_is_noop(): void
    {
        $provider = new StubProvider('openai');
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        $before = $body;

        StubAdapter::apply($provider, ['enabled' => false, 'required' => true], $body);

        $this->assertSame($before, $body);
    }

    public function test_optional_feature_without_capability_is_silent_noop(): void
    {
        $provider = new StubProvider('openai');
        $body = ['messages' => []];
        $before = $body;

        // required defaults to false — no throw, no mutation.
        StubAdapter::apply($provider, [], $body);

        $this->assertSame($before, $body);
    }

    public function test_merge_replaces_scalars_and_deep_merges_assoc(): void
    {
        $provider = new StubProvider('openai');
        $body = [
            'model' => 'original',
            'thinking' => ['type' => 'disabled'],
            'messages' => [['role' => 'user']],
        ];

        StubMergingAdapter::apply($provider, [], $body);

        // Scalar overwrite
        $this->assertSame('overridden', $body['model']);
        // Deep-merge assoc
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2000], $body['thinking']);
        // Untouched
        $this->assertSame([['role' => 'user']], $body['messages']);
    }

    public function test_merge_replaces_indexed_list_wholesale(): void
    {
        $provider = new StubProvider('openai');
        $body = [
            'stop_sequences' => ['a', 'b', 'c'],
        ];

        StubListAdapter::apply($provider, [], $body);

        // Indexed lists must be replaced wholesale, NOT element-merged.
        $this->assertSame(['x', 'y'], $body['stop_sequences']);
    }
}

/**
 * Minimal LLMProvider stub for adapter tests. The chat generator is never
 * driven — only name() is touched by the adapter paths.
 */
class StubProvider implements LLMProvider
{
    public function __construct(private readonly string $name) {}

    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator {
        yield from [];
    }

    public function formatMessages(array $messages): array { return []; }
    public function formatTools(array $tools): array { return []; }
    public function getModel(): string { return ''; }
    public function setModel(string $model): void {}
    public function name(): string { return $this->name; }
}

/**
 * Concrete adapter used to exercise the required/disabled/noop branches.
 */
class StubAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'test_feature';

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }
        // Stub never implements any capability → required path raises.
        if (self::isRequired($spec)) {
            self::fail($provider);
        }
        // else silent noop
    }
}

/**
 * Concrete adapter that exercises the deep-merge helper.
 */
class StubMergingAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'merge_probe';

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        self::merge($body, [
            'model' => 'overridden',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 2000],
        ]);
    }
}

class StubListAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'list_probe';

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        self::merge($body, [
            'stop_sequences' => ['x', 'y'],
        ]);
    }
}
