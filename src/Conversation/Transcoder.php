<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

use SuperAgent\Conversation\Encoder\AnthropicEncoder;
use SuperAgent\Conversation\Encoder\DashScopeEncoder;
use SuperAgent\Conversation\Encoder\GeminiEncoder;
use SuperAgent\Conversation\Encoder\OllamaEncoder;
use SuperAgent\Conversation\Encoder\OpenAIChatEncoder;
use SuperAgent\Conversation\Encoder\OpenAIResponsesEncoder;
use SuperAgent\Messages\Message;

/**
 * Single point of translation between SuperAgent's internal
 * `Message[]` representation and the six wire-format families
 * supported by the underlying providers.
 *
 * The Transcoder exists so that a conversation started against one
 * provider (say, Anthropic) can be handed off to another (say, Kimi)
 * mid-flight without each provider growing its own ad-hoc converter
 * for every other provider's output. There is exactly one canonical
 * encoder per family; provider classes call `encode($messages,
 * $family)` and stay out of the wire-format business.
 *
 * The internal representation is itself Anthropic-shaped (text /
 * tool_use / tool_result / thinking content blocks; tool_use_id as
 * the correlation key). That is a historical accident, not a contract
 * — but it means `WireFamily::Anthropic` is effectively a no-op pass
 * through `Message::toArray()`, while every other family is a true
 * down-translation that may drop vendor-only artifacts and re-key tool
 * call ids.
 *
 * Encoders are stateless. Per-conversation state needed for
 * cross-family handoff (e.g. a ToolCallIdMap for the Gemini case
 * where results correlate by name+order, not by id) lands in P2 as a
 * `TranscoderContext` parameter; the public `encode()` signature will
 * grow that argument when it lands. For P1 we cover families A and B
 * — the two that 11 of the 13 shipped providers target.
 */
class Transcoder
{
    /**
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    public function encode(array $messages, WireFamily $target): array
    {
        return match ($target) {
            WireFamily::Anthropic       => (new AnthropicEncoder())->encode($messages),
            WireFamily::OpenAIChat      => (new OpenAIChatEncoder())->encode($messages),
            WireFamily::Gemini          => (new GeminiEncoder())->encode($messages),
            WireFamily::OpenAIResponses => (new OpenAIResponsesEncoder())->encode($messages),
            WireFamily::DashScope       => (new DashScopeEncoder())->encode($messages),
            WireFamily::Ollama          => (new OllamaEncoder())->encode($messages),
        };
    }
}
