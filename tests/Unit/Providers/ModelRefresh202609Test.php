<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\OpenAIResponsesProvider;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Pins for the 2026-09 model refresh — the API-surface changes that come
 * with Claude Fable 5.1, GPT-6 Astra and Gemini 3.8 Flash.
 *
 * Each assertion here stands for a request that would otherwise 400:
 * forced `tool_choice` on Fable 5.1, `reasoning.effort: none` on Astra,
 * `thinking_level: MINIMAL` on 3.8 Flash (that one lives in
 * {@see GeminiProviderTest}).
 */
class ModelRefresh202609Test extends TestCase
{
    // ---------------- Claude Fable 5.1: forced tool use removed ----------------

    public function test_forced_tool_choice_is_downgraded_on_fable_5_1(): void
    {
        $p = new AnthropicProvider(['api_key' => 'sk-test', 'model' => 'claude-fable-5-1']);

        foreach ([['type' => 'any'], ['type' => 'tool', 'name' => 'demo']] as $forced) {
            $body = $this->anthropicBody($p, ['tool_choice' => $forced]);
            $this->assertSame(
                ['type' => 'auto'],
                $body['tool_choice'],
                'Fable 5.1 returns 400 for forced tool_choice — it must be downgraded, not forwarded',
            );
        }
    }

    public function test_tool_choice_none_and_auto_pass_through_on_fable_5_1(): void
    {
        $p = new AnthropicProvider(['api_key' => 'sk-test', 'model' => 'claude-fable-5-1']);

        foreach ([['type' => 'none'], ['type' => 'auto']] as $choice) {
            $body = $this->anthropicBody($p, ['tool_choice' => $choice]);
            $this->assertSame($choice, $body['tool_choice']);
        }
    }

    public function test_forced_tool_choice_still_works_on_fable_5_and_opus(): void
    {
        foreach (['claude-fable-5', 'claude-opus-5', 'claude-sonnet-5'] as $model) {
            $p = new AnthropicProvider(['api_key' => 'sk-test', 'model' => $model]);
            $body = $this->anthropicBody($p, ['tool_choice' => ['type' => 'any']]);
            $this->assertSame(['type' => 'any'], $body['tool_choice'], $model);
        }
    }

    public function test_tool_choice_absent_when_caller_did_not_ask(): void
    {
        $p = new AnthropicProvider(['api_key' => 'sk-test', 'model' => 'claude-fable-5-1']);
        $this->assertArrayNotHasKey('tool_choice', $this->anthropicBody($p, []));
    }

    public function test_fable_5_1_keeps_the_claude_5_request_surface(): void
    {
        $p = new AnthropicProvider(['api_key' => 'sk-test', 'model' => 'claude-fable-5-1']);
        $body = $this->anthropicBody($p, ['temperature' => 0.7, 'top_p' => 0.9]);

        // Sampling params were removed across the Claude 5 generation.
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('top_p', $body);
    }

    // ---------------- GPT-6 Astra: no `none` effort, async tools ----------------

    public function test_astra_maps_no_thinking_onto_low_instead_of_none(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test', 'model' => 'gpt-6-astra']);

        foreach (['off', 'none', 'minimal', 'disabled'] as $tier) {
            $this->assertSame(
                ['reasoning' => ['effort' => 'low']],
                $p->reasoningEffortFragment($tier),
                "Astra rejects reasoning.effort=none — '{$tier}' must land on low",
            );
        }

        $this->assertSame(['reasoning' => ['effort' => 'max']], $p->reasoningEffortFragment('max'));
        $this->assertSame(['reasoning' => ['effort' => 'xhigh']], $p->reasoningEffortFragment('xhigh'));
    }

    public function test_gpt_56_keeps_its_none_tier(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test', 'model' => 'gpt-5.6-sol']);
        $this->assertSame(['reasoning' => ['effort' => 'none']], $p->reasoningEffortFragment('off'));
    }

    public function test_async_tools_are_marked_only_on_gpt_6(): void
    {
        $tool = $this->demoTool();

        $astra = new OpenAIResponsesProvider(['api_key' => 'sk-test', 'model' => 'gpt-6-astra']);
        $body = $this->responsesBody($astra, [$tool], ['async_tools' => ['demo']]);
        $this->assertTrue($body['tools'][0]['async'] ?? false);

        // Same request against a 5.6 model must not carry the field — it is a
        // validation error there.
        $sol = new OpenAIResponsesProvider(['api_key' => 'sk-test', 'model' => 'gpt-5.6-sol']);
        $body = $this->responsesBody($sol, [$tool], ['async_tools' => ['demo']]);
        $this->assertArrayNotHasKey('async', $body['tools'][0]);
    }

    public function test_async_tools_true_marks_every_tool(): void
    {
        $astra = new OpenAIResponsesProvider(['api_key' => 'sk-test', 'model' => 'gpt-6-astra']);
        $body = $this->responsesBody($astra, [$this->demoTool()], ['async_tools' => true]);
        $this->assertTrue($body['tools'][0]['async'] ?? false);
    }

    public function test_tools_stay_synchronous_by_default(): void
    {
        $astra = new OpenAIResponsesProvider(['api_key' => 'sk-test', 'model' => 'gpt-6-astra']);
        $body = $this->responsesBody($astra, [$this->demoTool()], []);
        $this->assertArrayNotHasKey('async', $body['tools'][0]);
    }

    // ---------------- Catalog ----------------

    public function test_catalog_carries_the_september_lineup(): void
    {
        foreach ([
            'claude-fable-5-1',
            'gpt-6-astra',
            'gemini-3.8-flash',
            'deepseek-flash',
            'qwen3.8-max-0902',
            'glm-5.3-flash',
        ] as $id) {
            $this->assertNotNull(ModelCatalog::pricing($id), "missing catalog pricing for {$id}");
        }
    }

    public function test_repriced_models_match_the_published_rates(): void
    {
        $this->assertSame(['input' => 2.0, 'output' => 10.0], $this->priceOf('claude-sonnet-5'));
        $this->assertSame(['input' => 10.0, 'output' => 50.0], $this->priceOf('claude-fable-5-1'));
        $this->assertSame(['input' => 10.0, 'output' => 50.0], $this->priceOf('gpt-6-astra'));
        $this->assertSame(['input' => 4.0, 'output' => 20.0], $this->priceOf('gpt-5.6-sol'));
        $this->assertSame(['input' => 0.2, 'output' => 1.2], $this->priceOf('gpt-5.6-luna'));
    }

    // ---------------- helpers ----------------

    /**
     * @param  array<string, mixed> $price
     * @return array{input: float, output: float}
     */
    private function priceOf(string $id): array
    {
        $price = ModelCatalog::pricing($id);
        $this->assertNotNull($price, $id);

        return ['input' => (float) $price['input'], 'output' => (float) $price['output']];
    }

    private function demoTool(): Tool
    {
        return new class extends Tool {
            public function name(): string { return 'demo'; }
            public function description(): string { return 'demo tool'; }
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }
            public function execute(array $input): ToolResult
            {
                return ToolResult::success('ok');
            }
            public function isReadOnly(): bool { return true; }
        };
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function anthropicBody(AnthropicProvider $p, array $options): array
    {
        $m = new \ReflectionMethod($p, 'buildRequestBody');

        return $m->invoke($p, [new UserMessage('hi')], [$this->demoTool()], null, $options);
    }

    /**
     * @param  Tool[]               $tools
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function responsesBody(OpenAIResponsesProvider $p, array $tools, array $options): array
    {
        $m = new \ReflectionMethod($p, 'buildRequestBody');

        return $m->invoke($p, [new UserMessage('hi')], $tools, null, $options);
    }
}
