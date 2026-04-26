<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Conversation;

use PHPUnit\Framework\TestCase;
use SuperAgent\Conversation\Transcoder;
use SuperAgent\Conversation\WireFamily;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Tests the Transcoder facade and its two implemented encoders. The
 * deeper invariants (parallel tool calls, text+tool mix, empty input,
 * etc.) are pinned at the encoder level via WireFormatMatrixTest and
 * ChatCompletionsConvertMessageTest — this class focuses on the
 * facade's contract: dispatch by WireFamily, fail loudly on
 * not-yet-supported families, treat each encoder as an isolated unit.
 */
class TranscoderTest extends TestCase
{
    /**
     * @return list<\SuperAgent\Messages\Message>
     */
    private function fixture(): array
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::text('let me check'),
            ContentBlock::toolUse('call_1', 'search', ['q' => 'cats']),
        ];

        return [
            new SystemMessage('be concise'),
            new UserMessage('hi'),
            $assistant,
            ToolResultMessage::fromResult('call_1', 'cat results'),
        ];
    }

    public function test_anthropic_family_round_trips_native_shape(): void
    {
        $wire = (new Transcoder())->encode($this->fixture(), WireFamily::Anthropic);

        $this->assertCount(4, $wire);
        $this->assertSame('system',    $wire[0]['role']);
        $this->assertSame('be concise', $wire[0]['content']);

        $this->assertSame('user', $wire[1]['role']);
        $this->assertSame('hi',   $wire[1]['content']);

        $this->assertSame('assistant', $wire[2]['role']);
        $this->assertSame('text',     $wire[2]['content'][0]['type']);
        $this->assertSame('tool_use', $wire[2]['content'][1]['type']);
        $this->assertSame('call_1',   $wire[2]['content'][1]['id']);

        // tool_results in Anthropic ride on a user message
        $this->assertSame('user',        $wire[3]['role']);
        $this->assertSame('tool_result', $wire[3]['content'][0]['type']);
        $this->assertSame('call_1',      $wire[3]['content'][0]['tool_use_id']);
        $this->assertSame('cat results', $wire[3]['content'][0]['content']);
    }

    public function test_openai_chat_family_collapses_assistant_and_expands_tool_results(): void
    {
        $wire = (new Transcoder())->encode($this->fixture(), WireFamily::OpenAIChat);

        $this->assertCount(4, $wire);
        $this->assertSame('system',    $wire[0]['role']);
        $this->assertSame('user',      $wire[1]['role']);
        $this->assertSame('assistant', $wire[2]['role']);
        $this->assertSame('let me check', $wire[2]['content']);
        $this->assertSame('call_1',       $wire[2]['tool_calls'][0]['id']);

        $this->assertSame('tool',         $wire[3]['role']);
        $this->assertSame('call_1',       $wire[3]['tool_call_id']);
        $this->assertSame('cat results',  $wire[3]['content']);
    }

    public function test_openai_responses_family_emits_typed_input_items(): void
    {
        $wire = (new Transcoder())->encode($this->fixture(), WireFamily::OpenAIResponses);

        // SystemMessage 'be concise' → 1 message item
        // UserMessage  'hi'         → 1 message item
        // Assistant    text+tool   → 1 message + 1 function_call
        // ToolResult                → 1 function_call_output
        $this->assertCount(5, $wire);

        $this->assertSame('message', $wire[0]['type']);
        $this->assertSame('system',  $wire[0]['role']);
        $this->assertSame('input_text', $wire[0]['content'][0]['type']);

        $this->assertSame('message', $wire[1]['type']);
        $this->assertSame('user',    $wire[1]['role']);

        $this->assertSame('message',     $wire[2]['type']);
        $this->assertSame('assistant',   $wire[2]['role']);
        $this->assertSame('output_text', $wire[2]['content'][0]['type']);
        $this->assertSame('let me check', $wire[2]['content'][0]['text']);

        $this->assertSame('function_call', $wire[3]['type']);
        $this->assertSame('call_1',        $wire[3]['call_id']);
        $this->assertSame('search',        $wire[3]['name']);

        $this->assertSame('function_call_output', $wire[4]['type']);
        $this->assertSame('call_1',      $wire[4]['call_id']);
        $this->assertSame('cat results', $wire[4]['output']);
    }

    public function test_gemini_family_renames_assistant_to_model_and_routes_results_via_user(): void
    {
        $wire = (new Transcoder())->encode($this->fixture(), WireFamily::Gemini);

        // SystemMessage is dropped from contents[] (Gemini puts system
        // prompts on the request body's systemInstruction field).
        $this->assertCount(3, $wire);
        $this->assertSame('user',  $wire[0]['role']);
        $this->assertSame('hi',    $wire[0]['parts'][0]['text']);
        $this->assertSame('model', $wire[1]['role'], 'Gemini uses "model", not "assistant"');
        $this->assertSame('search', $wire[1]['parts'][1]['functionCall']['name']);

        $this->assertSame('user', $wire[2]['role']);
        $this->assertSame('search', $wire[2]['parts'][0]['functionResponse']['name']);
        $this->assertSame(
            ['content' => 'cat results'],
            $wire[2]['parts'][0]['functionResponse']['response']
        );
    }

    public function test_dashscope_family_emits_chat_completions_shape(): void
    {
        $wire = (new Transcoder())->encode($this->fixture(), WireFamily::DashScope);

        $this->assertCount(4, $wire);
        $this->assertSame(['system', 'user', 'assistant', 'tool'], array_column($wire, 'role'));
        $this->assertSame('let me check', $wire[2]['content']);
        $this->assertSame('call_1',       $wire[2]['tool_calls'][0]['id']);
        $this->assertSame('search',       $wire[2]['tool_calls'][0]['function']['name']);
        $this->assertSame('call_1',       $wire[3]['tool_call_id']);
        $this->assertSame('cat results',  $wire[3]['content']);
    }

    public function test_ollama_family_round_trips_tool_calls_and_results(): void
    {
        $wire = (new Transcoder())->encode($this->fixture(), WireFamily::Ollama);

        $this->assertCount(4, $wire);
        $this->assertSame(['system', 'user', 'assistant', 'tool'], array_column($wire, 'role'));
        $this->assertSame('let me check', $wire[2]['content']);
        $this->assertSame('search', $wire[2]['tool_calls'][0]['function']['name']);
        // Ollama wants `arguments` as a structured value (not a JSON
        // string the way OpenAI does).
        $this->assertSame(['q' => 'cats'], $wire[2]['tool_calls'][0]['function']['arguments']);
        $this->assertSame('call_1',      $wire[3]['tool_call_id']);
        $this->assertSame('cat results', $wire[3]['content']);
    }

    public function test_all_six_families_encode_without_throwing(): void
    {
        // After P4, every WireFamily case has a real Encoder. This
        // test exists to fail loudly the moment someone adds a new
        // case to the enum without wiring an encoder.
        $t = new Transcoder();
        foreach (WireFamily::cases() as $family) {
            $wire = $t->encode($this->fixture(), $family);
            $this->assertIsArray($wire, "{$family->value} produced non-array output");
        }
    }

    public function test_empty_input_round_trips_empty_for_every_family(): void
    {
        $t = new Transcoder();
        foreach (WireFamily::cases() as $family) {
            $this->assertSame([], $t->encode([], $family), "{$family->value} should produce []");
        }
    }
}
