<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\Capabilities\SupportsCodeInterpreter;

/**
 * Translates `features.code_interpreter` into the right provider-specific
 * primitive (Qwen's `parameters.enable_code_interpreter`, future OpenAI /
 * Gemini variants) or degrades gracefully when the provider has no
 * native support.
 *
 * Degrade path: inject a system-prompt paragraph telling the model that
 * if code execution would help, it should call whatever local sandbox
 * tool the caller has wired up (`python`, `shell`, …). Unlike thinking
 * which has a clean prompt fallback, code-interpreter's fallback
 * critically depends on the caller actually providing a local sandbox
 * tool — the adapter documents this in the prompt but doesn't guarantee
 * execution.
 *
 * Spec shape:
 *   [
 *     'enabled'  => true|false,      // default true
 *     'required' => true|false,      // default false
 *     'timeout_seconds' => 30,       // provider hint (reserved)
 *   ]
 */
class CodeInterpreterAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'code_interpreter';

    public static function validSpecKeys(): ?array
    {
        return ['enabled', 'required', 'timeout_seconds'];
    }

    private const FALLBACK_PROMPT = "If executing short code snippets "
        . "(Python / shell) would help answer the user's question, call "
        . "a locally-available sandbox tool rather than guessing — prefer "
        . "verified output over confident speculation.";

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }

        if ($provider instanceof SupportsCodeInterpreter) {
            $fragment = $provider->codeInterpreterRequestFragment($spec);
            self::merge($body, $fragment);
            return;
        }

        if (self::isRequired($spec)) {
            self::fail($provider, $body['model'] ?? null);
        }

        // Graceful fallback — prepend / merge a prompt hint that tells the
        // model to defer execution to a caller-provided sandbox tool. This
        // is weaker than native code_interpreter (only works if the caller
        // wired such a tool) but it's the best non-native approximation.
        self::injectFallbackPrompt($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function injectFallbackPrompt(array &$body): void
    {
        if (! isset($body['messages']) || ! is_array($body['messages'])) {
            return;
        }

        // Idempotent — don't stack the hint on repeated apply.
        foreach ($body['messages'] as $msg) {
            $content = $msg['content'] ?? null;
            if (is_string($content) && str_contains($content, 'sandbox tool rather than guessing')) {
                return;
            }
        }

        if (! empty($body['messages']) && ($body['messages'][0]['role'] ?? null) === 'system') {
            $existing = (string) ($body['messages'][0]['content'] ?? '');
            $body['messages'][0]['content'] = trim($existing . "\n\n" . self::FALLBACK_PROMPT);
            return;
        }

        array_unshift($body['messages'], [
            'role' => 'system',
            'content' => self::FALLBACK_PROMPT,
        ]);
    }
}
