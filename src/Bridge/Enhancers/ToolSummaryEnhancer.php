<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ToolResultMessage;

/**
 * Replaces verbose old tool results with compact summaries to save context.
 *
 * Uses a simple rule-based approach (no LLM call) to generate summaries:
 * - Keeps the first N lines of output
 * - Appends a "[truncated]" indicator
 * - Preserves recent results unmodified
 */
class ToolSummaryEnhancer implements EnhancerInterface
{
    /** Keep this many recent messages unmodified */
    private int $keepRecent;

    /** Truncate tool results longer than this (chars) */
    private int $maxChars;

    /** Keep this many lines from the start of truncated results */
    private int $keepLines;

    public function __construct(
        int $keepRecent = 8,
        int $maxChars = 1000,
        int $keepLines = 10,
    ) {
        $this->keepRecent = $keepRecent;
        $this->maxChars = $maxChars;
        $this->keepLines = $keepLines;
    }

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        $count = count($messages);
        $boundary = max(0, $count - $this->keepRecent);

        for ($i = 0; $i < $boundary; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                $messages[$i] = $this->summarize($messages[$i]);
            }
        }
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        return $message;
    }

    private function summarize(ToolResultMessage $msg): ToolResultMessage
    {
        $modified = false;
        $results = [];

        foreach ($msg->content as $block) {
            $content = $block->content ?? '';

            if (strlen($content) > $this->maxChars) {
                $lines = explode("\n", $content);
                $kept = array_slice($lines, 0, $this->keepLines);
                $totalLines = count($lines);

                $results[] = [
                    'tool_use_id' => $block->toolUseId ?? '',
                    'content' => implode("\n", $kept)
                        . "\n\n[... {$totalLines} lines total, "
                        . number_format(strlen($content)) . " chars, truncated by bridge]",
                    'is_error' => $block->isError ?? false,
                ];
                $modified = true;
            } else {
                $results[] = [
                    'tool_use_id' => $block->toolUseId ?? '',
                    'content' => $content,
                    'is_error' => $block->isError ?? false,
                ];
            }
        }

        return $modified ? ToolResultMessage::fromResults($results) : $msg;
    }
}
