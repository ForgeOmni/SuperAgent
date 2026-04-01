<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

use SuperAgent\Messages\Message;

/**
 * Context for forking a sub-agent from the parent agent's conversation.
 *
 * Fork semantics (inspired by Claude Code):
 * - Child inherits the parent's full conversation context and system prompt
 * - Parent's system prompt bytes are reused exactly (for prompt cache sharing)
 * - All fork children produce byte-identical API request prefixes
 * - Only the per-child directive differs, maximizing cache hits
 * - Fork children cannot recursively fork (guarded by fork boilerplate tag)
 */
class ForkContext
{
    public const FORK_BOILERPLATE_TAG = 'fork-context';
    public const FORK_DIRECTIVE_PREFIX = 'DIRECTIVE: ';

    /**
     * @param Message[] $parentMessages The parent's full conversation history
     * @param string $parentSystemPrompt The parent's rendered system prompt (byte-exact)
     * @param string[] $parentToolNames The parent's exact tool names (for cache-identical prefixes)
     */
    public function __construct(
        public readonly array $parentMessages,
        public readonly string $parentSystemPrompt,
        public readonly array $parentToolNames = [],
    ) {}

    /**
     * Build the forked messages for the child agent.
     *
     * Returns the parent's messages plus a directive message for the child.
     * The directive message includes fork boilerplate rules and the child's task.
     *
     * @return Message[]
     */
    public function buildForkedMessages(string $directive): array
    {
        return array_merge(
            $this->parentMessages,
            [new \SuperAgent\Messages\UserMessage($this->buildChildMessage($directive))],
        );
    }

    /**
     * Build the child's directive message with fork boilerplate.
     */
    public function buildChildMessage(string $directive): string
    {
        $tag = self::FORK_BOILERPLATE_TAG;

        return <<<PROMPT
<{$tag}>
STOP. READ THIS FIRST.

You are a forked worker process. You are NOT the main agent.

RULES (non-negotiable):
1. Do NOT spawn sub-agents — you ARE the fork. Execute directly.
2. Do NOT converse, ask questions, or suggest next steps.
3. USE your tools directly to complete the task.
4. If you modify files, commit your changes before reporting.
5. Do NOT emit text between tool calls. Use tools silently, then report once at the end.
6. Stay strictly within your directive's scope.
7. Keep your report under 500 words unless the directive specifies otherwise.
8. Your response MUST begin with "Scope:". No preamble.
9. REPORT structured facts, then stop.

Output format:
  Scope: <echo back your assigned scope in one sentence>
  Result: <the answer or key findings>
  Key files: <relevant file paths>
  Files changed: <list — include only if you modified files>
  Issues: <list — include only if there are issues to flag>
</{$tag}>

PROMPT . self::FORK_DIRECTIVE_PREFIX . $directive;
    }

    /**
     * Check if the current conversation is already a fork child.
     * Prevents recursive forking.
     *
     * @param Message[] $messages
     */
    public static function isInForkChild(array $messages): bool
    {
        $tag = self::FORK_BOILERPLATE_TAG;

        foreach ($messages as $message) {
            if ($message instanceof \SuperAgent\Messages\UserMessage) {
                $content = $message->toArray()['content'] ?? '';
                if (is_string($content) && str_contains($content, "<{$tag}>")) {
                    return true;
                }
            }
        }

        return false;
    }
}
