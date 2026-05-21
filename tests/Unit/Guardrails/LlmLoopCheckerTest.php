<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Guardrails;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Guardrails\LlmLoopChecker;
use SuperAgent\Guardrails\LoopType;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\UserMessage;

class LlmLoopCheckerTest extends TestCase
{
    private function provider(string $responseJson, string $model = 'gemini-3-flash-preview'): LLMProvider
    {
        return new class($responseJson, $model) implements LLMProvider {
            public int $calls = 0;
            public function __construct(private string $response, private string $model) {}
            public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator
            {
                $this->calls++;
                $msg = new AssistantMessage();
                $msg->content = [ContentBlock::text($this->response)];
                yield $msg;
            }
            public function formatMessages(array $messages): array { return []; }
            public function formatTools(array $tools): array { return []; }
            public function getModel(): string { return $this->model; }
            public function setModel(string $model): void { $this->model = $model; }
            public function name(): string { return 'fake'; }
        };
    }

    private function history(): array
    {
        $h = [];
        for ($i = 0; $i < 5; $i++) {
            $h[] = new UserMessage("user prompt $i");
            $a = new AssistantMessage();
            $a->content = [ContentBlock::text("assistant says $i")];
            $h[] = $a;
        }
        return $h;
    }

    public function test_skips_under_min_turn_threshold(): void
    {
        $p = $this->provider('{"unproductive_state_confidence": 0.95, "unproductive_state_analysis": "x"}');
        $checker = new LlmLoopChecker($p);

        $this->assertNull($checker->turnStarted(5, $this->history()));
        $this->assertNull($checker->turnStarted(29, $this->history()));
        $this->assertSame(0, $p->calls, 'classifier must not be invoked under min turn threshold');
    }

    public function test_returns_violation_on_high_confidence(): void
    {
        $p = $this->provider('{"unproductive_state_confidence": 0.95, "unproductive_state_analysis": "stuck"}');
        $checker = new LlmLoopChecker($p);

        $v = $checker->turnStarted(30, $this->history(), 'original user goal');
        $this->assertNotNull($v);
        $this->assertSame(LoopType::LlmDetected, $v->type);
        $this->assertSame(0.95, $v->metadata['confidence']);
        $this->assertSame('gemini-3-flash-preview', $v->metadata['classifier_model']);
        $this->assertSame(1, $p->calls);
    }

    public function test_returns_null_below_confidence_threshold(): void
    {
        $p = $this->provider('{"unproductive_state_confidence": 0.5, "unproductive_state_analysis": "no"}');
        $checker = new LlmLoopChecker($p);

        $this->assertNull($checker->turnStarted(30, $this->history()));
        $this->assertSame(1, $p->calls);
    }

    public function test_respects_check_interval_between_probes(): void
    {
        $p = $this->provider('{"unproductive_state_confidence": 0.2, "unproductive_state_analysis": "no"}');
        $checker = new LlmLoopChecker($p);

        // Turn 30: probe fires (low confidence → interval widens).
        $this->assertNull($checker->turnStarted(30, $this->history()));
        $this->assertSame(1, $p->calls);

        // Turn 31..44: must NOT probe again (interval ≥ 5).
        for ($t = 31; $t < 30 + LlmLoopChecker::MAX_LLM_CHECK_INTERVAL; $t++) {
            $checker->turnStarted($t, $this->history());
        }
        $this->assertSame(1, $p->calls, 'no additional probes inside interval');

        // Turn at next eligible probe should fire again.
        $checker->turnStarted(30 + LlmLoopChecker::MAX_LLM_CHECK_INTERVAL, $this->history());
        $this->assertSame(2, $p->calls);
    }

    public function test_interval_tightens_with_rising_confidence(): void
    {
        $p = $this->provider('{"unproductive_state_confidence": 0.75, "unproductive_state_analysis": "maybe"}');
        $checker = new LlmLoopChecker($p);

        $checker->turnStarted(30, $this->history());
        // 0.75 ≥ 0.7 → MIN interval (5).
        $this->assertSame(LlmLoopChecker::MIN_LLM_CHECK_INTERVAL, $checker->checkInterval());
    }

    public function test_handles_json_inside_code_fence(): void
    {
        $p = $this->provider("Sure, here is my answer:\n```json\n{\"unproductive_state_confidence\": 0.92, \"unproductive_state_analysis\": \"yep\"}\n```\n");
        $checker = new LlmLoopChecker($p);

        $v = $checker->turnStarted(30, $this->history());
        $this->assertNotNull($v);
        $this->assertSame(0.92, $v->metadata['confidence']);
    }

    public function test_handles_provider_exception_gracefully(): void
    {
        $p = new class implements LLMProvider {
            public function chat(array $m, array $t = [], ?string $s = null, array $o = []): Generator
            {
                throw new \RuntimeException('upstream offline');
                yield; // make this a generator
            }
            public function formatMessages(array $messages): array { return []; }
            public function formatTools(array $tools): array { return []; }
            public function getModel(): string { return 'x'; }
            public function setModel(string $m): void {}
            public function name(): string { return 'broken'; }
        };
        $checker = new LlmLoopChecker($p);

        // Must not bubble; just return null.
        $this->assertNull($checker->turnStarted(30, $this->history()));
    }

    public function test_handles_unparseable_response(): void
    {
        $p = $this->provider("I'm not sure what to say");
        $checker = new LlmLoopChecker($p);

        $this->assertNull($checker->turnStarted(30, $this->history()));
    }
}
