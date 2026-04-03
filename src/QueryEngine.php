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

class QueryEngine
{
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
        $this->budgetTracker?->reset();

        // --- Smart Context Window: adjust thinking budget based on task complexity ---
        if ($this->smartContextManager !== null && $this->smartContextManager->isEnabled()) {
            $promptText = is_string($prompt) ? $prompt : json_encode($prompt);
            $allocation = $this->smartContextManager->allocate($promptText);

            // Apply thinking budget to options (if model supports thinking)
            if (!isset($this->options['thinking']) || $this->options['thinking'] === null) {
                $this->options['thinking_budget_tokens'] = $allocation->thinkingBudgetTokens;
            }

            // Store allocation for compaction decisions
            $this->options['_smart_context_allocation'] = $allocation->toArray();
        }

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
                prompt: is_string($prompt) ? $prompt : json_encode($prompt),
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

        $generator = $this->provider->chat(
            $this->messages,
            $this->tools,
            $this->systemPrompt,
            $options,
        );

        $lastMessage = null;
        foreach ($generator as $message) {
            $lastMessage = $message;
        }

        if ($lastMessage === null) {
            throw new SuperAgentException('Provider returned no response');
        }

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

            // --- Step 6: Execute tool ---
            try {
                $result = $tool->execute($toolInput);
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

        return ToolResultMessage::fromResults($results);
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
        if (in_array($toolName, $this->deniedTools, true)) {
            return false;
        }

        if ($this->allowedTools !== null && ! in_array($toolName, $this->allowedTools, true)) {
            return false;
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
