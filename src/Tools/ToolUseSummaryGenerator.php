<?php

declare(strict_types=1);

namespace SuperAgent\Tools;

use SuperAgent\LLM\ProviderInterface;

/**
 * Tool use summary generator ported from Claude Code.
 *
 * After each batch of tool executions, generates a brief, human-readable
 * summary using a lightweight model (e.g., Haiku). Summaries are
 * git-commit-subject style: past tense verb + distinctive noun, ~30 chars.
 *
 * Examples: "Searched in auth/", "Fixed NPE in UserService", "Created signup endpoint"
 */
class ToolUseSummaryGenerator
{
    /** Maximum characters per tool input/output in the summary prompt */
    private const MAX_TOOL_CONTENT_CHARS = 300;

    /** Model to use for summaries (lightweight/fast) */
    private string $summaryModel;

    public function __construct(
        private ProviderInterface $provider,
        string $summaryModel = 'claude-haiku-4-5-20251001',
    ) {
        $this->summaryModel = $summaryModel;
    }

    /**
     * Generate a brief summary for a batch of tool executions.
     *
     * @param array $toolExecutions Array of ['name' => string, 'input' => array, 'output' => string, 'is_error' => bool]
     * @param string|null $assistantContext Last assistant text (for context)
     * @return string|null Summary text or null on failure
     */
    public function generate(array $toolExecutions, ?string $assistantContext = null): ?string
    {
        if (empty($toolExecutions)) {
            return null;
        }

        $prompt = $this->buildPrompt($toolExecutions, $assistantContext);

        try {
            $response = $this->provider->generateResponse(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                options: [
                    'model' => $this->summaryModel,
                    'max_tokens' => 100,
                    'temperature' => 0.0,
                ],
            );

            $summary = trim($response->content ?? '');

            // Truncate to ~60 chars for display
            if (strlen($summary) > 60) {
                $summary = substr($summary, 0, 57) . '...';
            }

            return $summary ?: null;
        } catch (\Throwable $e) {
            // Non-critical: failures don't block the query
            return null;
        }
    }

    /**
     * Create a summary message record.
     *
     * @param string   $summary             The summary text
     * @param string[] $precedingToolUseIds  IDs of the tool_use blocks this summarizes
     */
    public static function createSummaryMessage(string $summary, array $precedingToolUseIds): array
    {
        return [
            'type' => 'tool_use_summary',
            'summary' => $summary,
            'preceding_tool_use_ids' => $precedingToolUseIds,
            'uuid' => self::uuid(),
            'timestamp' => date('c'),
        ];
    }

    private function buildPrompt(array $toolExecutions, ?string $assistantContext): string
    {
        $toolDescriptions = [];
        foreach ($toolExecutions as $exec) {
            $name = $exec['name'] ?? 'unknown';
            $input = $this->truncate(json_encode($exec['input'] ?? []), self::MAX_TOOL_CONTENT_CHARS);
            $output = $this->truncate($exec['output'] ?? '', self::MAX_TOOL_CONTENT_CHARS);
            $status = ($exec['is_error'] ?? false) ? ' [ERROR]' : '';

            $toolDescriptions[] = "- {$name}{$status}: input={$input} output={$output}";
        }

        $toolsText = implode("\n", $toolDescriptions);
        $contextNote = $assistantContext
            ? "\n\nContext from assistant: " . $this->truncate($assistantContext, 200)
            : '';

        return <<<PROMPT
Write a short summary label for these tool executions. The label should be like a git commit subject: past tense verb + distinctive noun. Keep it under 40 characters.

Examples of good labels:
- "Searched in auth/"
- "Fixed NPE in UserService"
- "Read package.json config"
- "Ran test suite (3 failures)"
- "Created signup endpoint"
- "Edited validation logic"

Tools executed:
{$toolsText}{$contextNote}

Respond with ONLY the summary label, nothing else.
PROMPT;
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength - 3) . '...';
    }

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        );
    }
}
