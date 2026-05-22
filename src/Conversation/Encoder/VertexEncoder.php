<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\Message;

/**
 * Family — Google Vertex AI.
 *
 * Vertex serves two distinct wire dialects depending on the publisher
 * of the model being invoked:
 *
 *   1. `publishers/google/models/gemini-*` — uses the same `contents[]`
 *      + `parts` shape as Google AI Studio's generateContent endpoint.
 *      For this dialect we delegate to {@see GeminiEncoder} so the
 *      tool-correlation-by-name logic stays in one place.
 *
 *   2. `publishers/anthropic/models/claude-*` — Vertex forwards the
 *      Anthropic Messages body verbatim (same shape Bedrock uses), so
 *      for this dialect we delegate to {@see AnthropicEncoder}.
 *
 * Dialect picking is decided by the caller (typically the Provider
 * that resolved the model id) and threaded through the constructor.
 * The encoder itself is a thin dispatcher — the heavy lifting lives in
 * GeminiEncoder / AnthropicEncoder so they remain the single source of
 * truth for those wire formats.
 *
 * 9Router-borrowed (decolua/9router): 9Router's translation matrix
 * treats Vertex as a first-class destination since GCP-hosted teams
 * can't always hit AI Studio / Anthropic-direct endpoints. The same
 * surface area is needed here for parity with their format coverage.
 */
final class VertexEncoder
{
    public const DIALECT_GEMINI    = 'gemini';
    public const DIALECT_ANTHROPIC = 'anthropic';

    public function __construct(
        public readonly string $dialect = self::DIALECT_GEMINI,
    ) {}

    /**
     * Decide which dialect to use from a Vertex model id. Examples:
     *   publishers/google/models/gemini-2.5-pro       → gemini
     *   publishers/anthropic/models/claude-opus-4-7   → anthropic
     *   gemini-2.5-pro                                → gemini (bare alias)
     *   claude-opus-4-7                               → anthropic (bare alias)
     */
    public static function dialectForModel(string $modelId): string
    {
        $m = strtolower($modelId);
        if (str_contains($m, 'anthropic') || str_contains($m, 'claude')) {
            return self::DIALECT_ANTHROPIC;
        }
        return self::DIALECT_GEMINI;
    }

    /**
     * @param Message[] $messages
     * @return list<array<string,mixed>>
     */
    public function encode(array $messages): array
    {
        return match ($this->dialect) {
            self::DIALECT_ANTHROPIC => (new AnthropicEncoder())->encode($messages),
            default                 => (new GeminiEncoder())->encode($messages),
        };
    }
}
