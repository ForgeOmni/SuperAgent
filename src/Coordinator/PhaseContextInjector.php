<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use SuperAgent\Swarm\AgentSpawnConfig;

/**
 * Injects prior phase results into agent prompts for cross-phase context sharing.
 *
 * When phase B depends on phase A, agents in B need to know what A discovered.
 * Without injection, each agent starts with zero context and may re-discover
 * information, wasting tokens. This class builds a structured summary of prior
 * phase outputs and prepends it to each agent's system prompt.
 *
 * Token budget:
 *   - maxSummaryTokens: per-phase summary limit (estimated at ~4 chars/token)
 *   - maxTotalTokens: total injection cap across all prior phases
 */
class PhaseContextInjector
{
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private int $maxSummaryTokens = 2000,
        private int $maxTotalTokens = 8000,
        private string $strategy = 'summary', // 'summary' | 'full'
    ) {}

    /**
     * Build the context string from prior phase results.
     *
     * @param array<string, PhaseResult> $priorResults
     */
    public function buildContext(array $priorResults): string
    {
        if (empty($priorResults)) {
            return '';
        }

        $sections = [];
        $totalChars = 0;
        $maxTotalChars = $this->maxTotalTokens * self::CHARS_PER_TOKEN;

        foreach ($priorResults as $phaseName => $result) {
            if ($totalChars >= $maxTotalChars) {
                break;
            }

            $section = $this->summarizePhaseResult($phaseName, $result);
            if ($section === '') {
                continue;
            }

            $remainingChars = $maxTotalChars - $totalChars;
            if (mb_strlen($section) > $remainingChars) {
                $section = $this->truncate($section, $remainingChars);
            }

            $sections[] = $section;
            $totalChars += mb_strlen($section);
        }

        if (empty($sections)) {
            return '';
        }

        return "<prior-phase-results>\n"
            . implode("\n\n", $sections)
            . "\n</prior-phase-results>";
    }

    /**
     * Build a summary for a single phase result.
     */
    private function summarizePhaseResult(string $phaseName, PhaseResult $result): string
    {
        $status = $result->getStatus()->value;
        $agentCount = $result->getAgentCount();
        $header = "### Phase: {$phaseName} ({$status}, {$agentCount} agent" . ($agentCount !== 1 ? 's' : '') . ')';

        if (!$result->isSuccessful()) {
            $error = $result->getError() ?? 'Unknown error';
            return "{$header}\nFailed: {$error}";
        }

        $agentOutputs = [];
        $maxPerPhaseChars = $this->maxSummaryTokens * self::CHARS_PER_TOKEN;
        $usedChars = mb_strlen($header) + 1; // +1 for newline

        foreach ($result->getAgentResults() as $agentName => $agentResult) {
            $text = $agentResult->text();
            if ($text === '') {
                continue;
            }

            if ($this->strategy === 'summary') {
                $text = $this->extractSummary($text);
            }

            $entry = "[{$agentName}] {$text}";

            $remainingChars = $maxPerPhaseChars - $usedChars;
            if ($remainingChars <= 0) {
                $agentOutputs[] = "[...truncated]";
                break;
            }

            if (mb_strlen($entry) > $remainingChars) {
                $entry = $this->truncate($entry, $remainingChars);
            }

            $agentOutputs[] = $entry;
            $usedChars += mb_strlen($entry) + 1;
        }

        if (empty($agentOutputs)) {
            return "{$header}\n(no output)";
        }

        return $header . "\n" . implode("\n", $agentOutputs);
    }

    /**
     * Extract a concise summary from full agent output.
     *
     * Takes the first meaningful paragraph or up to ~500 chars.
     */
    private function extractSummary(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= 500) {
            return $text;
        }

        // Try to find a natural break point
        $cutoff = 500;
        $breakPoints = ["\n\n", ".\n", '. ', ";\n"];
        foreach ($breakPoints as $bp) {
            $pos = mb_strpos($text, $bp, (int) ($cutoff * 0.6));
            if ($pos !== false && $pos <= $cutoff * 1.2) {
                return mb_substr($text, 0, $pos + mb_strlen($bp)) . '...';
            }
        }

        return mb_substr($text, 0, $cutoff) . '...';
    }

    /**
     * Truncate text to a character limit at a word boundary.
     */
    private function truncate(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $maxChars * 0.7) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '...';
    }

    /**
     * Inject prior phase context into an agent's spawn config.
     *
     * @param array<string, PhaseResult> $priorResults
     */
    public function injectIntoConfig(AgentSpawnConfig $config, array $priorResults): AgentSpawnConfig
    {
        if (empty($priorResults)) {
            return $config;
        }

        $context = $this->buildContext($priorResults);
        if ($context === '') {
            return $config;
        }

        $existingPrompt = $config->systemPrompt ?? '';
        $newSystemPrompt = $existingPrompt !== ''
            ? $existingPrompt . "\n\n" . $context
            : $context;

        return new AgentSpawnConfig(
            name: $config->name,
            prompt: $config->prompt,
            teamName: $config->teamName,
            model: $config->model,
            systemPrompt: $newSystemPrompt,
            permissionMode: $config->permissionMode,
            backend: $config->backend,
            isolation: $config->isolation,
            runInBackground: $config->runInBackground,
            allowedTools: $config->allowedTools,
            deniedTools: $config->deniedTools,
            workingDirectory: $config->workingDirectory,
            environment: $config->environment,
            color: $config->color,
            planModeRequired: $config->planModeRequired,
            readOnly: $config->readOnly,
            forkContext: $config->forkContext,
            providerConfig: $config->providerConfig,
        );
    }

    public function getMaxSummaryTokens(): int
    {
        return $this->maxSummaryTokens;
    }

    public function getMaxTotalTokens(): int
    {
        return $this->maxTotalTokens;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }
}
