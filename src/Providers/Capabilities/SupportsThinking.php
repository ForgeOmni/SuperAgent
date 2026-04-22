<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider supports an explicit "thinking" / reasoning budget — a separate
 * phase of token generation that is metered and returned distinct from the
 * final response (Anthropic extended thinking, Qwen3 `enable_thinking`,
 * GLM `thinking: {type: enabled}`, Kimi thinking-tuned model variants).
 *
 * Providers return a request-body fragment that the caller merges into the
 * outbound chat request — the interface is deliberately declarative so a
 * single `FeatureAdapter` can orchestrate the mapping without knowing
 * provider-specific field names.
 */
interface SupportsThinking
{
    /**
     * Build the provider-specific body fragment that enables thinking mode
     * with the requested budget (in tokens). The returned array is merged
     * into the outgoing chat request by the caller.
     *
     * Implementations SHOULD clamp the budget to whatever range the upstream
     * API accepts rather than raise — callers pass budgets advisorially.
     *
     * @return array<string, mixed>
     */
    public function thinkingRequestFragment(int $budgetTokens): array;
}
