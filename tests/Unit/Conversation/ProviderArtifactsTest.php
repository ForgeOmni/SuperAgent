<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Conversation;

use PHPUnit\Framework\TestCase;
use SuperAgent\Conversation\ProviderArtifacts;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

class ProviderArtifactsTest extends TestCase
{
    public function test_set_get_round_trip_under_provider_namespace(): void
    {
        $meta = [];
        $meta = ProviderArtifacts::set($meta, 'anthropic', 'thinking', [['t' => 'reasoning']]);
        $meta = ProviderArtifacts::set($meta, 'kimi',      'prompt_cache_key', 'sess-42');

        $this->assertSame(
            [['t' => 'reasoning']],
            ProviderArtifacts::get($meta, 'anthropic', 'thinking')
        );
        $this->assertSame(
            'sess-42',
            ProviderArtifacts::get($meta, 'kimi', 'prompt_cache_key')
        );
        $this->assertNull(ProviderArtifacts::get($meta, 'gemini', 'cachedContent'));
    }

    public function test_clear_provider_only_removes_named_namespace(): void
    {
        $meta = [];
        $meta = ProviderArtifacts::set($meta, 'anthropic', 'thinking', ['x']);
        $meta = ProviderArtifacts::set($meta, 'kimi', 'prompt_cache_key', 'sess');

        $cleared = ProviderArtifacts::clearProvider($meta, 'anthropic');

        $this->assertNull(ProviderArtifacts::get($cleared, 'anthropic', 'thinking'));
        $this->assertSame('sess', ProviderArtifacts::get($cleared, 'kimi', 'prompt_cache_key'));
    }

    public function test_capture_anthropic_thinking_moves_block_into_metadata_and_returns_clean_message(): void
    {
        $orig = new AssistantMessage();
        $orig->content = [
            ContentBlock::thinking('chain of thought'),
            ContentBlock::text('public answer'),
        ];

        $cleaned = ProviderArtifacts::captureAnthropicThinking($orig);

        $this->assertNotSame($orig, $cleaned, 'captureAnthropicThinking returns a fresh AssistantMessage');

        // visible content lost the thinking block
        $types = array_map(fn (ContentBlock $b) => $b->type, $cleaned->content);
        $this->assertSame(['text'], $types);

        // metadata picked it up under the anthropic namespace
        $stashed = ProviderArtifacts::get($cleaned->metadata, 'anthropic', 'thinking');
        $this->assertIsArray($stashed);
        $this->assertCount(1, $stashed);
        $this->assertSame('chain of thought', $stashed[0]['thinking']);
    }

    public function test_capture_is_idempotent_and_does_not_clobber_existing_artifacts(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::thinking('first')];
        $msg->metadata = [
            ProviderArtifacts::META_KEY => [
                'kimi' => ['prompt_cache_key' => 'sess-1'],
            ],
        ];

        $captured = ProviderArtifacts::captureAnthropicThinking($msg);

        // The kimi namespace must remain.
        $this->assertSame(
            'sess-1',
            ProviderArtifacts::get($captured->metadata, 'kimi', 'prompt_cache_key')
        );
        $this->assertCount(1, ProviderArtifacts::get($captured->metadata, 'anthropic', 'thinking'));
    }

    public function test_capture_with_no_thinking_blocks_returns_message_unchanged(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('hi')];

        $out = ProviderArtifacts::captureAnthropicThinking($msg);

        $this->assertSame($msg, $out, 'no-op path must not allocate a new message');
    }
}
