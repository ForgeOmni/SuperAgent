<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider exposes a discrete reasoning-effort dial separate from the
 * binary thinking on/off toggle of `SupportsThinking`.
 *
 * DeepSeek V4 family + the OpenAI-compat reasoners (GPT-5 reasoning,
 * Qwen3-thinking) accept three useful tiers:
 *
 *   off  — disable thinking entirely; cheapest, fastest path.
 *   high — DeepSeek's standard thinking budget; the default for V4-Pro
 *          when thinking is enabled.
 *   max  — V4-Pro's "think harder" tier; trades latency + cost for
 *          deeper chain-of-thought.
 *
 * Implementations return the body fragment to merge into the outgoing
 * request. Different upstreams shape the field differently:
 *
 *   - DeepSeek native / OpenRouter / Novita / Fireworks / SGLang —
 *     `reasoning_effort: "high"|"max"` plus `thinking: {type: enabled}`.
 *   - NVIDIA NIM — nests under `chat_template_kwargs.{thinking,
 *     reasoning_effort}`.
 *
 * The returned fragment is deep-merged into the body, so it can carry
 * BOTH `thinking` and `reasoning_effort` (and friends) in one shot.
 */
interface SupportsReasoningEffort
{
    /**
     * Build the body fragment that selects the requested effort tier.
     *
     * Accepted values are normalised by callers; implementations should
     * tolerate the canonical set: "off" (synonym: disabled / none /
     * false), "low" / "minimal" / "medium" / "mid" / "high" (treated
     * the same — single intermediate band), "max" (synonym: xhigh /
     * highest).
     *
     * Unknown values MUST return `[]` — we never want a misconfigured
     * effort to produce a malformed request.
     *
     * @return array<string, mixed>
     */
    public function reasoningEffortFragment(string $effort): array;
}
