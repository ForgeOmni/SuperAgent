<?php

namespace SuperAgent\Tests\Unit\Bridge\Streaming;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Streaming\OpenAIStreamTranslator;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;

class OpenAIStreamTranslatorTest extends TestCase
{
    public function test_text_message_translation(): void
    {
        $translator = new OpenAIStreamTranslator('gpt-4o', 'chatcmpl-test');

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hello world')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(10, 5);

        $chunks = $translator->translate($msg);

        // Should have: role chunk, content chunk, finish chunk, [DONE]
        $this->assertGreaterThanOrEqual(4, count($chunks));

        // First chunk: role
        $first = json_decode(substr($chunks[0], 6), true); // strip "data: "
        $this->assertSame('assistant', $first['choices'][0]['delta']['role']);

        // Second chunk: content
        $second = json_decode(substr($chunks[1], 6), true);
        $this->assertSame('Hello world', $second['choices'][0]['delta']['content']);

        // Finish chunk
        $finish = json_decode(substr($chunks[2], 6), true);
        $this->assertSame('stop', $finish['choices'][0]['finish_reason']);

        // Last: [DONE]
        $this->assertSame("data: [DONE]\n\n", end($chunks));
    }

    public function test_tool_call_translation(): void
    {
        $translator = new OpenAIStreamTranslator('gpt-4o');

        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::toolUse('call_1', 'bash', ['command' => 'ls']),
            ContentBlock::toolUse('call_2', 'read', ['path' => '/tmp']),
        ];
        $msg->stopReason = StopReason::ToolUse;
        $msg->usage = new Usage(10, 5);

        $chunks = $translator->translate($msg);

        // Find tool call chunks
        $toolChunks = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, 'data: {')) {
                $data = json_decode(substr($chunk, 6), true);
                if (isset($data['choices'][0]['delta']['tool_calls'])) {
                    $toolChunks[] = $data;
                }
            }
        }

        $this->assertCount(2, $toolChunks);

        // First tool call
        $tc1 = $toolChunks[0]['choices'][0]['delta']['tool_calls'][0];
        $this->assertSame(0, $tc1['index']);
        $this->assertSame('call_1', $tc1['id']);
        $this->assertSame('bash', $tc1['function']['name']);

        // Second tool call
        $tc2 = $toolChunks[1]['choices'][0]['delta']['tool_calls'][0];
        $this->assertSame(1, $tc2['index']);
        $this->assertSame('call_2', $tc2['id']);

        // Finish reason should be tool_calls
        $finishChunks = array_filter($chunks, function ($c) {
            if (!str_starts_with($c, 'data: {')) return false;
            $d = json_decode(substr($c, 6), true);
            return ($d['choices'][0]['finish_reason'] ?? null) !== null;
        });
        $finishData = json_decode(substr(array_values($finishChunks)[0], 6), true);
        $this->assertSame('tool_calls', $finishData['choices'][0]['finish_reason']);
    }

    public function test_model_and_id_in_chunks(): void
    {
        $translator = new OpenAIStreamTranslator('gpt-4o-mini', 'chatcmpl-xyz');

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(1, 1);

        $chunks = $translator->translate($msg);
        $data = json_decode(substr($chunks[0], 6), true);

        $this->assertSame('chatcmpl-xyz', $data['id']);
        $this->assertSame('gpt-4o-mini', $data['model']);
        $this->assertSame('chat.completion.chunk', $data['object']);
    }

    public function test_usage_in_finish_chunk(): void
    {
        $translator = new OpenAIStreamTranslator('gpt-4o');

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(100, 50);

        $chunks = $translator->translate($msg);

        // Find the finish chunk (one before [DONE])
        $finishChunk = $chunks[count($chunks) - 2];
        $data = json_decode(substr($finishChunk, 6), true);

        $this->assertSame(100, $data['usage']['prompt_tokens']);
        $this->assertSame(50, $data['usage']['completion_tokens']);
        $this->assertSame(150, $data['usage']['total_tokens']);
    }
}
