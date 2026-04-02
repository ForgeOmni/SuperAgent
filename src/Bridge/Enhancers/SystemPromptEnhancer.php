<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Prompt\SystemPromptBuilder;

/**
 * Injects CC's optimized system prompt sections into the request.
 *
 * Prepends CC's carefully crafted instructions (task philosophy, tool usage
 * guidelines, output efficiency, security guardrails) to the client's
 * existing system prompt, giving non-Anthropic models better guidance.
 */
class SystemPromptEnhancer implements EnhancerInterface
{
    private ?string $cachedPrefix = null;

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        $prefix = $this->buildPrefix($tools);

        if ($prefix === '') {
            return;
        }

        if ($systemPrompt === null || $systemPrompt === '') {
            $systemPrompt = $prefix;
        } else {
            // Prepend CC instructions, then the client's original prompt
            $systemPrompt = $prefix . "\n\n# Client Instructions\n\n" . $systemPrompt;
        }
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        return $message; // No response-side enhancement
    }

    /**
     * Build the CC system prompt prefix using SystemPromptBuilder.
     *
     * Extracts only the relevant sections (not the full CC prompt, which
     * includes CC-specific tool references). Focuses on universal guidance
     * that benefits any model.
     */
    private function buildPrefix(array $tools): string
    {
        if ($this->cachedPrefix !== null) {
            return $this->cachedPrefix;
        }

        $toolNames = array_map(fn ($t) => is_object($t) && method_exists($t, 'name') ? $t->name() : '', $tools);

        $builder = SystemPromptBuilder::create()
            ->withTools($toolNames);

        // Strip the cache boundary marker since it's not needed for non-Anthropic providers
        $prompt = $builder->build();
        $prompt = str_replace(SystemPromptBuilder::CACHE_BOUNDARY, '', $prompt);

        // Add a custom bridge-mode prefix if configured
        $customPrefix = function_exists('config') ? (string) config('superagent.bridge.system_prompt_prefix', '') : '';
        if ($customPrefix !== '') {
            $prompt = $customPrefix . "\n\n" . $prompt;
        }

        $this->cachedPrefix = trim($prompt);

        return $this->cachedPrefix;
    }
}
