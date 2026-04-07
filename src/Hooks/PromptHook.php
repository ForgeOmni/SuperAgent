<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\UserMessage;

class PromptHook implements HookInterface
{
    private bool $hasExecuted = false;

    public function __construct(
        private string $prompt,
        private ?LLMProvider $provider = null,
        private ?string $model = null,
        private int $timeout = 30,
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
            error_log('[SuperAgent] PromptHook: no provider configured, skipping');
            return HookResult::continue();
        }

        try {
            // Inject $ARGUMENTS into prompt template
            $arguments = json_encode($input->additionalData, JSON_UNESCAPED_UNICODE);
            $resolvedPrompt = str_replace('$ARGUMENTS', $arguments, $this->prompt);

            // Also support individual variable interpolation
            $resolvedPrompt = $this->interpolateVariables($resolvedPrompt, $input);

            // Temporarily set model if specified
            $originalModel = null;
            if ($this->model !== null) {
                $originalModel = $this->provider->getModel();
                $this->provider->setModel($this->model);
            }

            try {
                $messages = [
                    new UserMessage($resolvedPrompt),
                ];

                $responseText = '';
                $generator = $this->provider->chat(
                    $messages,
                    [],
                    'You are a validation hook. Respond ONLY with a JSON object: {"ok": true} or {"ok": false, "reason": "explanation"}. No other text.',
                    ['max_tokens' => 256],
                );

                foreach ($generator as $chunk) {
                    $responseText .= $chunk->text();
                }
            } finally {
                // Restore original model
                if ($originalModel !== null) {
                    $this->provider->setModel($originalModel);
                }
            }

            // Parse the LLM response
            $result = $this->parseResponse($responseText);

            $this->hasExecuted = true;

            return $result;
        } catch (\Exception $e) {
            error_log('[SuperAgent] PromptHook error: ' . $e->getMessage());

            if ($this->blockOnFailure) {
                return HookResult::stop('Prompt hook failed: ' . $e->getMessage());
            }

            return HookResult::continue();
        }
    }

    public function getType(): HookType
    {
        return HookType::PROMPT;
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

    /**
     * Parse the LLM response into a HookResult.
     */
    private function parseResponse(string $responseText): HookResult
    {
        $responseText = trim($responseText);

        // Try to extract JSON from the response (it may have surrounding text)
        if (preg_match('/\{[^}]+\}/', $responseText, $matches)) {
            $data = json_decode($matches[0], true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['ok'])) {
                $ok = filter_var($data['ok'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($ok === true) {
                    return HookResult::continue();
                }

                $reason = $data['reason'] ?? 'Prompt hook validation failed';
                return HookResult::stop($reason);
            }
        }

        // Fallback: check for simple yes/no/true/false
        $lower = strtolower($responseText);
        if (in_array($lower, ['yes', 'true', 'ok', 'approved', 'pass'], true)) {
            return HookResult::continue();
        }

        if (in_array($lower, ['no', 'false', 'denied', 'rejected', 'fail'], true)) {
            return HookResult::stop('Prompt hook rejected: ' . $responseText);
        }

        // Could not parse response
        if ($this->blockOnFailure) {
            return HookResult::stop('Prompt hook returned unparseable response: ' . substr($responseText, 0, 200));
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
