<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

use Illuminate\Console\Command;
use SuperAgent\LLM\ProviderInterface;

class ConsolePermissionCallback implements PermissionCallbackInterface
{
    public function __construct(
        private Command $command,
        private ?ProviderInterface $autoClassifierProvider = null,
    ) {}
    
    public function askUserPermission(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): PermissionBehavior {
        $this->command->warn("🔒 Permission required for {$toolName}");
        
        if ($decision->message) {
            $this->command->info("Reason: {$decision->message}");
        }
        
        if ($decision->decisionReason?->detail) {
            $this->command->line("Detail: {$decision->decisionReason->detail}");
        }
        
        $this->displayInput($toolName, $input);
        
        if (!empty($decision->suggestions)) {
            $this->displaySuggestions($decision->suggestions);
        }
        
        $response = $this->command->choice(
            'Grant permission?',
            [
                'allow' => '✅ Allow',
                'deny' => '❌ Deny',
                'allow_all' => '✅ Allow all ' . $toolName,
                'deny_all' => '❌ Deny all ' . $toolName,
                'bypass' => '⚠️ Enter bypass mode',
            ],
            'allow',
        );
        
        return match ($response) {
            'allow' => PermissionBehavior::ALLOW,
            'deny' => PermissionBehavior::DENY,
            'allow_all' => $this->handleAllowAll($toolName),
            'deny_all' => $this->handleDenyAll($toolName),
            'bypass' => $this->handleBypassMode(),
            default => PermissionBehavior::DENY,
        };
    }
    
    public function runAutoClassifier(string $prompt): bool
    {
        if ($this->autoClassifierProvider === null) {
            return false;
        }
        
        $systemPrompt = <<<'PROMPT'
You are a security classifier for an AI agent. Your job is to determine if an action is safe.
Respond with only "ALLOW" or "DENY" based on the security risk.

Consider ALLOWING:
- Read-only operations
- Safe file edits in project directories
- Common development commands (git status, npm install, etc.)
- Network requests to known APIs

Consider DENYING:
- System modifications
- Deleting files
- Accessing sensitive directories (.ssh, .env, etc.)
- Running unknown scripts
- Privilege escalation
PROMPT;
        
        try {
            $response = $this->autoClassifierProvider->generateResponse(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
                options: ['max_tokens' => 10],
            );
            
            $decision = trim(strtoupper($response->content));
            
            return $decision === 'ALLOW';
        } catch (\Exception $e) {
            $this->command->error("Auto-classifier failed: {$e->getMessage()}");
            return false;
        }
    }
    
    public function onPermissionGranted(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): void {
        $this->command->info("✅ Permission granted for {$toolName}");
    }
    
    public function onPermissionDenied(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): void {
        $this->command->warn("❌ Permission denied for {$toolName}");
    }
    
    public function selectPermissionUpdate(array $suggestions): ?PermissionUpdate
    {
        if (empty($suggestions)) {
            return null;
        }
        
        $choices = ['none' => 'None - Just this once'];
        
        foreach ($suggestions as $index => $suggestion) {
            $choices["s{$index}"] = $suggestion->label;
        }
        
        $selected = $this->command->choice(
            'Would you like to update permissions?',
            $choices,
            'none',
        );
        
        if ($selected === 'none') {
            return null;
        }
        
        $index = (int) substr($selected, 1);
        
        return $suggestions[$index] ?? null;
    }
    
    private function displayInput(string $toolName, array $input): void
    {
        $this->command->line('Input:');
        
        foreach ($input as $key => $value) {
            if (is_string($value) && strlen($value) > 100) {
                $value = substr($value, 0, 100) . '...';
            }
            
            $this->command->line("  {$key}: " . json_encode($value));
        }
    }
    
    private function displaySuggestions(array $suggestions): void
    {
        if (empty($suggestions)) {
            return;
        }
        
        $this->command->line('');
        $this->command->line('Quick actions:');
        
        foreach ($suggestions as $index => $suggestion) {
            $this->command->line("  [{$index}] {$suggestion->label}");
        }
    }
    
    private function handleAllowAll(string $toolName): PermissionBehavior
    {
        $this->command->info("Adding allow rule for all {$toolName} actions");
        return PermissionBehavior::ALLOW;
    }
    
    private function handleDenyAll(string $toolName): PermissionBehavior
    {
        $this->command->warn("Adding deny rule for all {$toolName} actions");
        return PermissionBehavior::DENY;
    }
    
    private function handleBypassMode(): PermissionBehavior
    {
        $this->command->warn('⚠️ Entering bypass mode - all permissions will be granted automatically!');
        return PermissionBehavior::ALLOW;
    }
}