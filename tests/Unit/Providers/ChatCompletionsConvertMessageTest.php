<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\OpenAIProvider;

/**
 * Pins the OpenAI Chat Completions wire-format conversion against the
 * full set of internal Message shapes — text, tool_use, parallel
 * tool_use, text+tool_use mix, tool_result, and the simple text round
 * trips. ChatCompletionsProvider is abstract; OpenAIProvider is the
 * thinnest concrete subclass and is constructible from just an api_key,
 * so it's the right vehicle for testing the base class behaviour.
 *
 * The original implementation had three latent defects this fixture
 * locks down:
 *   1. assistant tool_use blocks were serialized via $block->id /
 *      $block->name / $block->input — properties that don't exist on
 *      ContentBlock (the real names are toolUseId / toolName /
 *      toolInput). Every tool call sent back as conversation history
 *      went out as {id: null, name: null, arguments: "null"}.
 *   2. an early return on the first tool_use block dropped any
 *      sibling text or sibling tool_use blocks (parallel tool calls).
 *   3. ToolResultMessage fell through to a default branch that emitted
 *      `role: <Role enum>` + raw ContentBlock[] as content — never the
 *      `role: tool` + tool_call_id + string content shape OpenAI Chat
 *      Completions actually requires.
 */
class ChatCompletionsConvertMessageTest extends TestCase
{
    private function provider(): OpenAIProvider
    {
        return new OpenAIProvider(['api_key' => 'sk-test']);
    }

    public function test_text_only_user_message_round_trips(): void
    {
        $wire = $this->provider()->formatMessages([
            new UserMessage('hello'),
        ]);

        $this->assertSame([['role' => 'user', 'content' => 'hello']], $wire);
    }

    public function test_system_message_round_trips(): void
    {
        $wire = $this->provider()->formatMessages([
            new SystemMessage('you are a helpful assistant'),
        ]);

        $this->assertSame(
            [['role' => 'system', 'content' => 'you are a helpful assistant']],
            $wire
        );
    }

    public function test_assistant_text_only_round_trips(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [ContentBlock::text('the answer is 42')];

        $wire = $this->provider()->formatMessages([$assistant]);

        $this->assertSame(
            [['role' => 'assistant', 'content' => 'the answer is 42']],
            $wire
        );
    }

    public function test_assistant_tool_use_carries_id_name_and_input(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::toolUse('call_abc', 'get_weather', ['city' => 'Paris']),
        ];

        $wire = $this->provider()->formatMessages([$assistant])[0];

        $this->assertSame('assistant', $wire['role']);
        $this->assertNull($wire['content']);
        $this->assertCount(1, $wire['tool_calls']);

        $call = $wire['tool_calls'][0];
        $this->assertSame('call_abc', $call['id']);
        $this->assertSame('function', $call['type']);
        $this->assertSame('get_weather', $call['function']['name']);
        $this->assertSame(
            ['city' => 'Paris'],
            json_decode($call['function']['arguments'], true)
        );
    }

    public function test_parallel_tool_use_blocks_become_one_assistant_message_with_many_tool_calls(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::toolUse('call_1', 'search', ['q' => 'paris']),
            ContentBlock::toolUse('call_2', 'search', ['q' => 'london']),
            ContentBlock::toolUse('call_3', 'search', ['q' => 'tokyo']),
        ];

        $wire = $this->provider()->formatMessages([$assistant]);

        $this->assertCount(1, $wire, 'parallel tool calls collapse to one assistant message');
        $this->assertCount(3, $wire[0]['tool_calls']);
        $this->assertSame(
            ['call_1', 'call_2', 'call_3'],
            array_column($wire[0]['tool_calls'], 'id')
        );
    }

    public function test_text_and_tool_use_mix_keeps_both(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::text('let me check the weather: '),
            ContentBlock::toolUse('call_1', 'get_weather', ['city' => 'Paris']),
        ];

        $wire = $this->provider()->formatMessages([$assistant])[0];

        $this->assertSame('let me check the weather: ', $wire['content']);
        $this->assertCount(1, $wire['tool_calls']);
        $this->assertSame('call_1', $wire['tool_calls'][0]['id']);
    }

    public function test_thinking_block_is_dropped_for_chat_completions(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::thinking('internal reasoning'),
            ContentBlock::text('public answer'),
        ];

        $wire = $this->provider()->formatMessages([$assistant])[0];

        $this->assertSame('public answer', $wire['content']);
        $this->assertArrayNotHasKey('tool_calls', $wire);
    }

    public function test_empty_tool_input_serializes_as_object_not_array(): void
    {
        // Some compatible backends reject `arguments: "[]"` as a malformed
        // tool-call payload; the wire convention is `arguments: "{}"`.
        $assistant = new AssistantMessage();
        $assistant->content = [ContentBlock::toolUse('call_1', 'noop', [])];

        $args = $this->provider()->formatMessages([$assistant])[0]['tool_calls'][0]['function']['arguments'];

        $this->assertSame('{}', $args);
    }

    public function test_single_tool_result_becomes_role_tool_message(): void
    {
        $result = ToolResultMessage::fromResult('call_abc', 'sunny, 21°C');

        $wire = $this->provider()->formatMessages([$result]);

        $this->assertSame(
            [[
                'role' => 'tool',
                'tool_call_id' => 'call_abc',
                'content' => 'sunny, 21°C',
            ]],
            $wire
        );
    }

    public function test_parallel_tool_results_become_multiple_role_tool_messages(): void
    {
        $result = ToolResultMessage::fromResults([
            ['tool_use_id' => 'call_1', 'content' => 'paris result'],
            ['tool_use_id' => 'call_2', 'content' => 'london result'],
            ['tool_use_id' => 'call_3', 'content' => 'tokyo result'],
        ]);

        $wire = $this->provider()->formatMessages([$result]);

        $this->assertCount(3, $wire);
        $this->assertSame('tool', $wire[0]['role']);
        $this->assertSame('call_1', $wire[0]['tool_call_id']);
        $this->assertSame('paris result', $wire[0]['content']);
        $this->assertSame('call_2', $wire[1]['tool_call_id']);
        $this->assertSame('call_3', $wire[2]['tool_call_id']);
    }

    public function test_tool_result_array_payload_was_stringified_at_construction(): void
    {
        // The internal `tool_result` block always carries a string body —
        // ToolResultMessage::fromResult() json_encodes non-string inputs
        // at construction time. The wire converter must preserve that
        // string verbatim, not double-encode.
        $result = ToolResultMessage::fromResult(
            'call_1',
            ['ok' => true, 'rows' => 3]
        );

        $wire = $this->provider()->formatMessages([$result])[0];

        $this->assertSame('{"ok":true,"rows":3}', $wire['content']);
    }

    public function test_full_agent_loop_history_serializes_in_order(): void
    {
        // The realistic shape this fixture exists to lock down:
        //
        //   user → assistant(text + parallel tool_use) → tool_result(parallel)
        //        → assistant(final text)
        //
        // The wire form OpenAI requires is a flat list where the parallel
        // tool_results expand into N separate role:tool entries between
        // the two assistant turns. This is the case the original buggy
        // converter could not produce at all.
        $user1 = new UserMessage('what is the weather in paris and london?');

        $assistant1 = new AssistantMessage();
        $assistant1->content = [
            ContentBlock::text("I'll look both up."),
            ContentBlock::toolUse('call_1', 'get_weather', ['city' => 'Paris']),
            ContentBlock::toolUse('call_2', 'get_weather', ['city' => 'London']),
        ];

        $results = ToolResultMessage::fromResults([
            ['tool_use_id' => 'call_1', 'content' => 'Paris: 21°C sunny'],
            ['tool_use_id' => 'call_2', 'content' => 'London: 14°C rain'],
        ]);

        $assistant2 = new AssistantMessage();
        $assistant2->content = [
            ContentBlock::text('Paris is 21°C sunny, London is 14°C rain.'),
        ];

        $wire = $this->provider()->formatMessages([$user1, $assistant1, $results, $assistant2]);

        $this->assertCount(5, $wire, 'user + assistant + 2x tool + assistant');

        $this->assertSame('user',      $wire[0]['role']);
        $this->assertSame('assistant', $wire[1]['role']);
        $this->assertSame("I'll look both up.", $wire[1]['content']);
        $this->assertSame(['call_1', 'call_2'], array_column($wire[1]['tool_calls'], 'id'));

        $this->assertSame('tool',   $wire[2]['role']);
        $this->assertSame('call_1', $wire[2]['tool_call_id']);
        $this->assertSame('tool',   $wire[3]['role']);
        $this->assertSame('call_2', $wire[3]['tool_call_id']);

        $this->assertSame('assistant', $wire[4]['role']);
        $this->assertSame('Paris is 21°C sunny, London is 14°C rain.', $wire[4]['content']);
    }
}
