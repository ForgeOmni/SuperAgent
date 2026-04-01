<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Team context for managing agent teams.
 */
class TeamContext
{
    private string $teamName;
    private string $leaderId;
    private array $members = [];
    private array $agentRegistry = [];
    private ?\DateTimeInterface $createdAt;
    
    public function __construct(
        string $teamName,
        string $leaderId,
    ) {
        $this->teamName = $teamName;
        $this->leaderId = $leaderId;
        $this->createdAt = new \DateTimeImmutable();
    }
    
    public function getTeamName(): string
    {
        return $this->teamName;
    }
    
    public function getLeaderId(): string
    {
        return $this->leaderId;
    }
    
    public function isLeader(string $agentId): bool
    {
        return $this->leaderId === $agentId;
    }
    
    public function addMember(TeamMember $member): void
    {
        $this->members[$member->agentId] = $member;
        
        // Register agent name mapping
        $this->agentRegistry[$member->name] = $member->agentId;
    }
    
    public function removeMember(string $agentId): void
    {
        if (isset($this->members[$agentId])) {
            $member = $this->members[$agentId];
            unset($this->agentRegistry[$member->name]);
            unset($this->members[$agentId]);
        }
    }
    
    public function getMember(string $agentId): ?TeamMember
    {
        return $this->members[$agentId] ?? null;
    }
    
    public function getMemberByName(string $name): ?TeamMember
    {
        $agentId = $this->agentRegistry[$name] ?? null;
        return $agentId ? $this->getMember($agentId) : null;
    }
    
    public function getMembers(): array
    {
        return array_values($this->members);
    }
    
    public function getMemberCount(): int
    {
        return count($this->members);
    }
    
    public function resolveAgentId(string $nameOrId): ?string
    {
        // Check if it's already an agent ID
        if (isset($this->members[$nameOrId])) {
            return $nameOrId;
        }
        
        // Try to resolve by name
        return $this->agentRegistry[$nameOrId] ?? null;
    }
    
    public function getActiveMembers(): array
    {
        return array_filter(
            $this->members,
            fn(TeamMember $m) => in_array($m->status, [
                AgentStatus::PENDING,
                AgentStatus::RUNNING,
                AgentStatus::PAUSED,
            ])
        );
    }
    
    public function toTeam(): Team
    {
        return new Team(
            name: $this->teamName,
            leaderId: $this->leaderId,
            members: $this->getMembers(),
            createdAt: $this->createdAt,
        );
    }
    
    /**
     * Save team state to file.
     */
    public function save(?string $basePath = null): void
    {
        $basePath = $basePath ?? sys_get_temp_dir() . '/superagent_teams';
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        
        $teamFile = $basePath . '/' . $this->teamName . '.json';
        $data = $this->toTeam()->toArray();
        
        file_put_contents($teamFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Load team state from file.
     */
    public static function load(string $teamName, ?string $basePath = null): ?self
    {
        $basePath = $basePath ?? sys_get_temp_dir() . '/superagent_teams';
        $teamFile = $basePath . '/' . $teamName . '.json';
        
        if (!file_exists($teamFile)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($teamFile), true);
        if (!$data) {
            return null;
        }
        
        $team = Team::fromArray($data);
        $context = new self($team->name, $team->leaderId);
        
        foreach ($team->members as $member) {
            $context->addMember($member);
        }
        
        $context->createdAt = $team->createdAt;
        
        return $context;
    }
}