<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Messages\UserMessage;

class AgentHook implements HookInterface
{
    private bool $hasExecuted = false;

    public function __construct(
        private string $prompt,
        private ?LLMProvider $provider = null,
        private ?string $model = null,
        private int $timeout = 60,
        private bool $blockOnFailure = true,
        private bool $once = false,
        private ?string $matcher = null,
    ) {}

    public function execute(HookInput $input, ?int $timeout = null): HookResult
    {
        if ($this->once && $this->hasExecuted) {
            return HookResult::continue('Hook already executed (once=true)');
        }

        if (!$this->provider) {
            error_log('[SuperAgent] AgentHook: no provider configured, skipping');
            return HookResult::continue();
        }

        try {
            // Build extended context for the agent hook
            $contextData = [
                'event' => $input->hookEvent->value,
                'session_id' => $input->sessionId,
                'cwd' => $input->cwd,
                'git_repo_root' => $input->gitRepoRoot,
                'data' => $input->additionalData,
                'timestamp' => date('c'),
            ];

            $arguments = json_encode($input->additionalData, JSON_UNESCAPED_UNICODE);
            $contextJson = json_encode($contextData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            $resolvedPrompt = str_replace('$ARGUMENTS', $arguments, $this->prompt);
            $resolvedPrompt = $this->interpolateVariables($resolvedPrompt, $input);

            // The agent hook includes full context in its prompt for deeper validation
            $fullPrompt = <<<PROMPT
            ## Context
            {$contextJson}

            ## Validation Task
            {$resolvedPrompt}
            PROMPT;

            $originalModel = null;
            if ($this->model !== null) {
                $originalModel = $this->provider->getModel();
                $this->provider->setModel($this->model);
            }

            try {
                $messages = [
                    new UserMessage($fullPrompt),
                ];

                $systemPrompt = <<<SYSTEM
                You are an agent-level validation hook with deep analysis capabilities.
                Analyze the provided context thoroughly before making a decision.
                You MUST respond with ONLY a JSON object in one of these formats:
                {"ok": true}
                {"ok": false, "reason": "detailed explanation of why validation failed"}
                No other text or formatting.
                SYSTEM;

                $responseText = '';
                $generator = $this->provider->chat(
                    $messages,
                    [],
                    $systemPrompt,
                    ['max_tokens' => 1024],
                );

                foreach ($generator as $chunk) {
                    $responseText .= $chunk->text();
                }
            } finally {
                if ($originalModel !== null) {
                    $this->provider->setModel($originalModel);
                }
            }

            $result = $this->parseResponse($responseText);

            $this->hasExecuted = true;

            return $result;
        } catch (\Exception $e) {
            error_log('[SuperAgent] AgentHook error: ' . $e->getMessage());

            if ($this->blockOnFailure) {
                return HookResult::stop('Agent hook failed: ' . $e->getMessage());
            }

            return HookResult::continue();
        }
    }

    public function getType(): HookType
    {
        return HookType::AGENT;
    }

    public function matches(string $toolName = null, array $context = []): bool
    {
        if ($this->matcher === null) {
            return true;
        }

        if ($toolName === null) {
            return false;
        }

        return fnmatch($this->matcher, $toolName);
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function isOnce(): bool
    {
        return $this->once;
    }

    public function getCondition(): ?string
    {
        return $this->matcher;
    }

    /**
     * Set the LLM provider for this hook.
     */
    public function setProvider(LLMProvider $provider): void
    {
        $this->provider = $provider;
    }

    private function parseResponse(string $responseText): HookResult
    {
        $responseText = trim($responseText);

        if (preg_match('/\{[^}]+\}/', $responseText, $matches)) {
            $data = json_decode($matches[0], true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['ok'])) {
                $ok = filter_var($data['ok'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($ok === true) {
                    return HookResult::continue();
                }

                $reason = $data['reason'] ?? 'Agent hook validation failed';
                return HookResult::stop($reason);
            }
        }

        $lower = strtolower($responseText);
        if (in_array($lower, ['yes', 'true', 'ok', 'approved', 'pass'], true)) {
            return HookResult::continue();
        }

        if (in_array($lower, ['no', 'false', 'denied', 'rejected', 'fail'], true)) {
            return HookResult::stop('Agent hook rejected: ' . $responseText);
        }

        if ($this->blockOnFailure) {
            return HookResult::stop('Agent hook returned unparseable response: ' . substr($responseText, 0, 200));
        }

        return HookResult::continue();
    }

    private function interpolateVariables(string $text, HookInput $input): string
    {
        $variables = [
            '$SESSION_ID' => $input->sessionId,
            '$CWD' => $input->cwd,
            '$GIT_REPO_ROOT' => $input->gitRepoRoot ?? '',
            '$HOOK_EVENT' => $input->hookEvent->value,
        ];

        foreach ($input->additionalData as $key => $value) {
            $varName = '$' . strtoupper($key);
            if (!is_array($value)) {
                $variables[$varName] = (string) $value;
            }
        }

        return strtr($text, $variables);
    }
}
