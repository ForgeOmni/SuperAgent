<?php

declare(strict_types=1);

namespace SuperAgent\Context;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Context\Strategies\CompressionResult;
use SuperAgent\Context\Strategies\CompressionStrategy;
use SuperAgent\Context\Strategies\ConversationCompressor;
use SuperAgent\Context\Strategies\MicroCompressor;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\LLM\ProviderInterface;

class ContextManager
{
    /**
     * @var Message[]
     */
    private array $messages = [];
    
    /**
     * @var CompressionStrategy[]
     */
    private array $strategies = [];
    
    private int $compressionFailures = 0;
    private ?string $lastCompressionError = null;
    private array $compressionHistory = [];
    
    public function __construct(
        private TokenEstimator $tokenEstimator,
        private CompressionConfig $config,
        private ?ProviderInterface $provider = null,
        private ?HookRegistry $hookRegistry = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->initializeStrategies();
    }
    
    /**
     * Initialize compression strategies
     */
    private function initializeStrategies(): void
    {
        // Add micro compressor
        $this->strategies[] = new MicroCompressor($this->tokenEstimator, $this->config);
        
        // Add conversation compressor if provider is available
        if ($this->provider !== null) {
            $this->strategies[] = new ConversationCompressor(
                $this->tokenEstimator,
                $this->config,
                $this->provider
            );
        }
        
        // Sort by priority
        usort($this->strategies, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }
    
    /**
     * Add a message to the context
     */
    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
        
        $this->logger->debug('Message added to context', [
            'role' => $message->role->value,
            'type' => $message->type->value,
            'message_count' => count($this->messages),
        ]);
    }
    
    /**
     * Add multiple messages
     */
    public function addMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->addMessage($message);
        }
    }
    
    /**
     * Get all messages
     * 
     * @return Message[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
    
    /**
     * Get messages as arrays for API submission
     */
    public function getMessagesForApi(): array
    {
        return array_map(fn($m) => $m->toArray(), $this->messages);
    }
    
    /**
     * Get current token count
     */
    public function getTokenCount(): int
    {
        return $this->tokenEstimator->estimateMessagesTokens($this->getMessagesForApi());
    }
    
    /**
     * Check if auto-compact should trigger
     */
    public function shouldAutoCompact(string $model): bool
    {
        if (!$this->config->enableAutoCompact) {
            return false;
        }
        
        if ($this->compressionFailures >= $this->config->maxRetries) {
            $this->logger->warning('Auto-compact disabled due to repeated failures', [
                'failures' => $this->compressionFailures,
            ]);
            return false;
        }
        
        return $this->tokenEstimator->shouldAutoCompact($this->getMessagesForApi(), $model);
    }
    
    /**
     * Perform automatic compaction
     */
    public function autoCompact(string $model, array $options = []): bool
    {
        $this->logger->info('Starting auto-compact', [
            'model' => $model,
            'token_count' => $this->getTokenCount(),
            'message_count' => count($this->messages),
        ]);
        
        // Fire pre-compact hook
        if ($this->hookRegistry !== null) {
            $hookInput = new HookInput(
                hookEvent: HookEvent::PRE_COMPACT,
                sessionId: $options['session_id'] ?? 'unknown',
                cwd: $options['cwd'] ?? getcwd(),
                additionalData: [
                    'token_count' => $this->getTokenCount(),
                    'message_count' => count($this->messages),
                ],
            );
            
            $hookResult = $this->hookRegistry->executeHooks(HookEvent::PRE_COMPACT, $hookInput);
            
            if (!$hookResult->continue) {
                $this->logger->warning('Pre-compact hook blocked compression', [
                    'stop_reason' => $hookResult->stopReason,
                ]);
                return false;
            }
        }
        
        // Try each strategy in order
        foreach ($this->strategies as $strategy) {
            if (!$strategy->canCompress($this->messages)) {
                continue;
            }
            
            $this->logger->debug('Trying compression strategy', [
                'strategy' => $strategy->getName(),
            ]);
            
            try {
                $result = $strategy->compress($this->messages, $options);
                
                if ($result->isSuccessful()) {
                    $this->applyCompressionResult($result);
                    
                    $this->logger->info('Compression successful', [
                        'strategy' => $strategy->getName(),
                        'tokens_saved' => $result->tokensSaved,
                        'new_token_count' => $this->getTokenCount(),
                    ]);
                    
                    // Fire post-compact hook
                    if ($this->hookRegistry !== null) {
                        $hookInput = new HookInput(
                            hookEvent: HookEvent::POST_COMPACT,
                            sessionId: $options['session_id'] ?? 'unknown',
                            cwd: $options['cwd'] ?? getcwd(),
                            additionalData: [
                                'strategy' => $strategy->getName(),
                                'tokens_saved' => $result->tokensSaved,
                                'token_count' => $this->getTokenCount(),
                            ],
                        );
                        
                        $this->hookRegistry->executeHooks(HookEvent::POST_COMPACT, $hookInput);
                    }
                    
                    // Reset failure counter on success
                    $this->compressionFailures = 0;
                    
                    // Record compression in history
                    $this->compressionHistory[] = [
                        'timestamp' => time(),
                        'strategy' => $strategy->getName(),
                        'tokens_saved' => $result->tokensSaved,
                    ];
                    
                    return true;
                }
            } catch (\Exception $e) {
                $this->logger->error('Compression strategy failed', [
                    'strategy' => $strategy->getName(),
                    'error' => $e->getMessage(),
                ]);
                
                $this->lastCompressionError = $e->getMessage();
            }
        }
        
        // All strategies failed
        $this->compressionFailures++;
        
        $this->logger->error('All compression strategies failed', [
            'failures' => $this->compressionFailures,
        ]);
        
        return false;
    }
    
    /**
     * Apply compression result to messages
     */
    private function applyCompressionResult(CompressionResult $result): void
    {
        $this->messages = $result->getAllMessages();
    }
    
    /**
     * Manually trigger compression
     */
    public function compress(array $options = []): CompressionResult
    {
        $strategy = $options['strategy'] ?? null;
        
        if ($strategy !== null) {
            // Use specific strategy
            foreach ($this->strategies as $s) {
                if ($s->getName() === $strategy) {
                    return $s->compress($this->messages, $options);
                }
            }
            
            throw new \InvalidArgumentException("Unknown strategy: {$strategy}");
        }
        
        // Try each strategy
        foreach ($this->strategies as $s) {
            if ($s->canCompress($this->messages)) {
                $result = $s->compress($this->messages, $options);
                if ($result->isSuccessful()) {
                    return $result;
                }
            }
        }
        
        // No strategy could compress
        return new CompressionResult(
            compressedMessages: [],
            preservedMessages: $this->messages,
            tokensSaved: 0,
        );
    }
    
    /**
     * Clear all messages
     */
    public function clear(): void
    {
        $this->messages = [];
        $this->compressionFailures = 0;
        $this->lastCompressionError = null;
        $this->compressionHistory = [];
    }
    
    /**
     * Get compression statistics
     */
    public function getStatistics(): array
    {
        return [
            'message_count' => count($this->messages),
            'token_count' => $this->getTokenCount(),
            'compression_failures' => $this->compressionFailures,
            'last_error' => $this->lastCompressionError,
            'compression_history' => $this->compressionHistory,
            'strategies' => array_map(fn($s) => [
                'name' => $s->getName(),
                'priority' => $s->getPriority(),
                'can_compress' => $s->canCompress($this->messages),
            ], $this->strategies),
        ];
    }
    
    /**
     * Find messages by criteria
     */
    public function findMessages(array $criteria): array
    {
        $filtered = $this->messages;
        
        if (isset($criteria['role'])) {
            $filtered = array_filter($filtered, fn($m) => $m->role === $criteria['role']);
        }
        
        if (isset($criteria['type'])) {
            $filtered = array_filter($filtered, fn($m) => $m->type === $criteria['type']);
        }
        
        if (isset($criteria['after_id'])) {
            $found = false;
            $filtered = array_filter($filtered, function($m) use ($criteria, &$found) {
                if ($found) return true;
                if ($m->id === $criteria['after_id']) {
                    $found = true;
                    return false;
                }
                return false;
            });
        }
        
        if (isset($criteria['limit'])) {
            $filtered = array_slice($filtered, 0, $criteria['limit']);
        }
        
        return array_values($filtered);
    }
    
    /**
     * Get recent messages
     */
    public function getRecentMessages(int $count): array
    {
        return array_slice($this->messages, -$count);
    }
    
    /**
     * Check if approaching token limit
     */
    public function isApproachingLimit(string $model): bool
    {
        return $this->tokenEstimator->isApproachingLimit($this->getMessagesForApi(), $model);
    }
    
    /**
     * Add a custom compression strategy
     */
    public function addStrategy(CompressionStrategy $strategy): void
    {
        $this->strategies[] = $strategy;
        usort($this->strategies, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }
}