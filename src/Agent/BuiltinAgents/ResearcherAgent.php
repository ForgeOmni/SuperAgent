<?php

declare(strict_types=1);

namespace SuperAgent\Agent\BuiltinAgents;

use SuperAgent\Agent\AgentDefinition;

class ResearcherAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'researcher';
    }

    public function description(): string
    {
        return 'Research specialist for gathering information, analyzing data, and providing findings';
    }

    public function systemPrompt(): ?string
    {
        return 'You are a research specialist. Focus on gathering information, analyzing data, and providing comprehensive findings.';
    }

    public function allowedTools(): ?array
    {
        return ['read_file', 'bash', 'grep', 'glob', 'web_search', 'web_fetch'];
    }

    public function category(): string
    {
        return 'research';
    }
}
