<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;

interface EnhancerInterface
{
    /**
     * Enhance the request before it is sent to the LLM provider.
     *
     * All parameters are passed by reference so enhancers can modify them in-place.
     */
    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void;

    /**
     * Enhance the response after it is returned from the LLM provider.
     */
    public function enhanceResponse(AssistantMessage $message): AssistantMessage;
}
