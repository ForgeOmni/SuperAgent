<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

/**
 * Configuration for a cross-provider handoff. Describes WHAT should
 * happen to provider-specific artifacts when a conversation that has
 * been running against one provider gets re-targeted at another.
 *
 * The encoders themselves are content-blind on this front — they
 * always do the conservative thing and drop vendor-only artifacts on
 * outbound encode. The Policy is the place callers tune that
 * behaviour: keep tool history vs strip, drop signed thinking blocks
 * from the internal representation entirely vs keep them around for a
 * potential round-trip back to the source provider, what to do with
 * images that the new provider's wire shape can't accept, etc.
 *
 * Construction is via static factories so the common defaults live in
 * one place and bespoke knobs stay opt-in.
 */
final class HandoffPolicy
{
    /**
     * @param bool   $keepToolHistory       Preserve tool_use / tool_result
     *                                       blocks on switch. Default true —
     *                                       dropping them strands the new
     *                                       model mid-loop.
     * @param bool   $dropThinking          Strip provider-signed thinking
     *                                       blocks from the *internal*
     *                                       message list (not just outbound
     *                                       encode). Defaults true: signed
     *                                       reasoning is dead weight once
     *                                       the source provider is gone.
     * @param string $imageStrategy         How to handle image content
     *                                       blocks the new family can't
     *                                       represent: 'fail' | 'drop' |
     *                                       'recompress' (the last is a
     *                                       caller hook — base
     *                                       implementation falls back to
     *                                       'drop' if no recompressor is
     *                                       wired in).
     * @param bool   $insertHandoffMarker   Append a SystemMessage to the
     *                                       history that names the source
     *                                       and target providers. Useful
     *                                       for the new model to know its
     *                                       context didn't originate with
     *                                       it. Default true.
     * @param bool   $resetContinuationIds  Drop provider-scoped continuation
     *                                       state (Responses API
     *                                       previous_response_id, Kimi
     *                                       prompt_cache_key,
     *                                       Gemini cachedContent ref).
     *                                       Default true; turning this off
     *                                       only makes sense when the
     *                                       handoff is "back to the same
     *                                       provider after a brief
     *                                       experiment."
     */
    public function __construct(
        public readonly bool   $keepToolHistory      = true,
        public readonly bool   $dropThinking         = true,
        public readonly string $imageStrategy        = 'drop',
        public readonly bool   $insertHandoffMarker  = true,
        public readonly bool   $resetContinuationIds = true,
    ) {
    }

    /**
     * The "conservative" default — strip everything that's
     * provider-scoped, keep the conversation runnable. This is what
     * `Agent::switchProvider()` uses when no policy is supplied.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * "Pass through" — keep every artifact in the internal
     * representation. Encoders still drop what their wire shape can't
     * carry on the request itself, but the data survives so a swap
     * back to the source provider can recover it. Use when you're
     * mid-conversation and switching providers temporarily (e.g. to
     * delegate a sub-task to a faster / cheaper model and resume).
     */
    public static function preserveAll(): self
    {
        return new self(
            keepToolHistory: true,
            dropThinking: false,
            imageStrategy: 'drop',
            insertHandoffMarker: false,
            resetContinuationIds: false,
        );
    }

    /**
     * "Fresh slate" — drop the entire history except the original
     * system prompt and the most recent user turn. Useful when you've
     * decided the conversation is corrupted (model went off the rails)
     * and you want a different model to take a clean shot. The Agent
     * applies this by trimming the history before the encoder runs.
     */
    public static function freshStart(): self
    {
        return new self(
            keepToolHistory: false,
            dropThinking: true,
            imageStrategy: 'drop',
            insertHandoffMarker: true,
            resetContinuationIds: true,
        );
    }
}
