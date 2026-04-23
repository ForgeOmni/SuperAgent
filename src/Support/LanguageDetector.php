<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Dirt-simple CJK-vs-Latin detector.
 *
 * Motivation: the SDK ships built-in system prompts and user-facing
 * strings (e.g. the `AgentTool` spawn guardrails, `productivityWarning`
 * text) in English. When the driving prompt is Chinese, downstream
 * providers like Kimi / Qwen / GLM fluidly switch into Chinese — except
 * for those embedded English fragments, which leak through and produce
 * zh-and-en mixed output (SuperAICore RUN 71 surfaced this pattern in
 * the spawn-consolidation path; it applies just as well to any SDK
 * built-in string).
 *
 * The detection is intentionally *primitive* — one regex against the
 * Unicode ideograph block. Rationale: false positives (marking an
 * English prompt that happens to quote a Chinese proper noun as
 * `ZH`) are far rarer than true positives (any real Chinese run).
 * Calling code should feed in whatever text carries the user's intent
 * — normally the current prompt, or the first agent's `task_prompt`.
 *
 * We do NOT attempt to distinguish zh-CN vs zh-TW, ja vs zh, or any
 * other CJK variant. Downstream templates currently have at most two
 * variants (ZH / EN), so a binary gate is sufficient. If that ever
 * grows to a richer locale set, the right call is to surface this as
 * an {@see detect()} method returning a tag rather than adding a
 * second branch in {@see isCjk()}.
 *
 * Lifted in spirit from SuperAICore's
 * `SpawnConsolidationPrompt::looksChinese()` +
 * `SpawnPlan::appendGuards()` CJK gate — generalised so any SDK
 * surface can use it without dragging the host-side orchestration
 * dependencies.
 */
final class LanguageDetector
{
    /**
     * True iff the text contains at least one CJK Unified Ideograph
     * (U+4E00..U+9FFF). That block covers the vast majority of
     * everyday Chinese / Japanese / Korean characters; narrower
     * detection isn't justified given the binary template split.
     *
     * Empty / null / non-string inputs return false — the template
     * code then falls back to the English default, which is the
     * right conservative behaviour for unclear intent.
     *
     * @param scalar|null $text
     */
    public static function isCjk(mixed $text): bool
    {
        if (! is_string($text) || $text === '') {
            return false;
        }
        return preg_match('/[\x{4E00}-\x{9FFF}]/u', $text) === 1;
    }

    /**
     * Convenience: pick the first template that matches the detected
     * language. Two-entry dictionary: `{'zh': ..., 'en': ...}`.
     * Falls back to `en` when the text is non-CJK or when a matching
     * entry is missing — so callers can ship only `zh` overrides on
     * top of an existing English baseline.
     *
     * @template T
     * @param scalar|null $text
     * @param array<string,T> $templates
     * @param T              $default
     * @return T
     */
    public static function pick(mixed $text, array $templates, mixed $default = null): mixed
    {
        $lang = self::isCjk($text) ? 'zh' : 'en';
        if (array_key_exists($lang, $templates)) {
            return $templates[$lang];
        }
        if ($lang !== 'en' && array_key_exists('en', $templates)) {
            return $templates['en'];
        }
        return $default;
    }
}
