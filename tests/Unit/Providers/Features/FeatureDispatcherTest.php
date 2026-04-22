<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Features;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\Features\FeatureAdapter;
use SuperAgent\Providers\Features\FeatureDispatcher;
use SuperAgent\Providers\GlmProvider;

class FeatureDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        // Tests mutate the shared registry; restore to defaults for isolation.
        FeatureDispatcher::reset();
    }

    public function test_empty_features_is_strict_noop(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['messages' => []];
        $before = $body;
        FeatureDispatcher::apply($p, [], $body);
        $this->assertSame($before, $body);

        $body = ['messages' => []];
        FeatureDispatcher::apply($p, ['features' => []], $body);
        $this->assertSame(['messages' => []], $body);
    }

    public function test_unknown_feature_name_is_silently_ignored(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['messages' => []];
        FeatureDispatcher::apply($p, ['features' => ['not-a-real-feature' => []]], $body);
        $this->assertSame(['messages' => []], $body);
    }

    public function test_known_feature_is_dispatched_to_registered_adapter(): void
    {
        FeatureDispatcher::reset();
        FeatureDispatcher::register(StubCountingAdapter::class);

        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['x' => 0];
        FeatureDispatcher::apply(
            $p,
            ['features' => [StubCountingAdapter::FEATURE_NAME => ['inc' => 5]]],
            $body,
        );
        $this->assertSame(5, $body['x']);
    }

    public function test_scalar_spec_coerced_to_defaults_when_true(): void
    {
        FeatureDispatcher::reset();
        FeatureDispatcher::register(StubCountingAdapter::class);

        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['x' => 0];
        FeatureDispatcher::apply(
            $p,
            ['features' => [StubCountingAdapter::FEATURE_NAME => true]],
            $body,
        );
        // Default increment is 1 when spec is []/true.
        $this->assertSame(1, $body['x']);
    }

    public function test_scalar_false_disables_feature(): void
    {
        FeatureDispatcher::reset();
        FeatureDispatcher::register(StubCountingAdapter::class);

        $p = new GlmProvider(['api_key' => 'k']);
        $body = ['x' => 0];
        FeatureDispatcher::apply(
            $p,
            ['features' => [StubCountingAdapter::FEATURE_NAME => false]],
            $body,
        );
        $this->assertSame(0, $body['x']);
    }

    public function test_register_rejects_non_adapter_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeatureDispatcher::register(\stdClass::class);
    }

    public function test_register_rejects_empty_feature_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeatureDispatcher::register(StubUnnamedAdapter::class);
    }

    public function test_register_defaults_includes_thinking(): void
    {
        FeatureDispatcher::reset();
        FeatureDispatcher::registerDefaults();
        $this->assertArrayHasKey('thinking', FeatureDispatcher::registered());
    }
}

class StubCountingAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'stub_counter';

    public static function apply(\SuperAgent\Contracts\LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }
        $inc = (int) ($spec['inc'] ?? 1);
        $body['x'] = ($body['x'] ?? 0) + $inc;
    }
}

class StubUnnamedAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = '';

    public static function apply(\SuperAgent\Contracts\LLMProvider $provider, array $spec, array &$body): void
    {
        // never reached — constant is empty
    }
}
