<?php

declare(strict_types=1);

namespace SuperAgent\Agent\BuiltinAgents;

use SuperAgent\Agent\AgentDefinition;

class ReviewerAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'reviewer';
    }

    public function description(): string
    {
        return 'Code review specialist for identifying issues and ensuring code quality';
    }

    public function systemPrompt(): ?string
    {
        return 'You are a code review specialist. Focus on identifying issues, suggesting improvements, and ensuring code quality.';
    }

    public function allowedTools(): ?array
    {
        return ['read_file', 'grep', 'glob', 'bash'];
    }

    public function category(): string
    {
        return 'development';
    }
}
