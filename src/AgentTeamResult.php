<?php

namespace SuperAgent;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;

/**
 * Result from executing a team of agents in parallel or sequence.
 * Aggregates individual agent results and provides unified access to team outcomes.
 */
class AgentTeamResult
{
    /**
     * @param AgentResult[] $agentResults Individual results from each agent
     * @param array<string, mixed> $metadata Team execution metadata
     */
    public function __construct(
        public readonly array $agentResults = [],
        public readonly array $metadata = []
    ) {
    }

    /**
     * Get results grouped by agent name/ID
     * @return array<string, AgentResult>
     */
    public function getResultsByAgent(): array
    {
        $byAgent = [];
        foreach ($this->agentResults as $i => $result) {
            // Check if the agent key is numeric or named
            if (isset($this->metadata['agents'][$i])) {
                $agentName = $this->metadata['agents'][$i]['name'] ?? "agent-$i";
            } elseif (is_array($this->metadata['agents'])) {
                // Look for the first agent with a name key
                $keys = array_keys($this->metadata['agents']);
                $agentName = isset($keys[$i]) && is_string($keys[$i]) ? $keys[$i] : "agent-$i";
            } else {
                $agentName = "agent-$i";
            }
            $byAgent[$agentName] = $result;
        }
        return $byAgent;
    }

    /**
     * Get the combined text output from all agents
     */
    public function text(): string
    {
        $texts = [];
        foreach ($this->getResultsByAgent() as $agentName => $result) {
            $agentText = $result->text();
            if (!empty($agentText)) {
                $texts[] = "## $agentName\n\n$agentText";
            }
        }
        return implode("\n\n---\n\n", $texts);
    }

    /**
     * Get a summary of team execution
     */
    public function summary(): string
    {
        $agentCount = count($this->agentResults);
        $totalTurns = $this->totalTurns();
        $totalCost = $this->totalCostUsd();
        $usage = $this->totalUsage();
        
        $summary = "Team execution completed:\n";
        $summary .= "- Agents: $agentCount\n";
        $summary .= "- Total turns: $totalTurns\n";
        $summary .= "- Total tokens: " . ($usage->inputTokens + $usage->outputTokens) . "\n";
        $summary .= "- Total cost: $" . number_format($totalCost, 4);
        
        if (!empty($this->metadata['execution_time'])) {
            $summary .= "\n- Execution time: " . number_format($this->metadata['execution_time'], 2) . "s";
        }
        
        return $summary;
    }

    /**
     * Get individual agent summaries
     * @return array<string, array{turns: int, tokens: int, cost: float, status: string}>
     */
    public function agentSummaries(): array
    {
        $summaries = [];
        foreach ($this->getResultsByAgent() as $agentName => $result) {
            $usage = $result->totalUsage();
            $summaries[$agentName] = [
                'turns' => $result->turns(),
                'tokens' => $usage->inputTokens + $usage->outputTokens,
                'cost' => $result->totalCostUsd,
                'status' => $this->metadata['agents'][$agentName]['status'] ?? 'completed'
            ];
        }
        return $summaries;
    }

    /**
     * Get the total number of turns across all agents
     */
    public function totalTurns(): int
    {
        return array_sum(array_map(fn($r) => $r->turns(), $this->agentResults));
    }

    /**
     * Get aggregated token usage across all agents
     */
    public function totalUsage(): Usage
    {
        $totalInput = 0;
        $totalOutput = 0;
        
        foreach ($this->agentResults as $result) {
            $usage = $result->totalUsage();
            $totalInput += $usage->inputTokens;
            $totalOutput += $usage->outputTokens;
        }
        
        return new Usage($totalInput, $totalOutput);
    }

    /**
     * Get total cost across all agents
     */
    public function totalCostUsd(): float
    {
        return array_sum(array_map(fn($r) => $r->totalCostUsd, $this->agentResults));
    }

    /**
     * Get all messages from all agents (flattened)
     * @return Message[]
     */
    public function allMessages(): array
    {
        $messages = [];
        foreach ($this->agentResults as $result) {
            $messages = array_merge($messages, $result->messages);
        }
        return $messages;
    }

    /**
     * Get messages grouped by agent
     * @return array<string, Message[]>
     */
    public function messagesByAgent(): array
    {
        $byAgent = [];
        foreach ($this->getResultsByAgent() as $agentName => $result) {
            $byAgent[$agentName] = $result->messages;
        }
        return $byAgent;
    }

    /**
     * Check if all agents completed successfully
     */
    public function allSucceeded(): bool
    {
        foreach ($this->metadata['agents'] ?? [] as $agent) {
            if (($agent['status'] ?? 'completed') !== 'completed') {
                return false;
            }
        }
        return true;
    }

    /**
     * Get failed agents
     * @return array<string, array{reason?: string, error?: string}>
     */
    public function failedAgents(): array
    {
        $failed = [];
        foreach ($this->metadata['agents'] ?? [] as $name => $agent) {
            if (($agent['status'] ?? 'completed') === 'failed') {
                $failed[$name] = [
                    'reason' => $agent['reason'] ?? 'Unknown error',
                    'error' => $agent['error'] ?? null
                ];
            }
        }
        return $failed;
    }

    /**
     * Merge multiple team results into a single result
     */
    public static function merge(AgentTeamResult ...$results): self
    {
        $allAgentResults = [];
        $mergedMetadata = [
            'agents' => [],
            'merged_from' => count($results) . ' team results',
            'execution_time' => 0
        ];
        
        foreach ($results as $teamResult) {
            $allAgentResults = array_merge($allAgentResults, $teamResult->agentResults);
            
            // Merge agent metadata
            foreach ($teamResult->metadata['agents'] ?? [] as $name => $agent) {
                $mergedMetadata['agents'][$name] = $agent;
            }
            
            // Sum execution times
            $mergedMetadata['execution_time'] += $teamResult->metadata['execution_time'] ?? 0;
        }
        
        return new self($allAgentResults, $mergedMetadata);
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary(),
            'agents' => $this->agentSummaries(),
            'total_turns' => $this->totalTurns(),
            'total_usage' => [
                'input_tokens' => $this->totalUsage()->inputTokens,
                'output_tokens' => $this->totalUsage()->outputTokens,
            ],
            'total_cost_usd' => $this->totalCostUsd(),
            'all_succeeded' => $this->allSucceeded(),
            'failed_agents' => $this->failedAgents(),
            'metadata' => $this->metadata
        ];
    }

    /**
     * Create from a single AgentResult (for backwards compatibility)
     */
    public static function fromSingle(AgentResult $result, string $agentName = 'main'): self
    {
        return new self(
            [$result],
            [
                'agents' => [
                    0 => ['name' => $agentName, 'status' => 'completed']
                ],
                'single_agent' => true
            ]
        );
    }
}