<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Features;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Providers\Features\DashScopeCacheControlAdapter;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\QwenProvider;

/**
 * Pins the DashScope block-level prompt caching contract:
 *
 *   - System message gets `cache_control: {type: 'ephemeral'}` — always.
 *   - Last tool definition gets the same — when tools[] is non-empty.
 *   - Last non-system message gets it — ONLY when `stream: true` is
 *     present in the body (so single-shot non-streaming requests
 *     don't pollute the cache key space with user content that
 *     changes every call).
 *
 * Mirrors qwen-code `provider/dashscope.ts:40-54`.
 */
class DashScopeCacheControlAdapterTest extends TestCase
{
    public function test_system_message_gets_ephemeral_marker(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = [
            'model' => 'qwen3.6-max-preview',
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user',   'content' => 'hi'],
            ],
        ];
        DashScopeCacheControlAdapter::apply($p, [], $body);

        $this->assertSame(
            ['type' => 'ephemeral'],
            $body['messages'][0]['cache_control'],
        );
        // User message not marked (non-streaming).
        $this->assertArrayNotHasKey('cache_control', $body['messages'][1]);
    }

    public function test_last_tool_gets_ephemeral_marker(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = [
            'model' => 'qwen3.6-max-preview',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools' => [
                ['type' => 'function', 'function' => ['name' => 'tool_a']],
                ['type' => 'function', 'function' => ['name' => 'tool_b']],
                ['type' => 'function', 'function' => ['name' => 'tool_c']],
            ],
        ];
        DashScopeCacheControlAdapter::apply($p, [], $body);

        $this->assertSame(['type' => 'ephemeral'], $body['tools'][2]['cache_control']);
        // Earlier tools untouched — the tools block is cached as a
        // single unit anchored at the last marker.
        $this->assertArrayNotHasKey('cache_control', $body['tools'][0]);
        $this->assertArrayNotHasKey('cache_control', $body['tools'][1]);
    }

    public function test_last_message_only_marked_when_streaming(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = [
            'model' => 'qwen3.6-max-preview',
            'stream' => true,
            'messages' => [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user',   'content' => 'turn 1'],
                ['role' => 'assistant', 'content' => 'reply 1'],
                ['role' => 'user',   'content' => 'turn 2'],
            ],
        ];
        DashScopeCacheControlAdapter::apply($p, [], $body);

        $this->assertSame(['type' => 'ephemeral'], $body['messages'][0]['cache_control']);
        $this->assertSame(['type' => 'ephemeral'], $body['messages'][3]['cache_control']);
        // Intermediate messages untouched.
        $this->assertArrayNotHasKey('cache_control', $body['messages'][1]);
        $this->assertArrayNotHasKey('cache_control', $body['messages'][2]);
    }

    public function test_non_streaming_last_message_is_not_marked(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = [
            'model' => 'qwen3.6-max-preview',
            'messages' => [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user',   'content' => 'question'],
            ],
        ];
        // No 'stream' => true
        DashScopeCacheControlAdapter::apply($p, [], $body);

        $this->assertSame(['type' => 'ephemeral'], $body['messages'][0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $body['messages'][1]);
    }

    public function test_non_qwen_provider_silently_skips(): void
    {
        // Caching is a perf optimization; falling back on a non-Qwen
        // provider would be surprising. Silent skip by default.
        $p = new OpenAIProvider(['api_key' => 'k']);
        $body = [
            'model' => 'gpt-4o',
            'messages' => [['role' => 'system', 'content' => 'sys']],
        ];
        DashScopeCacheControlAdapter::apply($p, [], $body);
        $this->assertArrayNotHasKey('cache_control', $body['messages'][0]);
    }

    public function test_required_on_non_qwen_raises(): void
    {
        $p = new OpenAIProvider(['api_key' => 'k']);
        $body = ['model' => 'gpt-4o', 'messages' => []];

        $this->expectException(FeatureNotSupportedException::class);
        DashScopeCacheControlAdapter::apply($p, ['required' => true], $body);
    }

    public function test_enabled_false_disables_entirely(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = [
            'model' => 'qwen3.6-max-preview',
            'messages' => [['role' => 'system', 'content' => 'sys']],
        ];
        DashScopeCacheControlAdapter::apply($p, ['enabled' => false], $body);
        $this->assertArrayNotHasKey('cache_control', $body['messages'][0]);
    }

    public function test_caller_supplied_cache_control_is_preserved(): void
    {
        // If the caller already put a non-standard marker on the
        // system message (e.g. custom ttl hint), don't clobber it.
        $p = new QwenProvider(['api_key' => 'k']);
        $body = [
            'model' => 'qwen3.6-max-preview',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'sys',
                    'cache_control' => ['type' => 'persistent'],
                ],
            ],
        ];
        DashScopeCacheControlAdapter::apply($p, [], $body);
        $this->assertSame(['type' => 'persistent'], $body['messages'][0]['cache_control']);
    }

    public function test_header_is_always_sent_via_qwen_provider(): void
    {
        // The server-side toggle header is unconditional — sent on
        // every Qwen request whether or not the caller enables body
        // markers. Safe because the server ignores it when the body
        // has no markers.
        $p = new QwenProvider(['api_key' => 'k']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('enable', $headers['x-dashscope-cachecontrol']);
    }

    private function clientHeaders(object $provider): array
    {
        $rc = new \ReflectionObject($provider);
        while ($rc && ! $rc->hasProperty('client')) {
            $rc = $rc->getParentClass();
        }
        $prop = $rc->getProperty('client');
        $prop->setAccessible(true);
        $headers = $prop->getValue($provider)->getConfig()['headers'] ?? [];
        return array_change_key_case($headers, CASE_LOWER);
    }
}
