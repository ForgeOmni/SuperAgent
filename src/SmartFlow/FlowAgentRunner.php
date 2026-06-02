<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Providers\ResponseFormat;
use SuperAgent\Tools\Schema\ProviderNormalizer;

/**
 * Executes a single {@see AgentCall} against whichever provider/model the call
 * (or its persona) resolves to — this is the "跨模型 agent" core: the same call
 * shape runs on any of the 15 registered providers.
 *
 * Pipeline for one call:
 *   1. Resolve persona → provider/model/system/temperature defaults.
 *   2. In fake/rehearsal mode, force the `fake` provider (zero cost).
 *   3. When the provider natively supports structured output AND a schema was
 *      requested, attach `response_format`; otherwise fall back to schema-in-prompt.
 *   4. Drain the streaming `chat()` generator into one AssistantMessage.
 *   5. Run the {@see StructuredOutputLadder} (native → submitted → extracted).
 *   6. Account tokens + cost and return an {@see AgentResult}.
 *
 * The runner never touches the budget or ledger — its caller ({@see Flow}) owns
 * those, so the same runner works in-process or inside a parallel worker.
 */
final class FlowAgentRunner
{
    public function __construct(
        private PersonaRegistry $personas,
        private bool $fake = false,
        private ?string $defaultProvider = null,
        private ?string $defaultModel = null,
        private ?LoggerInterface $logger = null,
        /** @var (callable(string, array):LLMProvider)|null Test seam to inject providers. */
        private $providerFactory = null,
    ) {
        $this->logger ??= new NullLogger();
        if ($this->defaultProvider === null) {
            $this->defaultProvider = (string) Cfg::get('superagent.default_provider', 'anthropic');
        }
        $this->defaultProvider = $this->defaultProvider ?: 'anthropic';
    }

    public function isFake(): bool
    {
        return $this->fake;
    }

    public function run(AgentCall $call): AgentResult
    {
        $persona = $call->role !== null ? ($this->personas->get($call->role) ?? []) : [];

        $providerName = $this->fake
            ? 'fake'
            : ($call->provider ?? ($persona['provider'] ?? $this->defaultProvider));
        $providerName = (string) $providerName;

        $model = $call->model
            ?? ($persona['model'] ?? null)
            ?? $this->defaultModel
            ?? $this->configModelFor($providerName);

        $system = $this->composeSystem($persona, $call);
        $temperature = $call->temperature ?? ($persona['temperature'] ?? null);

        try {
            $provider = $this->makeProvider($providerName, (string) $model);
        } catch (\Throwable $e) {
            return $this->failure($call, $providerName, (string) $model, 'provider init failed: ' . $e->getMessage());
        }

        // Decide native vs prompt-based structured output.
        $schema = $call->schema;
        $nativeRequested = false;
        $options = ['max_tokens' => $call->maxTokens];
        if ($temperature !== null) {
            $options['temperature'] = $temperature;
        }

        $userPrompt = $call->prompt;

        if ($schema !== null) {
            if ($this->fake) {
                // Let the fake provider synthesize a conforming stub.
                $options['_smartflow_schema'] = $schema;
                $options['_smartflow_label'] = $call->label;
                $nativeRequested = true;
            } elseif ($this->supportsNative($providerName)) {
                $options += ResponseFormat::jsonSchema(
                    $this->normalizeSchema($providerName, $schema),
                    $this->schemaName($call)
                )->toOpenAIFormat();
                $nativeRequested = true;
            } else {
                // Prompt-based: bake the schema into the user message.
                $userPrompt = $this->schemaPrompt($call->prompt, $schema);
            }
        }

        try {
            $messages = [new UserMessage($userPrompt)];
            $final = $this->drain($provider->chat($messages, [], $system, $options));
        } catch (\Throwable $e) {
            return $this->failure($call, $providerName, (string) $model, 'chat failed: ' . $e->getMessage());
        }

        $text = $final?->text() ?? '';
        $usage = $final?->usage ?? new Usage();
        $costUsd = $this->fake ? 0.0 : $this->safeCost((string) $model, $usage);

        $ladder = StructuredOutputLadder::resolve($text, $schema, $nativeRequested);

        if ($schema !== null && !$ladder['valid']) {
            $this->logger->warning('SmartFlow agent failed schema validation', [
                'label' => $call->label,
                'provider' => $providerName,
                'errors' => $ladder['errors'],
            ]);
        }

        return new AgentResult(
            value: $ladder['value'],
            text: $text,
            layer: $ladder['layer'],
            provider: $providerName,
            model: (string) $model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            costUsd: $costUsd,
            valid: $ladder['valid'],
            error: $ladder['valid'] ? null : implode('; ', $ladder['errors']),
            fake: $this->fake,
        );
    }

    private function composeSystem(array $persona, AgentCall $call): ?string
    {
        $parts = [];
        if (!empty($persona['system'])) {
            $parts[] = (string) $persona['system'];
        }
        if ($call->system !== null && $call->system !== '') {
            $parts[] = $call->system;
        }
        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function makeProvider(string $providerName, string $model): LLMProvider
    {
        if ($this->providerFactory !== null) {
            return ($this->providerFactory)($providerName, ['model' => $model]);
        }

        $config = [];
        $cfg = Cfg::get("superagent.providers.{$providerName}", []);
        if (is_array($cfg)) {
            $config = $cfg;
        }
        if ($model !== '') {
            $config['model'] = $model;
        }

        $provider = ProviderRegistry::create($providerName, $config);
        if ($model !== '') {
            $provider->setModel($model);
        }
        return $provider;
    }

    private function drain(\Generator $stream): ?AssistantMessage
    {
        $final = null;
        foreach ($stream as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
            }
        }
        return $final;
    }

    private function supportsNative(string $providerName): bool
    {
        $caps = ProviderRegistry::getCapabilities($providerName);
        return ($caps['structured_output'] ?? false) === true;
    }

    private function normalizeSchema(string $providerName, array $schema): array
    {
        try {
            return match (true) {
                $providerName === 'gemini' => ProviderNormalizer::forGemini($schema),
                $providerName === 'anthropic' => ProviderNormalizer::forAnthropic($schema),
                default => ProviderNormalizer::forOpenAI($schema),
            };
        } catch (\Throwable) {
            return $schema;
        }
    }

    private function schemaName(AgentCall $call): string
    {
        $name = $call->schema['title'] ?? $call->label;
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $name) ?: 'response';
        return $name;
    }

    private function schemaPrompt(string $prompt, array $schema): string
    {
        $schemaStr = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
{$prompt}

Respond with ONLY a JSON value conforming to this JSON Schema — no prose, no code fence:
{$schemaStr}
PROMPT;
    }

    private function configModelFor(string $providerName): string
    {
        $cfg = Cfg::get("superagent.providers.{$providerName}.model");
        if (is_string($cfg) && $cfg !== '') {
            return $cfg;
        }
        $default = Cfg::get('superagent.default_model');
        if (is_string($default) && $default !== '') {
            return $default;
        }
        $defaults = ProviderRegistry::getDefaultConfig($providerName);
        return (string) ($defaults['model'] ?? 'default');
    }

    private function safeCost(string $model, Usage $usage): float
    {
        try {
            return CostCalculator::calculate($model, $usage);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function failure(AgentCall $call, string $provider, string $model, string $error): AgentResult
    {
        $this->logger->error('SmartFlow agent run failed', ['label' => $call->label, 'error' => $error]);

        return new AgentResult(
            value: $call->schema !== null ? Skip::instance() : '',
            text: '',
            layer: 'none',
            provider: $provider,
            model: $model,
            valid: false,
            error: $error,
            fake: $this->fake,
        );
    }
}
