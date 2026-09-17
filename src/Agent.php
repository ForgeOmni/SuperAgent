<?php

namespace SuperAgent;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\Conversation\HandoffPolicy;
use SuperAgent\Conversation\ProviderArtifacts;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Bridge\BridgeFactory;
use SuperAgent\Config\ExperimentalFeatures;
use SuperAgent\Providers\ModelResolver;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\AutoMode\TaskAnalyzer;
use SuperAgent\AutoMode\AutoModeAgent;
use SuperAgent\Tools\ToolLoader;
use SuperAgent\Tools\ToolPolicy;
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Config\Profile;
use SuperAgent\Exceptions\ResumeException;
use SuperAgent\Exceptions\ToolPolicyException;
use SuperAgent\Resume\ResumeEnvelope;
use SuperAgent\Tools\ToolResult;

class Agent
{
    protected LLMProvider $provider;

    /** @var ToolInterface[] */
    protected array $tools = [];

    protected ?string $systemPrompt = null;

    protected int $maxTurns = 50;

    protected array $options = [];

    protected ?StreamingHandler $streamingHandler = null;

    /** @var string[]|null */
    protected ?array $allowedTools = null;

    /** @var string[] */
    protected array $deniedTools = [];

    protected float $maxBudgetUsd = 0.0;

    /** @var Message[] */
    protected array $messages = [];
    
    protected bool $autoMode = false;
    
    protected array $autoModeConfig = [];
    
    protected ?ToolLoader $toolLoader = null;

    protected bool $toolsWereNamedByCaller = false;

    protected string $profile = Profile::WORKSTATION;

    protected ?ToolPolicy $toolPolicy = null;

    /**
     * An agent built for a host that embeds this SDK in its own product:
     * no tools load unless they are handed over, and anything that can reach
     * the machine or the network is refused even if it is.
     *
     * @since 1.2.0
     */
    public static function embedded(array $config = []): static
    {
        $config['profile'] = Profile::EMBEDDED;

        return new static($config);
    }

    public function __construct(array $config = [])
    {
        $config = Profile::apply($config);

        // Once, here: a credential resolver is a call to a vault, and the
        // config array is read again further down for the provider and for
        // sub-agent spawn configs. `resolveCredentials()` is idempotent on a
        // plain string, so those later passes cost nothing.
        $config = $this->resolveCredentials($config);

        $this->profile = $config['profile'];
        $this->toolPolicy = $this->resolveToolPolicy($config);

        $this->provider = $this->resolveProvider($config);
        $this->maxTurns = $config['max_turns'] ?? static::config('superagent.agent.max_turns', 50);
        $this->maxBudgetUsd = (float) ($config['max_budget_usd'] ?? static::config('superagent.agent.max_budget_usd', 0));
        $this->systemPrompt = $config['system_prompt'] ?? null;
        $this->options = $config['options'] ?? [];
        
        // Auto-mode configuration
        $this->autoMode = $config['auto_mode'] ?? static::config('superagent.auto_mode.enabled', false);
        $this->autoModeConfig = $config['auto_mode_config'] ?? static::config('superagent.auto_mode', []);

        // Tool loading configuration
        $this->initializeTools($config);

        // Enforcement point 1 of 2: what the agent is allowed to hold. The
        // second is in QueryEngine, immediately before a call, for tools that
        // arrive after this point.
        $this->enforceToolPolicyOnInitialTools();

        // Inject provider config into AgentTool so sub-agents share the same LLM credentials
        $this->injectProviderConfigIntoAgentTools($config);

        if (isset($config['allowed_tools'])) {
            $this->allowedTools = $config['allowed_tools'];
        }
        if (isset($config['denied_tools'])) {
            $this->deniedTools = $config['denied_tools'];
        }
        if (isset($config['streaming_handler'])) {
            $this->streamingHandler = $config['streaming_handler'];
        }
    }
    
    /**
     * Inject the parent provider config into any AgentTool instances so that
     * spawned sub-agents can create a real LLM connection.
     */
    protected function injectProviderConfigIntoAgentTools(array $config): void
    {
        // Collect only the scalar keys needed to reconstruct a provider in
        // a child process.  The 'provider' key might be an LLMProvider object
        // (not JSON-serializable) — replace it with the provider's string name.
        $config = $this->resolveCredentials($config);

        $providerConfig = array_intersect_key($config, array_flip([
            'provider', 'driver', 'api_key', 'model', 'base_url', 'max_tokens',
            'api_version', 'organization', 'app_name', 'site_url',
            'auth_mode', 'access_token', 'account_id', 'anthropic_beta',
        ]));

        // Ensure 'provider' is a serializable string, not an object
        if (isset($providerConfig['provider']) && $providerConfig['provider'] instanceof LLMProvider) {
            $providerConfig['provider'] = $providerConfig['provider']->name();
        }

        // If api_key was not in $config (e.g. it came from Laravel config()),
        // try to read it from the resolved provider so the child can authenticate.
        if (!isset($providerConfig['api_key']) && isset($this->provider)) {
            // The provider stores the key internally. We can't read private
            // fields, but we can pull it from the Laravel config if available.
            $name = $this->provider->name();
            $configKey = static::config("superagent.providers.{$name}.api_key");
            if ($configKey) {
                $providerConfig['api_key'] = $configKey;
            }
        }

        // Ensure provider name is always set
        if (!isset($providerConfig['provider']) || !is_string($providerConfig['provider'])) {
            $providerConfig['provider'] = $this->provider->name();
        }

        // Propagate model from the resolved provider if not already set
        if (!isset($providerConfig['model'])) {
            $providerConfig['model'] = $this->provider->getModel();
        }

        foreach ($this->tools as $tool) {
            if ($tool instanceof AgentTool) {
                $tool->setProviderConfig($providerConfig);
            }
        }
    }

    /**
     * Initialize tools with lazy loading support
     */
    protected function initializeTools(array $config): void
    {
        // If tools are explicitly provided, use them
        if (isset($config['tools'])) {
            $this->tools = $config['tools'];
            $this->toolsWereNamedByCaller = true;
            return;
        }
        
        // Initialize tool loader
        $this->toolLoader = new ToolLoader($config['tool_loader'] ?? []);
        
        // Determine which tools to load
        if (isset($config['load_tools'])) {
            if ($config['load_tools'] === true) {
                // Load default tools
                $this->tools = $this->toolLoader->getDefaultTools();
            } elseif (is_array($config['load_tools'])) {
                // Load specific tools
                $this->tools = $this->toolLoader->loadMany($config['load_tools']);
            } elseif ($config['load_tools'] === 'all') {
                // Load all available tools
                $this->tools = $this->toolLoader->getAllTools();
            } elseif ($config['load_tools'] === 'none' || $config['load_tools'] === false) {
                // No tools
                $this->tools = [];
            }
        } else {
            // Default behavior: load default tools if auto_load is enabled
            $autoLoad = $config['tool_loader']['auto_load'] ?? true;
            if ($autoLoad) {
                $this->tools = $this->toolLoader->getDefaultTools();
            }
        }
    }

    public function addTool(ToolInterface $tool): static
    {
        if ($this->toolPolicy !== null) {
            $reason = $this->toolPolicy->refusalReason($tool);
            if ($reason !== null) {
                throw new ToolPolicyException(ucfirst($reason) . '.');
            }
        }

        $this->tools[] = $tool;

        return $this;
    }

    /**
     * The tools this agent currently holds, after any policy has been applied.
     *
     * @return ToolInterface[]
     *
     * @since 1.2.0
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * The policy this agent enforces, or null when it enforces none.
     *
     * @since 1.2.0
     */
    public function getToolPolicy(): ?ToolPolicy
    {
        return $this->toolPolicy;
    }

    /**
     * The profile this agent was built with: `workstation` or `embedded`.
     *
     * @since 1.2.0
     */
    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * A policy handed in as an array, a ToolPolicy, or false to opt out of the
     * profile's own default.
     */
    protected function resolveToolPolicy(array $config): ?ToolPolicy
    {
        $spec = $config['tool_policy'] ?? static::config('superagent.tool_policy');

        if ($spec instanceof ToolPolicy) {
            return $spec;
        }

        if ($spec === false || $spec === null || ! is_array($spec) || ToolPolicy::isEmptySpec($spec)) {
            return null;
        }

        return ToolPolicy::fromArray($spec);
    }

    /**
     * Tools named by the caller are a contradiction with the caller's own
     * policy, so they raise rather than disappear. Tools the loader produced
     * are filtered: a profile that leaves the default set loading and a policy
     * that refuses half of it is a configuration, not a mistake.
     */
    protected function enforceToolPolicyOnInitialTools(): void
    {
        if ($this->toolPolicy === null || $this->tools === []) {
            return;
        }

        if ($this->toolsWereNamedByCaller) {
            foreach ($this->tools as $tool) {
                $reason = $this->toolPolicy->refusalReason($tool);
                if ($reason !== null) {
                    throw new ToolPolicyException(ucfirst($reason) . '.');
                }
            }

            return;
        }

        $this->tools = $this->toolPolicy->filter($this->tools);
    }

    /** Loader-produced tools are filtered, the same way they are at construction. */
    protected function applyToolPolicyToLoadedTools(): void
    {
        if ($this->toolPolicy !== null) {
            $this->tools = $this->toolPolicy->filter($this->tools);
        }
    }

    public function withSystemPrompt(string $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function withModel(string $model): static
    {
        $this->provider->setModel(ModelResolver::resolve($model));

        return $this;
    }

    /**
     * Hand the conversation off to a different provider mid-flight.
     *
     * The internal Message[] is preserved (so the new provider sees
     * the full history, transcoded into its own wire format on the
     * next request) but a HandoffPolicy gets a chance to clean up
     * provider-scoped artifacts that don't survive a vendor switch:
     *
     *   - signed `thinking` blocks (Anthropic, DashScope) — useless to
     *     anyone but the originator
     *   - prior tool history — kept by default, droppable for a
     *     "fresh start" handoff via HandoffPolicy::freshStart()
     *   - continuation tokens (Responses API previous_response_id,
     *     Kimi prompt_cache_key) — those live on the provider object
     *     itself; the swap implicitly resets them
     *
     * The new provider is constructed via `ProviderRegistry::create()`
     * with `$config` merged onto the SDK's host config for the named
     * driver. Pass `model`, `api_key`, `base_url`, `region` etc. the
     * same way `__construct()` accepts them.
     *
     * @param string $providerName  Driver key understood by ProviderRegistry
     *                              (`anthropic`, `kimi`, `gemini`, …).
     * @param array<string, mixed> $config  Provider-construction overrides.
     */
    public function switchProvider(
        string $providerName,
        array $config = [],
        ?HandoffPolicy $policy = null,
    ): static {
        $policy ??= HandoffPolicy::default();

        $sourceName = $this->provider->name();
        $sourceModel = $this->safeProviderModel($this->provider);

        // Build the new provider before mutating any state — if the
        // new driver fails to construct (missing api_key, unknown
        // region, etc.) the agent stays on the old provider.
        $providerConfig = static::config("superagent.providers.{$providerName}", []);
        foreach (['api_key', 'model', 'base_url', 'max_tokens', 'auth_mode',
                  'access_token', 'account_id', 'region'] as $k) {
            if (array_key_exists($k, $config)) {
                $providerConfig[$k] = $config[$k];
            }
        }
        if (isset($providerConfig['model'])) {
            $providerConfig['model'] = ModelResolver::resolve($providerConfig['model']);
        }
        $driver = $providerConfig['driver'] ?? $providerName;
        $newProvider = ProviderRegistry::create($driver, $providerConfig);
        $newProvider = $this->maybeWrapWithBridge($newProvider, $config + ['provider' => $providerName]);

        // Apply the policy to the in-memory message list. The wire
        // encoders will do an extra outbound pass (dropping anything
        // their target family can't carry) — what we do here is
        // permanent: artifacts removed from $this->messages don't come
        // back, even if the agent is later switched again.
        $this->messages = $this->applyHandoffPolicy(
            $this->messages,
            $policy,
            sourceName: $sourceName,
            sourceModel: $sourceModel,
            targetName: $providerName,
            targetModel: $newProvider->getModel(),
        );

        $this->provider = $newProvider;

        // Different tokenizers count the same history differently
        // (Anthropic vs GPT-4 can drift 20-30%) and the new model's
        // context window may be smaller than the source's. Run the
        // estimator now so callers can react before the next request
        // gets rejected for being over-budget. We expose the result
        // via $this->lastHandoffTokenStatus so the caller can read it
        // without us forcing a particular reaction (warn / compress /
        // throw) onto every consumer.
        $this->lastHandoffTokenStatus = $this->estimateContextStatusForCurrentProvider();

        return $this;
    }

    /**
     * @var array{tokens:int, window:int, fits:bool, model:string}|null
     *      Filled in by switchProvider(); null until the first switch.
     */
    protected ?array $lastHandoffTokenStatus = null;

    /**
     * Token-budget snapshot taken right after the last switchProvider().
     * Returns null if no handoff has happened yet.
     *
     * The shape:
     *   - tokens: estimated tokens of the current message list under
     *             the new tokenizer
     *   - window: the target model's context window
     *   - fits  : false if the estimate exceeds the auto-compact
     *             threshold for this model — the caller should
     *             compress before the next chat() / run() call
     *   - model : the resolved model id we measured against
     *
     * @return array{tokens:int, window:int, fits:bool, model:string}|null
     */
    public function lastHandoffTokenStatus(): ?array
    {
        return $this->lastHandoffTokenStatus;
    }

    /**
     * @return array{tokens:int, window:int, fits:bool, model:string}
     */
    protected function estimateContextStatusForCurrentProvider(): array
    {
        $estimator = new TokenEstimator();
        $model = (string) ($this->safeProviderModel($this->provider) ?? 'default');
        // TokenEstimator's `array|Message` type hint resolves Message
        // against its own namespace (SuperAgent\Context\Message) which
        // doesn't exist, so passing real Message objects trips a
        // TypeError. Convert to array form first — that path is
        // exercised by IncrementalContextManager and is the
        // estimator's intended public surface.
        $arr = array_map(static fn (Message $m) => $m->toArray(), $this->messages);
        $tokens = $estimator->estimateMessagesTokens($arr);
        $window = $estimator->getContextWindow($model);
        $fits = ! $estimator->shouldAutoCompact($arr, $model);
        return [
            'tokens' => $tokens,
            'window' => $window,
            'fits'   => $fits,
            'model'  => $model,
        ];
    }

    /**
     * @param Message[] $messages
     * @return Message[]
     */
    protected function applyHandoffPolicy(
        array $messages,
        HandoffPolicy $policy,
        string $sourceName,
        ?string $sourceModel,
        string $targetName,
        ?string $targetModel,
    ): array {
        if (! $policy->keepToolHistory) {
            // "Fresh start" — collapse history to (latest user
            // turn). System prompt lives on $this->systemPrompt
            // separately; we don't need to preserve a SystemMessage
            // here.
            $latestUser = null;
            foreach (array_reverse($messages) as $m) {
                if ($m instanceof UserMessage) {
                    $latestUser = $m;
                    break;
                }
            }
            $messages = $latestUser !== null ? [$latestUser] : [];
        } else {
            // Strip provider-only blocks from each AssistantMessage.
            $messages = array_map(
                fn (Message $m) => $this->stripProviderArtifacts($m, $policy),
                $messages,
            );
        }

        if ($policy->insertHandoffMarker) {
            $marker = sprintf(
                '[handoff: context migrated from %s%s to %s%s]',
                $sourceName,
                $sourceModel !== null && $sourceModel !== '' ? "/{$sourceModel}" : '',
                $targetName,
                $targetModel !== null && $targetModel !== '' ? "/{$targetModel}" : '',
            );
            $messages[] = new SystemMessage($marker);
        }

        return $messages;
    }

    /**
     * Drop thinking / vendor-only blocks from one Message according
     * to the policy. The dropped artifacts get *captured* into the
     * `provider_artifacts` namespace on metadata rather than discarded
     * — if the agent is later switched BACK to the originating
     * provider, that provider can re-stitch them into its request
     * body. Non-AssistantMessage subclasses are returned verbatim.
     */
    protected function stripProviderArtifacts(Message $message, HandoffPolicy $policy): Message
    {
        if (! $message instanceof AssistantMessage) {
            return $message;
        }
        if (! $policy->dropThinking) {
            return $message;
        }
        return ProviderArtifacts::captureAnthropicThinking($message);
    }

    /**
     * Some provider implementations (notably the EnhancedProvider
     * bridge wrapper) don't expose getModel() directly. Read it
     * defensively so a handoff marker can still be informative.
     */
    private function safeProviderModel(LLMProvider $provider): ?string
    {
        if (method_exists($provider, 'getModel')) {
            try {
                return (string) $provider->getModel();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    public function withMaxTurns(int $maxTurns): static
    {
        $this->maxTurns = $maxTurns;

        return $this;
    }

    public function withOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function withStreamingHandler(StreamingHandler $handler): static
    {
        $this->streamingHandler = $handler;

        return $this;
    }

    public function withAllowedTools(array $toolNames): static
    {
        $this->allowedTools = $toolNames;

        return $this;
    }

    public function withDeniedTools(array $toolNames): static
    {
        $this->deniedTools = $toolNames;

        return $this;
    }

    public function withMaxBudget(float $usd): static
    {
        $this->maxBudgetUsd = $usd;

        return $this;
    }
    
    public function withAutoMode(bool $enabled = true, array $config = []): static
    {
        $this->autoMode = $enabled;
        if (!empty($config)) {
            $this->autoModeConfig = array_merge($this->autoModeConfig, $config);
        }
        
        return $this;
    }
    
    /**
     * Load tools based on task content
     */
    public function loadToolsForTask(string $task): static
    {
        if ($this->toolLoader === null) {
            $this->toolLoader = new ToolLoader();
        }
        
        $this->tools = $this->toolLoader->loadForTask($task);
        $this->applyToolPolicyToLoadedTools();

        return $this;
    }
    
    /**
     * Manually load specific tools
     */
    public function loadTools(array $toolNames): static
    {
        if ($this->toolLoader === null) {
            $this->toolLoader = new ToolLoader();
        }
        
        $this->tools = $this->toolLoader->loadMany($toolNames);
        $this->applyToolPolicyToLoadedTools();

        return $this;
    }
    
    /**
     * Run the agent with automatic mode detection.
     * This is the primary entry point for agent execution.
     */
    public function run(string $prompt, array $options = []): AgentResult
    {
        // Merge caller-supplied options over the per-instance defaults so
        // callers of the non-auto path actually see their options applied
        // (pre-0.9.1 this silently dropped them). `idempotency_key` in
        // particular rides on this to surface on the returned AgentResult.
        if (! empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        // If auto-mode is enabled, use AutoModeAgent
        if ($this->autoMode) {
            $autoAgent = new AutoModeAgent(
                array_merge([
                    'provider' => $this->provider,
                    'auto_mode' => true,
                    'analyzer_config' => $this->autoModeConfig,
                    'tools' => $this->tools,
                    'system_prompt' => $this->systemPrompt,
                    'max_turns' => $this->maxTurns,
                    'max_budget_usd' => $this->maxBudgetUsd,
                ], $options)
            );

            return $autoAgent->run($prompt, $options);
        }

        // Otherwise use standard single-agent execution
        return $this->prompt($prompt);
    }

    /**
     * Pi-borrowed: enqueue a mid-turn correction without aborting the
     * current turn. Differs from {@see prompt()} (which starts a new turn)
     * and {@see followUp()} (which queues for after current turn ends).
     *
     * The steer is recorded as an in-memory queue on the agent; the
     * agent engine should drain it at its next safe checkpoint (between
     * tool calls or stream messages) and prepend the steer's content as
     * a synthetic user message before the next LLM call.
     *
     * Currently this is an enqueue-only API; engines that haven't yet
     * adopted the drain protocol will treat the steered message as a
     * normal next-turn prompt. See pi.dev/docs/latest/rpc §steer.
     */
    public function steer(string $message): void
    {
        if (!isset($this->options['_steer_queue']) || !is_array($this->options['_steer_queue'])) {
            $this->options['_steer_queue'] = [];
        }
        $this->options['_steer_queue'][] = [
            'message' => $message,
            'at' => microtime(true),
        ];
    }

    /**
     * Pi-borrowed: enqueue a prompt to be sent after the current turn
     * completes. Equivalent to the multi-message "send while still
     * generating" pattern in pi's RPC.
     */
    public function followUp(string $message): void
    {
        if (!isset($this->options['_followup_queue']) || !is_array($this->options['_followup_queue'])) {
            $this->options['_followup_queue'] = [];
        }
        $this->options['_followup_queue'][] = [
            'message' => $message,
            'at' => microtime(true),
        ];
    }

    /**
     * Drain and return the steer queue (called by the engine at safe
     * checkpoints). Returns the entries in FIFO order; empties the queue.
     *
     * @return list<array{message:string,at:float}>
     */
    public function drainSteer(): array
    {
        $queue = $this->options['_steer_queue'] ?? [];
        $this->options['_steer_queue'] = [];
        return is_array($queue) ? array_values($queue) : [];
    }

    /**
     * Drain and return the follow-up queue.
     *
     * @return list<array{message:string,at:float}>
     */
    public function drainFollowUp(): array
    {
        $queue = $this->options['_followup_queue'] ?? [];
        $this->options['_followup_queue'] = [];
        return is_array($queue) ? array_values($queue) : [];
    }

    /**
     * Run the agent with a prompt. Executes the full agentic loop.
     * @deprecated Use run() instead for auto-mode support
     */
    public function prompt(string $prompt, ?StreamingHandler $streamingHandler = null): AgentResult
    {
        $engine = $this->createEngine($streamingHandler);
        $engine->setMessages($this->messages);

        $lastMessage = null;
        $allResponses = [];
        $totalCost = 0.0;

        foreach ($engine->run($prompt) as $assistantMessage) {
            $lastMessage = $assistantMessage;
            $allResponses[] = $assistantMessage;
        }

        $this->messages = $engine->getMessages();
        $totalCost += $engine->getTotalCostUsd();

        // A tool (or a PreToolUse hook) handed a decision to a human: hand the
        // caller everything needed to finish the turn later and stop here.
        // Follow-ups are deliberately not drained — the conversation is not
        // finished, and queueing more prompts onto an unanswered tool call
        // would produce a transcript no provider accepts.
        if ($engine->isAwaitingHuman()) {
            return new AgentResult(
                message: $lastMessage,
                allResponses: $allResponses,
                messages: $this->messages,
                totalCostUsd: $totalCost,
                idempotencyKey: $this->idempotencyKeyFromOptions(),
                resume: $this->buildResumeEnvelope($engine, $totalCost),
            );
        }

        // Pi-borrowed follow-up drain: prompts queued via Agent::followUp()
        // while the main turn was running get processed in FIFO order
        // before returning. Each follow-up reuses the same Agent so
        // message history accumulates naturally — matches pi's
        // "backpressure-aware multi-message send" semantics.
        // See pi.dev/docs/latest/rpc §follow_up.
        $maxFollowUps = (int) ($this->options['_max_followups_per_call'] ?? 8);
        $processed = 0;
        while ($processed < $maxFollowUps && ($followUps = $this->drainFollowUp()) !== []) {
            foreach ($followUps as $fu) {
                if ($processed >= $maxFollowUps) break;
                $msg = (string) ($fu['message'] ?? '');
                if ($msg === '') { continue; }

                $followEngine = $this->createEngine($streamingHandler);
                $followEngine->setMessages($this->messages);
                foreach ($followEngine->run($msg) as $assistantMessage) {
                    $lastMessage = $assistantMessage;
                    $allResponses[] = $assistantMessage;
                }
                $this->messages = $followEngine->getMessages();
                $totalCost += $followEngine->getTotalCostUsd();
                $processed++;
            }
        }

        return new AgentResult(
            message: $lastMessage,
            allResponses: $allResponses,
            messages: $this->messages,
            totalCostUsd: $totalCost,
            idempotencyKey: $this->idempotencyKeyFromOptions(),
        );
    }

    /**
     * Finish a turn that stopped to wait for a human.
     *
     * The envelope is the only state that had to survive: a row, a queue
     * message, a JSON string — and it may come back in a different process,
     * after a deploy. Answering the last outstanding ticket continues the
     * conversation from exactly where it stopped; answering one of several
     * returns an envelope that is still waiting, with no model call made.
     *
     * A resumed turn can defer again, and the result carries the new envelope
     * when it does.
     *
     * @param ResumeEnvelope|array|string $envelope  The envelope, its array form, or its JSON.
     *
     * @throws ResumeException on an unknown or already-answered ticket, an
     *         expired envelope, or one belonging to a different provider.
     *
     * @since 1.2.0
     */
    public function resume(
        ResumeEnvelope|array|string $envelope,
        string $ticketId,
        ToolResult $result,
        ?StreamingHandler $streamingHandler = null,
    ): AgentResult {
        $envelope = $this->readEnvelope($envelope);

        if ($envelope->providerName !== null && $envelope->providerName !== $this->provider->name()) {
            throw new ResumeException(
                "This envelope was created by the '{$envelope->providerName}' provider, "
                . "but this agent runs '{$this->provider->name()}'."
            );
        }

        $envelope = $envelope->withAnswer($ticketId, $result);

        // Still waiting on a sibling ticket: record the answer, call nothing.
        if (! $envelope->isReady()) {
            return new AgentResult(
                message: null,
                allResponses: [],
                messages: $envelope->messages,
                totalCostUsd: $envelope->totalCostUsd,
                idempotencyKey: $this->idempotencyKeyFromOptions(),
                resume: $envelope,
            );
        }

        $engine = $this->createEngine($streamingHandler);
        $engine->setMessages($envelope->messages);

        $lastMessage = null;
        $allResponses = [];

        foreach ($engine->resumeWithToolResults($envelope->toolResults()) as $assistantMessage) {
            $lastMessage = $assistantMessage;
            $allResponses[] = $assistantMessage;
        }

        $this->messages = $engine->getMessages();
        $totalCost = $envelope->totalCostUsd + $engine->getTotalCostUsd();

        return new AgentResult(
            message: $lastMessage,
            allResponses: $allResponses,
            messages: $this->messages,
            totalCostUsd: $totalCost,
            idempotencyKey: $this->idempotencyKeyFromOptions(),
            resume: $engine->isAwaitingHuman() ? $this->buildResumeEnvelope($engine, $totalCost) : null,
        );
    }

    /** @since 1.2.0 */
    protected function readEnvelope(ResumeEnvelope|array|string $envelope): ResumeEnvelope
    {
        if ($envelope instanceof ResumeEnvelope) {
            return $envelope;
        }

        return is_string($envelope)
            ? ResumeEnvelope::fromJson($envelope)
            : ResumeEnvelope::fromArray($envelope);
    }

    /** @since 1.2.0 */
    protected function buildResumeEnvelope(QueryEngine $engine, float $totalCost): ResumeEnvelope
    {
        $ttl = (int) static::config('superagent.resume.ttl_seconds', 0);

        return new ResumeEnvelope(
            id: bin2hex(random_bytes(16)),
            messages: $engine->getMessages(),
            completedResults: $engine->getDeferredCompletedResults(),
            pending: $engine->getDeferrals(),
            resolved: [],
            providerName: $this->provider->name(),
            model: $this->provider->getModel(),
            turnCount: $engine->getTurnCount(),
            totalCostUsd: $totalCost,
            createdAt: date('c'),
            expiresAt: $ttl > 0 ? date('c', time() + $ttl) : null,
        );
    }

    protected function idempotencyKeyFromOptions(): ?string
    {
        return isset($this->options['idempotency_key']) && is_string($this->options['idempotency_key'])
            ? substr($this->options['idempotency_key'], 0, 80)
            : null;
    }

    /**
     * Run the agent and yield each AssistantMessage as it streams.
     *
     * @return \Generator<int, AssistantMessage>
     */
    public function stream(string $prompt, ?StreamingHandler $streamingHandler = null): \Generator
    {
        $engine = $this->createEngine($streamingHandler);
        $engine->setMessages($this->messages);

        yield from $engine->run($prompt);

        $this->messages = $engine->getMessages();
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clear(): static
    {
        $this->messages = [];

        return $this;
    }

    public function getProvider(): LLMProvider
    {
        return $this->provider;
    }

    protected function createEngine(?StreamingHandler $overrideHandler = null): QueryEngine
    {
        $engine = new QueryEngine(
            provider: $this->provider,
            tools: $this->tools,
            systemPrompt: $this->systemPrompt,
            maxTurns: $this->maxTurns,
            options: $this->options,
            streamingHandler: $overrideHandler ?? $this->streamingHandler,
            allowedTools: $this->allowedTools,
            deniedTools: $this->deniedTools,
            maxBudgetUsd: $this->maxBudgetUsd,
            toolPolicy: $this->toolPolicy,
        );

        // Pi-borrowed: let the engine pull mid-turn corrections from this
        // Agent instance's steer queue. The drainer is invoked between
        // turns so steer() calls made on the Agent (typically from a host
        // RPC handler) actually affect the in-flight prompt.
        $engine->setSteerDrainer(fn() => $this->drainSteer());

        return $engine;
    }

    /**
     * Credentials may be a callable, resolved when the agent is built rather
     * than held in the caller's configuration array.
     *
     * A host serving many tenants has one key per tenant, and the array that
     * carries it gets copied into sub-agent spawn configs, log context and
     * telemetry payloads. A closure keeps the value out of those copies until
     * something actually needs to authenticate, and lets the host fetch it
     * from a vault per turn instead of holding thousands in memory.
     *
     *     new Agent([
     *         'provider' => 'anthropic',
     *         'api_key'  => fn (): string => $vault->keyFor($tenantId),
     *     ]);
     *
     * @since 1.2.0
     */
    protected function resolveCredentials(array $config): array
    {
        foreach (['api_key', 'access_token'] as $key) {
            if (isset($config[$key]) && ! is_string($config[$key]) && is_callable($config[$key])) {
                $resolved = ($config[$key])();

                if (! is_string($resolved) || $resolved === '') {
                    throw new \InvalidArgumentException(
                        "The {$key} resolver must return a non-empty string."
                    );
                }

                $config[$key] = $resolved;
            }
        }

        return $config;
    }

    protected function resolveProvider(array $config): LLMProvider
    {
        $config = $this->resolveCredentials($config);

        if (isset($config['provider']) && $config['provider'] instanceof LLMProvider) {
            $provider = $config['provider'];

            return $this->maybeWrapWithBridge($provider, $config);
        }

        $providerName = $config['provider'] ?? static::config('superagent.default_provider', 'anthropic');
        $providerConfig = static::config("superagent.providers.{$providerName}", []);

        foreach (['api_key', 'model', 'base_url', 'max_tokens', 'auth_mode', 'access_token', 'account_id', 'anthropic_beta'] as $key) {
            if (isset($config[$key])) {
                $providerConfig[$key] = $config[$key];
            }
        }

        // Resolve model aliases (e.g., "opus" → "claude-opus-4-20250514")
        if (isset($providerConfig['model'])) {
            $providerConfig['model'] = ModelResolver::resolve($providerConfig['model']);
        }

        // Use 'driver' to determine which provider class to use,
        // falling back to the provider name itself.
        // This allows named instances like 'anthropic-proxy' with driver 'anthropic'.
        $driver = $providerConfig['driver'] ?? $providerName;

        $provider = ProviderRegistry::create($driver, $providerConfig);

        return $this->maybeWrapWithBridge($provider, $config);
    }

    /**
     * Wrap a non-Anthropic provider with Bridge enhancement if enabled.
     *
     * Priority (highest first):
     *  1. $config['bridge_mode'] = true/false  — explicit per-instance override
     *  2. config('superagent.bridge.auto_enhance') — config file setting
     *  3. ExperimentalFeatures::enabled('bridge_mode') — feature flag
     *
     * Anthropic providers are never wrapped (they natively have these optimizations).
     */
    protected function maybeWrapWithBridge(LLMProvider $provider, array $config): LLMProvider
    {
        // Anthropic never needs bridge enhancement
        if ($provider->name() === 'anthropic') {
            return $provider;
        }

        // Already wrapped
        if ($provider instanceof \SuperAgent\Bridge\EnhancedProvider) {
            return $provider;
        }

        // Resolve bridge_mode: explicit param > config auto_enhance > feature flag
        // Default is OFF to avoid surprising behavior — must be explicitly enabled.
        if (array_key_exists('bridge_mode', $config)) {
            $enabled = (bool) $config['bridge_mode'];
        } else {
            $autoEnhance = static::config('superagent.bridge.auto_enhance');
            if ($autoEnhance !== null) {
                $enabled = (bool) $autoEnhance;
            } elseif (function_exists('config') && function_exists('app') && app()->bound('config')) {
                $enabled = ExperimentalFeatures::enabled('bridge_mode');
            } else {
                $enabled = false; // No config available — default off
            }
        }

        if (! $enabled) {
            return $provider;
        }

        return BridgeFactory::wrapProvider($provider);
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            return config($key, $default);
        }

        return $default;
    }
}
