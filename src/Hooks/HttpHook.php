<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use Illuminate\Support\Facades\Http;

class HttpHook implements HookInterface
{
    private bool $hasExecuted = false;
    
    public function __construct(
        private string $url,
        private array $headers = [],
        private array $allowedEnvVars = [],
        private int $timeout = 30,
        private bool $once = false,
        private ?string $condition = null,
        private ?string $statusMessage = null,
    ) {}
    
    public function execute(HookInput $input, ?int $timeout = null): HookResult
    {
        if ($this->once && $this->hasExecuted) {
            return HookResult::continue('Hook already executed (once=true)');
        }
        
        try {
            $url = $this->interpolateUrl($this->url, $input);
            $headers = $this->interpolateHeaders($this->headers, $input);
            $actualTimeout = $timeout ?? $this->timeout;
            
            $response = Http::timeout($actualTimeout)
                ->withHeaders($headers)
                ->post($url, $input->toArray());
            
            if (!$response->successful()) {
                return HookResult::error(
                    "HTTP hook failed: {$response->status()} - {$response->body()}",
                );
            }
            
            $data = $response->json();
            
            if ($data === null) {
                // Non-JSON response, treat as success
                $this->hasExecuted = true;
                return HookResult::continue($response->body());
            }
            
            $this->hasExecuted = true;
            
            return new HookResult(
                continue: $data['continue'] ?? true,
                suppressOutput: $data['suppress_output'] ?? false,
                stopReason: $data['stop_reason'] ?? null,
                systemMessage: $data['system_message'] ?? null,
                updatedInput: $data['updated_input'] ?? null,
                additionalContext: $data['additional_context'] ?? null,
                watchPaths: $data['watch_paths'] ?? null,
            );
        } catch (\Exception $e) {
            return HookResult::error("HTTP hook error: " . $e->getMessage());
        }
    }
    
    public function getType(): HookType
    {
        return HookType::HTTP;
    }
    
    public function matches(string $toolName = null, array $context = []): bool
    {
        return true;
    }
    
    public function isAsync(): bool
    {
        return false; // HTTP hooks are synchronous
    }
    
    public function isOnce(): bool
    {
        return $this->once;
    }
    
    public function getCondition(): ?string
    {
        return $this->condition;
    }
    
    private function interpolateUrl(string $url, HookInput $input): string
    {
        $variables = $this->getVariables($input);
        return strtr($url, $variables);
    }
    
    private function interpolateHeaders(array $headers, HookInput $input): array
    {
        $variables = $this->getVariables($input);
        
        $interpolated = [];
        foreach ($headers as $key => $value) {
            $interpolated[$key] = strtr($value, $variables);
        }
        
        return $interpolated;
    }
    
    private function getVariables(HookInput $input): array
    {
        $variables = [
            '$SESSION_ID' => $input->sessionId,
            '$CWD' => $input->cwd,
            '$GIT_REPO_ROOT' => $input->gitRepoRoot ?? '',
            '$HOOK_EVENT' => $input->hookEvent->value,
        ];
        
        // Add allowed environment variables
        foreach ($this->allowedEnvVars as $envVar) {
            $variables['$' . $envVar] = $_ENV[$envVar] ?? '';
        }
        
        // Add specific variables from additional data
        foreach ($input->additionalData as $key => $value) {
            $varName = '$' . strtoupper($key);
            $variables[$varName] = is_array($value) ? json_encode($value) : (string)$value;
        }
        
        return $variables;
    }
}