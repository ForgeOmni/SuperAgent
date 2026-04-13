<?php

declare(strict_types=1);

namespace SuperAgent\CLI;

use SuperAgent\Agent;
use SuperAgent\Config\ConfigRepository;
use SuperAgent\Harness\HarnessLoop;
use SuperAgent\Harness\CommandRouter;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Harness\AutoCompactor;
use SuperAgent\Session\SessionManager;
use SuperAgent\CLI\Terminal\Renderer;

/**
 * Factory for creating Agent and HarnessLoop instances in CLI mode.
 *
 * Reads configuration from ConfigRepository and assembles all the
 * components needed for an interactive or one-shot agent session.
 */
class AgentFactory
{
    private ConfigRepository $config;
    private Renderer $renderer;

    public function __construct(?Renderer $renderer = null)
    {
        $this->config = ConfigRepository::getInstance();
        $this->renderer = $renderer ?? new Renderer();
    }

    /**
     * Create an Agent instance from CLI options.
     */
    public function createAgent(array $options = []): Agent
    {
        $agentConfig = [];

        // Provider
        $provider = $options['provider'] ?? $this->config->get('superagent.default_provider', 'anthropic');
        $providerConfig = $this->config->get("superagent.providers.{$provider}", []);

        if (! empty($providerConfig)) {
            $agentConfig['provider'] = $provider;
            $agentConfig = array_merge($agentConfig, $providerConfig);
        }

        // Model
        if (! empty($options['model'])) {
            $agentConfig['model'] = $options['model'];
        } elseif ($this->config->has('superagent.model')) {
            $agentConfig['model'] = $this->config->get('superagent.model');
        }

        // Max turns
        if (! empty($options['max_turns'])) {
            $agentConfig['max_turns'] = (int) $options['max_turns'];
        }

        // System prompt
        if (! empty($options['system_prompt'])) {
            $agentConfig['system_prompt'] = $options['system_prompt'];
        }

        return new Agent($agentConfig);
    }

    /**
     * Create a HarnessLoop for interactive mode.
     */
    public function createHarnessLoop(Agent $agent, array $options = []): HarnessLoop
    {
        $emitter = new StreamEventEmitter();
        $renderer = $this->renderer;

        // Wire stream events to terminal output
        $emitter->on(function ($event) use ($renderer) {
            $renderer->handleStreamEvent($event);
        });

        // Create the agent runner closure
        $agentRunner = function (string $prompt, array $messages = []) use ($agent): \Generator {
            yield from $agent->streamPrompt($prompt, $messages);
        };

        // Session manager
        $sessionManager = null;
        try {
            $sessionManager = SessionManager::fromConfig();
        } catch (\Throwable) {
            // Session storage unavailable
        }

        // Command router with defaults
        $router = new CommandRouter();

        // Auto compactor
        $autoCompactor = null;
        try {
            $autoCompactor = AutoCompactor::fromConfig();
        } catch (\Throwable) {
            // Auto-compaction unavailable
        }

        $model = $options['model']
            ?? $this->config->get('superagent.model', 'claude-sonnet-4-6');

        return new HarnessLoop(
            agentRunner: $agentRunner,
            router: $router,
            emitter: $emitter,
            autoCompactor: $autoCompactor,
            sessionManager: $sessionManager,
            model: $model,
            cwd: $options['project'] ?? getcwd(),
        );
    }

    /**
     * Run a one-shot prompt and return the result.
     */
    public function runOneShot(Agent $agent, string $prompt): array
    {
        $result = $agent->prompt($prompt);

        return [
            'content' => $result['content'] ?? '',
            'cost' => $result['cost'] ?? 0.0,
            'turns' => $result['turns'] ?? 1,
            'usage' => $result['usage'] ?? [],
        ];
    }
}
