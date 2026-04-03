<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Tracks individual agent progress similar to Claude Code's ProgressTracker.
 */
class AgentProgressTracker
{
    private string $agentId;
    private string $agentName;
    private int $toolUseCount = 0;
    private int $latestInputTokens = 0;
    private int $cumulativeOutputTokens = 0;
    private array $recentActivities = [];
    private ?string $currentActivity = null;
    private ?\DateTimeInterface $startedAt;
    private ?\DateTimeInterface $completedAt = null;
    
    private const MAX_RECENT_ACTIVITIES = 5;
    
    public function __construct(string $agentId, string $agentName)
    {
        $this->agentId = $agentId;
        $this->agentName = $agentName;
        $this->startedAt = new \DateTimeImmutable();
    }
    
    /**
     * Update progress from a message/response.
     */
    public function updateFromResponse(array $usage, ?array $toolUses = null): void
    {
        // Keep latest input (cumulative in API), sum outputs
        $this->latestInputTokens = ($usage['input_tokens'] ?? 0) 
            + ($usage['cache_creation_input_tokens'] ?? 0)
            + ($usage['cache_read_input_tokens'] ?? 0);
            
        $this->cumulativeOutputTokens += $usage['output_tokens'] ?? 0;
        
        if ($toolUses) {
            foreach ($toolUses as $toolUse) {
                $this->addToolActivity($toolUse);
            }
        }
    }
    
    /**
     * Add a tool activity.
     */
    public function addToolActivity(array $toolUse): void
    {
        $this->toolUseCount++;
        
        $activity = [
            'toolName' => $toolUse['name'] ?? 'unknown',
            'input' => $toolUse['input'] ?? [],
            'timestamp' => new \DateTimeImmutable(),
            'description' => $this->generateActivityDescription($toolUse),
        ];
        
        $this->recentActivities[] = $activity;
        $this->currentActivity = $activity['description'];
        
        // Keep only recent activities
        while (count($this->recentActivities) > self::MAX_RECENT_ACTIVITIES) {
            array_shift($this->recentActivities);
        }
    }
    
    /**
     * Generate human-readable activity description.
     */
    private function generateActivityDescription(array $toolUse): string
    {
        $toolName = $toolUse['name'] ?? 'unknown';
        $input = $toolUse['input'] ?? [];
        
        // Common tool descriptions
        return match($toolName) {
            'Read' => isset($input['file_path']) 
                ? "Reading {$input['file_path']}"
                : "Reading file",
            'Write' => isset($input['file_path'])
                ? "Writing {$input['file_path']}"
                : "Writing file",
            'Edit' => isset($input['file_path'])
                ? "Editing {$input['file_path']}"
                : "Editing file",
            'Bash' => isset($input['command'])
                ? "Running: " . substr($input['command'], 0, 50)
                : "Running command",
            'Grep' => isset($input['pattern'])
                ? "Searching for: {$input['pattern']}"
                : "Searching",
            'Glob' => isset($input['pattern'])
                ? "Finding files: {$input['pattern']}"
                : "Finding files",
            'AgentTool', 'Task' => isset($input['description'])
                ? "Spawning agent: {$input['description']}"
                : "Spawning agent",
            default => "Using $toolName"
        };
    }
    
    /**
     * Get total token count.
     */
    public function getTotalTokens(): int
    {
        return $this->latestInputTokens + $this->cumulativeOutputTokens;
    }
    
    /**
     * Get progress summary.
     */
    public function getProgress(): array
    {
        return [
            'agentId' => $this->agentId,
            'agentName' => $this->agentName,
            'toolUseCount' => $this->toolUseCount,
            'tokenCount' => $this->getTotalTokens(),
            'inputTokens' => $this->latestInputTokens,
            'outputTokens' => $this->cumulativeOutputTokens,
            'currentActivity' => $this->currentActivity,
            'recentActivities' => $this->recentActivities,
            'startedAt' => $this->startedAt,
            'completedAt' => $this->completedAt,
            'durationMs' => $this->getDurationMs(),
        ];
    }
    
    /**
     * Mark as completed.
     */
    public function complete(): void
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->currentActivity = null;
        $this->status = 'completed';
    }
    
    /**
     * Set the status directly.
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
        if ($status === 'completed') {
            $this->completedAt = new \DateTimeImmutable();
        }
    }
    
    /**
     * Get the current status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    
    /**
     * Get duration in milliseconds.
     */
    public function getDurationMs(): ?int
    {
        if (!$this->startedAt) {
            return null;
        }
        
        $endTime = $this->completedAt ?? new \DateTimeImmutable();
        
        // Use timestamps for more accurate calculation
        $startTimestamp = $this->startedAt->getTimestamp() * 1000 + 
            (int)($this->startedAt->format('u') / 1000);
        $endTimestamp = $endTime->getTimestamp() * 1000 + 
            (int)($endTime->format('u') / 1000);
        
        $duration = $endTimestamp - $startTimestamp;
        
        // Ensure at least 1ms for completed tasks
        if ($this->completedAt !== null && $duration === 0) {
            return 1;
        }
        
        return $duration;
    }
    
    /**
     * Set current activity description.
     */
    public function setCurrentActivity(?string $activity): void
    {
        $this->currentActivity = $activity;
    }
    
    public function getAgentId(): string
    {
        return $this->agentId;
    }
    
    public function getAgentName(): string
    {
        return $this->agentName;
    }
}