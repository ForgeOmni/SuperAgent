<?php

declare(strict_types=1);

namespace SuperAgent\Evals;

use SuperAgent\Config\ConfigRepository;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Orchestrate eval runs across (models × dimensions × cases).
 *
 * One run = one CLI invocation. For each (model, dim) pair the runner:
 *   1. Resolves the provider for the model via ModelCatalog.
 *   2. Builds a provider instance using ConfigRepository credentials.
 *   3. Iterates the dim's cases, sending each as a single-shot chat.
 *   4. Scores each output via Scorer.
 *   5. Aggregates (score, latency, cost) and upserts into ScoreCatalog.
 *
 * Progress is reported via the optional `$onEvent` callback so callers
 * (CLI, tests) can render whatever they like.
 *
 * @phpstan-type EventPayload array{
 *   type: string,
 *   model?: string,
 *   dim?: string,
 *   case_id?: string,
 *   passed?: bool,
 *   detail?: string,
 *   error?: string,
 * }
 */
final class EvalRunner
{
    /** @var callable(array<string, mixed>): void|null */
    private $onEvent;

    public function __construct(
        private DimensionLoader $loader,
        private ScoreCatalog $catalog,
        private ?LLMProvider $judgeProvider = null,
        ?callable $onEvent = null,
    ) {
        $this->onEvent = $onEvent;
    }

    /**
     * @param list<string> $modelIds      e.g. ['claude-opus-4-7', 'gpt-4o']
     * @param list<string> $dimensions    e.g. ['coding', 'tool_use']
     * @return array<string, array<string, array<string, mixed>>>  model → dim → result
     */
    public function run(array $modelIds, array $dimensions): array
    {
        $scorer = new Scorer($this->judgeProvider);
        $summary = [];

        foreach ($modelIds as $modelId) {
            $entry = ModelCatalog::model($modelId);
            $provider = $entry['provider'] ?? null;
            if (! is_string($provider) || $provider === '') {
                $this->emit(['type' => 'model_skip', 'model' => $modelId, 'error' => 'unknown model (not in catalog)']);
                continue;
            }

            try {
                $providerInstance = $this->buildProvider($provider, $modelId);
            } catch (\Throwable $e) {
                $this->emit(['type' => 'model_skip', 'model' => $modelId, 'error' => $e->getMessage()]);
                continue;
            }

            $this->emit(['type' => 'model_start', 'model' => $modelId]);

            foreach ($dimensions as $dim) {
                try {
                    $def = $this->loader->load($dim);
                } catch (\Throwable $e) {
                    $this->emit(['type' => 'dim_skip', 'model' => $modelId, 'dim' => $dim, 'error' => $e->getMessage()]);
                    continue;
                }

                $this->emit(['type' => 'dim_start', 'model' => $modelId, 'dim' => $dim, 'cases' => count($def['cases'])]);

                $passed = 0;
                $latencies = [];
                $cost = 0.0;

                foreach ($def['cases'] as $i => $case) {
                    $caseId = (string) ($case['id'] ?? ('case-' . ($i + 1)));
                    $prompt = (string) ($case['prompt'] ?? '');
                    $sysOverride = $case['system'] ?? null;
                    $sys = is_string($sysOverride) ? $sysOverride : $def['system'];
                    $opts = is_array($case['options'] ?? null) ? $case['options'] : [];

                    $started = microtime(true);
                    try {
                        [$output, $usage] = $this->oneShot($providerInstance, $prompt, $sys, $opts);
                    } catch (\Throwable $e) {
                        $this->emit([
                            'type' => 'case_error', 'model' => $modelId, 'dim' => $dim,
                            'case_id' => $caseId, 'error' => $e->getMessage(),
                        ]);
                        $latencies[] = (int) round((microtime(true) - $started) * 1000);
                        continue;
                    }
                    $latencyMs = (int) round((microtime(true) - $started) * 1000);
                    $latencies[] = $latencyMs;

                    $cost += $this->estimateCost($modelId, $usage);

                    $verdict = $scorer->score($case, $output);
                    if ($verdict['passed']) {
                        $passed++;
                    }
                    $this->emit([
                        'type' => 'case_done', 'model' => $modelId, 'dim' => $dim,
                        'case_id' => $caseId, 'passed' => $verdict['passed'], 'detail' => $verdict['detail'],
                        'latency_ms' => $latencyMs,
                    ]);
                }

                $cases = count($def['cases']);
                $avgLatency = $latencies === [] ? 0 : (int) round(array_sum($latencies) / count($latencies));
                $result = [
                    'score'      => $cases > 0 ? $passed / $cases : 0.0,
                    'cases'      => $cases,
                    'passed'     => $passed,
                    'latency_ms' => $avgLatency,
                    'cost_usd'   => $cost,
                ];

                $this->catalog->upsert($modelId, $provider, $dim, $result);
                $summary[$modelId][$dim] = $result;
                $this->emit(['type' => 'dim_done', 'model' => $modelId, 'dim' => $dim] + $result);
            }
        }

        return $summary;
    }

    /**
     * Single-shot chat — drains the streaming generator and returns
     * the final aggregated text + usage.
     *
     * @param array<string, mixed> $options
     * @return array{0: string, 1: ?Usage}
     */
    private function oneShot(LLMProvider $provider, string $prompt, ?string $system, array $options): array
    {
        $messages = [new UserMessage($prompt)];
        $final = null;
        foreach ($provider->chat($messages, [], $system, $options) as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
            }
        }
        $text = $final?->text() ?? '';
        return [$text, $final?->usage];
    }

    private function estimateCost(string $modelId, ?Usage $usage): float
    {
        if ($usage === null) {
            return 0.0;
        }
        try {
            return (float) CostCalculator::calculate($modelId, $usage);
        } catch (\Throwable) {
            $pricing = ModelCatalog::pricing($modelId);
            if ($pricing === null) {
                return 0.0;
            }
            $in = (int) ($usage->inputTokens ?? 0);
            $out = (int) ($usage->outputTokens ?? 0);
            return ($in * $pricing['input'] + $out * $pricing['output']) / 1_000_000;
        }
    }

    private function buildProvider(string $provider, string $modelId): LLMProvider
    {
        $config = ConfigRepository::getInstance()->get("superagent.providers.{$provider}", []);
        $config = is_array($config) ? $config : [];
        $config['model'] = $modelId;
        return ProviderRegistry::create($provider, $config);
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($event);
        }
    }
}
