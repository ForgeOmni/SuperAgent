<?php

declare(strict_types=1);

namespace SuperAgent\CLI;

use SuperAgent\Agent;
use SuperAgent\Auth\ClaudeCodeCredentials;
use SuperAgent\Auth\CodexCredentials;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Config\ConfigRepository;
use SuperAgent\Harness\HarnessLoop;
use SuperAgent\Harness\CommandRouter;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Harness\AutoCompactor;
use SuperAgent\Session\SessionManager;
use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Console\Output\RealTimeCliRenderer;
use Symfony\Component\Console\Output\ConsoleOutput;

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

        // Merge OAuth credentials from CredentialStore (superagent auth login).
        $agentConfig = array_merge($agentConfig, $this->resolveStoredAuth($provider));
        $agentConfig['provider'] = $agentConfig['provider'] ?? $provider;

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

        // Rich renderer (Claude Code-style) by default; legacy stream via
        // `--no-rich`. Both paths use the same StreamEventEmitter, so auxiliary
        // listeners (NDJSON, telemetry) keep working regardless of mode.
        if (($options['rich'] ?? true) !== false) {
            $symfonyOut = new ConsoleOutput();
            $rich = new RealTimeCliRenderer(
                output: $symfonyOut,
                decorated: ($options['plain'] ?? false) ? false : null,
                thinkingMode: $this->thinkingMode($options),
            );
            $rich->attach($emitter);
        } else {
            $emitter->on(function ($event) use ($renderer) {
                $renderer->handleStreamEvent($event);
            });
        }

        // Create the agent runner closure
        $agentRunner = function (string $prompt, array $messages = []) use ($agent): \Generator {
            yield from $agent->stream($prompt);
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
     * Pull stored OAuth credentials (from `superagent auth login`) for the given
     * provider. Refreshes transparently if expired and a refresh_token is stored.
     * Returns a partial config array to merge on top of providerConfig.
     */
    private function resolveStoredAuth(string $provider): array
    {
        $store = new CredentialStore();
        $storeKey = match ($provider) {
            'anthropic' => 'anthropic',
            'openai' => 'openai',
            default => null,
        };
        if ($storeKey === null) {
            return [];
        }

        $mode = $store->get($storeKey, 'auth_mode');
        if (! $mode) {
            return [];
        }

        if ($mode === 'api_key') {
            $key = $store->get($storeKey, 'api_key');
            return $key ? ['auth_mode' => 'api_key', 'api_key' => $key] : [];
        }

        if ($mode !== 'oauth') {
            return [];
        }

        $token = $store->get($storeKey, 'access_token');
        if (! $token) {
            return [];
        }

        // Best-effort refresh for Anthropic tokens (expires_at is stored in ms).
        // Runs under CredentialStore::withLock (Phase 3) so parallel
        // SuperAgent sessions don't race-write. Under the lock we
        // double-check: another process may have already refreshed,
        // in which case we re-read the updated token and skip the
        // HTTP call entirely.
        if ($storeKey === 'anthropic') {
            $token = $store->withLock($storeKey, function () use ($store, $storeKey, $token) {
                $expiresAt = $store->get($storeKey, 'expires_at');
                if (!$expiresAt || (int) floor(((int) $expiresAt) / 1000) - 60 > time()) {
                    // Fresh enough — either because nothing was
                    // expired or another process refreshed while we
                    // waited. Re-read the stored token in case the
                    // latter is true.
                    return $store->get($storeKey, 'access_token') ?? $token;
                }

                $reader = ClaudeCodeCredentials::default();
                $creds = [
                    'access_token' => $token,
                    'refresh_token' => $store->get($storeKey, 'refresh_token'),
                    'expires_at' => (int) $expiresAt,
                ];
                $refreshed = $reader->refresh($creds);
                if ($refreshed === null) {
                    return $token;
                }
                $store->store($storeKey, 'access_token', $refreshed['access_token']);
                if (!empty($refreshed['refresh_token'])) {
                    $store->store($storeKey, 'refresh_token', $refreshed['refresh_token']);
                }
                if (!empty($refreshed['expires_at'])) {
                    $store->store($storeKey, 'expires_at', (string) $refreshed['expires_at']);
                }
                return $refreshed['access_token'];
            });
        }

        $out = ['auth_mode' => 'oauth', 'access_token' => $token];
        if ($storeKey === 'openai' && ($acct = $store->get($storeKey, 'account_id'))) {
            $out['account_id'] = $acct;
        }
        return $out;
    }

    /**
     * Map the raw CLI `--thinking` / `--verbose-thinking` / `--no-thinking`
     * options onto the RealTimeCliRenderer's THINKING_* constants.
     */
    private function thinkingMode(array $options): string
    {
        return match ($options['thinking'] ?? 'normal') {
            'verbose' => RealTimeCliRenderer::THINKING_VERBOSE,
            'hidden' => RealTimeCliRenderer::THINKING_HIDDEN,
            default => RealTimeCliRenderer::THINKING_NORMAL,
        };
    }

    /**
     * Build a StreamEventEmitter pre-wired with the rich renderer — used by
     * the one-shot path in ChatCommand when `--rich` is active.
     */
    public function makeRichEmitter(array $options = []): StreamEventEmitter
    {
        $emitter = new StreamEventEmitter();
        $rich = new RealTimeCliRenderer(
            output: new ConsoleOutput(),
            decorated: ($options['plain'] ?? false) ? false : null,
            thinkingMode: $this->thinkingMode($options),
        );
        $rich->attach($emitter);

        return $emitter;
    }

    /**
     * Build a StreamEventEmitter that writes the wire protocol (v1)
     * as newline-delimited JSON to the provided stream — used by
     * `--output json-stream` mode for IDE bridges, CI logs, and any
     * other pipeline consumer that wants structured events rather
     * than a rich terminal.
     *
     * Every event that flows through the emitter also implements
     * `WireEvent` (see `StreamEvent`'s base-class migration in Phase
     * 8b), so the output is self-describing — consumers pinning
     * `wire_version: 1` can latch on without prior state.
     *
     * @param resource $stream Writable resource (defaults to STDOUT).
     */
    public function makeJsonStreamEmitter($stream = null): StreamEventEmitter
    {
        $stream ??= defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        $out = new \SuperAgent\Harness\Wire\WireStreamOutput($stream);
        $emitter = new StreamEventEmitter();
        $emitter->on(static function ($event) use ($out) {
            if ($event instanceof \SuperAgent\Harness\Wire\WireEvent) {
                $out->emit($event);
            }
        });
        return $emitter;
    }

    /**
     * Decorate a StreamingHandler with LoopDetector observation —
     * opt-in via `$options['loop_detection']` (any truthy value,
     * including an array of per-detector threshold overrides
     * `['TOOL_CALL_LOOP_THRESHOLD' => 10, ...]`).
     *
     * When a detector trips, a `LoopDetectedEvent` is emitted on the
     * provided StreamEventEmitter (usually the json-stream emitter)
     * so consumers see the violation on the wire. The inner handler
     * keeps receiving every chunk unchanged — detection is purely
     * additive, doesn't stop the turn by itself.
     *
     * Default-off: callers who don't pass the option see zero
     * behaviour change.
     *
     * @param array<string, mixed> $options
     * @return array{0: ?\SuperAgent\StreamingHandler, 1: ?\SuperAgent\Guardrails\LoopDetector}
     */
    public function maybeWrapWithLoopDetection(
        ?\SuperAgent\StreamingHandler $inner,
        array $options,
        ?StreamEventEmitter $wireEmitter = null,
    ): array {
        $cfg = $options['loop_detection'] ?? null;
        if ($cfg === null || $cfg === false) {
            return [$inner, null];
        }

        $thresholds = is_array($cfg) ? $cfg : [];
        $detector = new \SuperAgent\Guardrails\LoopDetector($thresholds);

        $wrapped = \SuperAgent\Harness\Wire\ApprovalRuntime::class;  // keep use-tree warm for downstream readers
        unset($wrapped);

        $onViolation = static function (\SuperAgent\Guardrails\LoopViolation $v) use ($wireEmitter): void {
            if ($wireEmitter !== null) {
                $wireEmitter->emit(\SuperAgent\Harness\LoopDetectedEvent::fromViolation($v));
            }
        };

        $handler = \SuperAgent\Harness\LoopDetectionHarness::wrap(
            inner: $inner,
            detector: $detector,
            onViolation: $onViolation,
        );
        return [$handler, $detector];
    }

    /**
     * Run a one-shot prompt and return the result.
     *
     * When $emitter is provided, a StreamingHandler is built from it and
     * passed to Agent::prompt() so the rich renderer can show thinking,
     * tool use, and streaming text in real time.
     */
    public function runOneShot(Agent $agent, string $prompt, ?StreamEventEmitter $emitter = null): array
    {
        $result = $emitter !== null
            ? $agent->prompt($prompt, $emitter->toStreamingHandler())
            : $agent->prompt($prompt);

        if ($emitter !== null) {
            $turns = 0;
            $cost = 0.0;
            if ($result instanceof \SuperAgent\AgentResult) {
                $turns = $result->turns();
                $cost = (float) $result->totalCostUsd;
            }
            $emitter->emit(new \SuperAgent\Harness\AgentCompleteEvent(
                totalTurns: $turns,
                totalCostUsd: $cost,
                finalMessage: $result instanceof \SuperAgent\AgentResult ? $result->message : null,
            ));
        }

        if ($result instanceof \SuperAgent\AgentResult) {
            return [
                'content' => $result->text(),
                'cost' => $result->totalCostUsd,
                'turns' => $result->turns(),
                'usage' => method_exists($result, 'totalUsage')
                    ? (array) $result->totalUsage()
                    : [],
            ];
        }

        return [
            'content' => $result['content'] ?? '',
            'cost' => $result['cost'] ?? 0.0,
            'turns' => $result['turns'] ?? 1,
            'usage' => $result['usage'] ?? [],
        ];
    }
}
