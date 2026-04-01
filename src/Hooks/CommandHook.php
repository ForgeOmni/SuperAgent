<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use Symfony\Component\Process\Process;
use SuperAgent\Permissions\PermissionRuleParser;

class CommandHook implements HookInterface
{
    private bool $hasExecuted = false;
    
    public function __construct(
        private string $command,
        private string $shell = 'bash',
        private int $timeout = 30,
        private bool $async = false,
        private bool $asyncRewake = false,
        private bool $once = false,
        private ?string $condition = null,
        private ?string $statusMessage = null,
    ) {}
    
    public function execute(HookInput $input, ?int $timeout = null): HookResult
    {
        if ($this->once && $this->hasExecuted) {
            return HookResult::continue('Hook already executed (once=true)');
        }
        
        if (!$this->matchesCondition($input)) {
            return HookResult::continue('Hook condition not met');
        }
        
        try {
            $command = $this->interpolateVariables($this->command, $input);
            
            $process = match ($this->shell) {
                'powershell' => Process::fromShellCommandline(
                    "powershell -Command \"$command\"",
                    $input->cwd,
                ),
                default => Process::fromShellCommandline(
                    $command,
                    $input->cwd,
                ),
            };
            
            $process->setTimeout($timeout ?? $this->timeout);
            
            if ($this->async) {
                $process->start();
                
                if (!$this->asyncRewake) {
                    return HookResult::continue($this->statusMessage ?? 'Hook running in background');
                }
                
                // For async with rewake, we need to monitor the process
                // This would typically be handled by a separate process manager
                return HookResult::continue(
                    systemMessage: $this->statusMessage ?? 'Hook running with rewake enabled',
                    additionalContext: ['async_process_pid' => $process->getPid()],
                );
            }
            
            $process->run();
            
            if (!$process->isSuccessful()) {
                return HookResult::error(
                    "Hook command failed: " . $process->getErrorOutput(),
                );
            }
            
            $output = trim($process->getOutput());
            
            // Parse hook output if it's JSON
            if (str_starts_with($output, '{')) {
                $result = json_decode($output, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return new HookResult(
                        continue: $result['continue'] ?? true,
                        suppressOutput: $result['suppress_output'] ?? false,
                        stopReason: $result['stop_reason'] ?? null,
                        systemMessage: $result['system_message'] ?? null,
                        updatedInput: $result['updated_input'] ?? null,
                        additionalContext: $result['additional_context'] ?? null,
                        watchPaths: $result['watch_paths'] ?? null,
                    );
                }
            }
            
            // Exit code 2 means stop
            if ($process->getExitCode() === 2) {
                return HookResult::stop('Hook requested stop', $output ?: null);
            }
            
            $this->hasExecuted = true;
            
            return HookResult::continue($output ?: null);
        } catch (\Exception $e) {
            return HookResult::error("Hook execution error: " . $e->getMessage());
        }
    }
    
    public function getType(): HookType
    {
        return HookType::COMMAND;
    }
    
    public function matches(string $toolName = null, array $context = []): bool
    {
        return true; // Matching is handled by HookMatcher
    }
    
    public function isAsync(): bool
    {
        return $this->async;
    }
    
    public function isOnce(): bool
    {
        return $this->once;
    }
    
    public function getCondition(): ?string
    {
        return $this->condition;
    }
    
    private function matchesCondition(HookInput $input): bool
    {
        if ($this->condition === null) {
            return true;
        }
        
        // Parse the condition using permission rule syntax
        try {
            $parser = new PermissionRuleParser();
            $rule = $parser->parse($this->condition);
            
            $toolName = $input->additionalData['tool_name'] ?? null;
            $toolInput = $input->additionalData['tool_input'] ?? [];
            
            if ($toolName === null) {
                return false;
            }
            
            if ($rule->toolName !== $toolName) {
                return false;
            }
            
            if ($rule->ruleContent === null) {
                return true;
            }
            
            // Check if the rule content matches any tool input field
            $content = $this->extractContentFromInput($toolName, $toolInput);
            
            if (str_ends_with($rule->ruleContent, '*')) {
                $prefix = substr($rule->ruleContent, 0, -1);
                return str_starts_with($content, $prefix);
            }
            
            return $content === $rule->ruleContent;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function extractContentFromInput(string $toolName, array $input): string
    {
        return match ($toolName) {
            'Bash' => $input['command'] ?? '',
            'Read', 'Write', 'Edit' => $input['file_path'] ?? '',
            'WebFetch' => $input['url'] ?? '',
            default => '',
        };
    }
    
    private function interpolateVariables(string $command, HookInput $input): string
    {
        $variables = [
            '$SESSION_ID' => $input->sessionId,
            '$CWD' => $input->cwd,
            '$GIT_REPO_ROOT' => $input->gitRepoRoot ?? '',
            '$HOOK_EVENT' => $input->hookEvent->value,
            '$ARGUMENTS' => json_encode($input->additionalData),
        ];
        
        // Add specific variables from additional data
        foreach ($input->additionalData as $key => $value) {
            $varName = '$' . strtoupper($key);
            $variables[$varName] = is_array($value) ? json_encode($value) : (string)$value;
        }
        
        return strtr($command, $variables);
    }
}