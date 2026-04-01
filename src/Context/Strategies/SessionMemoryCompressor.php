<?php

declare(strict_types=1);

namespace SuperAgent\Context\Strategies;

use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\Message;
use SuperAgent\Context\MessageRole;
use SuperAgent\Context\MessageType;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\LLM\ProviderInterface;

/**
 * Session memory compaction strategy ported from Claude Code.
 *
 * Two-stage approach:
 *  1. Determine which messages to keep (semantic boundary protection).
 *  2. Generate a 9-section structured summary of the older messages.
 *
 * Boundary protection ensures:
 *  - tool_use / tool_result pairs are never split
 *  - At least minTokens tokens and minTextBlockMessages text-bearing messages are preserved
 *  - maxTokens hard cap is respected
 *  - Existing compact-boundary markers act as a floor (never prune past them)
 */
class SessionMemoryCompressor implements CompressionStrategy
{
    /** Minimum tokens to preserve after compaction */
    private int $minTokens;

    /** Minimum number of messages with text blocks to keep */
    private int $minTextBlockMessages;

    /** Maximum tokens to preserve after compaction (hard cap) */
    private int $maxTokens;

    public function __construct(
        private TokenEstimator $tokenEstimator,
        private CompressionConfig $config,
        private ProviderInterface $provider,
        ?int $minTokens = null,
        ?int $minTextBlockMessages = null,
        ?int $maxTokens = null,
    ) {
        $this->minTokens = $minTokens ?? 10_000;
        $this->minTextBlockMessages = $minTextBlockMessages ?? 5;
        $this->maxTokens = $maxTokens ?? 40_000;
    }

    public function getPriority(): int
    {
        return 5; // Between micro (1) and conversation summary (10)
    }

    public function getName(): string
    {
        return 'session_memory';
    }

    public function canCompress(array $messages, array $context = []): bool
    {
        if (!$this->config->enableSessionMemory) {
            return false;
        }

        if (count($messages) < $this->config->minMessages) {
            return false;
        }

        $tokenCount = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), $messages)
        );

        return $tokenCount >= $this->config->minTokens;
    }

    public function compress(array $messages, array $options = []): CompressionResult
    {
        $preCompactTokenCount = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), $messages)
        );

        // Find the last compact boundary (floor for backward expansion)
        $boundaryFloor = $this->findLastBoundaryIndex($messages);

        // Find last summarized message index
        $lastSummarizedIndex = $this->findLastSummarizedIndex($messages, $options);

        // Calculate which messages to keep using semantic boundary protection
        $startIndex = $this->calculateMessagesToKeepIndex(
            $messages,
            $lastSummarizedIndex,
            $boundaryFloor,
        );

        $messagesToCompress = array_slice($messages, 0, $startIndex);
        $messagesToKeep = array_slice($messages, $startIndex);

        if (empty($messagesToCompress)) {
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
            );
        }

        // Generate structured 9-section summary
        $summary = $this->generateStructuredSummary($messagesToCompress, $options);

        if ($summary === null) {
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
                metadata: ['error' => 'Summary generation failed'],
            );
        }

        // Format summary: strip <analysis>, extract <summary>
        $formattedSummary = $this->formatCompactSummary($summary);

        // Build the user-facing summary message
        $summaryContent = $this->buildSummaryMessage($formattedSummary, $options);

        $summaryMessage = Message::summary(
            content: $summaryContent,
            metadata: [
                'messages_compressed' => count($messagesToCompress),
                'compact_type' => 'session_memory',
            ],
        );

        $summaryTokens = $this->tokenEstimator->estimateTokens($summaryContent);
        $compressedTokens = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), $messagesToCompress)
        );
        $tokensSaved = $compressedTokens - $summaryTokens;

        $postCompactTokenCount = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), array_merge([$summaryMessage], $messagesToKeep))
        );

        $boundaryMessage = Message::boundary(
            content: sprintf(
                "--- Session Memory Compaction ---\nCompressed %d messages into structured summary.\nTokens saved: %d\nRecent messages preserved with semantic boundary protection.",
                count($messagesToCompress),
                $tokensSaved,
            ),
            metadata: [
                'compact_type' => 'session_memory',
                'start_index' => $startIndex,
                'messages_kept' => count($messagesToKeep),
                'boundary_floor' => $boundaryFloor,
            ],
        );

        return new CompressionResult(
            compressedMessages: [$summaryMessage],
            preservedMessages: $messagesToKeep,
            boundaryMessage: $boundaryMessage,
            tokensSaved: max(0, $tokensSaved),
            preCompactTokenCount: $preCompactTokenCount,
            postCompactTokenCount: $postCompactTokenCount,
            metadata: [
                'strategy' => 'session_memory',
                'messages_compressed' => count($messagesToCompress),
                'messages_kept' => count($messagesToKeep),
            ],
        );
    }

    // ----------------------------------------------------------------
    // Semantic boundary protection
    // ----------------------------------------------------------------

    /**
     * Calculate starting index for messages to keep.
     * Starts from lastSummarizedIndex, expands backwards to meet minimums,
     * respects maxTokens cap and boundary floor.
     */
    private function calculateMessagesToKeepIndex(
        array $messages,
        int $lastSummarizedIndex,
        int $boundaryFloor,
    ): int {
        if (empty($messages)) {
            return 0;
        }

        $startIndex = $lastSummarizedIndex >= 0
            ? $lastSummarizedIndex + 1
            : count($messages);

        // Calculate current tokens and text-block count from startIndex
        $totalTokens = 0;
        $textBlockCount = 0;
        for ($i = $startIndex; $i < count($messages); $i++) {
            $totalTokens += $this->tokenEstimator->estimateMessageTokens($messages[$i]->toArray());
            if ($this->hasTextBlocks($messages[$i])) {
                $textBlockCount++;
            }
        }

        // Already at max cap
        if ($totalTokens >= $this->maxTokens) {
            return $this->adjustForToolPairs($messages, $startIndex);
        }

        // Already meets both minimums
        if ($totalTokens >= $this->minTokens && $textBlockCount >= $this->minTextBlockMessages) {
            return $this->adjustForToolPairs($messages, $startIndex);
        }

        // Expand backwards until minimums met or max cap hit
        $floor = max(0, $boundaryFloor);
        for ($i = $startIndex - 1; $i >= $floor; $i--) {
            $msgTokens = $this->tokenEstimator->estimateMessageTokens($messages[$i]->toArray());
            $totalTokens += $msgTokens;
            if ($this->hasTextBlocks($messages[$i])) {
                $textBlockCount++;
            }
            $startIndex = $i;

            if ($totalTokens >= $this->maxTokens) {
                break;
            }

            if ($totalTokens >= $this->minTokens && $textBlockCount >= $this->minTextBlockMessages) {
                break;
            }
        }

        return $this->adjustForToolPairs($messages, $startIndex);
    }

    /**
     * Adjust index to preserve tool_use/tool_result pairs (API invariants).
     */
    private function adjustForToolPairs(array $messages, int $startIndex): int
    {
        if ($startIndex <= 0 || $startIndex >= count($messages)) {
            return $startIndex;
        }

        $adjustedIndex = $startIndex;

        // Collect tool_result IDs in kept range
        $toolResultIds = [];
        for ($i = $startIndex; $i < count($messages); $i++) {
            $msg = $messages[$i];
            if (is_array($msg->content)) {
                foreach ($msg->content as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'tool_result') {
                        $toolResultIds[] = $part['tool_use_id'] ?? '';
                    }
                }
            }
        }

        if (empty($toolResultIds)) {
            return $adjustedIndex;
        }

        // Collect tool_use IDs already in kept range
        $keptToolUseIds = [];
        for ($i = $adjustedIndex; $i < count($messages); $i++) {
            $msg = $messages[$i];
            if ($msg->role === MessageRole::ASSISTANT && is_array($msg->content)) {
                foreach ($msg->content as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'tool_use') {
                        $keptToolUseIds[] = $part['id'] ?? '';
                    }
                }
            }
        }

        // Find tool_use messages needed but not in kept range
        $neededIds = array_diff($toolResultIds, $keptToolUseIds);
        $neededSet = array_flip($neededIds);

        for ($i = $adjustedIndex - 1; $i >= 0 && !empty($neededSet); $i--) {
            $msg = $messages[$i];
            if ($msg->role !== MessageRole::ASSISTANT || !is_array($msg->content)) {
                continue;
            }
            $found = false;
            foreach ($msg->content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'tool_use') {
                    $id = $part['id'] ?? '';
                    if (isset($neededSet[$id])) {
                        $found = true;
                        unset($neededSet[$id]);
                    }
                }
            }
            if ($found) {
                $adjustedIndex = $i;
            }
        }

        return $adjustedIndex;
    }

    /**
     * Check if a message has text content blocks.
     */
    private function hasTextBlocks(Message $message): bool
    {
        if ($message->role === MessageRole::ASSISTANT || $message->role === MessageRole::USER) {
            if (is_string($message->content) && strlen($message->content) > 0) {
                return true;
            }
            if (is_array($message->content)) {
                foreach ($message->content as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'text') {
                        return true;
                    }
                    if (is_string($part) && strlen($part) > 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function findLastBoundaryIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->type === MessageType::BOUNDARY) {
                return $i + 1;
            }
        }
        return 0;
    }

    private function findLastSummarizedIndex(array $messages, array $options): int
    {
        // If caller provides the last summarized message ID
        $lastId = $options['last_summarized_id'] ?? null;
        if ($lastId !== null) {
            foreach ($messages as $i => $msg) {
                if ($msg->id === $lastId) {
                    return $i;
                }
            }
        }

        // Default: use keepRecentMessages from config
        $keep = $options['keep_recent'] ?? $this->config->keepRecentMessages;
        return max(0, count($messages) - $keep - 1);
    }

    // ----------------------------------------------------------------
    // Summary generation (9-section structured prompt from CC)
    // ----------------------------------------------------------------

    private function generateStructuredSummary(array $messages, array $options): ?string
    {
        $conversation = $this->formatMessagesForSummary($messages);
        $prompt = $this->getCompactPrompt($options['custom_instructions'] ?? null);

        try {
            $response = $this->provider->generateResponse(
                messages: [
                    ['role' => 'user', 'content' => $prompt . "\n\n" . $conversation],
                ],
                options: [
                    'max_tokens' => 8000,
                    'temperature' => 0.3,
                    'model' => $this->config->summaryModel,
                ],
            );
            return $response->content ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build the full compact prompt with 9-section structure from CC.
     */
    private function getCompactPrompt(?string $customInstructions): string
    {
        $noToolsPreamble = "CRITICAL: Respond with TEXT ONLY. Do NOT call any tools.\n\n"
            . "- You already have all the context you need in the conversation above.\n"
            . "- Your entire response must be plain text: an <analysis> block followed by a <summary> block.\n\n";

        $analysisInstruction = <<<'INST'
Before providing your final summary, wrap your analysis in <analysis> tags to organize your thoughts and ensure you've covered all necessary points. In your analysis process:

1. Chronologically analyze each message and section of the conversation. For each section thoroughly identify:
   - The user's explicit requests and intents
   - Your approach to addressing the user's requests
   - Key decisions, technical concepts and code patterns
   - Specific details like:
     - file names
     - full code snippets
     - function signatures
     - file edits
   - Errors that you ran into and how you fixed them
   - Pay special attention to specific user feedback that you received, especially if the user told you to do something differently.
2. Double-check for technical accuracy and completeness, addressing each required element thoroughly.
INST;

        $mainPrompt = <<<PROMPT
Your task is to create a detailed summary of the conversation so far, paying close attention to the user's explicit requests and your previous actions.
This summary should be thorough in capturing technical details, code patterns, and architectural decisions that would be essential for continuing development work without losing context.

{$analysisInstruction}

Your summary should include the following sections:

1. Primary Request and Intent: Capture all of the user's explicit requests and intents in detail
2. Key Technical Concepts: List all important technical concepts, technologies, and frameworks discussed.
3. Files and Code Sections: Enumerate specific files and code sections examined, modified, or created. Pay special attention to the most recent messages and include full code snippets where applicable and include a summary of why this file read or edit is important.
4. Errors and fixes: List all errors that you ran into, and how you fixed them. Pay special attention to specific user feedback that you received, especially if the user told you to do something differently.
5. Problem Solving: Document problems solved and any ongoing troubleshooting efforts.
6. All user messages: List ALL user messages that are not tool results. These are critical for understanding the users' feedback and changing intent.
7. Pending Tasks: Outline any pending tasks that you have explicitly been asked to work on.
8. Current Work: Describe in detail precisely what was being worked on immediately before this summary request, paying special attention to the most recent messages from both user and assistant. Include file names and code snippets where applicable.
9. Optional Next Step: List the next step that you will take that is related to the most recent work you were doing. IMPORTANT: ensure that this step is DIRECTLY in line with the user's most recent explicit requests. If your last task was concluded, then only list next steps if they are explicitly in line with the users request.

Here's an example of how your output should be structured:

<example>
<analysis>
[Your thought process, ensuring all points are covered thoroughly and accurately]
</analysis>

<summary>
1. Primary Request and Intent:
   [Detailed description]

2. Key Technical Concepts:
   - [Concept 1]
   - [Concept 2]

3. Files and Code Sections:
   - [File Name 1]
      - [Summary of why this file is important]
      - [Important Code Snippet]

4. Errors and fixes:
    - [Error description]:
      - [How you fixed it]

5. Problem Solving:
   [Description]

6. All user messages:
    - [Detailed non tool use user message]

7. Pending Tasks:
   - [Task 1]

8. Current Work:
   [Precise description of current work]

9. Optional Next Step:
   [Optional Next step to take]

</summary>
</example>

Please provide your summary based on the conversation so far, following this structure and ensuring precision and thoroughness in your response.
PROMPT;

        $prompt = $noToolsPreamble . $mainPrompt;

        if ($customInstructions !== null && trim($customInstructions) !== '') {
            $prompt .= "\n\nAdditional Instructions:\n{$customInstructions}";
        }

        $prompt .= "\n\nREMINDER: Do NOT call any tools. Respond with plain text only — "
            . "an <analysis> block followed by a <summary> block.";

        return $prompt;
    }

    /**
     * Format the raw summary: strip <analysis> scratchpad, extract <summary>.
     */
    public function formatCompactSummary(string $summary): string
    {
        // Strip analysis section (drafting scratchpad)
        $formatted = preg_replace('/<analysis>[\s\S]*?<\/analysis>/', '', $summary);

        // Extract summary section
        if (preg_match('/<summary>([\s\S]*?)<\/summary>/', $formatted, $matches)) {
            $content = trim($matches[1] ?? '');
            $formatted = preg_replace(
                '/<summary>[\s\S]*?<\/summary>/',
                "Summary:\n{$content}",
                $formatted,
            );
        }

        // Clean up extra whitespace
        $formatted = preg_replace('/\n\n+/', "\n\n", $formatted);

        return trim($formatted);
    }

    /**
     * Build the user-facing summary message with continuation instructions.
     */
    private function buildSummaryMessage(string $formattedSummary, array $options): string
    {
        $message = "This session is being continued from a previous conversation that ran out of context. "
            . "The summary below covers the earlier portion of the conversation.\n\n"
            . $formattedSummary;

        if (isset($options['transcript_path'])) {
            $message .= "\n\nIf you need specific details from before compaction (like exact code snippets, "
                . "error messages, or content you generated), read the full transcript at: "
                . $options['transcript_path'];
        }

        $message .= "\n\nRecent messages are preserved verbatim.";

        if ($options['suppress_followup'] ?? false) {
            $message .= "\n\nContinue the conversation from where it left off without asking the user any further questions. "
                . "Resume directly — do not acknowledge the summary, do not recap what was happening, do not preface "
                . "with \"I'll continue\" or similar. Pick up the last task as if the break never happened.";
        }

        return $message;
    }

    private function formatMessagesForSummary(array $messages): string
    {
        $formatted = [];
        foreach ($messages as $message) {
            $role = ucfirst($message->role->value);
            $content = is_string($message->content)
                ? $message->content
                : json_encode($message->content);

            if ($message->isToolUse()) {
                $toolName = $message->getToolName();
                $formatted[] = "{$role}: [Tool Use: {$toolName}] {$content}";
            } elseif ($message->isToolResult()) {
                $toolName = $message->getToolName();
                // Truncate very long tool results
                if (strlen($content) > 5000) {
                    $content = substr($content, 0, 5000) . '... [truncated]';
                }
                $formatted[] = "{$role}: [Tool Result: {$toolName}] {$content}";
            } else {
                $formatted[] = "{$role}: {$content}";
            }
        }
        return implode("\n\n", $formatted);
    }
}
