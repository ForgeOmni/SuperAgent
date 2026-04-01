<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\LLM\ProviderInterface;

/**
 * Real-time session memory extraction ported from Claude Code.
 *
 * Runs as a post-turn background extraction that periodically extracts
 * session notes from the conversation into a structured memory file.
 *
 * Trigger conditions (all must be met):
 *  1. Token count >= minimumMessageTokensToInit (10K) for first extraction
 *  2. Token growth >= minimumTokensBetweenUpdate (5K) since last extraction
 *  3. Tool calls >= toolCallsBetweenUpdates (3) OR natural break (no tool calls in last turn)
 *
 * The extraction produces a structured file with 10 sections:
 *  - Session Title, Current State, Task specification
 *  - Files and Functions, Workflow, Errors & Corrections
 *  - Codebase Documentation, Learnings, Key results, Worklog
 */
class SessionMemoryExtractor
{
    /** Section token limit */
    private const MAX_TOKENS_PER_SECTION = 2000;

    /** Total memory file token limit */
    private const MAX_TOTAL_TOKENS = 12000;

    // State tracking
    private bool $initialized = false;
    private int $tokensAtLastExtraction = 0;
    private int $toolCallsSinceExtraction = 0;
    private ?string $lastMemoryMessageId = null;
    private bool $extractionInProgress = false;

    private TokenEstimator $tokenEstimator;

    public function __construct(
        private ProviderInterface $provider,
        private MemoryConfig $config,
        private LoggerInterface $logger = new NullLogger(),
        ?TokenEstimator $tokenEstimator = null,
    ) {
        $this->tokenEstimator = $tokenEstimator ?? new TokenEstimator();
    }

    /**
     * Check if extraction should run and execute if so.
     *
     * Called at the end of each query loop (post-turn hook).
     *
     * @param array  $messages       Full conversation messages
     * @param string $sessionId      Current session ID
     * @param string $memoryBasePath Base path for memory files
     * @param bool   $lastTurnHadToolCalls Whether the last assistant turn had tool calls
     * @return bool Whether extraction ran
     */
    public function maybeExtract(
        array $messages,
        string $sessionId,
        string $memoryBasePath,
        bool $lastTurnHadToolCalls = true,
    ): bool {
        if ($this->extractionInProgress) {
            return false;
        }

        // Estimate current token count
        $currentTokens = $this->estimateTokenCount($messages);

        // Gate 1: Initialization threshold
        if (!$this->initialized) {
            if ($currentTokens < $this->config->minimumMessageTokensToInit) {
                return false;
            }
            $this->initialized = true;
            $this->tokensAtLastExtraction = $currentTokens;
        }

        // Gate 2: Token growth threshold
        $tokenDelta = $currentTokens - $this->tokensAtLastExtraction;
        if ($tokenDelta < $this->config->minimumTokensBetweenUpdate) {
            // Track tool calls even if we don't extract
            if ($lastTurnHadToolCalls) {
                $this->toolCallsSinceExtraction++;
            }
            return false;
        }

        // Gate 3: Tool call threshold OR natural break
        $meetsToolCallThreshold = $this->toolCallsSinceExtraction >= $this->config->toolCallsBetweenUpdates;
        $isNaturalBreak = !$lastTurnHadToolCalls;

        if (!$meetsToolCallThreshold && !$isNaturalBreak) {
            if ($lastTurnHadToolCalls) {
                $this->toolCallsSinceExtraction++;
            }
            return false;
        }

        // All gates passed — extract
        return $this->extract($messages, $sessionId, $memoryBasePath);
    }

    /**
     * Force an extraction (bypass gates).
     */
    public function extract(
        array $messages,
        string $sessionId,
        string $memoryBasePath,
    ): bool {
        $this->extractionInProgress = true;

        try {
            $memoryFilePath = $memoryBasePath . '/session-memory/' . $sessionId . '.md';

            // Read existing memory file
            $existingContent = $this->readMemoryFile($memoryFilePath);

            // Get messages since last extraction
            $newMessages = $this->getMessagesSinceLastExtraction($messages);
            if (empty($newMessages)) {
                return false;
            }

            // Build extraction prompt
            $prompt = $this->buildUpdatePrompt($existingContent, $newMessages);

            // Run extraction via LLM
            $response = $this->provider->generateResponse(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                options: [
                    'max_tokens' => 4000,
                    'temperature' => 0.3,
                ],
            );

            if (empty($response->content)) {
                return false;
            }

            // Write updated memory file
            $this->writeMemoryFile($memoryFilePath, $response->content);

            // Update state
            $this->tokensAtLastExtraction = $this->estimateTokenCount($messages);
            $this->toolCallsSinceExtraction = 0;
            $this->lastMemoryMessageId = $this->getLastMessageId($messages);

            $this->logger->info('Session memory extracted', [
                'session_id' => $sessionId,
                'messages_processed' => count($newMessages),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Session memory extraction failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            $this->extractionInProgress = false;
        }
    }

    /**
     * Get the session memory template with 10 sections.
     */
    public static function getTemplate(): string
    {
        return <<<'TEMPLATE'
# Session Title
*Brief title describing the main focus of this session*

# Current State
*What is the current state of the work? What was just accomplished or is in progress?*

# Task specification
*What is the user trying to accomplish? Include specific requirements and constraints.*

# Files and Functions
*Key files and functions that have been examined, modified, or are relevant to the current work.*

# Workflow
*The sequence of steps taken, approaches tried, and the general flow of work.*

# Errors & Corrections
*Errors encountered, how they were fixed, and user corrections/feedback.*

# Codebase and System Documentation
*Important documentation about the codebase, architecture, or system discovered during work.*

# Learnings
*Key insights, patterns, or knowledge gained during this session.*

# Key results
*Important outputs, findings, or deliverables produced.*

# Worklog
*Chronological log of significant actions taken.*
TEMPLATE;
    }

    /**
     * Build the update prompt for the extraction LLM call.
     */
    private function buildUpdatePrompt(string $existingContent, array $messages): string
    {
        $conversation = $this->formatMessagesForExtraction($messages);
        $template = empty($existingContent) ? self::getTemplate() : $existingContent;

        return <<<PROMPT
You are updating a session memory file. This file captures important context from the current coding session.

CURRENT MEMORY FILE:
{$template}

RECENT CONVERSATION TO EXTRACT FROM:
{$conversation}

INSTRUCTIONS:
1. Read the current memory file and the recent conversation
2. Update ONLY the sections that have new relevant information
3. Preserve existing content that is still accurate
4. Keep each section under {$this->getMaxTokensPerSection()} tokens
5. Keep the total file under {$this->getMaxTotalTokens()} tokens
6. Use bullet points for clarity
7. Include specific file paths, function names, and code snippets where relevant
8. For the Worklog section, append new entries chronologically
9. Convert relative dates/times to absolute where possible
10. Do NOT add speculative or uncertain information

Output the COMPLETE updated memory file (all sections, not just changed ones).
Preserve the exact section header format (# Section Name).
PROMPT;
    }

    private function formatMessagesForExtraction(array $messages): string
    {
        $formatted = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content);
            }
            // Truncate very long messages
            if (strlen($content) > 5000) {
                $content = substr($content, 0, 5000) . '... [truncated]';
            }
            $formatted[] = ucfirst($role) . ": {$content}";
        }
        return implode("\n\n", $formatted);
    }

    private function getMessagesSinceLastExtraction(array $messages): array
    {
        if ($this->lastMemoryMessageId === null) {
            return $messages;
        }

        $found = false;
        $result = [];
        foreach ($messages as $message) {
            $id = $message['id'] ?? $message['uuid'] ?? null;
            if ($id === $this->lastMemoryMessageId) {
                $found = true;
                continue;
            }
            if ($found) {
                $result[] = $message;
            }
        }

        return $found ? $result : $messages;
    }

    private function getLastMessageId(array $messages): ?string
    {
        if (empty($messages)) {
            return null;
        }
        $last = end($messages);
        return $last['id'] ?? $last['uuid'] ?? null;
    }

    private function estimateTokenCount(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content);
            }
            $total += $this->tokenEstimator->estimateTokens(is_string($content) ? $content : '');
        }
        return $total;
    }

    private function readMemoryFile(string $path): string
    {
        if (!file_exists($path)) {
            return '';
        }
        return file_get_contents($path) ?: '';
    }

    private function writeMemoryFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    private function getMaxTokensPerSection(): int
    {
        return self::MAX_TOKENS_PER_SECTION;
    }

    private function getMaxTotalTokens(): int
    {
        return self::MAX_TOTAL_TOKENS;
    }

    /**
     * Reset state (for testing).
     */
    public function reset(): void
    {
        $this->initialized = false;
        $this->tokensAtLastExtraction = 0;
        $this->toolCallsSinceExtraction = 0;
        $this->lastMemoryMessageId = null;
        $this->extractionInProgress = false;
    }
}
