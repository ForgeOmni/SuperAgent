<?php

namespace SuperAgent\IncrementalContext;

use SuperAgent\Messages\Message;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Messages\ToolResultMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class IncrementalContextManager
{
    private array $baseContext = [];
    private array $currentContext = [];
    private array $checkpoints = [];
    private ?string $lastCheckpointId = null;
    private ContextDiffer $differ;
    private ContextCompressor $compressor;
    private CheckpointManager $checkpointManager;
    private LoggerInterface $logger;
    private array $config;
    private array $statistics = [
        'total_tokens_saved' => 0,
        'compression_ratio' => 0,
        'delta_count' => 0,
    ];
    
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();
        $this->differ = new ContextDiffer($config);
        $this->compressor = new ContextCompressor($config);
        $this->checkpointManager = new CheckpointManager($config);
    }
    
    /**
     * Initialize with full context
     */
    public function initialize(array $messages): void
    {
        $this->baseContext = $messages;
        $this->currentContext = $messages;
        $this->lastCheckpointId = $this->createCheckpoint('initial');
        
        $this->logger->info('Incremental context initialized', [
            'message_count' => count($messages),
            'checkpoint' => $this->lastCheckpointId,
        ]);
    }
    
    /**
     * Get delta (changes since last checkpoint)
     */
    public function getDelta(?string $fromCheckpoint = null): ContextDelta
    {
        $fromCheckpoint = $fromCheckpoint ?? $this->lastCheckpointId;
        
        if (!$fromCheckpoint) {
            // No checkpoint, return full context
            return new ContextDelta(
                added: $this->currentContext,
                modified: [],
                removed: [],
                checkpoint: $this->createCheckpoint('full')
            );
        }
        
        // Get checkpoint data
        $checkpoint = $this->checkpointManager->get($fromCheckpoint);
        if (!$checkpoint) {
            throw new \RuntimeException("Checkpoint not found: {$fromCheckpoint}");
        }
        
        // Calculate differences
        $delta = $this->differ->diff(
            $checkpoint->getContext(),
            $this->currentContext
        );
        
        // Apply compression if enabled
        if ($this->config['compress_delta']) {
            $delta = $this->compressor->compressDelta($delta);
        }
        
        // Update statistics
        $this->updateStatistics($delta);
        
        // Create new checkpoint
        $newCheckpoint = $this->createCheckpoint('delta');
        $delta->setCheckpoint($newCheckpoint);
        
        $this->logger->debug('Delta calculated', [
            'from' => $fromCheckpoint,
            'to' => $newCheckpoint,
            'added' => count($delta->getAdded()),
            'modified' => count($delta->getModified()),
            'removed' => count($delta->getRemoved()),
        ]);
        
        return $delta;
    }
    
    /**
     * Apply delta to reconstruct full context
     */
    public function applyDelta(ContextDelta $delta, ?array $baseContext = null): array
    {
        $base = $baseContext ?? $this->baseContext;
        
        // Remove deleted items
        foreach ($delta->getRemoved() as $index) {
            unset($base[$index]);
        }
        
        // Apply modifications
        foreach ($delta->getModified() as $index => $content) {
            $base[$index] = $content;
        }
        
        // Add new items
        foreach ($delta->getAdded() as $item) {
            $base[] = $item;
        }
        
        // Re-index array
        $base = array_values($base);
        
        $this->logger->debug('Delta applied', [
            'result_count' => count($base),
        ]);
        
        return $base;
    }
    
    /**
     * Add message to context
     */
    public function addMessage(Message $message): void
    {
        $this->currentContext[] = $message;
        
        // Auto-compress if needed
        if ($this->shouldCompress()) {
            $this->compress();
        }
        
        // Auto-checkpoint if needed
        if ($this->shouldCheckpoint()) {
            $this->createCheckpoint('auto');
        }
    }
    
    /**
     * Update existing message
     */
    public function updateMessage(int $index, Message $message): void
    {
        if (isset($this->currentContext[$index])) {
            $this->currentContext[$index] = $message;
        }
    }
    
    /**
     * Remove message from context
     */
    public function removeMessage(int $index): void
    {
        if (isset($this->currentContext[$index])) {
            unset($this->currentContext[$index]);
            $this->currentContext = array_values($this->currentContext);
        }
    }
    
    /**
     * Compress current context
     */
    public function compress(): array
    {
        $originalSize = $this->getContextSize();
        
        $this->currentContext = $this->compressor->compress($this->currentContext);
        
        $newSize = $this->getContextSize();
        $saved = $originalSize - $newSize;
        
        $this->statistics['total_tokens_saved'] += $saved;
        $this->statistics['compression_ratio'] = $newSize / max(1, $originalSize);
        
        $this->logger->info('Context compressed', [
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'saved' => $saved,
            'ratio' => round($this->statistics['compression_ratio'], 2),
        ]);
        
        return $this->currentContext;
    }
    
    /**
     * Create checkpoint
     */
    public function createCheckpoint(string $type = 'manual'): string
    {
        $checkpoint = $this->checkpointManager->create(
            $this->currentContext,
            $type,
            $this->statistics
        );
        
        $this->checkpoints[$checkpoint->getId()] = $checkpoint;
        $this->lastCheckpointId = $checkpoint->getId();
        
        // Clean old checkpoints
        $this->cleanOldCheckpoints();
        
        return $checkpoint->getId();
    }
    
    /**
     * Restore from checkpoint
     */
    public function restoreCheckpoint(string $checkpointId): void
    {
        $checkpoint = $this->checkpointManager->get($checkpointId);
        if (!$checkpoint) {
            throw new \RuntimeException("Checkpoint not found: {$checkpointId}");
        }
        
        $this->currentContext = $checkpoint->getContext();
        $this->lastCheckpointId = $checkpointId;
        
        $this->logger->info('Restored from checkpoint', [
            'checkpoint' => $checkpointId,
            'message_count' => count($this->currentContext),
        ]);
    }
    
    /**
     * Get context summary for efficient reference
     */
    public function getSummary(): ContextSummary
    {
        return new ContextSummary(
            messageCount: count($this->currentContext),
            totalTokens: $this->getContextSize(),
            checkpoints: array_keys($this->checkpoints),
            lastCheckpoint: $this->lastCheckpointId,
            compressionRatio: $this->statistics['compression_ratio'],
            tokensSaved: $this->statistics['total_tokens_saved']
        );
    }
    
    /**
     * Get smart context window (optimized for current task)
     */
    public function getSmartWindow(int $maxTokens): array
    {
        // Prioritize recent messages
        $window = [];
        $tokens = 0;
        
        // Always include system message if exists
        if (!empty($this->currentContext) && $this->currentContext[0] instanceof UserMessage) {
            $window[] = $this->currentContext[0];
            $tokens += $this->estimateTokens($this->currentContext[0]);
        }
        
        // Add recent messages in reverse order
        for ($i = count($this->currentContext) - 1; $i >= 1 && $tokens < $maxTokens; $i--) {
            $message = $this->currentContext[$i];
            $messageTokens = $this->estimateTokens($message);
            
            if ($tokens + $messageTokens <= $maxTokens) {
                array_unshift($window, $message);
                $tokens += $messageTokens;
            } else {
                // Try to compress message
                $compressed = $this->compressor->compressMessage($message);
                $compressedTokens = $this->estimateTokens($compressed);
                
                if ($tokens + $compressedTokens <= $maxTokens) {
                    array_unshift($window, $compressed);
                    $tokens += $compressedTokens;
                }
                break;
            }
        }
        
        return $window;
    }
    
    /**
     * Check if should auto-compress
     */
    private function shouldCompress(): bool
    {
        if (!$this->config['auto_compress']) {
            return false;
        }
        
        $size = $this->getContextSize();
        return $size > $this->config['compress_threshold'];
    }
    
    /**
     * Check if should auto-checkpoint
     */
    private function shouldCheckpoint(): bool
    {
        if (!$this->config['auto_checkpoint']) {
            return false;
        }
        
        // Check message count
        $messagesSinceCheckpoint = count($this->currentContext) - 
            count($this->checkpointManager->get($this->lastCheckpointId)?->getContext() ?? []);
        
        return $messagesSinceCheckpoint >= $this->config['checkpoint_interval'];
    }
    
    /**
     * Clean old checkpoints
     */
    private function cleanOldCheckpoints(): void
    {
        $maxCheckpoints = $this->config['max_checkpoints'];
        
        if (count($this->checkpoints) > $maxCheckpoints) {
            // Keep only recent checkpoints
            $sorted = $this->checkpoints;
            usort($sorted, fn($a, $b) => $b->getTimestamp() <=> $a->getTimestamp());
            
            $toKeep = array_slice($sorted, 0, $maxCheckpoints);
            $this->checkpoints = array_combine(
                array_map(fn($c) => $c->getId(), $toKeep),
                $toKeep
            );
            
            $this->checkpointManager->cleanup($maxCheckpoints);
        }
    }
    
    /**
     * Estimate context size in tokens
     */
    private function getContextSize(): int
    {
        $tokens = 0;
        foreach ($this->currentContext as $message) {
            $tokens += $this->estimateTokens($message);
        }
        return $tokens;
    }
    
    /**
     * Estimate tokens for a message
     */
    private function estimateTokens($message): int
    {
        // Simple estimation: ~4 characters per token
        if ($message instanceof Message) {
            if ($message instanceof ToolResultMessage) {
                $content = json_encode($message->content);
            } elseif ($message instanceof AssistantMessage) {
                $content = is_array($message->content)
                    ? json_encode($message->content)
                    : (string) $message->content;
            } else {
                $content = '';
            }
            return (int)(strlen($content) / 4);
        }

        return (int)(strlen(json_encode($message)) / 4);
    }
    
    /**
     * Update statistics
     */
    private function updateStatistics(ContextDelta $delta): void
    {
        $this->statistics['delta_count']++;
        
        // Calculate tokens saved vs sending full context
        $fullSize = $this->getContextSize();
        $deltaSize = $this->estimateTokens($delta);
        $saved = max(0, $fullSize - $deltaSize);
        
        $this->statistics['total_tokens_saved'] += $saved;
    }
    
    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'auto_compress' => true,
            'compress_threshold' => 4000, // tokens
            'compress_delta' => true,
            'auto_checkpoint' => true,
            'checkpoint_interval' => 10, // messages
            'max_checkpoints' => 10,
            'preserve_semantic_boundaries' => true,
            'compression_level' => 'balanced', // minimal, balanced, aggressive
        ];
    }
}