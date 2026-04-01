<?php

declare(strict_types=1);

namespace SuperAgent\Agent\BuiltinAgents;

use SuperAgent\Agent\AgentDefinition;

class CodeWriterAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'code-writer';
    }

    public function description(): string
    {
        return 'Code-writing specialist for implementing clean, efficient, and well-tested code';
    }

    public function systemPrompt(): ?string
    {
        return 'You are a code-writing specialist. Focus on implementing clean, efficient, and well-tested code.';
    }

    public function allowedTools(): ?array
    {
        return ['read_file', 'write_file', 'edit_file', 'bash', 'grep', 'glob'];
    }

    public function category(): string
    {
        return 'development';
    }
}
