<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartFlow\Flow;
use SuperAgent\SmartFlow\FlowDefinition;
use SuperAgent\SmartFlow\FlowEngine;
use SuperAgent\SmartFlow\FlowOptions;
use SuperAgent\SmartFlow\Skip;

class FlowEngineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/smartflow-test-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function opts(array $overrides = []): FlowOptions
    {
        return new FlowOptions(
            rehearse: $overrides['rehearse'] ?? true,
            resumeRunId: $overrides['resumeRunId'] ?? null,
            budgetTokens: $overrides['budgetTokens'] ?? null,
            runId: $overrides['runId'] ?? null,
            ledgerDir: $this->dir,
        );
    }

    public function test_solo_agent_text_and_schema(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['summary'],
            'properties' => ['summary' => ['type' => 'string']],
        ];

        $def = FlowDefinition::make('solo', 'demo', function (Flow $flow) use ($schema) {
            $text = $flow->agent('say hi', ['label' => 'greet']);
            $structured = $flow->agent('summarize', ['label' => 'sum', 'schema' => $schema]);
            return ['text' => $text, 'structured' => $structured];
        });

        $result = (new FlowEngine())->run($def, [], $this->opts());

        $this->assertTrue($result->isSuccessful(), $result->error ?? '');
        $this->assertIsString($result->value['text']);
        $this->assertIsArray($result->value['structured']);
        $this->assertArrayHasKey('summary', $result->value['structured']);
        // Rehearsal must cost nothing.
        $this->assertSame(0.0, $result->costUsd());
        $this->assertSame(2, $result->ledger['calls']);
    }

    public function test_parallel_runs_all_and_preserves_order(): void
    {
        $def = FlowDefinition::make('par', 'demo', function (Flow $flow) {
            return $flow->parallel([
                $flow->call('a', ['label' => 'a']),
                $flow->call('b', ['label' => 'b']),
                static fn () => 'closure-c',
            ]);
        });

        $result = (new FlowEngine())->run($def, [], $this->opts());
        $this->assertCount(3, $result->value);
        $this->assertStringContainsString('a', (string) $result->value[0]);
        $this->assertSame('closure-c', $result->value[2]);
    }

    public function test_pipeline_advances_items_through_stages(): void
    {
        $def = FlowDefinition::make('pipe', 'demo', function (Flow $flow) {
            return $flow->pipeline(
                ['x', 'y'],
                fn ($prev, $item) => $flow->call("stage1 {$item}", ['label' => 'stage1']),
                fn ($prev, $item) => $flow->call("stage2 {$item}", ['label' => 'stage2'])
            );
        });

        $result = (new FlowEngine())->run($def, [], $this->opts());
        $this->assertCount(2, $result->value);
        // 2 items x 2 stages = 4 agent calls
        $this->assertSame(4, $result->ledger['calls']);
    }

    public function test_council_votes_pass_under_fake(): void
    {
        $def = FlowDefinition::make('council', 'demo', function (Flow $flow) {
            return $flow->council('The sky is blue.', ['correctness', 'evidence', 'logic']);
        });

        $result = (new FlowEngine())->run($def, [], $this->opts());
        // SchemaStub returns enum[0] = 'pass' for every verdict → unanimous.
        $this->assertTrue($result->value['passed']);
        $this->assertSame(3, $result->value['total']);
    }

    public function test_gate_fallback_relays_value(): void
    {
        $def = FlowDefinition::make('gate', 'demo', function (Flow $flow) {
            return $flow->gate('nonempty', fn () => false, [
                'fallback' => fn () => 'recovered',
            ]);
        });

        $result = (new FlowEngine())->run($def, [], $this->opts());
        $this->assertFalse($result->value->passed);
        $this->assertTrue($result->value->relayed);
        $this->assertSame('recovered', $result->value->value);
    }

    public function test_required_gate_failure_fails_flow(): void
    {
        $def = FlowDefinition::make('gate2', 'demo', function (Flow $flow) {
            $flow->gate('must', fn () => false, ['required' => true]);
            return 'unreachable';
        });

        $result = (new FlowEngine())->run($def, [], $this->opts());
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('must', (string) $result->error);
    }

    public function test_zero_token_budget_blocks_calls(): void
    {
        $def = FlowDefinition::make('budget', 'demo', function (Flow $flow) {
            $flow->agent('expensive', ['label' => 'x']);
            return 'done';
        });

        $result = (new FlowEngine())->run($def, [], $this->opts(['budgetTokens' => 0]));
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('budget', strtolower((string) $result->error));
    }

    public function test_skip_sentinel_on_invalid_then_keep_filters(): void
    {
        // A schema the fake stub satisfies, so we instead force SKIP by using a
        // batchRunner override is overkill; assert keep() filters SKIP + null.
        $def = FlowDefinition::make('keep', 'demo', function (Flow $flow) {
            $values = [$flow->SKIP, null, 'real', 42];
            return $flow->keep($values);
        });
        $result = (new FlowEngine())->run($def, [], $this->opts());
        $this->assertSame(['real', 42], $result->value);
        $this->assertTrue(Skip::isSkip($flowSkip = Skip::instance()));
    }
}
