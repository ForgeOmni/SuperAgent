<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Permissions\BashSecurityValidator;

/**
 * Intercepts tool calls in the LLM response and validates bash/shell
 * commands through CC's 23-point security checker.
 *
 * If a dangerous command is detected, the tool_use block is replaced
 * with a text block warning the client, preventing execution.
 */
class BashSecurityEnhancer implements EnhancerInterface
{
    private BashSecurityValidator $validator;

    /** Tool names that contain shell commands to validate */
    private array $shellToolNames = [
        'bash',
        'shell',
        'terminal',
        'execute',
        'run_command',
        'Bash',
    ];

    public function __construct()
    {
        $this->validator = new BashSecurityValidator();
    }

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        // No request-side enhancement — validation happens on response
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        $modified = false;
        $newContent = [];

        foreach ($message->content as $block) {
            if ($block->type === 'tool_use' && $this->isShellTool($block->toolName ?? '')) {
                $command = $this->extractCommand($block);

                if ($command !== null) {
                    $result = $this->validator->validate($command);

                    if ($result->decision === 'deny') {
                        // Replace the tool_use with a warning text block
                        $newContent[] = ContentBlock::text(
                            "[Bridge Security] Blocked dangerous bash command: {$result->reason}\n"
                            . "Command: " . mb_substr($command, 0, 200) . (strlen($command) > 200 ? '...' : '') . "\n"
                            . "Security check #" . ($result->checkId ?? '?')
                        );
                        $modified = true;
                        continue;
                    }
                }
            }

            $newContent[] = $block;
        }

        if (! $modified) {
            return $message;
        }

        $enhanced = new AssistantMessage();
        $enhanced->content = $newContent;
        $enhanced->stopReason = $message->stopReason;
        $enhanced->usage = $message->usage;

        return $enhanced;
    }

    private function isShellTool(string $toolName): bool
    {
        return in_array($toolName, $this->shellToolNames, true);
    }

    /**
     * Extract the shell command string from a tool_use block's input.
     *
     * Supports common parameter names used by various shell tools.
     */
    private function extractCommand(ContentBlock $block): ?string
    {
        $input = $block->toolInput ?? [];

        // Try common parameter names
        foreach (['command', 'cmd', 'script', 'code', 'input'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                return $input[$key];
            }
        }

        return null;
    }
}
