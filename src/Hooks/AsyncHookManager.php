<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use Symfony\Component\Process\Process;

class AsyncHookManager
{
    /**
     * @var array<string, AsyncHookProcess>
     */
    private array $runningHooks = [];
    
    /**
     * Start an async hook execution
     */
    public function startAsyncHook(HookInterface $hook, HookInput $input): string
    {
        $hookId = uniqid('async_hook_', true);
        
        if ($hook->getType() === HookType::COMMAND && $hook instanceof CommandHook) {
            // For command hooks, we can run them as background processes
            $process = $this->createProcessForHook($hook, $input);
            $process->start();
            
            $this->runningHooks[$hookId] = new AsyncHookProcess(
                id: $hookId,
                hook: $hook,
                process: $process,
                input: $input,
                startTime: microtime(true),
            );
            
            return $hookId;
        }
        
        // For other hook types, we'd need to implement different async strategies
        // For now, we'll execute them synchronously in a background process
        throw new \RuntimeException("Async execution not implemented for hook type: " . $hook->getType()->value);
    }
    
    /**
     * Check the status of an async hook
     */
    public function checkStatus(string $hookId): ?AsyncHookStatus
    {
        if (!isset($this->runningHooks[$hookId])) {
            return null;
        }
        
        $asyncHook = $this->runningHooks[$hookId];
        $process = $asyncHook->process;
        
        if (!$process->isRunning()) {
            // Process has finished
            $result = $this->processCompleted($asyncHook);
            unset($this->runningHooks[$hookId]);
            
            return new AsyncHookStatus(
                id: $hookId,
                running: false,
                exitCode: $process->getExitCode(),
                output: $process->getOutput(),
                errorOutput: $process->getErrorOutput(),
                result: $result,
                duration: microtime(true) - $asyncHook->startTime,
            );
        }
        
        return new AsyncHookStatus(
            id: $hookId,
            running: true,
            exitCode: null,
            output: $process->getIncrementalOutput(),
            errorOutput: $process->getIncrementalErrorOutput(),
            result: null,
            duration: microtime(true) - $asyncHook->startTime,
        );
    }
    
    /**
     * Wait for an async hook to complete
     */
    public function wait(string $hookId, ?int $timeout = null): ?HookResult
    {
        if (!isset($this->runningHooks[$hookId])) {
            return null;
        }
        
        $asyncHook = $this->runningHooks[$hookId];
        $process = $asyncHook->process;
        
        $process->wait(function ($type, $buffer) {
            // Could emit events or log output here
        });
        
        $result = $this->processCompleted($asyncHook);
        unset($this->runningHooks[$hookId]);
        
        return $result;
    }
    
    /**
     * Stop an async hook
     */
    public function stop(string $hookId): bool
    {
        if (!isset($this->runningHooks[$hookId])) {
            return false;
        }
        
        $asyncHook = $this->runningHooks[$hookId];
        $asyncHook->process->stop(5); // 5 second timeout
        
        unset($this->runningHooks[$hookId]);
        
        return true;
    }
    
    /**
     * Stop all running async hooks
     */
    public function stopAll(): void
    {
        foreach ($this->runningHooks as $hookId => $asyncHook) {
            $asyncHook->process->stop(5);
        }
        
        $this->runningHooks = [];
    }
    
    /**
     * Get the count of running hooks
     */
    public function getRunningCount(): int
    {
        // Clean up finished processes
        $this->cleanupFinished();
        
        return count($this->runningHooks);
    }
    
    /**
     * Get all running hook IDs
     */
    public function getRunningHookIds(): array
    {
        $this->cleanupFinished();
        
        return array_keys($this->runningHooks);
    }
    
    /**
     * Check for hooks that need rewaking
     */
    public function checkForRewake(): array
    {
        $rewakeHooks = [];
        
        foreach ($this->runningHooks as $hookId => $asyncHook) {
            if (!$asyncHook->process->isRunning()) {
                $exitCode = $asyncHook->process->getExitCode();
                
                // Exit code 2 means rewake
                if ($exitCode === 2 && $asyncHook->hook instanceof CommandHook) {
                    $rewakeHooks[] = [
                        'hook_id' => $hookId,
                        'input' => $asyncHook->input,
                        'output' => $asyncHook->process->getOutput(),
                    ];
                }
                
                unset($this->runningHooks[$hookId]);
            }
        }
        
        return $rewakeHooks;
    }
    
    private function createProcessForHook(CommandHook $hook, HookInput $input): Process
    {
        // We need to serialize the hook execution into a command
        // This is a simplified version - in production you'd want more robust serialization
        $scriptContent = $this->generateHookScript($hook, $input);
        $scriptFile = tempnam(sys_get_temp_dir(), 'hook_');
        file_put_contents($scriptFile, $scriptContent);
        chmod($scriptFile, 0755);
        
        $process = new Process([$scriptFile], $input->cwd);
        $process->setTimeout(null); // No timeout for async processes
        
        return $process;
    }
    
    private function generateHookScript(CommandHook $hook, HookInput $input): string
    {
        // This is a simplified script generator
        // In production, you'd want to properly escape and handle edge cases
        $variables = [
            'SESSION_ID' => $input->sessionId,
            'CWD' => $input->cwd,
            'GIT_REPO_ROOT' => $input->gitRepoRoot ?? '',
            'HOOK_EVENT' => $input->hookEvent->value,
            'ARGUMENTS' => json_encode($input->additionalData),
        ];
        
        $exports = [];
        foreach ($variables as $key => $value) {
            $exports[] = "export {$key}=" . escapeshellarg($value);
        }
        
        $script = "#!/bin/bash\n";
        $script .= implode("\n", $exports) . "\n";
        $script .= "cd " . escapeshellarg($input->cwd) . "\n";
        
        // Add the actual command
        $refl = new \ReflectionClass($hook);
        $commandProp = $refl->getProperty('command');
        $commandProp->setAccessible(true);
        $command = $commandProp->getValue($hook);
        
        $script .= $command . "\n";
        
        return $script;
    }
    
    private function processCompleted(AsyncHookProcess $asyncHook): HookResult
    {
        $process = $asyncHook->process;
        $exitCode = $process->getExitCode();
        $output = trim($process->getOutput());
        
        if (!$process->isSuccessful() && $exitCode !== 2) {
            return HookResult::error(
                "Async hook failed: " . $process->getErrorOutput(),
            );
        }
        
        // Try to parse JSON output
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
        if ($exitCode === 2) {
            return HookResult::stop('Async hook requested stop', $output ?: null);
        }
        
        return HookResult::continue($output ?: null);
    }
    
    private function cleanupFinished(): void
    {
        foreach ($this->runningHooks as $hookId => $asyncHook) {
            if (!$asyncHook->process->isRunning()) {
                unset($this->runningHooks[$hookId]);
            }
        }
    }
}

class AsyncHookProcess
{
    public function __construct(
        public readonly string $id,
        public readonly HookInterface $hook,
        public readonly Process $process,
        public readonly HookInput $input,
        public readonly float $startTime,
    ) {}
}

class AsyncHookStatus
{
    public function __construct(
        public readonly string $id,
        public readonly bool $running,
        public readonly ?int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly ?HookResult $result,
        public readonly float $duration,
    ) {}
}