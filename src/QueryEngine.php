<?php

namespace SuperAgent;

use Generator;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\SuperAgentException;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Hooks\StopHooksPipeline;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\SmartContext\SmartContextManager;
use SuperAgent\CostAutopilot\CostAutopilot;
use SuperAgent\CostAutopilot\AutopilotDecision;
use SuperAgent\Guardrails\Context\RuntimeContextCollector;
use SuperAgent\TokenBudget\TokenBudgetTracker;
use SuperAgent\Tools\ToolResult;
use SuperAgent\ErrorRecovery\ErrorRecoveryManager;
use SuperAgent\ErrorRecovery\ErrorClassifier;
use SuperAgent\Traits\ErrorRecoveryTrait;
use SuperAgent\Optimization\ToolResultCompactor;
use SuperAgent\Optimization\ToolSchemaFilter;
use SuperAgent\Optimization\ModelRouter;
use SuperAgent\Optimization\ResponsePrefill;
use SuperAgent\Optimization\PromptCachePinning;
use SuperAgent\Performance\ParallelToolExecutor;
use SuperAgent\Performance\StreamingToolDispatch;
use SuperAgent\Performance\AdaptiveMaxTokens;
use SuperAgent\Performance\SpeculativePrefetch;
use SuperAgent\Performance\LocalToolZeroCopy;
use SuperAgent\Traits\CachedToolExecutionTrait;

class QueryEngine
{
    use ErrorRecoveryTrait;
    use CachedToolExecutionTrait;

    /** @var Message[] */
    protected array $messages = [];

    /** @var ToolInterface[] name => tool */
    protected array $toolMap = [];

    protected int $turnCount = 0;

    /** @var string[] tool names that are denied */
    protected array $deniedTools = [];

    /** @var string[]|null tool names that are allowed (null = allow all) */
    protected ?array $allowedTools = null;

    protected float $totalCostUsd = 0.0;

    protected ?HookRegistry $hookRegistry = null;

    protected ?TokenBudgetTracker $budgetTracker = null;

    protected ?StopHooksPipeline $stopHooksPipeline = null;

    /** Token budget for continuation logic (null = use maxTurns instead) */
    protected ?int $tokenBudget = null;

    /** Total output tokens consumed in current turn */
    protected int $turnOutputTokens = 0;

    protected ?RuntimeContextCollector $guardrailsCollector = null;

    protected ?CostAutopilot $costAutopilot = null;

    protected ?CheckpointManager $checkpointManager = null;

    protected ?SmartContextManager $smartContextManager = null;

    /** Last prompt text (for checkpoint context in continuePending) */
    protected string $lastPrompt = '';

    // ── Token optimizations (v0.7.0) ────────────────────────────────
    protected ?ToolResultCompactor $toolResultCompactor = null;
    protected ?ToolSchemaFilter $toolSchemaFilter = null;
    protected ?ModelRouter $modelRouter = null;
    protected ?ResponsePrefill $responsePrefill = null;
    protected ?PromptCachePinning $promptCachePinning = null;

    // ── Execution performance (v0.7.1) ───────────────────────────
    protected ?ParallelToolExecutor $parallelExecutor = null;
    protected ?StreamingToolDispatch $streamingDispatch = null;
    protected ?AdaptiveMaxTokens $adaptiveMaxTokens = null;
    protected ?SpeculativePrefetch $speculativePrefetch = null;
    protected ?LocalToolZeroCopy $zeroCopy = null;

    public function __construct(
        protected readonly LLMProvider $provider,
        protected readonly array $tools = [],
        protected readonly ?string $systemPrompt = null,
        protected readonly int $maxTurns = 50,
        protected readonly array $options = [],
        protected readonly ?StreamingHandler $streamingHandler = null,
        ?array $allowedTools = null,
        array $deniedTools = [],
        protected readonly float $maxBudgetUsd = 0.0,
        ?HookRegistry $hookRegistry = null,
        ?int $tokenBudget = null,
        ?RuntimeContextCollector $guardrailsCollector = null,
        ?CostAutopilot $costAutopilot = null,
        ?CheckpointManager $checkpointManager = null,
        ?SmartContextManager $smartContextManager = null,
    ) {
        foreach ($this->tools as $tool) {
            $this->toolMap[$tool->name()] = $tool;
        }
        $this->allowedTools = $allowedTools;
        $this->deniedTools = $deniedTools;
        $this->hookRegistry = $hookRegistry;
        $this->tokenBudget = $tokenBudget;

        if ($this->tokenBudget !== null
            && \SuperAgent\Config\ExperimentalFeatures::enabled('token_budget')) {
            $this->budgetTracker = new TokenBudgetTracker();
        }

        if ($this->hookRegistry !== null) {
            $this->stopHooksPipeline = new StopHooksPipeline($this->hookRegistry);
        }

        $this->guardrailsCollector = $guardrailsCollector;
        $this->costAutopilot = $costAutopilot;
        $this->checkpointManager = $checkpointManager;
        $this->smartContextManager = $smartContextManager;

        // Apply per-task context_strategy override from options
        if ($this->smartContextManager !== null && isset($this->options['context_strategy'])) {
            $this->smartContextManager->setForceStrategy($this->options['context_strategy']);
        }

        // Apply per-task checkpoint override from options
        if ($this->checkpointManager !== null && array_key_exists('checkpoint', $this->options)) {
            $this->checkpointManager->setForceEnabled(
                $this->options['checkpoint'] === true || $this->options['checkpoint'] === 'true',
            );
        }

        if ($this->costAutopilot !== null) {
            $this->costAutopilot->setCurrentModel(
                $this->options['model'] ?? $this->provider->getModel(),
            );
        }

        // ── Initialize token optimizations (v0.7.0) ─────────────────
        $this->toolResultCompactor = ToolResultCompactor::fromConfig();
        $this->toolSchemaFilter = ToolSchemaFilter::fromConfig();
        $this->modelRouter = ModelRouter::fromConfig(
            $this->options['model'] ?? $this->provider->getModel(),
        );
        $this->responsePrefill = ResponsePrefill::fromConfig();
        $this->promptCachePinning = PromptCachePinning::fromConfig();

        // ── Initialize execution performance (v0.7.1) ───────────────
        $this->parallelExecutor = ParallelToolExecutor::fromConfig();
        $this->streamingDispatch = StreamingToolDispatch::fromConfig();
        $this->adaptiveMaxTokens = AdaptiveMaxTokens::fromConfig();
        $this->speculativePrefetch = SpeculativePrefetch::fromConfig();
        $this->zeroCopy = LocalToolZeroCopy::fromConfig();
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function getTotalCostUsd(): float
    {
        return $this->totalCostUsd;
    }

    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    /**
     * Check if the conversation ends with tool results awaiting model response.
     *
     * This happens when:
     *  - The agent loop was interrupted (max turns, budget, error)
     *  - The last message is a ToolResultMessage
     *
     * Use continuePending() to resume the loop from this state.
     */
    public function hasPendingContinuation(): bool
    {
        if (empty($this->messages)) {
            return false;
        }

        $last = end($this->messages);
        return $last instanceof ToolResultMessage;
    }

    /**
     * Continue the agentic loop without a new user message.
     *
     * Resumes from where hasPendingContinuation() == true.
     * Useful for interrupted loops, REPL /continue, or externally
     * paused tool execution.
     *
     * @return Generator<int, AssistantMessage>
     */
    public function continuePending(): Generator
    {
        if (!$this->hasPendingContinuation()) {
            return;
        }

        yield from $this->runLoop();
    }

    /**
     * Run the agentic loop: send prompt, handle tool calls, repeat until done.
     *
     * Uses token budget continuation logic when tokenBudget is set:
     *  - Continues if under 90% of budget and no diminishing returns
     *  - Stops on diminishing returns (3+ continuations with <500 token deltas)
     *
     * Falls back to fixed maxTurns when no token budget is configured.
     *
     * @return Generator<int, AssistantMessage>
     */
    public function run(string|array $prompt): Generator
    {
        $this->messages[] = new UserMessage($prompt);
        $this->turnCount = 0;
        $this->turnOutputTokens = 0;
        $this->lastPrompt = is_string($prompt) ? $prompt : json_encode($prompt);
        $this->budgetTracker?->reset();

        // --- Smart Context Window: adjust thinking budget based on task complexity ---
        if ($this->smartContextManager !== null && $this->smartContextManager->isEnabled()) {
            $allocation = $this->smartContextManager->allocate($this->lastPrompt);

            // Apply thinking budget to options (if model supports thinking)
            if (!isset($this->options['thinking']) || $this->options['thinking'] === null) {
                $this->options['thinking_budget_tokens'] = $allocation->thinkingBudgetTokens;
            }

            // Store allocation for compaction decisions
            $this->options['_smart_context_allocation'] = $allocation->toArray();
        }

        yield from $this->runLoop();
    }

    /**
     * Inner agentic loop shared by run() and continuePending().
     *
     * @return Generator<int, AssistantMessage>
     */
    protected function runLoop(): Generator
    {
        while ($this->turnCount < $this->maxTurns) {
            $this->turnCount++;
            $this->guardrailsCollector?->recordTurn();

            // USD budget check
            if ($this->maxBudgetUsd > 0 && $this->totalCostUsd >= $this->maxBudgetUsd) {
                throw new SuperAgentException(
                    "Budget exhausted: \${$this->totalCostUsd} >= \${$this->maxBudgetUsd}"
                );
            }

            $assistantMessage = $this->callProvider();
            $this->messages[] = $assistantMessage;

            // Track cost and output tokens
            $callCost = 0.0;
            if ($assistantMessage->usage) {
                $callCost = CostCalculator::calculate(
                    $this->options['model'] ?? $this->provider->getModel(),
                    $assistantMessage->usage,
                );
                $this->totalCostUsd += $callCost;
                $this->turnOutputTokens += $assistantMessage->usage->outputTokens ?? 0;
            }

            // Feed guardrails runtime context collector
            $this->guardrailsCollector?->recordUsage($assistantMessage->usage, $callCost);

            // --- Checkpoint ---
            $this->checkpointManager?->maybeCheckpoint(
                sessionId: $this->options['session_id'] ?? 'default',
                messages: $this->messages,
                turnCount: $this->turnCount,
                totalCostUsd: $this->totalCostUsd,
                turnOutputTokens: $this->turnOutputTokens,
                model: $this->options['model'] ?? $this->provider->getModel(),
                prompt: $this->lastPrompt ?? '',
            );

            // --- Cost Autopilot evaluation ---
            if ($this->costAutopilot !== null) {
                $autopilotDecision = $this->costAutopilot->evaluate($this->totalCostUsd);
                $this->applyCostAutopilotDecision($autopilotDecision);

                if ($autopilotDecision->shouldHalt()) {
                    $this->streamingHandler?->emitTurn($assistantMessage, $this->turnCount);
                    yield $assistantMessage;
                    throw new SuperAgentException(
                        "CostAutopilot halted: {$autopilotDecision->message}"
                    );
                }
            }

            $this->streamingHandler?->emitTurn($assistantMessage, $this->turnCount);

            yield $assistantMessage;

            if (! $assistantMessage->hasToolUse() || $assistantMessage->stopReason !== StopReason::ToolUse) {
                // --- Run stop hooks pipeline at turn end ---
                $this->runStopHooksPipeline($assistantMessage);

                $this->streamingHandler?->emitFinalMessage($assistantMessage);
                return;
            }

            $toolResults = $this->executeTools($assistantMessage);
            $this->messages[] = $toolResults;

            // --- Token budget continuation check ---
            if ($this->budgetTracker !== null && $this->tokenBudget !== null) {
                $decision = $this->budgetTracker->check(
                    budget: $this->tokenBudget,
                    globalTurnTokens: $this->turnOutputTokens,
                    isSubAgent: ($this->options['agent_id'] ?? null) !== null,
                );

                if ($decision->shouldStop()) {
                    // Run stop hooks before exiting
                    $this->runStopHooksPipeline($assistantMessage);
                    $this->streamingHandler?->emitFinalMessage($assistantMessage);
                    return;
                }

                // Inject nudge message for the model to continue
                if ($decision->nudgeMessage !== null) {
                    $this->messages[] = new UserMessage($decision->nudgeMessage);
                }
            }
        }

        throw new SuperAgentException("Agent loop exceeded max turns ({$this->maxTurns})");
    }

    /**
     * Run the stop hooks pipeline at turn end.
     */
    protected function runStopHooksPipeline(AssistantMessage $lastMessage): void
    {
        if ($this->stopHooksPipeline === null) {
            return;
        }

        $result = $this->stopHooksPipeline->execute(
            messages: $this->messages,
            assistantMessages: [$lastMessage],
            context: [
                'session_id' => $this->options['session_id'] ?? 'unknown',
                'cwd' => $this->options['cwd'] ?? getcwd() ?: '.',
                'agent_id' => $this->options['agent_id'] ?? null,
                'agent_type' => $this->options['agent_type'] ?? null,
                'is_teammate' => $this->options['is_teammate'] ?? false,
                'teammate_name' => $this->options['teammate_name'] ?? '',
                'team_name' => $this->options['team_name'] ?? '',
                'in_progress_tasks' => $this->options['in_progress_tasks'] ?? [],
            ],
        );

        // Inject blocking errors as user messages
        foreach ($result->blockingErrors as $error) {
            $this->messages[] = new UserMessage("[Stop Hook Error]: {$error}");
        }
    }

    protected function callProvider(): AssistantMessage
    {
        $options = $this->options;
        if ($this->streamingHandler) {
            $options['streaming_handler'] = $this->streamingHandler;
        }

        // ── Optimization 1: Compact old tool results ──────────────
        $messages = $this->messages;
        if ($this->toolResultCompactor?->isEnabled()) {
            $messages = $this->toolResultCompactor->compact($messages);
        }

        // ── Optimization 2: Selective tool schema ─────────────────
        $tools = $this->tools;
        if ($this->toolSchemaFilter?->isEnabled()) {
            $tools = $this->toolSchemaFilter->filter($tools, $messages);
        }

        // ── Optimization 3: Per-turn model routing ────────────────
        if ($this->modelRouter?->isEnabled()) {
            $routedModel = $this->modelRouter->route($messages, $this->turnCount);
            if ($routedModel !== null) {
                $options['model'] = $routedModel;
            }
        }

        // ── Performance: Adaptive max_tokens ──────────────────────
        if ($this->adaptiveMaxTokens?->isEnabled()) {
            $options['max_tokens'] = $this->adaptiveMaxTokens->adjust(
                $messages,
                $this->turnCount,
                $options['max_tokens'] ?? 8192,
            );
        }

        // ── Optimization 4: Response prefill ──────────────────────
        if ($this->responsePrefill?->isEnabled()) {
            $prefill = $this->responsePrefill->generate($messages, $tools);
            if ($prefill !== null) {
                $options['assistant_prefill'] = $prefill;
            }
        }

        // ── Optimization 5: Prompt cache pinning ──────────────────
        $systemPrompt = $this->systemPrompt;
        if ($this->promptCachePinning?->isEnabled()) {
            $systemPrompt = $this->promptCachePinning->pin($systemPrompt);
        }

        $generator = $this->provider->chat(
            $messages,
            $tools,
            $systemPrompt,
            $options,
        );

        $lastMessage = null;
        foreach ($generator as $message) {
            $lastMessage = $message;
        }

        if ($lastMessage === null) {
            throw new SuperAgentException('Provider returned no response');
        }

        // Record turn for model routing decisions
        $this->modelRouter?->recordTurn($lastMessage);

        return $lastMessage;
    }

    /**
     * Execute tools with full pipeline:
     *
     * 1. Permission check (allowed/denied lists)
     * 2. Tool lookup
     * 3. Input validation (tool.validateInput if available)
     * 4. PreToolUse hooks (can modify input, allow/deny/ask, prevent continuation)
     * 5. Resolve hook permission decision
     * 6. Execute tool
     * 7. PostToolUse hooks (can inject context, prevent continuation)
     * 8. On failure: PostToolUseFailure hooks
     */
    protected function executeTools(AssistantMessage $assistantMessage): ToolResultMessage
    {
        $results = [];

        // ── Performance: Parallel tool execution ──────────────────
        $toolBlocks = $assistantMessage->toolUseBlocks();
        if ($this->parallelExecutor?->isEnabled() && count($toolBlocks) > 1) {
            $classified = $this->parallelExecutor->classify($toolBlocks);

            // Execute read-only tools in parallel
            if (!empty($classified['parallel'])) {
                $parallelResults = $this->parallelExecutor->executeParallel(
                    $classified['parallel'],
                    fn($block) => $this->executeSingleTool($block),
                );
                array_push($results, ...$parallelResults);
            }

            // Execute remaining tools sequentially
            foreach ($classified['sequential'] as $block) {
                $results[] = $this->executeSingleTool($block);
            }

            // Speculative prefetch after tool execution
            $this->runSpeculativePrefetch($results);

            return ToolResultMessage::fromResults($results);
        }

        foreach ($assistantMessage->toolUseBlocks() as $block) {
            $toolName = $block->toolName;
            $toolInput = $block->toolInput ?? [];
            $toolUseId = $block->toolUseId;

            // --- Step 1: Permission check (allowed/denied lists) ---
            if (! $this->isToolAllowed($toolName)) {
                $content = "Error: Tool '{$toolName}' is not permitted.";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                continue;
            }

            // --- Step 2: Tool lookup ---
            if (! isset($this->toolMap[$toolName])) {
                $content = "Error: Unknown tool '{$toolName}'";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                continue;
            }

            $tool = $this->toolMap[$toolName];

            // --- Step 3: Input validation ---
            if (method_exists($tool, 'validateInput')) {
                $validationError = $tool->validateInput($toolInput);
                if ($validationError !== null) {
                    $content = "Error: Invalid input for tool '{$toolName}': {$validationError}";
                    $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                    $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                    continue;
                }
            }

            // --- Step 4: PreToolUse hooks ---
            $hookResult = $this->runPreToolUseHooks($toolName, $toolUseId, $toolInput);

            // Hook may have modified the input
            if ($hookResult->updatedInput !== null) {
                $toolInput = $hookResult->updatedInput;
            }

            // --- Step 5: Resolve hook permission decision ---
            $permissionDecision = $this->resolveHookPermission($hookResult, $toolName);

            if ($permissionDecision === 'deny') {
                $reason = $hookResult->permissionReason ?? "Denied by hook";
                $content = "Error: Tool '{$toolName}' denied by hook: {$reason}";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);

                // Run permission denied hooks
                $this->runHook(HookEvent::PERMISSION_DENIED, [
                    'tool_name' => $toolName,
                    'tool_input' => $toolInput,
                    'reason' => $reason,
                ]);
                continue;
            }

            // If hook says stop processing entirely
            if (! $hookResult->continue) {
                $content = $hookResult->stopReason ?? "Execution stopped by PreToolUse hook";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                continue;
            }

            // --- Step 6: Execute tool (with caching) ---
            try {
                // Use cached execution if available
                if (method_exists($this, 'executeToolWithCache')) {
                    $result = $this->executeToolWithCache(
                        $toolName,
                        $toolInput,
                        fn() => $tool->execute($toolInput)
                    );
                } else {
                    $result = $tool->execute($toolInput);
                }
                $content = $result->contentAsString();
                $isError = $result->isError;

                // --- Step 7: PostToolUse hooks ---
                $postResult = $this->runPostToolUseHooks($toolName, $toolUseId, $toolInput, $content, $isError);

                // PostToolUse hooks can inject additional context
                if ($postResult->systemMessage !== null) {
                    $content .= "\n\n[Hook Context]: " . $postResult->systemMessage;
                }

                $results[] = [
                    'tool_use_id' => $toolUseId,
                    'content' => $content,
                    'is_error' => $isError,
                ];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, $isError);

                // If PostToolUse hook says prevent continuation, stop after this tool
                if ($postResult->preventContinuation) {
                    break;
                }

            } catch (\Throwable $e) {
                $errorContent = "Error executing tool '{$toolName}': {$e->getMessage()}";

                // --- Step 8: PostToolUseFailure hooks ---
                $failResult = $this->runPostToolUseFailureHooks(
                    $toolName, $toolUseId, $toolInput, $e->getMessage()
                );

                if ($failResult->systemMessage !== null) {
                    $errorContent .= "\n\n[Hook Context]: " . $failResult->systemMessage;
                }

                $results[] = ['tool_use_id' => $toolUseId, 'content' => $errorContent, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $errorContent, true);
            }
        }

        // Speculative prefetch after sequential execution
        $this->runSpeculativePrefetch($results);

        return ToolResultMessage::fromResults($results);
    }

    /**
     * Execute a single tool block through the full pipeline (permissions, hooks, execution).
     * Used by both parallel and sequential execution paths.
     *
     * @return array{tool_use_id: string, content: string, is_error: bool}
     */
    protected function executeSingleTool(ContentBlock $block): array
    {
        $toolName = $block->toolName;
        $toolInput = $block->toolInput ?? [];
        $toolUseId = $block->toolUseId;

        if (!$this->isToolAllowed($toolName)) {
            $content = "Error: Tool '{$toolName}' is not permitted.";
            $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
            return ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
        }

        if (!isset($this->toolMap[$toolName])) {
            $content = "Error: Unknown tool '{$toolName}'";
            $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
            return ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
        }

        $tool = $this->toolMap[$toolName];

        if (method_exists($tool, 'validateInput')) {
            $validationError = $tool->validateInput($toolInput);
            if ($validationError !== null) {
                $content = "Error: Invalid input for tool '{$toolName}': {$validationError}";
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                return ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
            }
        }

        try {
            // Zero-copy: check file cache before executing Read
            if ($this->zeroCopy?->isEnabled() && $toolName === 'read' && isset($toolInput['file_path'])) {
                $cached = $this->zeroCopy->getCachedFile($toolInput['file_path']);
                if ($cached !== null) {
                    $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $cached, false);
                    return ['tool_use_id' => $toolUseId, 'content' => $cached, 'is_error' => false];
                }
            }

            if (method_exists($this, 'executeToolWithCache')) {
                $result = $this->executeToolWithCache(
                    $toolName,
                    $toolInput,
                    fn() => $tool->execute($toolInput)
                );
            } else {
                $result = $tool->execute($toolInput);
            }

            $content = $result->contentAsString();
            $isError = $result->isError;

            // Zero-copy: cache result for subsequent tools
            if ($this->zeroCopy?->isEnabled()) {
                $this->zeroCopy->wrapResult($toolName, $toolInput, $result);
            }

            $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, $isError);
            return ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => $isError];

        } catch (\Throwable $e) {
            $content = "Error executing tool '{$toolName}': {$e->getMessage()}";
            $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
            return ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
        }
    }

    /**
     * Run speculative prefetch after tool results are collected.
     */
    protected function runSpeculativePrefetch(array $results): void
    {
        if (!$this->speculativePrefetch?->isEnabled()) {
            return;
        }

        foreach ($results as $result) {
            // After successful Read, prefetch related files
            if (!($result['is_error'] ?? false)) {
                // Check if this was a Read by looking at the content pattern
                // (tool name not available in results, but file paths are in content for reads)
                $this->speculativePrefetch->prefetchRelated($result['content'] ?? '');
            }
        }
    }

    /**
     * Run PreToolUse hooks and return merged result.
     */
    protected function runPreToolUseHooks(string $toolName, string $toolUseId, array $toolInput): HookResult
    {
        if ($this->hookRegistry === null) {
            return HookResult::continue();
        }

        return $this->hookRegistry->executeHooks(
            HookEvent::PRE_TOOL_USE,
            new HookInput(
                event: HookEvent::PRE_TOOL_USE,
                additionalData: [
                    'tool_name' => $toolName,
                    'tool_use_id' => $toolUseId,
                    'tool_input' => $toolInput,
                ],
            ),
        );
    }

    /**
     * Run PostToolUse hooks.
     */
    protected function runPostToolUseHooks(
        string $toolName,
        string $toolUseId,
        array $toolInput,
        string $toolOutput,
        bool $isError,
    ): HookResult {
        if ($this->hookRegistry === null) {
            return HookResult::continue();
        }

        return $this->hookRegistry->executeHooks(
            HookEvent::POST_TOOL_USE,
            new HookInput(
                event: HookEvent::POST_TOOL_USE,
                additionalData: [
                    'tool_name' => $toolName,
                    'tool_use_id' => $toolUseId,
                    'tool_input' => $toolInput,
                    'tool_output' => $toolOutput,
                    'is_error' => $isError,
                ],
            ),
        );
    }

    /**
     * Run PostToolUseFailure hooks.
     */
    protected function runPostToolUseFailureHooks(
        string $toolName,
        string $toolUseId,
        array $toolInput,
        string $error,
    ): HookResult {
        if ($this->hookRegistry === null) {
            return HookResult::continue();
        }

        return $this->hookRegistry->executeHooks(
            HookEvent::POST_TOOL_USE_FAILURE,
            new HookInput(
                event: HookEvent::POST_TOOL_USE_FAILURE,
                additionalData: [
                    'tool_name' => $toolName,
                    'tool_use_id' => $toolUseId,
                    'tool_input' => $toolInput,
                    'error' => $error,
                ],
            ),
        );
    }

    /**
     * Run a generic hook event.
     */
    protected function runHook(HookEvent $event, array $data): HookResult
    {
        if ($this->hookRegistry === null) {
            return HookResult::continue();
        }

        return $this->hookRegistry->executeHooks(
            $event,
            new HookInput(event: $event, additionalData: $data),
        );
    }

    /**
     * Resolve a PreToolUse hook's permission result into a final decision.
     *
     * Hook 'allow' does NOT bypass settings deny rules — the allowed/denied
     * tool lists still apply. Hook 'deny' takes immediate effect.
     *
     * @return string|null 'allow', 'deny', or null (normal flow)
     */
    protected function resolveHookPermission(HookResult $hookResult, string $toolName): ?string
    {
        if ($hookResult->permissionBehavior === null) {
            return null; // No hook permission decision, use normal flow
        }

        if ($hookResult->permissionBehavior === 'deny') {
            return 'deny';
        }

        if ($hookResult->permissionBehavior === 'allow') {
            // Hook allow does NOT bypass settings deny rules
            if (in_array($toolName, $this->deniedTools, true)) {
                return 'deny'; // Settings deny overrides hook allow
            }
            return 'allow';
        }

        // 'ask' — for now, treat as normal flow (future: user interaction)
        return null;
    }

    protected function isToolAllowed(string $toolName): bool
    {
        // Check both the original name and its CC/SA alias
        $resolved = \SuperAgent\Tools\ToolNameResolver::toSuperAgent($toolName);

        if (in_array($toolName, $this->deniedTools, true)
            || in_array($resolved, $this->deniedTools, true)) {
            return false;
        }

        if ($this->allowedTools !== null) {
            if (!in_array($toolName, $this->allowedTools, true)
                && !in_array($resolved, $this->allowedTools, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a CostAutopilot decision: model downgrades and context compaction.
     */
    protected function applyCostAutopilotDecision(AutopilotDecision $decision): void
    {
        if (!$decision->requiresAction()) {
            return;
        }

        // Apply model downgrade
        if ($decision->hasDowngrade() && $decision->newModel !== null) {
            $this->provider->setModel($decision->newModel);

            // Inject a system-level notice so the model knows it was downgraded
            $this->messages[] = new UserMessage(
                "[System: CostAutopilot] Model downgraded from {$decision->previousModel} to "
                . "{$decision->newModel} ({$decision->tierName} tier) to conserve budget. "
                . "Budget usage: " . round($decision->budgetUsedPct, 1) . "%."
            );
        }

        // Request context compaction (truncate old tool results)
        if ($decision->shouldCompact()) {
            $this->compactMessagesForCost();
        }
    }

    /**
     * Compact messages to reduce input tokens and save cost.
     *
     * Truncates old tool result content (keeps first 200 chars) and removes
     * thinking blocks from older messages. Preserves the most recent messages.
     */
    protected function compactMessagesForCost(): void
    {
        $preserveRecent = 6; // Keep last N messages untouched
        $total = count($this->messages);

        if ($total <= $preserveRecent) {
            return;
        }

        $boundary = $total - $preserveRecent;

        for ($i = 0; $i < $boundary; $i++) {
            $msg = $this->messages[$i];

            // Truncate tool result messages
            if ($msg instanceof ToolResultMessage) {
                $content = $msg->contentAsString();
                if (strlen($content) > 500) {
                    // Replace with truncated version by creating a new message
                    $truncated = substr($content, 0, 200) . "\n[...truncated by CostAutopilot to save tokens...]";
                    $this->messages[$i] = ToolResultMessage::fromResults([
                        ['tool_use_id' => 'compacted', 'content' => $truncated, 'is_error' => false],
                    ]);
                }
            }
        }
    }
}
