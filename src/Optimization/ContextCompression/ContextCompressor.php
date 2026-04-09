<?php

declare(strict_types=1);

namespace SuperAgent\Optimization\ContextCompression;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Unified hierarchical context compressor.
 *
 * Inspired by hermes-agent's context_compressor.py — implements a structured
 * multi-phase compression pipeline:
 *
 *   Phase 1: Prune old tool results (cheap, no LLM call)
 *   Phase 2: Protect head (system context) and tail (recent work)
 *   Phase 3: Summarize middle section via LLM
 *   Phase 4: Iteratively update summary on subsequent compressions
 *
 * Key features:
 *   - Token-budget tail protection (not fixed message count)
 *   - Structured summary template preserving key decisions
 *   - Iterative summary updates across multiple compressions
 *   - Cheap pre-pass before LLM summarization
 */
class ContextCompressor
{
    private ?string $previousSummary = null;

    public function __construct(
        private bool $enabled = true,
        private int $tailBudgetTokens = 8000,
        private int $maxToolResultLength = 200,
        private int $preserveHeadMessages = 2,
        private int $targetTokenBudget = 80000,
    ) {}

    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.optimization.context_compression') ?? [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            tailBudgetTokens: (int) ($config['tail_budget_tokens'] ?? 8000),
            maxToolResultLength: (int) ($config['max_tool_result_length'] ?? 200),
            preserveHeadMessages: (int) ($config['preserve_head_messages'] ?? 2),
            targetTokenBudget: (int) ($config['target_token_budget'] ?? 80000),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Proactively check if messages need compression and compress if so.
     *
     * Unlike compress() which always runs the pipeline, this method only
     * triggers compression when estimated tokens exceed the budget.
     * Designed to be called on every message addition for automatic management.
     *
     * @param Message[] $messages
     * @param callable|null $summarizer fn(string $text, ?string $previousSummary): string
     * @return array{0: Message[], 1: bool} [messages, wasCompressed]
     */
    public function compressIfNeeded(array $messages, ?callable $summarizer = null): array
    {
        if (!$this->enabled) {
            return [$messages, false];
        }

        $estimatedTokens = $this->estimateTokens($messages);
        if ($estimatedTokens <= $this->targetTokenBudget) {
            return [$messages, false];
        }

        return [$this->compress($messages, $summarizer), true];
    }

    /**
     * Get the current token budget.
     */
    public function getTargetTokenBudget(): int
    {
        return $this->targetTokenBudget;
    }

    /**
     * Estimate token count for a set of messages.
     */
    public function estimateTokenCount(array $messages): int
    {
        return $this->estimateTokens($messages);
    }

    /**
     * Get compression ratio: how much the last compression saved.
     */
    public function getCompressionStats(array $original, array $compressed): array
    {
        $originalTokens = $this->estimateTokens($original);
        $compressedTokens = $this->estimateTokens($compressed);
        $saved = $originalTokens - $compressedTokens;

        return [
            'original_tokens' => $originalTokens,
            'compressed_tokens' => $compressedTokens,
            'tokens_saved' => $saved,
            'ratio' => $originalTokens > 0 ? round($compressedTokens / $originalTokens, 3) : 1.0,
            'messages_before' => count($original),
            'messages_after' => count($compressed),
        ];
    }

    /**
     * Compress messages to fit within token budget.
     *
     * Returns the compressed message array. If a summarizer callable is provided,
     * the middle section will be LLM-summarized; otherwise only tool results are pruned.
     *
     * @param Message[] $messages
     * @param callable|null $summarizer fn(string $textToSummarize, ?string $previousSummary): string
     * @return Message[]
     */
    public function compress(array $messages, ?callable $summarizer = null): array
    {
        if (!$this->enabled || count($messages) <= $this->preserveHeadMessages + 3) {
            return $messages;
        }

        // Phase 1: Cheap pre-pass — prune old tool results
        $messages = $this->pruneToolResults($messages);

        $estimatedTokens = $this->estimateTokens($messages);
        if ($estimatedTokens <= $this->targetTokenBudget) {
            return $messages;
        }

        // Phase 2: Split into head / middle / tail
        [$head, $middle, $tail] = $this->splitMessages($messages);

        if (empty($middle)) {
            return $messages;
        }

        // Phase 3: Summarize middle section
        if ($summarizer !== null) {
            $middleText = $this->formatForSummarization($middle);
            $summary = $summarizer($middleText, $this->previousSummary);
            $this->previousSummary = $summary;

            $summaryMessage = new UserMessage(
                "[Context Summary — compressed from earlier conversation]\n\n" . $summary
            );

            return array_merge($head, [$summaryMessage], $tail);
        }

        // No summarizer: just drop the middle (aggressive but functional)
        $marker = new UserMessage(
            "[Context compressed — " . count($middle) . " messages omitted to stay within token budget]"
        );

        return array_merge($head, [$marker], $tail);
    }

    /**
     * Get the structured summary template used for LLM summarization.
     */
    public static function getSummaryTemplate(): string
    {
        return <<<'TEMPLATE'
Summarize the following conversation segment using this structure:

## Goal
What was the user trying to accomplish?

## Progress
What was completed? List specific changes (files modified, tools used).

## Key Decisions
What decisions were made and why?

## Current State
What files/resources are currently being worked on?

## Next Steps
What was about to happen next?

Be concise but preserve all actionable details. If there is a previous summary,
update it rather than creating a new one — preserve older information that remains relevant.
TEMPLATE;
    }

    /**
     * Get the previous compression summary (for iterative updates).
     */
    public function getPreviousSummary(): ?string
    {
        return $this->previousSummary;
    }

    // ── Phase 1: Prune tool results ─────────────────────────────

    private function pruneToolResults(array $messages): array
    {
        $assistantCount = 0;
        foreach ($messages as $msg) {
            if ($msg instanceof AssistantMessage) {
                $assistantCount++;
            }
        }

        // Only prune tool results from the first half of turns
        $turnsSeen = 0;
        $halfTurns = (int) ceil($assistantCount / 2);
        $result = [];

        foreach ($messages as $msg) {
            if ($msg instanceof AssistantMessage) {
                $turnsSeen++;
            }

            if ($msg instanceof ToolResultMessage && $turnsSeen <= $halfTurns) {
                $result[] = $this->truncateToolResult($msg);
            } else {
                $result[] = $msg;
            }
        }

        return $result;
    }

    private function truncateToolResult(ToolResultMessage $msg): ToolResultMessage
    {
        $newBlocks = [];
        foreach ($msg->content as $block) {
            if ($block->isError) {
                $newBlocks[] = $block;
                continue;
            }

            $content = $block->content ?? '';
            if (mb_strlen($content) > $this->maxToolResultLength) {
                $truncated = mb_substr($content, 0, $this->maxToolResultLength) . '... [truncated]';
                $newBlocks[] = \SuperAgent\Messages\ContentBlock::toolResult(
                    $block->toolUseId,
                    $truncated,
                    false,
                );
            } else {
                $newBlocks[] = $block;
            }
        }

        return new ToolResultMessage($newBlocks);
    }

    // ── Phase 2: Split messages ─────────────────────────────────

    /**
     * Split messages into [head, middle, tail] using token-budget tail protection.
     *
     * @return array{0: Message[], 1: Message[], 2: Message[]}
     */
    private function splitMessages(array $messages): array
    {
        $head = array_slice($messages, 0, $this->preserveHeadMessages);
        $rest = array_slice($messages, $this->preserveHeadMessages);

        // Determine tail size by token budget (not fixed count)
        $tailTokens = 0;
        $tailStart = count($rest);

        for ($i = count($rest) - 1; $i >= 0; $i--) {
            $msgTokens = $this->estimateMessageTokens($rest[$i]);
            if ($tailTokens + $msgTokens > $this->tailBudgetTokens) {
                break;
            }
            $tailTokens += $msgTokens;
            $tailStart = $i;
        }

        // Ensure at least the last 2 messages are in the tail
        $tailStart = min($tailStart, max(0, count($rest) - 2));

        $middle = array_slice($rest, 0, $tailStart);
        $tail = array_slice($rest, $tailStart);

        return [$head, $middle, $tail];
    }

    // ── Phase 3: Format for summarization ───────────────────────

    private function formatForSummarization(array $messages): string
    {
        $parts = [];

        if ($this->previousSummary !== null) {
            $parts[] = "=== PREVIOUS SUMMARY ===\n" . $this->previousSummary . "\n";
        }

        $parts[] = "=== CONVERSATION TO SUMMARIZE ===\n";

        foreach ($messages as $msg) {
            if ($msg instanceof UserMessage) {
                $content = is_string($msg->content) ? $msg->content : json_encode($msg->content);
                $parts[] = "[User] " . $content;
            } elseif ($msg instanceof AssistantMessage) {
                $text = $msg->text();
                $toolUses = [];
                foreach ($msg->content as $block) {
                    if ($block->type === 'tool_use') {
                        $toolUses[] = $block->toolName . '(' . json_encode($block->toolInput) . ')';
                    }
                }
                if (!empty($text)) {
                    $parts[] = "[Assistant] " . $text;
                }
                if (!empty($toolUses)) {
                    $parts[] = "[Tool calls] " . implode(', ', $toolUses);
                }
            } elseif ($msg instanceof ToolResultMessage) {
                foreach ($msg->content as $block) {
                    $status = $block->isError ? 'ERROR' : 'OK';
                    $content = mb_substr($block->content ?? '', 0, 200);
                    $parts[] = "[Tool result: {$status}] {$content}";
                }
            }
        }

        return implode("\n", $parts);
    }

    // ── Token estimation ────────────────────────────────────────

    private function estimateTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $total += $this->estimateMessageTokens($msg);
        }
        return $total;
    }

    private function estimateMessageTokens(Message $msg): int
    {
        $text = $this->getMessageText($msg);
        // Rough estimate: ~4 characters per token
        return (int) ceil(mb_strlen($text) / 4);
    }

    private function getMessageText(Message $msg): string
    {
        if ($msg instanceof UserMessage) {
            $content = $msg->content;
            if (is_string($content)) {
                return $content;
            }
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $block) {
                    if (is_string($block)) {
                        $parts[] = $block;
                    } elseif (isset($block['text'])) {
                        $parts[] = $block['text'];
                    }
                }
                return implode("\n", $parts);
            }
            return '';
        }
        if ($msg instanceof AssistantMessage) {
            return $msg->text();
        }
        if ($msg instanceof ToolResultMessage) {
            $parts = [];
            foreach ($msg->content as $block) {
                $parts[] = $block->content ?? '';
            }
            return implode("\n", $parts);
        }
        return '';
    }
}
