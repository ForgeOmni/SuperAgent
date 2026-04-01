<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

/**
 * Defines an agent type that can be spawned by AgentTool.
 */
abstract class AgentDefinition
{
    /**
     * Unique name for this agent type (used as subagent_type).
     */
    abstract public function name(): string;

    /**
     * Human-readable description.
     */
    abstract public function description(): string;

    /**
     * System prompt for this agent type.
     */
    abstract public function systemPrompt(): ?string;

    /**
     * Allowed tools for this agent type. Return null to allow all tools.
     */
    public function allowedTools(): ?array
    {
        return null;
    }

    /**
     * Default model override. Return null to use the default model.
     */
    public function model(): ?string
    {
        return null;
    }

    /**
     * Category for grouping agent types.
     */
    public function category(): string
    {
        return 'general';
    }
}
