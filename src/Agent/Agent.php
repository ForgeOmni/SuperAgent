<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\AgentResult;
use SuperAgent\Config\ConfigRepository;
use SuperAgent\Context\ContextInterface;
use SuperAgent\Context\Context;
use SuperAgent\LLM\Response;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Lightweight Agent used by swarm / AutoMode workflows.
 *
 * Distinct from the main `SuperAgent\Agent` which carries the full tool loop,
 * harness wiring, OAuth handling, etc. This one is a single-shot wrapper:
 * configure model + system prompt, call `run($prompt)`, get an
 * `\SuperAgent\AgentResult` back. No tools, no multi-turn.
 *
 * The provider name is read from `Context::getMetadata('provider')` and
 * credentials are merged from `ConfigRepository` — matching the way
 * `AutoModeAgent` configures the context before invoking `run()`.
 *
 * If neither metadata['provider'] nor a registered model id is provided,
 * `run()` returns a clearly-marked failure `AgentResult` rather than throwing,
 * so callers like `ParallelAgentCoordinator` can store the error and move on.
 */
class Agent
{
    private ContextInterface $context;
    private LoggerInterface $logger;
    private ?string $model = null;
    private ?string $systemPrompt = null;
    private ?array $allowedTools = null;
    
    public function __construct(
        ?ContextInterface $context = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->context = $context ?? new Context();
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function getContext(): ContextInterface
    {
        return $this->context;
    }
    
    public function setModel(string $model): void
    {
        $this->model = $model;
    }
    
    public function getModel(): ?string
    {
        return $this->model;
    }
    
    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }
    
    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }
    
    public function setAllowedTools(array $tools): void
    {
        $this->allowedTools = $tools;
    }
    
    public function getAllowedTools(): ?array
    {
        return $this->allowedTools;
    }
    
    /**
     * Run the agent with a given prompt.
     *
     * Resolves a provider from `context.metadata['provider']` (or infers from
     * `setModel()`'s catalog entry), pulls credentials from `ConfigRepository`,
     * and issues a single non-tool chat. Drains the streaming generator and
     * wraps the final AssistantMessage in an AgentResult.
     */
    public function run(string $prompt): AgentResult
    {
        $this->logger->info('Agent running', [
            'prompt_len' => strlen($prompt),
            'model' => $this->model,
        ]);

        if ($this->context->getMetadata('cancelled')) {
            return $this->buildResult('[agent cancelled]', null);
        }

        try {
            $providerName = (string) ($this->context->getMetadata('provider') ?? '');
            if ($providerName === '' && is_string($this->model) && $this->model !== '') {
                $entry = ModelCatalog::model($this->model);
                if (is_array($entry) && isset($entry['provider'])) {
                    $providerName = (string) $entry['provider'];
                }
            }
            if ($providerName === '') {
                return $this->buildResult(
                    '[agent error] no provider configured (set context metadata "provider" or a catalog-known model)',
                    null,
                );
            }

            $config = ConfigRepository::getInstance()->get("superagent.providers.{$providerName}", []);
            $config = is_array($config) ? $config : [];
            if (is_string($this->model) && $this->model !== '') {
                $config['model'] = $this->model;
            }
            $providerInstance = ProviderRegistry::create($providerName, $config);

            $messages = [new UserMessage($prompt)];
            $final = null;
            foreach ($providerInstance->chat($messages, [], $this->systemPrompt, ['max_tokens' => 4000]) as $chunk) {
                if ($chunk instanceof AssistantMessage) {
                    $final = $chunk;
                }
            }

            return new AgentResult(
                message: $final,
                allResponses: $final !== null ? [$final] : [],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Agent run failed', ['error' => $e->getMessage()]);
            return $this->buildResult('[agent error] ' . $e->getMessage(), null);
        }
    }

    private function buildResult(string $text, ?\SuperAgent\Messages\Usage $usage): AgentResult
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text($text)];
        $msg->usage = $usage;
        return new AgentResult(message: $msg, allResponses: [$msg]);
    }
}