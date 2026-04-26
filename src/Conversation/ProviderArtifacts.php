<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

/**
 * Helper for the per-message `provider_artifacts` namespace on
 * `AssistantMessage::$metadata`.
 *
 * The internal Message representation is Anthropic-shaped, but every
 * provider has its own set of fields that ride alongside the
 * conversation and don't survive a vendor switch:
 *
 *   - Anthropic        — signed `thinking` blocks, `cache_control`
 *                        markers on system / message blocks
 *   - OpenAI Responses — encrypted `reasoning` items, the
 *                        `previous_response_id` continuation token
 *   - Kimi             — `prompt_cache_key` (session-level cache)
 *   - Gemini           — references to a server-side `cachedContent`
 *
 * To keep cross-family handoff lossless when the conversation later
 * comes back to the original provider, those artifacts get stashed on
 * `AssistantMessage::$metadata['provider_artifacts'][$providerKey]`
 * rather than thrown away. Encoders keep ignoring unknown
 * provider_artifacts on outbound encode (the wire shape they target
 * doesn't have a place for them); when a handoff comes BACK to the
 * original provider, it can read the artifacts off the metadata and
 * stitch them back into its own request body.
 *
 * The convention is namespaced by provider key so a single
 * `AssistantMessage` can carry artifacts for multiple providers
 * across a complex multi-handoff conversation:
 *
 *   $message->metadata['provider_artifacts'] = [
 *       'anthropic' => [
 *           'thinking' => [
 *               ['signature' => '...', 'thinking' => '...'],
 *           ],
 *           'cache_breakpoints' => [...],
 *       ],
 *       'openai_responses' => [
 *           'previous_response_id' => 'resp_abc',
 *           'encrypted_reasoning'  => [...],
 *       ],
 *   ];
 *
 * The keys under each provider namespace are provider-defined — this
 * class is intentionally agnostic about their shape, just enforces
 * the outer namespace convention so different providers can't step on
 * each other.
 */
final class ProviderArtifacts
{
    /** @var string The metadata key under which artifacts are stored. */
    public const META_KEY = 'provider_artifacts';

    /**
     * Capture all `thinking` ContentBlocks on a message into the
     * Anthropic provider_artifacts slot, then return a new
     * AssistantMessage with the thinking blocks removed from the
     * visible content. Idempotent: already-captured artifacts are
     * preserved.
     *
     * Used by `HandoffPolicy::dropThinking = true` so the visible
     * conversation history shrinks but the signed reasoning is still
     * available if the agent is later switched back to Anthropic.
     */
    public static function captureAnthropicThinking(AssistantMessage $message): AssistantMessage
    {
        $thinking = [];
        $kept = [];
        foreach ($message->content as $block) {
            if ($block->type === 'thinking') {
                $thinking[] = [
                    'thinking' => $block->thinking,
                    // The signature is not currently exposed on
                    // ContentBlock; when AnthropicProvider's parser
                    // starts surfacing it, this slot is where it goes.
                    // For now we record the body so a future re-emit
                    // is at least possible.
                ];
                continue;
            }
            $kept[] = $block;
        }

        if ($thinking === []) {
            return $message;
        }

        $copy = new AssistantMessage();
        $copy->content    = $kept;
        $copy->stopReason = $message->stopReason;
        $copy->usage      = $message->usage;
        $copy->metadata   = self::set($message->metadata, 'anthropic', 'thinking', array_merge(
            self::get($message->metadata, 'anthropic', 'thinking') ?? [],
            $thinking,
        ));
        return $copy;
    }

    /**
     * Read a single artifact slot for a provider, or null if absent.
     *
     * @param array<string, mixed> $metadata
     * @return mixed
     */
    public static function get(array $metadata, string $providerKey, string $artifactKey): mixed
    {
        return $metadata[self::META_KEY][$providerKey][$artifactKey] ?? null;
    }

    /**
     * Return a new metadata array with the artifact slot set.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function set(
        array $metadata,
        string $providerKey,
        string $artifactKey,
        mixed $value
    ): array {
        $metadata[self::META_KEY] ??= [];
        $metadata[self::META_KEY][$providerKey] ??= [];
        $metadata[self::META_KEY][$providerKey][$artifactKey] = $value;
        return $metadata;
    }

    /**
     * Drop every artifact recorded under the named provider key.
     * Used by `HandoffPolicy::resetContinuationIds` when the
     * handoff target is the named provider — its old continuation
     * state is meaningless on the new turn.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function clearProvider(array $metadata, string $providerKey): array
    {
        if (isset($metadata[self::META_KEY][$providerKey])) {
            unset($metadata[self::META_KEY][$providerKey]);
        }
        return $metadata;
    }
}
