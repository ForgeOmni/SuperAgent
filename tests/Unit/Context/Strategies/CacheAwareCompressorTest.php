<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Context\Strategies;

use PHPUnit\Framework\TestCase;
use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\Message;
use SuperAgent\Context\MessageRole;
use SuperAgent\Context\Strategies\CacheAwareCompressor;
use SuperAgent\Context\Strategies\CompressionResult;
use SuperAgent\Context\Strategies\CompressionStrategy;
use SuperAgent\Context\TokenEstimator;

/**
 * The cache-aware wrapper exists to KEEP THE PREFIX BYTE-STABLE.
 * These tests assert that property: after compaction, the leading
 * messages must equal the leading messages of the original input,
 * irrespective of how many compaction rounds run.
 */
class CacheAwareCompressorTest extends TestCase
{
    public function test_pins_system_message_and_first_n_messages(): void
    {
        $messages = [
            Message::system('You are SuperAgent.'),
            Message::user('start the project'),
            Message::assistant('Got it. What stack?'),
            Message::user('PHP'),
            Message::assistant('Cool. Setting up.'),
            Message::user('keep going'),
            Message::assistant('Working on it...'),
            Message::user('any progress?'),
            Message::assistant('Almost there.'),
            Message::user('done?'),
        ];

        // Delegate that actually succeeds — summarises the middle and
        // keeps the last 2 messages.
        $delegate = $this->fakeDelegate(
            canCompressReturn: true,
            compressResult: function (array $forDelegate) {
                return new CompressionResult(
                    compressedMessages: [Message::assistant('SUMMARY')],
                    preservedMessages: array_slice($forDelegate, -2),
                    tokensSaved: 100,
                );
            },
        );
        $wrapper = new CacheAwareCompressor(
            delegate:        $delegate,
            tokenEstimator:  new TokenEstimator(),
            config:          new CompressionConfig(minMessages: 1, keepRecentMessages: 2),
            pinHead:         2,
            pinSystem:       true,
        );

        $result = $wrapper->compress($messages);

        $assembled = $result->getAllMessages();
        // First three messages MUST match the original — system +
        // pinHead=2 = 3 leading messages preserved verbatim.
        $this->assertSame($messages[0]->id, $assembled[0]->id);
        $this->assertSame($messages[1]->id, $assembled[1]->id);
        $this->assertSame($messages[2]->id, $assembled[2]->id);
    }

    public function test_summary_lands_after_pinned_head_not_at_position_zero(): void
    {
        $messages = [
            Message::system('sys'),
            Message::user('u1'),
            Message::assistant('a1'),
            Message::user('u2'),
            Message::assistant('a2'),
            Message::user('u3'),
            Message::assistant('a3'),
            Message::user('u4'),
            Message::assistant('a4'),
            Message::user('u5'),
        ];

        // Delegate produces a summary message + a boundary marker.
        $summary = Message::assistant('SUMMARY OF MIDDLE', metadata: ['fake_summary' => true]);
        $boundary = new Message(MessageRole::SYSTEM, '--- compacted ---');
        $delegate = $this->fakeDelegate(
            canCompressReturn: true,
            compressResult: function (array $forDelegate) use ($summary, $boundary) {
                // Inner strategy keeps the last 2 — same shape ConversationCompressor produces.
                $tail = array_slice($forDelegate, -2);
                return new CompressionResult(
                    compressedMessages: [$summary],
                    preservedMessages: $tail,
                    boundaryMessage: $boundary,
                    tokensSaved: 100,
                );
            },
        );
        $wrapper = new CacheAwareCompressor(
            delegate:       $delegate,
            tokenEstimator: new TokenEstimator(),
            config:         new CompressionConfig(minMessages: 1, keepRecentMessages: 2),
            pinHead:        2,
        );

        $result = $wrapper->compress($messages);
        $assembled = $result->getAllMessages();

        // Pinned [system, u1, a1] must come first and be unchanged.
        $this->assertSame($messages[0]->id, $assembled[0]->id);
        $this->assertSame($messages[1]->id, $assembled[1]->id);
        $this->assertSame($messages[2]->id, $assembled[2]->id);
        // Summary boundary must come AFTER the pinned head, not at 0.
        $foundSummary = false;
        foreach ($assembled as $idx => $m) {
            if (($m->metadata['fake_summary'] ?? false) === true) {
                $foundSummary = true;
                $this->assertGreaterThan(2, $idx, 'summary must NOT land in the cached prefix');
            }
        }
        $this->assertTrue($foundSummary, 'summary must be present in result');
    }

    public function test_idempotent_prefix_across_repeated_compactions(): void
    {
        // Critical contract: running the wrapper N times must leave
        // the first M bytes (the cached prefix) byte-stable. This
        // mirrors a long-running session where compaction fires every
        // few turns.
        $messages = [];
        $messages[] = Message::system('persistent system rules');
        for ($i = 0; $i < 12; $i++) {
            $messages[] = Message::user("turn $i question");
            $messages[] = Message::assistant("turn $i answer");
        }

        $delegate = $this->fakeDelegate(
            canCompressReturn: true,
            compressResult: function (array $forDelegate) {
                $tail = array_slice($forDelegate, -2);
                return new CompressionResult(
                    compressedMessages: [Message::assistant('SUMMARY')],
                    preservedMessages: $tail,
                    boundaryMessage: new Message(MessageRole::SYSTEM, '---'),
                    tokensSaved: 50,
                );
            },
        );
        $wrapper = new CacheAwareCompressor(
            delegate:       $delegate,
            tokenEstimator: new TokenEstimator(),
            config:         new CompressionConfig(minMessages: 1, keepRecentMessages: 2),
            pinHead:        2,
        );

        $r1 = $wrapper->compress($messages)->getAllMessages();
        // Feed the result back in (simulates a second compaction
        // round on the trimmed conversation + a few new turns).
        $r1[] = Message::user('another turn');
        $r1[] = Message::assistant('another reply');
        $r1[] = Message::user('and another');
        $r1[] = Message::assistant('and again');
        $r2 = $wrapper->compress($r1)->getAllMessages();

        // First 3 ids must equal the original first 3 ids on both
        // rounds. That's what makes the prefix cache-stable.
        $this->assertSame($messages[0]->id, $r1[0]->id);
        $this->assertSame($messages[1]->id, $r1[1]->id);
        $this->assertSame($messages[2]->id, $r1[2]->id);
        $this->assertSame($messages[0]->id, $r2[0]->id);
        $this->assertSame($messages[1]->id, $r2[1]->id);
        $this->assertSame($messages[2]->id, $r2[2]->id);
    }

    public function test_no_op_when_too_few_messages(): void
    {
        $delegate = $this->fakeDelegate(canCompressReturn: false);
        $wrapper = new CacheAwareCompressor(
            delegate:       $delegate,
            tokenEstimator: new TokenEstimator(),
            config:         new CompressionConfig(minMessages: 10),
            pinHead:        2,
        );
        $messages = [Message::system('s'), Message::user('u'), Message::assistant('a')];
        $this->assertFalse($wrapper->canCompress($messages));
    }

    public function test_inherits_delegate_priority_and_renames(): void
    {
        $delegate = $this->fakeDelegate(canCompressReturn: true);
        $wrapper = new CacheAwareCompressor(
            delegate:       $delegate,
            tokenEstimator: new TokenEstimator(),
            config:         new CompressionConfig(),
        );
        $this->assertSame($delegate->getPriority(), $wrapper->getPriority());
        $this->assertStringStartsWith('cache_aware_', $wrapper->getName());
    }

    /**
     * Build a fake CompressionStrategy whose canCompress() and compress()
     * are configurable per test.
     */
    private function fakeDelegate(
        bool $canCompressReturn = true,
        ?\Closure $compressResult = null,
    ): CompressionStrategy {
        return new class($canCompressReturn, $compressResult) implements CompressionStrategy {
            public function __construct(
                private bool $canCompressReturn,
                private ?\Closure $compressResult,
            ) {}
            public function getPriority(): int { return 42; }
            public function getName(): string { return 'fake'; }
            public function canCompress(array $messages, array $context = []): bool { return $this->canCompressReturn; }
            public function compress(array $messages, array $options = []): CompressionResult
            {
                if ($this->compressResult) {
                    return ($this->compressResult)($messages);
                }
                return new CompressionResult(
                    compressedMessages: [],
                    preservedMessages: $messages,
                    tokensSaved: 0,
                );
            }
        };
    }
}
