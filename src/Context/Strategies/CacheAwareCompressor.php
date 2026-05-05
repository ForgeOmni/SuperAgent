<?php

declare(strict_types=1);

namespace SuperAgent\Context\Strategies;

use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\Message;
use SuperAgent\Context\MessageRole;
use SuperAgent\Context\TokenEstimator;

/**
 * Cache-aware compressor — wraps a delegate strategy (typically
 * `ConversationCompressor`) so that compaction never mutates the
 * stable prefix that providers cache.
 *
 * Why this matters for DeepSeek (and any auto-prefix-cache vendor):
 *
 *   DeepSeek bills the cached portion of a prompt at 1/10 the normal
 *   read rate, but only when the byte sequence at the start of the
 *   request matches a previously-seen request EXACTLY. The cached
 *   slice extends from byte 0 up to the first divergence. Insert a
 *   summary at position 0, or even rewrite the system prompt, and
 *   you've nuked the entire cached prefix on every subsequent turn.
 *
 *   The naive `ConversationCompressor` splits the message list as
 *   `[compress_old | keep_recent]` and inserts a summary boundary
 *   between them — fine for cost on cold caches, terrible for cost
 *   on warm caches because the summary lands AT THE TOP of the
 *   surviving conversation.
 *
 *   This wrapper:
 *
 *     1. Pins a "head" prefix that NEVER moves (system message +
 *        the first `pinHead` non-system messages). That prefix is
 *        what the provider cached on turn 1 and what every
 *        subsequent turn must also send unchanged.
 *
 *     2. Delegates compression to an inner strategy, but only over
 *        the *middle* slice (between head and tail). The summary
 *        boundary lands AFTER the pinned head, so cache hits on
 *        the head slice continue to work.
 *
 *     3. Preserves the tail (recent messages) verbatim — same
 *        contract as the inner strategy.
 *
 *   Result shape: `[head_pinned, summary_boundary, summary,
 *   tail_preserved]`. Cached bytes: `[head_pinned]`.
 *
 * Default pin sizes are conservative — 1 system + 4 conversation
 * messages — which covers a typical "reset agent + give it the
 * goal" pattern. Bump `pinHead` for longer onboarding preambles.
 *
 * The wrapper is a strategy itself, so callers register it instead
 * of the bare delegate. It implements `getPriority()` by deferring
 * to the delegate so the strategy ranking stays stable.
 */
class CacheAwareCompressor implements CompressionStrategy
{
    /**
     * @param int  $pinHead    Non-system messages to pin at the head;
     *                         0 means "system message only". Defaults
     *                         to 4 so the first 2 user/assistant pairs
     *                         stay cache-stable.
     * @param bool $pinSystem  Always pin the leading system message
     *                         when present (it almost certainly should
     *                         be — disable only for tests).
     */
    public function __construct(
        private CompressionStrategy $delegate,
        private TokenEstimator $tokenEstimator,
        private CompressionConfig $config,
        private int $pinHead = 4,
        private bool $pinSystem = true,
    ) {}

    public function getPriority(): int
    {
        return $this->delegate->getPriority();
    }

    public function getName(): string
    {
        return 'cache_aware_' . $this->delegate->getName();
    }

    public function canCompress(array $messages, array $context = []): bool
    {
        // Need enough messages on top of the pinned region for the
        // delegate to find anything worth compressing.
        $pinTotal = $this->resolvePinHeadCount($messages);
        $tailKeep = $context['keep_recent'] ?? $this->config->keepRecentMessages;
        if (count($messages) <= $pinTotal + $tailKeep + $this->config->minMessages) {
            return false;
        }
        return $this->delegate->canCompress(
            $this->extractMiddle($messages, $pinTotal, $tailKeep),
            $context,
        );
    }

    public function compress(array $messages, array $options = []): CompressionResult
    {
        $pinTotal = $this->resolvePinHeadCount($messages);
        $tailKeep = $options['keep_recent'] ?? $this->config->keepRecentMessages;

        $head   = array_slice($messages, 0, $pinTotal);
        $middle = $this->extractMiddle($messages, $pinTotal, $tailKeep);
        $tail   = array_slice($messages, $pinTotal + count($middle));

        if (empty($middle)) {
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
                metadata: ['strategy' => $this->getName(), 'reason' => 'no_middle'],
            );
        }

        // Delegate operates on `[middle, ...tail]` so its own
        // keep_recent logic still has something to keep — otherwise
        // the delegate would summarise the tail and we'd lose recent
        // context.
        $forDelegate = array_merge($middle, $tail);
        $inner = $this->delegate->compress($forDelegate, $options);

        if (! $inner->isSuccessful()) {
            return $inner;
        }

        // Reassemble. Critical detail: `CompressionResult::getAllMessages()`
        // emits in the order `[boundary, compressed, preserved]`. To put
        // the pinned head at byte 0 we cannot keep the inner result's
        // `boundaryMessage` field — it would render BEFORE the head.
        // Instead we collapse boundary + inner-compressed into a single
        // sequence that the OUTER preservedMessages exposes, and surface
        // the assembled list as preservedMessages = [head, boundary,
        // compressed-from-inner, inner-preserved-tail].
        $compressedMessages = $inner->compressedMessages;
        $innerPreserved     = $inner->preservedMessages;
        $boundary           = $inner->boundaryMessage;

        $assembled = $head;
        if ($boundary !== null) {
            $assembled[] = $boundary;
        }
        foreach ($compressedMessages as $m) {
            $assembled[] = $m;
        }
        foreach ($innerPreserved as $m) {
            $assembled[] = $m;
        }

        $preCount  = $this->countTokens($messages);
        $postCount = $this->countTokens($assembled);

        return new CompressionResult(
            // Empty `compressedMessages` here (we already inlined the
            // summary into the preserved list) so getAllMessages()
            // doesn't double-render it.
            compressedMessages: [],
            preservedMessages: $assembled,
            boundaryMessage: null,
            attachments: $inner->attachments ?? [],
            tokensSaved: $inner->tokensSaved,
            preCompactTokenCount: $preCount,
            postCompactTokenCount: $postCount,
            metadata: array_merge($inner->metadata ?? [], [
                'strategy'      => $this->getName(),
                'pin_head'      => $pinTotal,
                'pinned_tokens' => $this->countTokens($head),
            ]),
        );
    }

    /**
     * Resolve how many leading messages we pin: optionally the system
     * message plus `$pinHead` further messages.
     *
     * @param Message[] $messages
     */
    private function resolvePinHeadCount(array $messages): int
    {
        $pin = $this->pinHead;
        if ($this->pinSystem
            && isset($messages[0])
            && $messages[0]->role === MessageRole::SYSTEM
        ) {
            $pin++;
        }
        return min($pin, max(0, count($messages) - 1));
    }

    /**
     * @param Message[] $messages
     * @return Message[]
     */
    private function extractMiddle(array $messages, int $pinTotal, int $tailKeep): array
    {
        $totalLen = count($messages);
        $middleEnd = max($pinTotal, $totalLen - $tailKeep);
        return array_slice($messages, $pinTotal, $middleEnd - $pinTotal);
    }

    /**
     * @param Message[] $messages
     */
    private function countTokens(array $messages): int
    {
        if ($messages === []) return 0;
        return $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn (Message $m) => $m->toArray(), $messages),
        );
    }
}
