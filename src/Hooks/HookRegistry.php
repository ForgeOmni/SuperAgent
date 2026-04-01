<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HookRegistry
{
    /**
     * @var Collection<string, Collection<HookMatcher>>
     */
    private Collection $hooks;
    
    /**
     * @var Collection<string, bool> Track which hooks have been executed (for once=true)
     */
    private Collection $executedHooks;
    
    /**
     * @var AsyncHookManager
     */
    private AsyncHookManager $asyncManager;
    
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->hooks = collect();
        $this->executedHooks = collect();
        $this->asyncManager = new AsyncHookManager();
    }
    
    /**
     * Register a hook for a specific event
     */
    public function register(HookEvent $event, HookMatcher $matcher): void
    {
        $eventKey = $event->value;
        
        if (!$this->hooks->has($eventKey)) {
            $this->hooks[$eventKey] = collect();
        }
        
        $this->hooks[$eventKey]->push($matcher);
        
        $this->logger->debug("Registered hook for event {$eventKey}", [
            'matcher' => $matcher->matcher,
            'plugin' => $matcher->pluginName,
            'hook_count' => count($matcher->getHooks()),
        ]);
    }
    
    /**
     * Execute all hooks for a given event
     */
    public function executeHooks(HookEvent $event, HookInput $input): HookResult
    {
        $eventKey = $event->value;
        
        if (!$this->hooks->has($eventKey)) {
            return HookResult::continue();
        }
        
        $results = [];
        $toolName = $input->additionalData['tool_name'] ?? null;
        $toolInput = $input->additionalData['tool_input'] ?? [];
        
        foreach ($this->hooks[$eventKey] as $matcher) {
            if (!$matcher->matches($toolName, $toolInput)) {
                continue;
            }
            
            foreach ($matcher->getHooks() as $hook) {
                $hookId = $this->getHookId($hook, $matcher);
                
                // Check if hook was already executed (for once=true)
                if ($hook->isOnce() && $this->executedHooks->get($hookId, false)) {
                    $this->logger->debug("Skipping once-only hook", ['hook_id' => $hookId]);
                    continue;
                }
                
                $this->logger->debug("Executing hook", [
                    'event' => $eventKey,
                    'hook_type' => $hook->getType()->value,
                    'async' => $hook->isAsync(),
                    'condition' => $hook->getCondition(),
                ]);
                
                if ($hook->isAsync()) {
                    // Handle async hooks
                    $this->asyncManager->startAsyncHook($hook, $input);
                    $results[] = HookResult::continue('Hook started in background');
                } else {
                    // Execute synchronous hook
                    $result = $hook->execute($input);
                    $results[] = $result;
                    
                    if ($hook->isOnce() && $result->continue) {
                        $this->executedHooks[$hookId] = true;
                    }
                    
                    // If a hook says stop, stop processing
                    if (!$result->continue) {
                        $this->logger->info("Hook stopped execution", [
                            'event' => $eventKey,
                            'stop_reason' => $result->stopReason,
                        ]);
                        break;
                    }
                }
            }
        }
        
        // Merge all results
        $merged = HookResult::merge($results);
        
        $this->logger->debug("Hook execution complete", [
            'event' => $eventKey,
            'continue' => $merged->continue,
            'hooks_executed' => count($results),
        ]);
        
        return $merged;
    }
    
    /**
     * Clear all hooks
     */
    public function clear(): void
    {
        $this->hooks = collect();
        $this->executedHooks = collect();
        $this->asyncManager->stopAll();
        
        $this->logger->info("Cleared all hooks");
    }
    
    /**
     * Clear hooks for a specific event
     */
    public function clearEvent(HookEvent $event): void
    {
        $eventKey = $event->value;
        
        if ($this->hooks->has($eventKey)) {
            $this->hooks->forget($eventKey);
            $this->logger->info("Cleared hooks for event {$eventKey}");
        }
    }
    
    /**
     * Load hooks from configuration
     */
    public function loadFromConfig(array $config, ?string $pluginName = null): void
    {
        foreach ($config as $eventKey => $matchers) {
            try {
                $event = HookEvent::from($eventKey);
                
                foreach ($matchers as $matcherConfig) {
                    $matcher = HookMatcher::fromConfig($matcherConfig, $pluginName);
                    $this->register($event, $matcher);
                }
            } catch (\ValueError $e) {
                $this->logger->warning("Unknown hook event: {$eventKey}");
            }
        }
    }
    
    /**
     * Get statistics about registered hooks
     */
    public function getStatistics(): array
    {
        $stats = [];
        
        foreach ($this->hooks as $eventKey => $matchers) {
            $hookCount = 0;
            $types = [];
            
            foreach ($matchers as $matcher) {
                foreach ($matcher->getHooks() as $hook) {
                    $hookCount++;
                    $types[] = $hook->getType()->value;
                }
            }
            
            $stats[$eventKey] = [
                'matcher_count' => $matchers->count(),
                'hook_count' => $hookCount,
                'types' => array_unique($types),
            ];
        }
        
        return [
            'events' => $stats,
            'async_hooks' => $this->asyncManager->getRunningCount(),
            'executed_once_hooks' => $this->executedHooks->count(),
        ];
    }
    
    /**
     * Get the async hook manager
     */
    public function getAsyncManager(): AsyncHookManager
    {
        return $this->asyncManager;
    }
    
    private function getHookId(HookInterface $hook, HookMatcher $matcher): string
    {
        return md5(serialize([
            $hook->getType()->value,
            $hook->getCondition(),
            $matcher->matcher,
            $matcher->pluginName,
        ]));
    }
}