<?php

declare(strict_types=1);

namespace SuperAgent\Context;

class CompressionConfig
{
    /**
     * Default configuration values
     */
    private const DEFAULT_MIN_TOKENS = 10_000;
    private const DEFAULT_MAX_TOKENS = 40_000;
    private const DEFAULT_MIN_MESSAGES = 5;
    private const DEFAULT_KEEP_RECENT = 10;
    private const DEFAULT_MAX_RETRIES = 3;

    /**
     * Pi-aligned token budgets (pi.dev/docs/latest/compaction).
     * - reserveTokens: headroom kept inside contextWindow when deciding
     *   whether to auto-compact. Auto fires when
     *   `contextTokens > contextWindow - reserveTokens`.
     * - keepRecentTokens: target token budget for messages preserved
     *   verbatim after compaction. When the recent slice exceeds this
     *   even after dropping older history, the ConversationCompressor
     *   trySplitTurn() path activates and cuts mid-turn.
     */
    private const DEFAULT_RESERVE_TOKENS     = 16_384;
    private const DEFAULT_KEEP_RECENT_TOKENS = 20_000;
    
    /**
     * Compactable tool names
     */
    private const DEFAULT_COMPACTABLE_TOOLS = [
        'Read',
        'Bash',
        'Grep',
        'Glob',
        'WebSearch',
        'WebFetch',
        'Edit',
        'Write',
    ];
    
    public function __construct(
        public readonly int $minTokens = self::DEFAULT_MIN_TOKENS,
        public readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
        public readonly int $minMessages = self::DEFAULT_MIN_MESSAGES,
        public readonly int $keepRecentMessages = self::DEFAULT_KEEP_RECENT,
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public readonly array $compactableTools = self::DEFAULT_COMPACTABLE_TOOLS,
        public readonly bool $enableMicroCompact = true,
        public readonly bool $enableSessionMemory = false,
        public readonly bool $enableAutoCompact = true,
        public readonly bool $enableCacheEditing = false,
        public readonly ?string $summaryModel = null,
        // Pi-aligned token budgets (see constants above).
        public readonly int $reserveTokens = self::DEFAULT_RESERVE_TOKENS,
        public readonly int $keepRecentTokens = self::DEFAULT_KEEP_RECENT_TOKENS,
        public readonly bool $enableSplitTurn = true,
    ) {}
    
    /**
     * Create config from array (e.g., from config file).
     *
     * When experimental feature flags are enabled, the corresponding
     * compression features default to ON (can still be overridden).
     */
    public static function fromArray(array $config): self
    {
        $exp = \SuperAgent\Config\ExperimentalFeatures::class;

        return new self(
            minTokens: $config['min_tokens'] ?? self::DEFAULT_MIN_TOKENS,
            maxTokens: $config['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
            minMessages: $config['min_messages'] ?? self::DEFAULT_MIN_MESSAGES,
            keepRecentMessages: $config['keep_recent_messages'] ?? self::DEFAULT_KEEP_RECENT,
            maxRetries: $config['max_retries'] ?? self::DEFAULT_MAX_RETRIES,
            compactableTools: $config['compactable_tools'] ?? self::DEFAULT_COMPACTABLE_TOOLS,
            enableMicroCompact: $config['enable_micro_compact'] ?? $exp::enabled('cached_microcompact'),
            enableSessionMemory: $config['enable_session_memory'] ?? $exp::enabled('extract_memories'),
            enableAutoCompact: $config['enable_auto_compact'] ?? $exp::enabled('compaction_reminders'),
            enableCacheEditing: $config['enable_cache_editing'] ?? false,
            summaryModel: $config['summary_model'] ?? null,
            reserveTokens: $config['reserve_tokens'] ?? self::DEFAULT_RESERVE_TOKENS,
            keepRecentTokens: $config['keep_recent_tokens'] ?? self::DEFAULT_KEEP_RECENT_TOKENS,
            enableSplitTurn: $config['enable_split_turn'] ?? true,
        );
    }
    
    /**
     * Create config with overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            minTokens: $overrides['minTokens'] ?? $this->minTokens,
            maxTokens: $overrides['maxTokens'] ?? $this->maxTokens,
            minMessages: $overrides['minMessages'] ?? $this->minMessages,
            keepRecentMessages: $overrides['keepRecentMessages'] ?? $this->keepRecentMessages,
            maxRetries: $overrides['maxRetries'] ?? $this->maxRetries,
            compactableTools: $overrides['compactableTools'] ?? $this->compactableTools,
            enableMicroCompact: $overrides['enableMicroCompact'] ?? $this->enableMicroCompact,
            enableSessionMemory: $overrides['enableSessionMemory'] ?? $this->enableSessionMemory,
            enableAutoCompact: $overrides['enableAutoCompact'] ?? $this->enableAutoCompact,
            enableCacheEditing: $overrides['enableCacheEditing'] ?? $this->enableCacheEditing,
            summaryModel: $overrides['summaryModel'] ?? $this->summaryModel,
            reserveTokens: $overrides['reserveTokens'] ?? $this->reserveTokens,
            keepRecentTokens: $overrides['keepRecentTokens'] ?? $this->keepRecentTokens,
            enableSplitTurn: $overrides['enableSplitTurn'] ?? $this->enableSplitTurn,
        );
    }
    
    /**
     * Check if a tool is compactable
     */
    public function isCompactableTool(string $toolName): bool
    {
        return in_array($toolName, $this->compactableTools, true);
    }
    
    /**
     * Validate configuration
     */
    public function validate(): array
    {
        $errors = [];
        
        if ($this->minTokens <= 0) {
            $errors[] = 'min_tokens must be positive';
        }
        
        if ($this->maxTokens <= $this->minTokens) {
            $errors[] = 'max_tokens must be greater than min_tokens';
        }
        
        if ($this->minMessages <= 0) {
            $errors[] = 'min_messages must be positive';
        }
        
        if ($this->keepRecentMessages < 0) {
            $errors[] = 'keep_recent_messages must be non-negative';
        }
        
        if ($this->maxRetries <= 0) {
            $errors[] = 'max_retries must be positive';
        }
        
        return $errors;
    }
}