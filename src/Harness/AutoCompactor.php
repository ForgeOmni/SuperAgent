<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Context\ContextManager;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;

/**
 * Two-tier auto-compaction that can be plugged into the agentic loop.
 *
 * Tier 1 — Micro-compact: clear old tool result content (no LLM call)
 * Tier 2 — Full compact: delegate to ContextManager (LLM-based summary)
 *
 * Call `maybeCompact()` at the start of each loop turn. It checks token
 * counts, tries the cheapest tier first, and emits CompactionEvents.
 *
 * Designed as a standalone composable — no dependency on QueryEngine.
 * Pass the messages array by reference and it compacts in-place.
 */
class AutoCompactor
{
    private TokenEstimator $estimator;
    private ?ContextManager $contextManager;
    private ?StreamEventEmitter $emitter;

    private bool $enabled;
    private string $model;

    /** Number of recent tool results to preserve (tier 1) */
    private int $preserveRecentResults;

    /** Maximum length of old tool result content before truncation (tier 1) */
    private int $truncateLength;

    /** Consecutive failure counter */
    private int $failures = 0;
    private int $maxFailures;

    /** Total tokens saved across all compactions in this session */
    private int $totalTokensSaved = 0;

    public function __construct(
        ?TokenEstimator $estimator = null,
        ?ContextManager $contextManager = null,
        ?StreamEventEmitter $emitter = null,
        bool $enabled = true,
        string $model = 'claude-sonnet-4-6',
        int $preserveRecentResults = 5,
        int $truncateLength = 200,
        int $maxFailures = 3,
    ) {
        $this->estimator = $estimator ?? new TokenEstimator();
        $this->contextManager = $contextManager;
        $this->emitter = $emitter;
        $this->enabled = $enabled;
        $this->model = $model;
        $this->preserveRecentResults = $preserveRecentResults;
        $this->truncateLength = $truncateLength;
        $this->maxFailures = $maxFailures;
    }

    /**
     * Create from config, with optional parameter overrides.
     *
     * Priority: $overrides > config > defaults.
     *
     * @param array $overrides  Keys: enabled, preserve_recent_results, truncate_length, max_failures
     */
    public static function fromConfig(
        string $model = 'claude-sonnet-4-6',
        ?ContextManager $contextManager = null,
        ?StreamEventEmitter $emitter = null,
        array $overrides = [],
    ): self {
        $config = self::resolveConfig();

        return new self(
            contextManager: $contextManager,
            emitter: $emitter,
            enabled: (bool) ($overrides['enabled'] ?? $config['enabled'] ?? true),
            model: $model,
            preserveRecentResults: (int) ($overrides['preserve_recent_results'] ?? $config['preserve_recent_results'] ?? 5),
            truncateLength: (int) ($overrides['truncate_length'] ?? $config['truncate_length'] ?? 200),
            maxFailures: (int) ($overrides['max_failures'] ?? $config['max_failures'] ?? 3),
        );
    }

    /**
     * Check and compact messages if the token count exceeds threshold.
     *
     * @param  Message[] $messages  The conversation messages (modified in-place)
     * @return bool  True if compaction occurred
     */
    public function maybeCompact(array &$messages): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->failures >= $this->maxFailures) {
            return false;
        }

        if (!$this->shouldCompact($messages)) {
            return false;
        }

        // Tier 1: micro-compact (no LLM call)
        $saved = $this->microCompact($messages);
        if ($saved > 0) {
            $this->totalTokensSaved += $saved;
            $this->failures = 0;
            $this->emitter?->emit(new CompactionEvent(
                tier: 'micro',
                tokensSaved: $saved,
                strategy: 'tool_result_truncation',
            ));

            // Check if micro-compact was sufficient
            if (!$this->shouldCompact($messages)) {
                return true;
            }
        }

        // Tier 2: full compact via ContextManager (LLM-based)
        if ($this->contextManager !== null) {
            try {
                $beforeTokens = $this->estimateTokens($messages);
                $result = $this->contextManager->autoCompact($this->model);

                if ($result) {
                    $afterTokens = $this->estimateTokens($messages);
                    $fullSaved = max(0, $beforeTokens - $afterTokens);
                    $this->totalTokensSaved += $fullSaved;
                    $this->failures = 0;
                    $this->emitter?->emit(new CompactionEvent(
                        tier: 'full',
                        tokensSaved: $fullSaved,
                        strategy: 'llm_summary',
                    ));
                    return true;
                }
            } catch (\Throwable $e) {
                $this->failures++;
                $this->emitter?->emit(new ErrorEvent(
                    message: "Full compaction failed: {$e->getMessage()}",
                    recoverable: true,
                    code: 'compaction_failed',
                ));
            }
        }

        // If only micro-compact ran and saved tokens, still count as success
        return $saved > 0;
    }

    /**
     * Tier 1 — Micro-compact: truncate old tool result content.
     *
     * Walks messages from oldest to newest. Preserves the most recent
     * N tool result messages untouched. Older results are truncated.
     *
     * @param  Message[] $messages  Modified in-place
     * @return int  Estimated tokens saved
     */
    public function microCompact(array &$messages): int
    {
        // Find tool result message indices
        $toolResultIndices = [];
        foreach ($messages as $i => $msg) {
            if ($msg instanceof ToolResultMessage) {
                $toolResultIndices[] = $i;
            }
        }

        if (count($toolResultIndices) <= $this->preserveRecentResults) {
            return 0;
        }

        // Indices to compact (oldest, excluding the most recent N)
        $toCompact = array_slice(
            $toolResultIndices,
            0,
            count($toolResultIndices) - $this->preserveRecentResults,
        );

        $saved = 0;

        foreach ($toCompact as $i) {
            $msg = $messages[$i];

            // Extract full content from all blocks
            $fullContent = $this->extractContent($msg);
            $originalLength = strlen($fullContent);

            if ($originalLength <= $this->truncateLength) {
                continue;
            }

            // Truncate and replace
            $truncated = mb_substr($fullContent, 0, $this->truncateLength)
                . "\n[...content cleared by auto-compact...]";

            $messages[$i] = ToolResultMessage::fromResults([
                [
                    'tool_use_id' => $this->extractToolUseId($msg) ?? 'compacted',
                    'content' => $truncated,
                    'is_error' => false,
                ],
            ]);

            // Rough token savings estimate
            $saved += (int) (($originalLength - strlen($truncated)) / 4);
        }

        return $saved;
    }

    // ── Query helpers ─────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function getTotalTokensSaved(): int
    {
        return $this->totalTokensSaved;
    }

    public function getFailureCount(): int
    {
        return $this->failures;
    }

    public function resetFailures(): void
    {
        $this->failures = 0;
    }

    // ── Internal ──────────────────────────────────────────────────

    private function shouldCompact(array $messages): bool
    {
        return $this->estimator->shouldAutoCompact(
            $this->messagesToArrays($messages),
            $this->model,
        );
    }

    private function estimateTokens(array $messages): int
    {
        return $this->estimator->estimateMessagesTokens(
            $this->messagesToArrays($messages),
        );
    }

    private function messagesToArrays(array $messages): array
    {
        return array_map(
            fn($m) => $m instanceof Message ? $m->toArray() : $m,
            $messages,
        );
    }

    private function extractContent(Message $msg): string
    {
        if ($msg instanceof ToolResultMessage) {
            $parts = [];
            foreach ($msg->content as $block) {
                if ($block->content !== null) {
                    $parts[] = $block->content;
                }
            }
            return implode("\n", $parts);
        }

        $arr = $msg->toArray();
        $content = $arr['content'] ?? '';
        return is_string($content) ? $content : json_encode($content);
    }

    private function extractToolUseId(Message $msg): ?string
    {
        $arr = $msg->toArray();
        foreach (($arr['content'] ?? []) as $block) {
            if (is_array($block) && isset($block['tool_use_id'])) {
                return $block['tool_use_id'];
            }
        }
        return null;
    }

    private static function resolveConfig(): array
    {
        $defaults = [
            'enabled' => true,
            'preserve_recent_results' => 5,
            'truncate_length' => 200,
            'max_failures' => 3,
        ];

        try {
            if (function_exists('config')) {
                $config = config('superagent.harness.auto_compact', []);
                return array_replace($defaults, is_array($config) ? $config : []);
            }
        } catch (\Throwable $e) {
            // No Laravel
        }

        return $defaults;
    }
}
