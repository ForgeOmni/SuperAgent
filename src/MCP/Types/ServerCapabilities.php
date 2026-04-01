<?php

namespace SuperAgent\MCP\Types;

class ServerCapabilities
{
    private array $tools;
    private array $resources;
    private array $prompts;
    private array $logging;

    public function __construct(array $capabilities)
    {
        $this->tools = $capabilities['tools'] ?? [];
        $this->resources = $capabilities['resources'] ?? [];
        $this->prompts = $capabilities['prompts'] ?? [];
        $this->logging = $capabilities['logging'] ?? [];
    }

    public function hasTools(): bool
    {
        return !empty($this->tools);
    }

    public function hasResources(): bool
    {
        return !empty($this->resources);
    }

    public function hasPrompts(): bool
    {
        return !empty($this->prompts);
    }

    public function hasLogging(): bool
    {
        return !empty($this->logging);
    }

    public function canCallTools(): bool
    {
        return in_array('call', $this->tools);
    }

    public function canReadResources(): bool
    {
        return in_array('read', $this->resources);
    }

    public function canListResources(): bool
    {
        return in_array('list', $this->resources);
    }

    public function canGetPrompts(): bool
    {
        return in_array('get', $this->prompts);
    }

    public function canListPrompts(): bool
    {
        return in_array('list', $this->prompts);
    }

    public function toArray(): array
    {
        return [
            'tools' => $this->tools,
            'resources' => $this->resources,
            'prompts' => $this->prompts,
            'logging' => $this->logging,
        ];
    }
}