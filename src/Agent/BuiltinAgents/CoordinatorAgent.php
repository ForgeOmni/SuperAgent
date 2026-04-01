<?php

declare(strict_types=1);

namespace SuperAgent\Agent\BuiltinAgents;

use SuperAgent\Agent\AgentDefinition;
use SuperAgent\Coordinator\CoordinatorMode;

/**
 * Coordinator agent — pure synthesis and delegation.
 *
 * Only has access to Agent, SendMessage, and TaskStop tools.
 * Never executes work directly; spawns workers for all tasks.
 */
class CoordinatorAgent extends AgentDefinition
{
    public function name(): string
    {
        return 'coordinator';
    }

    public function description(): string
    {
        return 'Orchestrator that delegates work to worker agents (no direct execution)';
    }

    public function systemPrompt(): ?string
    {
        $coordinator = new CoordinatorMode(true);
        return $coordinator->getSystemPrompt();
    }

    public function allowedTools(): ?array
    {
        return CoordinatorMode::COORDINATOR_TOOLS;
    }

    public function disallowedTools(): ?array
    {
        return null;
    }

    public function readOnly(): bool
    {
        return true; // Coordinator never writes files directly
    }

    public function category(): string
    {
        return 'orchestration';
    }
}
