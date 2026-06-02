<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use Generator;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;
use SuperAgent\SmartFlow\SchemaStub;

/**
 * Deterministic, zero-cost provider used for SmartFlow rehearsal
 * ("MULTI_AI_FAKE_PROVIDER=1" / `flow run --rehearse`). It never touches the
 * network and reports zero token usage, so an entire multi-agent flow can be
 * exercised end-to-end without spending a cent ("零成本演练").
 *
 * Behaviour:
 *   - When the caller passes a JSON Schema via `options['_smartflow_schema']`,
 *     it returns a schema-conforming stub (see {@see SchemaStub}) so structured
 *     flows pass validation and complete.
 *   - Otherwise it returns a short, deterministic echo derived from the last
 *     user message and the call label — reproducible across runs, which keeps
 *     resume signatures stable.
 *
 * It is registered in {@see ProviderRegistry} under the key `fake`; in fake mode
 * the SmartFlow runner routes every provider to it transparently. Promoted from
 * the test-only fixture so production rehearsal uses the same code path.
 */
final class FakeProvider implements LLMProvider
{
    private string $model;

    public function __construct(array $config = [])
    {
        $this->model = (string) ($config['model'] ?? 'fake-1');
    }

    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator {
        $schema = $options['_smartflow_schema'] ?? null;
        $label = (string) ($options['_smartflow_label'] ?? 'agent');

        if (is_array($schema) && $schema !== []) {
            $stub = SchemaStub::generate($schema, $label);
            $text = json_encode($stub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            $text = $this->echo($messages, $label);
        }

        $msg = new AssistantMessage();
        $msg->content[] = ContentBlock::text((string) $text);
        $msg->stopReason = StopReason::EndTurn;
        // Zero tokens → zero cost: the whole point of rehearsal mode.
        $msg->usage = new Usage(inputTokens: 0, outputTokens: 0);
        $msg->metadata = ['fake' => true, 'label' => $label];

        yield $msg;
    }

    private function echo(array $messages, string $label): string
    {
        $last = '';
        foreach ($messages as $m) {
            if (is_object($m) && method_exists($m, 'text')) {
                $t = $m->text();
                if (is_string($t) && $t !== '') {
                    $last = $t;
                }
            } elseif (is_array($m) && isset($m['content']) && is_string($m['content'])) {
                $last = $m['content'];
            }
        }
        $snippet = trim(mb_substr($last, 0, 200));

        return "[rehearsal:{$label}] " . ($snippet !== '' ? $snippet : 'ok');
    }

    public function formatMessages(array $messages): array
    {
        return [];
    }

    public function formatTools(array $tools): array
    {
        return [];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function name(): string
    {
        return 'fake';
    }
}
