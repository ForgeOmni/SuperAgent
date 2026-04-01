<?php

declare(strict_types=1);

namespace SuperAgent\Agent\BuiltinAgents;

use SuperAgent\Agent\AgentDefinition;

class GeneralPurposeAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'general-purpose';
    }

    public function description(): string
    {
        return 'General-purpose agent for multi-step tasks';
    }

    public function systemPrompt(): ?string
    {
        return null;
    }

    public function allowedTools(): ?array
    {
        return null; // All tools allowed
    }
}
