<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\Config\ConfigRepository;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;

/**
 * `superagent _subtask` — INTERNAL. Runs a single one-shot chat against one
 * model and prints a JSON envelope to stdout. Not user-facing; intended only
 * to be spawned by `SmartOrchestrator` in parallel mode so we get real
 * OS-level concurrency despite the providers' synchronous SSE pipes.
 *
 * Input (stdin, JSON):
 *   {
 *     "model":      "<model-id>",         // required
 *     "prompt":     "<user prompt>",      // required
 *     "system":     "<system prompt>",    // optional
 *     "max_tokens": 4000                  // optional
 *   }
 *
 * Output (stdout, JSON):
 *   {
 *     "ok":         true,
 *     "model":      "<model-id>",
 *     "output":     "<assistant text>",
 *     "usage":      { ... },
 *     "cost_usd":   0.0012,
 *     "latency_ms": 1840
 *   }
 *
 * On failure, `ok=false` and an `error` field is set; exit code is 1.
 * stderr is reserved for catastrophic crashes (Throwables that we couldn't
 * convert to a JSON envelope) — the parent surfaces stderr in its error
 * message so subprocess failures are debuggable.
 */
final class SubtaskCommand
{
    public function execute(array $options): int
    {
        $raw = stream_get_contents(STDIN);
        if ($raw === false || trim($raw) === '') {
            fwrite(STDERR, "_subtask: no input on stdin\n");
            return 2;
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            fwrite(STDERR, "_subtask: malformed JSON on stdin: " . json_last_error_msg() . "\n");
            return 2;
        }

        $modelId = (string) ($payload['model'] ?? '');
        $prompt  = (string) ($payload['prompt'] ?? '');
        if ($modelId === '' || $prompt === '') {
            fwrite(STDERR, "_subtask: 'model' and 'prompt' are required\n");
            return 2;
        }
        $system    = isset($payload['system']) ? (string) $payload['system'] : null;
        $maxTokens = (int) ($payload['max_tokens'] ?? 4000);

        $started = microtime(true);
        try {
            $entry = ModelCatalog::model($modelId);
            $provider = is_array($entry) ? (string) ($entry['provider'] ?? '') : '';
            if ($provider === '') {
                throw new \RuntimeException("model '{$modelId}' is not in the catalog");
            }
            $config = ConfigRepository::getInstance()->get("superagent.providers.{$provider}", []);
            $config = is_array($config) ? $config : [];
            $config['model'] = $modelId;
            $providerInstance = ProviderRegistry::create($provider, $config);

            $messages = [new UserMessage($prompt)];
            $final = null;
            foreach ($providerInstance->chat($messages, [], $system, ['max_tokens' => $maxTokens]) as $chunk) {
                if ($chunk instanceof AssistantMessage) {
                    $final = $chunk;
                }
            }

            $text = $final?->text() ?? '';
            $usage = $final?->usage;
            $cost = 0.0;
            if ($usage !== null) {
                try {
                    $cost = (float) CostCalculator::calculate($modelId, $usage);
                } catch (\Throwable) {
                    $cost = 0.0;
                }
            }
            $latency = (int) round((microtime(true) - $started) * 1000);

            $this->emitJson([
                'ok'         => true,
                'model'      => $modelId,
                'output'     => $text,
                'usage'      => $usage instanceof Usage ? $usage->toArray() : null,
                'cost_usd'   => round($cost, 6),
                'latency_ms' => $latency,
            ]);
            return 0;
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $started) * 1000);
            $this->emitJson([
                'ok'         => false,
                'model'      => $modelId,
                'error'      => $e->getMessage(),
                'latency_ms' => $latency,
            ]);
            return 1;
        }
    }

    /** @param array<string, mixed> $payload */
    private function emitJson(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
