<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * Translates a generic `thinking` feature spec into whatever the resolved
 * provider needs:
 *
 *   - Native path — provider implements `SupportsThinking` and returns the
 *     vendor-specific body fragment (Anthropic `thinking: {type, budget_tokens}`,
 *     GLM `thinking: {type: enabled}`, Qwen `parameters.enable_thinking` etc.).
 *     The fragment is deep-merged into `$body`.
 *
 *   - Fallback path — provider has no thinking primitive. The adapter
 *     injects a Chain-of-Thought instruction into the system prompt field
 *     (when the body carries an OpenAI-style `messages` array) so the model
 *     still produces its reasoning explicitly, just not in a structured
 *     thinking channel.
 *
 *   - Required path — `$spec['required'] = true` AND the provider has no
 *     native support: `FeatureNotSupportedException` is raised rather than
 *     silently degrading to CoT (the caller explicitly asked for the
 *     guaranteed-structured behaviour).
 *
 * Spec shape:
 *   [
 *     'budget'   => 4000,        // advisory token budget, default 4000
 *     'enabled'  => true|false,  // optional; false disables entirely
 *     'required' => true|false,  // default false (graceful degradation)
 *   ]
 */
class ThinkingAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'thinking';

    /**
     * Default budget when the spec doesn't set one. Picked to match
     * Anthropic's docs examples; Qwen accepts the same number; GLM ignores it.
     */
    public const DEFAULT_BUDGET_TOKENS = 4000;

    public static function validSpecKeys(): ?array
    {
        return ['enabled', 'required', 'budget'];
    }

    /**
     * CoT fallback text injected when the provider lacks native thinking.
     * Written in the second person so it composes naturally with whatever
     * primary system prompt was already in place.
     */
    private const COT_PROMPT = 'Before answering, think step-by-step and explain your reasoning, then give the final answer.';

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }

        $budget = (int) ($spec['budget'] ?? self::DEFAULT_BUDGET_TOKENS);

        if ($provider instanceof SupportsThinking) {
            $fragment = $provider->thinkingRequestFragment($budget);
            self::merge($body, $fragment);
            return;
        }

        if (self::isRequired($spec)) {
            self::fail($provider, $body['model'] ?? null);
        }

        // Graceful degradation — inject CoT instruction into the first
        // system message if there is one, otherwise prepend a new system
        // message. No-op if the body has no `messages` array (e.g. a
        // non-chat-completions shape that we don't know how to augment —
        // the user still gets a silent degrade rather than a confusing
        // partial apply).
        self::injectCotSystemPrompt($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function injectCotSystemPrompt(array &$body): void
    {
        if (! isset($body['messages']) || ! is_array($body['messages'])) {
            return;
        }

        // OpenAI chat-completions style: messages[] with role=system at position 0.
        if (! empty($body['messages']) && ($body['messages'][0]['role'] ?? null) === 'system') {
            $existing = $body['messages'][0]['content'] ?? '';
            if (is_string($existing)) {
                $body['messages'][0]['content'] = trim($existing . "\n\n" . self::COT_PROMPT);
            }
            return;
        }

        array_unshift($body['messages'], [
            'role' => 'system',
            'content' => self::COT_PROMPT,
        ]);
    }
}
